<?php

declare(strict_types=1);

namespace Aashan\PimcoreMcpBundle\Frontend;

/**
 * Builds the inline browser collector script.
 *
 * The script hooks uncaught errors, unhandled promise rejections and
 * console.error / console.warn, batches them and ships them to the ingest
 * endpoint with sendBeacon (falling back to fetch keepalive). It is defensive
 * throughout — every hook is wrapped so a failure in the collector can never
 * break the host page — and self-guards against double installation.
 */
final class CollectorScript
{
    /**
     * @return string A complete <script>…</script> block, endpoint pre-filled.
     */
    public static function html(string $ingestPath): string
    {
        $endpoint = json_encode($ingestPath, \JSON_UNESCAPED_SLASHES);
        $js = self::body();

        return "<script data-pimcore-mcp-client-errors>(function(){var __ENDPOINT__={$endpoint};{$js}})();</script>";
    }

    private static function body(): string
    {
        // Kept dependency-free and ES5-ish so it runs everywhere without a build step.
        return <<<'JS'
if (window.__pimcoreMcpClientErrors) { return; }
window.__pimcoreMcpClientErrors = true;

var MAX_QUEUE = 100, MAX_STR = 4000, FLUSH_MS = 2000, MAX_BEACON_BYTES = 50000;
var queue = [], timer = null;
var origError = window.console && console.error ? console.error.bind(console) : function(){};
var origWarn = window.console && console.warn ? console.warn.bind(console) : function(){};

function clip(s){ s = s == null ? '' : String(s); return s.length > MAX_STR ? s.slice(0, MAX_STR) + '…' : s; }

function push(rec){
    try {
        if (queue.length >= MAX_QUEUE) { return; }
        rec.ts = Date.now();
        rec.url = location.href;
        queue.push(rec);
        schedule();
    } catch (e) {}
}

function schedule(){ if (timer == null) { timer = setTimeout(flush, FLUSH_MS); } }

// POST one batch. Returns true only if the browser actually accepted it.
// sendBeacon returns false when it declines (payload over its ~64KB cap, or
// disabled) — we must honour that and fall back to fetch, otherwise reports
// are dropped silently.
function send(batch){
    if (!batch.length) { return true; }
    var body = JSON.stringify({ reports: batch });
    try {
        if (navigator.sendBeacon && navigator.sendBeacon(__ENDPOINT__, new Blob([body], { type: 'application/json' }))) {
            return true;
        }
    } catch (e) {}
    try {
        fetch(__ENDPOINT__, { method: 'POST', body: body, keepalive: true, credentials: 'same-origin', headers: { 'Content-Type': 'application/json' } });
        return true;
    } catch (e) {}
    return false;
}

function flush(){
    timer = null;
    if (!queue.length) { return; }
    // Pull a size-bounded chunk so a burst of errors never exceeds the beacon/
    // keepalive limit (which would make the whole batch fail).
    var batch = [], bytes = 0;
    while (queue.length) {
        var sz = MAX_STR;
        try { sz = JSON.stringify(queue[0]).length; } catch (e) {}
        if (batch.length && bytes + sz > MAX_BEACON_BYTES) { break; }
        batch.push(queue.shift());
        bytes += sz;
    }
    if (!send(batch)) {
        // Put the chunk back (bounded) so a later flush can retry.
        queue = batch.concat(queue).slice(0, MAX_QUEUE);
    }
    if (queue.length) { schedule(); } // drain the rest on the next tick
}

function argsToMessage(args){
    var parts = [];
    for (var i = 0; i < args.length; i++) {
        var a = args[i];
        try {
            if (a instanceof Error) { parts.push(a.message); }
            else if (typeof a === 'object') { parts.push(JSON.stringify(a)); }
            else { parts.push(String(a)); }
        } catch (e) { parts.push('[unserializable]'); }
    }
    return parts.join(' ');
}

function stackOf(args){
    for (var i = 0; i < args.length; i++) { if (args[i] instanceof Error && args[i].stack) { return clip(args[i].stack); } }
    return undefined;
}

// Uncaught errors. Skip resource-load errors (target is an element, not a script error).
window.addEventListener('error', function(e){
    if (e && e.target && e.target !== window && (e.target.tagName || e.target.nodeName)) { return; }
    push({ type: 'error', message: clip(e && e.message), stack: e && e.error && e.error.stack ? clip(e.error.stack) : undefined,
           source: e && e.filename ? clip(e.filename) : undefined, line: e && e.lineno, col: e && e.colno });
}, true);

// Unhandled promise rejections.
window.addEventListener('unhandledrejection', function(e){
    var r = e ? e.reason : null;
    push({ type: 'unhandledrejection', message: clip(r && r.message ? r.message : r), stack: r && r.stack ? clip(r.stack) : undefined });
});

// console.error / console.warn — wrapped without breaking normal logging.
console.error = function(){ try { push({ type: 'console.error', message: clip(argsToMessage(arguments)), stack: stackOf(arguments) }); } catch (e) {} return origError.apply(console, arguments); };
console.warn  = function(){ try { push({ type: 'console.warn',  message: clip(argsToMessage(arguments)), stack: stackOf(arguments) }); } catch (e) {} return origWarn.apply(console, arguments); };

// Make sure nothing is lost when the page goes away.
window.addEventListener('visibilitychange', function(){ if (document.visibilityState === 'hidden') { flush(); } });
window.addEventListener('pagehide', flush);
JS;
    }
}
