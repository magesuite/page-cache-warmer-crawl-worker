<?php

namespace MageSuite\PageCacheWarmerCrawlWorker\Job;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\TransferStats;
use GuzzleHttp\Promise;
use MageSuite\PageCacheWarmerCrawlWorker\Http\ClientFactory;
use MageSuite\PageCacheWarmerCrawlWorker\Customer\SessionProvider;
use MageSuite\PageCacheWarmerCrawlWorker\Logging\EventFormattingLogger;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Psr\Http\Message\RequestInterface;

class JobExecutor
{
    const CACHE_INFO_HEADER = 'X-Magento-Cache-Debug';

    const DEFAULT_WARMUP_HEADERS = [
        'X-Warmup' => 'yes',
        'Accept-Encoding' => 'gzip, deflate',
    ];

    /**
     * @var Client
     */
    protected $client;

    /**
     * @var EventFormattingLogger
     */
    protected $logger;

    /**
     * @var SessionProvider
     */
    private $sessions;
    /**
     * @var array
     */
    private $extraWarmupHeaders;

    /**
     * @param \MageSuite\PageCacheWarmerCrawlWorker\Customer\SessionProvider $sessions
     * @param ClientFactory $clientFactory
     * @param LoggerInterface $logger
     * @param int $requestTimeout
     * @param array $extraWarmupHeaders
     */
    public function __construct(
        SessionProvider $sessions,
        ClientFactory $clientFactory,
        LoggerInterface $logger,
        int $requestTimeout = ClientFactory::DEFAULT_TIMEOUT,
        array $extraWarmupHeaders = self::DEFAULT_WARMUP_HEADERS
    ) {
        $this->logger = new EventFormattingLogger($logger, 'Executor');
        $this->client = $clientFactory->createClient($requestTimeout);
        $this->sessions = $sessions;
        $this->extraWarmupHeaders = $extraWarmupHeaders;
    }

    protected function createWarmupRequest(Job $job): RequestInterface
    {
        return new Request('GET', $job->getUrl());
    }

    protected function sendAsyncWarmupRequest(Job $job): Promise\PromiseInterface
    {
        $job->setSession(
            $this->sessions->getSession(
                $job->getUrlScheme(),
                $job->getUrlHost(),
                $job->getCustomerGroup()
            )
        );

        return $this->client->sendAsync($this->createWarmupRequest($job), [
            'cookies' => $job->getSession()->getCookies(),
            /* If there's a redirect we want to know, because it means we did something wrong */
            'allow_redirects' => false,
            'headers' => $this->extraWarmupHeaders,
            'on_stats' => function (TransferStats $stats) use ($job) {
                if ($stats->hasResponse()) {
                    /* Get TTFB if avaialble as it's better measure of server load */
                    if (isset($stats->getHandlerStats()['starttransfer_time'])) {
                        $job->setTransferTime($stats->getHandlerStats()['starttransfer_time']);
                    }

                    $job->setTransferTime($stats->getTransferTime());
                }
            }
        ]);
    }

    private function isCacheHit(ResponseInterface $response): bool
    {
        if (!$response->hasHeader(self::CACHE_INFO_HEADER)) {
            return false;
        }

        return strtoupper($response->getHeader(self::CACHE_INFO_HEADER)[0]) == 'HIT';
    }

    /**
     * @param array $jobs List of jobs to execute
     * @param int $concurrentRequests Number of requests made in parallel
     * @param float $delay Requested delay between requests
     */
    public function execute(array $jobs, int $concurrentRequests = 1, float $delay = 0.0)
    {
        $batchNr = 0;

        /** @var $batch Job[] */
        while (!empty($batch = array_slice($jobs, $batchNr * $concurrentRequests, $concurrentRequests))) {
            $this->logger->debugEvent('ASYNC-REQUEST-BATCH-START', [
                'concurrent_requests' => count($batch),
            ]);

            $promises = array_map([$this, 'sendAsyncWarmupRequest'], $batch);
            $results = Promise\settle($promises)->wait();

            foreach ($results as $jobNr => $result) {
                $job = $batch[$jobNr];

                if ($result['state'] === Promise\PromiseInterface::REJECTED) {
                    $exception = $result['reason'];

                    if ($exception instanceof ConnectException) {
                        $handlerContext = $exception->getHandlerContext();

                        if (isset($handlerContext['errno']) && $handlerContext['errno'] === CURLE_OPERATION_TIMEDOUT) {
                            $job->markFailed(Job::FAILED_REASON_TIMEOUT);
                        } else {
                            $job->markFailed(Job::FAILED_REASON_CONNECTION);
                        }
                    } else {
                        throw $exception;
                    }

                    continue;
                }

                /** @var Response $response */
                $response = $result['value'];

                if (!in_array($response->getStatusCode(), [200, 204])) {
                    if (in_array($response->getStatusCode(), [502, 504, 504])) {
                        $batch[$jobNr]->markFailed(Job::FAILED_REASON_UNAVAILABLE);
                    } else {
                        $batch[$jobNr]->markFailed(Job::FAILED_REASON_INVALID_CODE);
                    }
                } elseif (!$job->getSession()->isValid()) {
                    /* Somehow the session has expired in the meantime (really fringe case), so fail the job */
                    $job->markFailed(Job::FAILED_REASON_SESSION_EXPIRED, $response->getStatusCode());
                } else {
                    $job->markCompleted($response->getStatusCode(), $this->isCacheHit($response));
                }

                $this->logger->debugEvent('EXECUTED', $job->toArray());
            }

            if ($delay !== 0.0) {
                /* Since we cannot wait between requests when doing them concurrently
                 * adjust the delay so the total time is correct */
                floor($delay * 1000000.0 * $concurrentRequests);
            }

            $batchNr++;
        }
    }
}