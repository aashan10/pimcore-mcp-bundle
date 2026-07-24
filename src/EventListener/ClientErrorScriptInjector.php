<?php

declare(strict_types=1);

namespace Aashan\PimcoreMcpBundle\EventListener;

use Aashan\PimcoreMcpBundle\Frontend\CollectorScript;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Auto-injects the browser collector script into HTML responses (debug only).
 *
 * The script is inserted as the FIRST thing inside <head> (immediately after
 * the opening tag) for full HTML documents on the main request, so both the
 * public website and the Pimcore admin pick it up with zero configuration.
 *
 * Placement matters: window.onerror / the 'error' event and the console.error
 * / console.warn wrappers only capture what happens AFTER they are installed.
 * The collector must therefore run before any other script on the page — being
 * an inline classic <script> at the top of <head> guarantees it executes first
 * (module and deferred scripts run later by definition). Injecting near </body>
 * would miss every error thrown by the page's own head/body scripts.
 *
 * Everything else — JSON/XHR responses, redirects, streamed/attachment
 * responses, the ingest endpoint itself — is left untouched, and it never runs
 * outside debug mode.
 */
#[AsEventListener(event: KernelEvents::RESPONSE, method: 'onResponse', priority: -1024)]
final class ClientErrorScriptInjector
{
    public function __construct(
        private readonly KernelInterface $kernel,
    ) {}

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest() || !$this->kernel->isDebug()) {
            return;
        }

        $request = $event->getRequest();
        if ($request->getPathInfo() === ClientErrorIngestListener::INGEST_PATH) {
            return;
        }

        $response = $event->getResponse();
        if (!$this->isInjectableHtml($response)) {
            return;
        }

        $content = $response->getContent();
        if ($content === false) {
            return;
        }
        // Guard against double injection (e.g. nested renders).
        if (str_contains($content, 'data-pimcore-mcp-client-errors')) {
            return;
        }

        $pos = $this->insertionOffset($content);
        if ($pos === null) {
            return;
        }

        $script = CollectorScript::html(ClientErrorIngestListener::INGEST_PATH, $this->cspScriptNonce($response));
        $response->setContent(substr($content, 0, $pos) . $script . substr($content, $pos));
    }

    /**
     * Extracts the script-src nonce from the response's enforcing CSP, if any.
     *
     * When a page enforces a Content-Security-Policy that lists a nonce in
     * script-src (the Pimcore admin does, per request), 'unsafe-inline' is
     * ignored and any inline <script> without a matching nonce is blocked. We
     * reuse the page's own nonce so the collector is allowed to run. Only the
     * enforcing header is consulted — a report-only policy blocks nothing.
     */
    private function cspScriptNonce(Response $response): ?string
    {
        $csp = (string) $response->headers->get('Content-Security-Policy', '');
        if ($csp === '') {
            return null;
        }

        foreach (explode(';', $csp) as $directive) {
            if (stripos(ltrim($directive), 'script-src') !== 0) {
                continue;
            }
            if (preg_match("/'nonce-([A-Za-z0-9+\/=_-]+)'/", $directive, $m) === 1) {
                return $m[1];
            }
        }

        return null;
    }

    /**
     * Byte offset at which to insert the script, chosen so it runs before any
     * other script on the page: right after the opening <head> tag, else right
     * after <html>, else right after <body> as a last resort. Null when the
     * body has no recognisable HTML structure to place into.
     *
     * Charset note: Symfony sends the charset in the Content-Type header, which
     * the browser honours over a <meta charset> — so placing the script ahead
     * of a <meta charset> does not affect encoding detection.
     */
    private function insertionOffset(string $content): ?int
    {
        foreach (['/<head\b[^>]*>/i', '/<html\b[^>]*>/i', '/<body\b[^>]*>/i'] as $pattern) {
            if (preg_match($pattern, $content, $m, \PREG_OFFSET_CAPTURE) === 1) {
                return $m[0][1] + \strlen($m[0][0]);
            }
        }

        return null;
    }

    private function isInjectableHtml(Response $response): bool
    {
        // Skip streamed/binary responses we cannot safely rewrite.
        if (!$response->getContent() && $response->getContent() !== '') {
            return false;
        }
        if ($response->headers->has('Content-Disposition')) {
            return false; // downloads/attachments
        }
        $contentType = (string) $response->headers->get('Content-Type', 'text/html');

        return str_contains(strtolower($contentType), 'text/html');
    }
}
