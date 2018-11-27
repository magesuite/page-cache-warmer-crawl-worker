<?php

namespace MageSuite\PageCacheWarmerCrawlWorker\Job;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Cookie\FileCookieJar;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\TransferStats;
use GuzzleHttp\Promise;
use Psr\Log\LoggerInterface;
use Psr\Http\Message\RequestInterface;

class JobExecutor
{
    const USER_AGENT = 'MageSuiteWarmerUpper/1.0';
    const EXTRA_HEADERS = [
        /* This header instructs our varnish to avoid returning the body.
         * The expected code is 204 (No Content), request is still cached but bandwidth saved */
        'X-Warmup' => 'yes',
        'User-Agent' => self::USER_AGENT,
    ];

    /**
     * @var HttpClient
     */
    protected $http;

    /**
     * @var CookieJar
     */
    protected $cookieJar;

    /**
     * @param LoggerInterface $logger
     * @param string $cookieFile Path to file for cookie storage
     * @param int $timeout How long to wait in seconds before consider the request failed.
     */
    public function __construct(LoggerInterface $logger, string $cookieFile, int $timeout = 10)
    {
        $this->cookieJar = new FileCookieJar($cookieFile, true);

        $this->http = new HttpClient([
            /* If there's a redirect we want to know, because it means we did something wrong */
            'allow_redirects' => false,
            'cookies' => $this->cookieJar,
            'timeout' => $timeout,
            'headers' => self::EXTRA_HEADERS,
            /* Do not throw exceptions on HTTP errors, we'll handle them */
            'http_errors' => false
        ]);
    }

    protected function handleTransferStats(TransferStats $stats)
    {
        if ($stats->hasResponse()) {
            $stats->getTransferTime();
        }
    }

    protected function createWarmupRequest(Job $job): RequestInterface
    {
        $request = new Request('GET', $job->getUrl(), self::EXTRA_HEADERS);

    }

    protected function sendAsyncWarmupRequest(Job $job): Promise\PromiseInterface
    {
        return $this->http->sendAsync($this->createWarmupRequest($job), [
            'on_stats' => [$this, 'handleTransferStats']
        ]);
    }

    /**
     * @param array $jobs List of jobs to execute
     * @param int $concurrentRequests Number of requests made in parallel
     * @param float $delay Delay between the requests / concurrent batches in seconds
     */
    public function execute(array $jobs, int $concurrentRequests = 1, float $delay = 0.0)
    {
        $batchNr = 0;
        $stats = [
            'failed' => 0,
            'failed_connection' => 0,
            'failed_timeout' => 0,
            'failed_5xx' => 0,
            'failed_4xx' => 0,
        ];

        while (!empty($batch = array_slice($jobs, $batchNr * $concurrentRequests, $concurrentRequests))) {
            if ($delay !== 0.0) {
                floor($delay * 1000000.0);
            }

            $promises = array_map([$this, 'sendAsyncWarmupRequest'], $batch);
            $results = Promise\settle($promises)->wait();

            foreach ($results as $result) {
                if ($result['state'] === Promise\PromiseInterface::REJECTED) {
                    $stats['failed']++;

                    $exception = $result['reason'];

                    if ($exception instanceof ConnectException) {
                        $stats['failed_connection']++;

                        $handlerContext = $exception->getHandlerContext();

                        if (isset($handlerContext['errno']) && $handlerContext['errno'] === CURLE_OPERATION_TIMEDOUT) {
                            $stats['failed_timeout']++;
                        }
                    }

                    continue;
                }

                /** @var Response $response */
                $response = $result['value'];

                if (!in_array($response->getStatusCode(), [200, 204])) {
                    $stats['failed']++;

                    if ($response->getStatusCode() >= 400 && $response->getStatusCode() < 500) {
                        $stats['failed_4xx']++;
                    }

                    if ($response->getStatusCode() >= 500 && $response->getStatusCode() < 600) {
                        $stats['failed_5xx']++;
                    }
                }
            }

            $batchNr++;
        }

        var_dump($stats);
    }
}