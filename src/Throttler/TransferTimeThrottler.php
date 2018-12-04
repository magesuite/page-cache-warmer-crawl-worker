<?php

namespace MageSuite\PageCacheWarmerCrawlWorker\Throttler;

use MageSuite\PageCacheWarmerCrawlWorker\Job\Job;
use MageSuite\PageCacheWarmerCrawlWorker\Job\Stats;
use Psr\Log\LoggerInterface;

class TransferTimeThrottler extends AbstractThrottler implements Throttler
{
    /**
     * @var float
     */
    private $requestDelay = 0.0;

    /**
     * @var int
     */
    private $concurrency = 1;

    /**
     * @var int
     */
    private $emergencyPause = 0;

    /**
     * {@inheritdoc}
     */
    public function __construct(LoggerInterface $logger, array $settings = [])
    {
        parent::__construct($logger, $settings);

        $this->concurrency = $this->getSetting('target_concurrency');
    }

    /**
     * {@inheritdoc}
     */
    protected function getDefaultConfig(): array
    {
        return [
            /* TTFB should be kept below this value or throttling starts */
            'target_ttfb' => 10.0,
            /* Request concurrency */
            'target_concurrency' => 1,
            /* How much to multiply slowdown delay */
            'slowdown_delay_multiplier' => 1.2,
            /* How much to delay execution per request fail */
            'fail_delay' => 10,
        ];
    }

    private function formatRelativeSlowdown(float $relativeSlowdown): string
    {
        return sprintf('%s%s%% %s',
            $relativeSlowdown > 0 ? '+' : '-',
            floor(abs($relativeSlowdown) * 100.0),
            $relativeSlowdown > 0 ? 'slower' : 'faster'
        );
    }

    /**
     * {@inheritdoc}
     */
    public function processBatchStats(Stats $stats)
    {
        if ($stats->getCacheMissTransferCount() === 0) {
            /* Do not adjust paramteres if no work was done */
            return;
        }

        $slowdown = $stats->getAverageCacheMissTransferTime() - $this->getSetting('target_ttfb');
        $relativeSlowdown = $slowdown / $this->getSetting('target_ttfb');

        if ($relativeSlowdown > 0.0) {
            $this->logger->warningEvent('THROTTLING-START', [
                'slowdown' => $this->formatRelativeSlowdown($relativeSlowdown),
            ]);

            $suggestedConcurrency = max(1, floor($this->concurrency / ceil($relativeSlowdown)));

            if ($suggestedConcurrency < 1) {
                $suggestedConcurrency = 1;
            }

            $relativeConcurrencyDecrease = ($this->concurrency - $suggestedConcurrency) / $this->concurrency;

            if ($suggestedConcurrency < $this->concurrency) {
                $this->concurrency = $suggestedConcurrency;

                $this->logger->warningEvent('DECREASE-CONCURRENCY', [
                    'new_concurrency' => $this->concurrency,
                    'target_concurrency' => $this->getSetting('target_concurrency'),
                ]);
            }

            $slowdownLeftAfterConcurrencyDecrease = $relativeSlowdown - $relativeConcurrencyDecrease;

            if ($slowdownLeftAfterConcurrencyDecrease > 0.0) {
                $this->requestDelay = abs($slowdownLeftAfterConcurrencyDecrease) * $this->getSetting('target_ttfb') * $this->getSetting('slowdown_delay_multiplier');

                $this->logger->warningEvent('ADD-REQUEST-DELAY', [
                    'new_request_delay' => $this->requestDelay,
                ]);
            }
        } else {
            $this->concurrency = $this->getSetting('target_concurrency');
            $this->requestDelay = 0;

            $this->logger->warningEvent('NO-THROTTLING', [
                'slowdown' => $this->formatRelativeSlowdown($relativeSlowdown)
            ]);
        }

        $failCount =
            $stats->getFailReasonCount(Job::FAILED_REASON_TIMEOUT) +
            $stats->getFailReasonCount(Job::FAILED_REASON_UNAVAILABLE)
        ;

        if ($failCount > 0) {
            $this->emergencyPause = $this->getSetting('fail_delay') * $failCount;

            $this->logger->alertEvent('EMERGENCY-PAUSE', [
                'request_failure_count' => $failCount,
                'pause_duration' => $this->emergencyPause
            ]);
        } else {
            $this->emergencyPause = 0;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getSuggestedRequestDelay(): float
    {
        return $this->requestDelay;
    }

    /**
     * {@inheritdoc}
     */
    public function getSuggestedConcurrency(): int
    {
        return $this->concurrency;
    }

    /**
     * {@inheritdoc}
     */
    public function getSuggestedEmergencyPause(): int
    {
        return $this->emergencyPause;
    }
}