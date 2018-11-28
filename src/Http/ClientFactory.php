<?php

namespace MageSuite\PageCacheWarmerCrawlWorker\Http;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\CurlMultiHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\MessageFormatter;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Uri;
use Psr\Http\Message\RequestInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

class ClientFactory
{
    const DEFAULT_TIMEOUT = 30;
    const USER_AGENT = 'MageSuiteWarmerUpper/1.0';
    const REQUEST_LOG_FORMAT = ">>>>>>>>\n{req_headers}\n{req_body}\n<<<<<<<<\n{res_headers}\n--------\n{error}";

    /**
     * @var Uri
     */
    private $varnishUri;
    /**
     * @var bool
     */
    private $debugLogging;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * If varnish uri is provided then requests are rewritten to directly use varnish via
     * local network instead of going through the whole stack, wasting bandwidth and, e.g.
     * HTTPS negotation time.
     *
     * @param LoggerInterface $logger
     * @param string|null $varnishUri E.g. http://10.13.37.1:8080/
     * @param bool $debugLogging
     */
    public function __construct(LoggerInterface $logger, string $varnishUri = null, bool $debugLogging = false)
    {
        if (null !== $varnishUri) {
            $this->varnishUri = new Uri($varnishUri);
        }

        $this->debugLogging = $debugLogging;
        $this->logger = $logger;
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
        $stack = HandlerStack::create(new CurlMultiHandler());
        $stack->push([$this, 'varnishRewriteMiddleware'], 'varnishRequestRewrite');

        if ($this->debugLogging) {
            $formatter = new MessageFormatter(self::REQUEST_LOG_FORMAT);

            $stack->push(Middleware::log($this->logger, $formatter, LogLevel::DEBUG));
        }

        return new Client([
            'timeout' => $timeout,
            /* Do not throw exceptions on HTTP errors, we'll handle them */
            'http_errors' => false,
            'handler' => $stack,
            'headers' => [
                'User-Agent' => self::USER_AGENT,
            ]
        ]);
    }
}