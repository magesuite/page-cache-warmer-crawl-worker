<?php

namespace MageSuite\PageCacheWarmerCrawlWorker;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Uri;
use Psr\Http\Message\RequestInterface;

class ClientFactory
{
    const DEFAULT_TIMEOUT = 10;
    const USER_AGENT = 'MageSuiteWarmerUpper/1.0';

    /**
     * @var Uri
     */
    private $varnishUri;
    /**
     * @var null|string
     */
    private $debugLog;

    /**
     * If varnish uri is provided then requests are rewritten to directly use varnish via
     * local network instead of going through the whole stack, wasting bandwidth and, e.g.
     * HTTPS negotation time.
     *
     * @param string|null $varnishUri E.g. http://10.13.37.1:8080/
     * @param string|null $debugLog If set then curl debug log will be written to this file
     */
    public function __construct(string $varnishUri = null, string $debugLog = null)
    {
        $this->varnishUri = new Uri($varnishUri);
        $this->debugLog = $debugLog;
    }

    public function varnishRewriteMiddleware(callable $handler)
    {
        $varnishUri = $this->varnishUri;

        return function (RequestInterface $request, array $options) use ($handler, $varnishUri) {
            if (null !== $varnishUri) {
                $request = $request
                    ->withUri(
                        $varnishUri
                            ->withPath($request->getUri()->getPath())
                            ->withQuery($request->getUri()->getQuery())
                    )
                    ->withHeader('Host', $request->getUri()->getHost())
                    ->withHeader('X-Forwarded-Proto', $request->getUri()->getScheme())
                ;
            }

            return $handler($request, $options);
        };
    }

    /**
     * @param int $timeout How long to wait in seconds before consider the request failed.
     * @return Client
     */
    public function createClient(int $timeout = self::DEFAULT_TIMEOUT): Client
    {
        $stack = new HandlerStack();
        $stack->setHandler(new CurlHandler());
        $stack->push([$this, 'varnishRewriteMiddleware'], 'varnishRequestRewrite');

        return new Client([
            'timeout' => $timeout,
            /* Do not throw exceptions on HTTP errors, we'll handle them */
            'http_errors' => false,
            'handler' => $stack,
            'headers' => [
                'User-Agent' => self::USER_AGENT,
            ],
            'debug' => null !== $this->debugLog ? fopen($this->debugLog, 'a') : false,
        ]);
    }
}