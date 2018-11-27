<?php

namespace MageSuite\PageCacheWarmerCrawlWorker\Tests\Utils;

use Creativestyle\AppHttpServerMock\Server;
use Symfony\Component\HttpFoundation\HeaderBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class VarnishStubServer extends Server
{
    const MAGENTO_VARY_COOKIE = 'X-Magento-Vary';
    const CACHE_INFO_HEADER = 'X-Magento-Cache-Debug';
    const DEFAULT_PAGE_HEADERS = [
        'Vary' => 'Accept-Encoding',
        'Pragma' => 'cache',
        'Cache-Control' => 'public, max-age=60',
        'Content-Type' => 'text/html; charset=UTF-8',
    ];

    /**
     * @var Response[]
     */
    private $vclCache = [];

    private function vclHash(Request $request): string
    {
        return md5($request->getRequestUri() . $request->cookies->get(self::MAGENTO_VARY_COOKIE));
    }

    private function vclMiddleware(callable $handler): callable
    {
        return function(Request $request, array $arg) use ($handler) {
            /* We only care about GET requests */
            if ($request->getMethod() !== 'GET') {
                return $handler($request, $arg);
            }

            $vclHash = $this->vclHash($request);

            if (isset($this->vclCache[$vclHash])) {
                $response = $this->vclCache[$vclHash];
                $response->headers->set(self::CACHE_INFO_HEADER, 'HIT');

                return $response;
            }

            /** @var Response $response */
            $response = $handler($request, $arg);

            /* We only cache 200 responses */
            if ($response->getStatusCode() !== 200) {
                return $response;
            }

            $this->vclCache[$vclHash] = $response;
            $response->headers->set(self::CACHE_INFO_HEADER, 'MISS');

            if ($request->headers->get('X-Warmup')) {
                $response->setStatusCode(204);
                $response->setContent('');
            } else {
                $response->headers = new HeaderBag(array_merge(
                    self::DEFAULT_PAGE_HEADERS,
                   $response->headers->all()
                ));
            }

            return $response;
        };
    }

    private function handlePdp(Request $request, array $args)
    {
        return new Response("<html>Product ${args['id']} Detail Page</html>");
    }

    /**
     * {@inheritdoc}
     */
    protected function registerRequestHandlers()
    {
        $this->registerRequestHandler('GET', '/product/(?<id>\d+)',
            $this->vclMiddleware([$this, 'handlePdp'])
        );
    }
}