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
 * The script is appended just before </body> for full HTML documents on the
 * main request, so both the public website and the Pimcore admin pick it up
 * with zero configuration. Everything else — JSON/XHR responses, redirects,
 * streamed/attachment responses, the ingest endpoint itself — is left
 * untouched, and it never runs outside debug mode.
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
        if ($content === false || stripos($content, '</body>') === false) {
            return;
        }
        // Guard against double injection (e.g. nested renders).
        if (str_contains($content, 'data-pimcore-mcp-client-errors')) {
            return;
        }

        $script = CollectorScript::html(ClientErrorIngestListener::INGEST_PATH);
        // Insert before the last </body> so the script is the last thing parsed.
        $pos = strripos($content, '</body>');
        $response->setContent(substr($content, 0, $pos) . $script . substr($content, $pos));
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
