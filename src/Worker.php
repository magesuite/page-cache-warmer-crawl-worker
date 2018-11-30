<?php

namespace MageSuite\PageCacheWarmerCrawlWorker;

use MageSuite\PageCacheWarmerCrawlWorker\Customer\CredentialsProvider;
use MageSuite\PageCacheWarmerCrawlWorker\Customer\SessionProvider;
use MageSuite\PageCacheWarmerCrawlWorker\Http\ClientFactory;
use MageSuite\PageCacheWarmerCrawlWorker\Job\JobExecutor;
use MageSuite\PageCacheWarmerCrawlWorker\Job\Stats;
use MageSuite\PageCacheWarmerCrawlWorker\Queue\Queue;
use Psr\Log\LoggerInterface;
use Symfony\Component\Stopwatch\Stopwatch;

class Worker
{
    const DEFAULT_SETTINGS = [
        /* Nr of concurrent warmup requests made. */
        'concurrency' => 1,

        /* Max number of jobs processed before terminating. */
        'max_jobs' => 100,

        /* Minimum amount of time to spend waiting for new jobs if there aren't any at start.
         * This prevents high cpu usage and frequent queries if there are no jobs.
         * It will also prevent fatal condition if using supervisord and startsecs is not reached. */
        'min_runtime' => 10.0,

        /* Delay (in seconds) between job checks if min runtime or max jobs is not reached. */
        'min_runtime_delay' => 0.5,

        /* Size of a single batch - influences peak memory usage.
         * Warning: Throttling adjustements are made only in-between batches.
         * Warning: Batch size should be bigger than concurrency. */
        'batch_size' => 10,

        /* Url directly pointing to the varnish instance.
         * If set then requests are made directly to varnish via internal providing better perf and saving costs. */
        'varnish_uri' => null,

        /* If true full requests are logged */
        'log_requests' => false,

        /* Session storage directory, if null then temp is used */
        'session_storage_dir' => null,

        /* Max timeout for HTTP requests */
        'warmup_requests_timeout' => ClientFactory::DEFAULT_TIMEOUT,

        /* Max timeout for login related requests */
        'session_requests_timeout' => ClientFactory::DEFAULT_TIMEOUT,

        /* Headers added to warmup requests.
         * Accept-encoding is a must if you compress your responses at the backend!
         * Also make sure that your varnish normalizes this header. */
        'warmup_headers' => JobExecutor::DEFAULT_WARMUP_HEADERS,
    ];

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

    private function normalizeSettings(array $settings)
    {
        return array_merge(self::DEFAULT_SETTINGS, $settings);
    }

    private function createClientFactory(array $settings): ClientFactory
    {
        return new ClientFactory(
            $this->logger,
            $settings['varnish_uri'],
            $settings['log_requests']
        );
    }

    private function createSessionProvider(array $settings): SessionProvider
    {
        return new SessionProvider(
            $this->credentialsProvider,
            $this->createClientFactory($settings),
            $this->logger,
            $settings['session_requests_timeout'],
            $settings['session_storage_dir']
        );
    }

    private function createJobExecutor(array $settings): JobExecutor
    {
        return new JobExecutor(
            $this->createSessionProvider($settings),
            $this->createClientFactory($settings),
            $this->logger,
            $settings['warmup_requests_timeout'],
            $settings['warmup_headers']
        );
    }

    public function work(array $settings)
    {
        $settings = $this->normalizeSettings($settings);
        $executor = $this->createJobExecutor($settings);

        $maxJobs = $settings['max_jobs'];
        $batchSize = $settings['batch_size'];
        $concurrency = $settings['concurrency'];
        $minRuntime = $settings['min_runtime'];
        $minRuntimeDelay = $settings['min_runtime_delay'];

        $jobsLeft = $maxJobs;
        $jobsProcessed = 0;
        $batchNr = 0;
        $totalStats = new Stats();
        $totalStats->startTimer();

        while (1) {
            while (count($jobBatch = $this->queue->acquireJobs(min($jobsLeft, $batchSize)))) {
                $batchNr++;

                $this->logger->debug(sprintf('Starting batch %d, acquired %d jobs, max %d jobs until exit', $batchNr, count($jobBatch), $jobsLeft));

                $executor->execute($jobBatch, $concurrency);
                $this->queue->updateStatus($jobBatch);

                $batchStats = new Stats($jobBatch);
                $totalStats->add($batchStats);

                $jobsProcessed += count($jobBatch);
                $jobsLeft = $maxJobs - $jobsProcessed;

                $this->logger->info(sprintf('Finished batch %d - %s', $batchNr, $batchStats->asString()));
            }

            if ($totalStats->getDuration() > $minRuntime || $jobsLeft <= 0) {
                break;
            }

            $this->logger->info(sprintf('Waiting for new jobs... Recheck after %.2fs because runtime %.2f/%.2fs and %d/%d jobs are left.',
                $minRuntimeDelay,
                $totalStats->getDuration(),
                $minRuntime,
                $jobsLeft,
                $maxJobs
            ));

            usleep(floor($minRuntimeDelay * 1E6));
        }

        $totalStats->stopTimer();

        $this->logger->notice(sprintf("Finished work run after %d batches:\nTook: %.2fs, Peak mem: %.2fMiB\n%s",
            $batchNr,
            $totalStats->getDuration(),
            memory_get_peak_usage(true) / 0x100000,
            $totalStats->asString(true)
        ));
    }
}