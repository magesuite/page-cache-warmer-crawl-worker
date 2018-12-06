<?php

namespace MageSuite\PageCacheWarmerCrawlWorker;

use MageSuite\PageCacheWarmerCrawlWorker\Customer\CredentialsProvider;
use MageSuite\PageCacheWarmerCrawlWorker\Customer\SessionProvider;
use MageSuite\PageCacheWarmerCrawlWorker\Http\ClientFactory;
use MageSuite\PageCacheWarmerCrawlWorker\Job\JobExecutor;
use MageSuite\PageCacheWarmerCrawlWorker\Job\Stats;
use MageSuite\PageCacheWarmerCrawlWorker\Logging\EventFormattingLogger;
use MageSuite\PageCacheWarmerCrawlWorker\Queue\Queue;
use MageSuite\PageCacheWarmerCrawlWorker\Throttler\Throttler;
use MageSuite\PageCacheWarmerCrawlWorker\Throttler\TransferTimeThrottler;
use Psr\Log\LoggerInterface;

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

        /* If false then no throttling is performed. If true then default throttling settings are used.
         * In case it's an array it's treated as throttling settings. */
        'throttle' => true,
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
        $this->logger = new EventFormattingLogger($logger, 'Worker');
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

    private function createThrottler(array $settings): ?Throttler
    {
        if (!$settings['throttle']) {
            return null;
        }

        $throttlerSettings = [
            'target_concurrency' => $settings['concurrency'],
        ];

        if (is_array($settings['throttle'])) {
            $throttlerSettings = array_merge($throttlerSettings, $settings['throttle']);
        }

        return new TransferTimeThrottler($this->logger, $throttlerSettings);
    }

    public function work(array $settings)
    {
        $settings = $this->normalizeSettings($settings);
        $executor = $this->createJobExecutor($settings);
        $throttler = $this->createThrottler($settings);

        $maxJobs = $settings['max_jobs'];
        $batchSize = $settings['batch_size'];
        $concurrency = $settings['concurrency'];
        $minRuntime = $settings['min_runtime'];
        $minRuntimeDelay = $settings['min_runtime_delay'];

        if ($concurrency < 1) {
            throw new \DomainException(sprintf('Conncurrency is %d and cannot be lower than 1', $concurrency));
        }

        $jobsLeft = $maxJobs;
        $jobsProcessed = 0;
        $batchNr = 0;
        $totalStats = new Stats('run');
        $totalStats->startTimer();

        while (1) {
            while (count($jobBatch = $this->queue->acquireJobs(min($jobsLeft, $batchSize)))) {
                $batchNr++;

                $this->logger->debugEvent('BATCH-START', [
                    'batch_nr' => $batchNr,
                    'jobs_acquired' => count($jobBatch),
                    'jobs_left_max' => $jobsLeft
                ]);

                $executor->execute(
                    $jobBatch,
                    $throttler ? $throttler->getSuggestedConcurrency() : $concurrency,
                    $throttler ? $throttler->getSuggestedRequestDelay() : 0
                );

                $this->queue->updateStatus($jobBatch);

                $batchStats = new Stats('batch', $jobBatch);
                $totalStats->add($batchStats);

                $jobsProcessed += count($jobBatch);
                $jobsLeft = $maxJobs - $jobsProcessed;

                $this->logger->infoEvent('BATCH-FINISHED', array_merge([
                    'batch_nr' => $batchNr,
                ], $batchStats->getSummaryArray()));

                if ($throttler) {
                    $throttler->processBatchStats($batchStats);

                    if ($throttler->getSuggestedEmergencyPause()) {
                        sleep($throttler->getSuggestedEmergencyPause());
                    }
                }
            }

            if ($totalStats->getDuration() > $minRuntime || $jobsLeft <= 0) {
                break;
            }

            $this->logger->debugEvent('WAITING-FOR-JOBS', [
                'delay_for' => $minRuntimeDelay,
                'runtime' => intval($totalStats->getDuration()),
                'runtime_min' => $minRuntime,
                'jobs_left' => $jobsLeft,
                'max_jobs' => $maxJobs
            ]);

            usleep(floor($minRuntimeDelay * 1E6));
        }

        $totalStats->stopTimer();

        if ($totalStats->getTotal() === 0) {
            $this->logger->debugEvent('NO-WORK-TO-DO');
        } else {
            $this->logger->infoEvent('WORK-FINISHED', array_merge([
                'batch_count' => $batchNr,
                'runtime' => $totalStats->getDuration(),
                'memory_usage_peak' => memory_get_peak_usage(true) / 0x100000,
            ], $totalStats->getSummaryArray()));
        }
    }
}