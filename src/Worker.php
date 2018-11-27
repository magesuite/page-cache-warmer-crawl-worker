<?php

namespace MageSuite\PageCacheWarmerCrawlWorker;

use Psr\Log\LoggerInterface;

class Worker
{
    /**
     * @var CredentialsProvider
     */
    private $credentialsProvider;
    /**
     * @var Queue
     */
    private $queue;
    /**
     * @var LoggerInterface
     */
    private $logger;

    public function __construct(
        CredentialsProvider $credentialsProvider,
        Queue $queue,
        LoggerInterface $logger
    ) {
        $this->credentialsProvider = $credentialsProvider;
        $this->queue = $queue;
        $this->logger = $logger;
    }

    /**
     * @param Job[] $jobs
     * @param array $stats
     * @return array
     */
    private function aggregateStats(array $jobs, array &$stats = null): array
    {
        if (null === $stats) {
            $stats = [
                'total' => 0,
                'completed' => 0,
                'failed' => 0,
                'fail_reasons' => [],
                'status_codes' => []
            ];
        }

        foreach ($jobs as $job) {
            if ($job->isFailed()) {
                $stats['failed']++;

                if (!isset($stats['fail_reasons'][$job->getFailReason()])) {
                    $stats['fail_reasons'][$job->getFailReason()] = 0;
                }

                $stats['fail_reasons'][$job->getFailReason()]++;
            } else {
                $stats['completed']++;
            }

            if (!isset($stats['status_codes'][$job->getStatusCode()])) {
                $stats['status_codes'][$job->getStatusCode()] = 0;
            }

            $stats['status_codes'][$job->getStatusCode()]++;
        }

        return $stats;
    }

    /**
     * @param int $concurrency
     * @param int $maxJobs
     * @param int $batchSize
     * @param string|null $varnishUri
     * @param string|null $httpDebugLog
     */
    public function work(
        int $concurrency = 1,
        int $maxJobs = 200,
        int $batchSize = 50,
        string $varnishUri = null,
        string $httpDebugLog = null
    ) {
        $clientFactory = new ClientFactory($varnishUri, $httpDebugLog);
        $sessionProvider = new SessionProvider($this->credentialsProvider, $clientFactory, $this->logger);
        $executor = new JobExecutor($sessionProvider, $clientFactory, $this->logger);

        $jobsLeft = $maxJobs;
        $stats = null;

        while (count($jobBatch = $this->queue->acquireJobs(min($jobsLeft, $batchSize)))) {
            $this->logger->debug(sprintf('Starting batch, jobs left %d/%d', $jobsLeft, $maxJobs));
            $executor->execute($jobBatch, $concurrency);

            $this->aggregateStats($jobBatch,$stats);

            $jobsLeft -= $batchSize;
        }

        $this->logger->info("Finished work run: \n" . print_r($stats, true));
    }
}