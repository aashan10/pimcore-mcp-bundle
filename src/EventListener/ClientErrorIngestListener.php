<?php

declare(strict_types=1);

namespace Aashan\PimcoreMcpBundle\EventListener;

use Aashan\PimcoreMcpBundle\Frontend\ClientErrorStore;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Receives browser error/warning reports and hands them to the store.
 *
 * Runs very early on kernel.request (before routing and the security firewall)
 * and short-circuits the one fixed ingest path with its own response, so no
 * route registration or authentication is involved — the endpoint simply
 * exists whenever the bundle is enabled in debug mode.
 *
 * It is a hard no-op outside debug mode: in production the path is not handled
 * at all, so the collector effectively does not exist there.
 */
#[AsEventListener(event: KernelEvents::REQUEST, method: 'onRequest', priority: 1024)]
final class ClientErrorIngestListener
{
    /** The path the injected collector posts to. Kept in sync with the injector. */
    public const INGEST_PATH = '/_pimcore-mcp/client-errors';

    private const MAX_BODY_BYTES = 65_536; // 64 KB per beacon

    public function __construct(
        private readonly ClientErrorStore $store,
        private readonly KernelInterface $kernel,
    ) {}

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || !$this->kernel->isDebug()) {
            return;
        }

        $request = $event->getRequest();
        if ($request->getPathInfo() !== self::INGEST_PATH) {
            return;
        }

        // From here the request is ours: always answer, never fall through.
        if ($request->getMethod() !== Request::METHOD_POST) {
            $event->setResponse(new JsonResponse(['error' => 'method not allowed'], 405));

            return;
        }

        $stored = 0;
        $content = $request->getContent();
        if ($content !== '' && \strlen($content) <= self::MAX_BODY_BYTES) {
            $payload = json_decode($content, true);
            $reports = \is_array($payload) ? ($payload['reports'] ?? $payload) : null;
            if (\is_array($reports)) {
                $stored = $this->store->append($reports, [
                    'ip' => $request->getClientIp(),
                    'ua' => $request->headers->get('User-Agent'),
                ]);
            }
        }

        // The client (sendBeacon/fetch keepalive) ignores the body; a small
        // JSON ack keeps the endpoint debuggable by hand.
        $event->setResponse(new JsonResponse(['stored' => $stored]));
    }
}
