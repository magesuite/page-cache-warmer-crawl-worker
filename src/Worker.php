<?php

namespace MageSuite\PageCacheWarmerCrawlWorker;

use Magento\Framework\Profiler\Driver\Standard\Stat;
use MageSuite\PageCacheWarmerCrawlWorker\Customer\CredentialsProvider;
use MageSuite\PageCacheWarmerCrawlWorker\Customer\SessionProvider;
use MageSuite\PageCacheWarmerCrawlWorker\Http\ClientFactory;
use MageSuite\PageCacheWarmerCrawlWorker\Job\Job;
use MageSuite\PageCacheWarmerCrawlWorker\Job\JobExecutor;
use MageSuite\PageCacheWarmerCrawlWorker\Job\Stats;
use MageSuite\PageCacheWarmerCrawlWorker\Queue\Queue;
use Psr\Log\LoggerInterface;

class Worker
{
    /**
     * Minimum runtime in seconds.
     * During this time, we'll wait for jobs to come if none.
     */
    const DEFAULT_MIN_RUNTIME = 10;

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
     * @param int $concurrency
     * @param int $maxJobs
     * @param int $batchSize
     * @param string|null $varnishUri
     * @param bool $debuggLogging
     * @param int $minRuntime
     */
    public function work(
        int $concurrency = 1,
        int $maxJobs = 200,
        int $batchSize = 50,
        string $varnishUri = null,
        bool $debuggLogging = false,
        int $minRuntime = self::DEFAULT_MIN_RUNTIME
    ) {
        $clientFactory = new ClientFactory($this->logger, $varnishUri, $debuggLogging);
        $sessionProvider = new SessionProvider($this->credentialsProvider, $clientFactory, $this->logger);
        $executor = new JobExecutor($sessionProvider, $clientFactory, $this->logger);

        $jobsLeft = $maxJobs;
        $jobsProcessed = 0;
        $batchNr = 0;
        $totalStats = new Stats();

        while (count($jobBatch = $this->queue->acquireJobs(min($jobsLeft, $batchSize)))) {
            $batchNr++;
            $this->logger->debug(sprintf('Starting batch %d, acquired %d jobs, max %d jobs until exit', $batchNr, count($jobBatch), $jobsLeft));
            $executor->execute($jobBatch, $concurrency);

            $this->queue->markCompleted(array_filter($jobBatch, function(Job $job) { return $job->isCompleted(); }));

            $batchStats = new Stats($jobBatch);
            $totalStats->add($batchStats);

            $this->logger->debug(sprintf('Finished batch %d - %s', $batchNr, $batchStats->asString()));

            $jobsLeft -= $batchSize;
            $jobsProcessed += count($jobBatch);
        }

        $this->logger->info(sprintf("Finished work run after %d batches:\n%s",
            $batchNr,
            $totalStats->asString(true)
        ));
    }
}