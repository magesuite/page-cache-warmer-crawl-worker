<?php

namespace MageSuite\PageCacheWarmerCrawlWorker;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\TransferStats;
use GuzzleHttp\Promise;

use Psr\Log\LoggerInterface;
use Psr\Http\Message\RequestInterface;

class JobExecutor
{
    /**
     * @var Client
     */
    protected $client;

    /**
     * @var LoggerInterface
     */
    protected $logger;
    /**
     * @var SessionProvider
     */
    private $sessions;

    /**
     * @param SessionProvider $sessions
     * @param ClientFactory $clientFactory
     * @param LoggerInterface $logger
     */
    public function __construct(
        SessionProvider $sessions,
        ClientFactory $clientFactory,
        LoggerInterface $logger
    ) {
        $this->logger = $logger;
        $this->client = $clientFactory->createClient();
        $this->sessions = $sessions;
    }

    protected function createWarmupRequest(Job $job): RequestInterface
    {
        return new Request('GET', $job->getUrl());
    }

    protected function sendAsyncWarmupRequest(Job $job): Promise\PromiseInterface
    {
        return $this->client->sendAsync($this->createWarmupRequest($job), [
            'cookies' => $this->sessions->getSession($job->getUrlHost(), $job->getCustomerGroup()),
            /* If there's a redirect we want to know, because it means we did something wrong */
            'allow_redirects' => false,
            'headers' => [
                /* This header instructs our varnish to avoid returning the body.
                 * The expected code is 204 (No Content), request is still cached but bandwidth saved */
                'X-Warmup' => 'yes',
            ],
            'on_stats' => function (TransferStats $stats) use ($job) {
                $job->setTransferTime($stats->getTransferTime());
            }
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

        /** @var $batch Job[] */
        while (!empty($batch = array_slice($jobs, $batchNr * $concurrentRequests, $concurrentRequests))) {
            if ($delay !== 0.0) {
                floor($delay * 1000000.0);
            }

            $promises = array_map([$this, 'sendAsyncWarmupRequest'], $batch);
            $results = Promise\settle($promises)->wait();

            foreach ($results as $jobNr => $result) {
                if ($result['state'] === Promise\PromiseInterface::REJECTED) {
                    $exception = $result['reason'];

                    if ($exception instanceof ConnectException) {
                        $handlerContext = $exception->getHandlerContext();

                        if (isset($handlerContext['errno']) && $handlerContext['errno'] === CURLE_OPERATION_TIMEDOUT) {
                            $batch[$jobNr]->markFailed(Job::FAILED_REASON_TIMEOUT);
                        } else {
                            $batch[$jobNr]->markFailed(Job::FAILED_REASON_CONNECTION);
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

                    continue;
                }

                $batch[$jobNr]->markCompleted($response->getStatusCode());
            }

            $batchNr++;
        }
    }
}