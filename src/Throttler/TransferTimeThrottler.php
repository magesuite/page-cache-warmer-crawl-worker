<?php

namespace MageSuite\PageCacheWarmerCrawlWorker\Throttler;

use MageSuite\PageCacheWarmerCrawlWorker\Job\Job;
use MageSuite\PageCacheWarmerCrawlWorker\Job\Stats;

class TransferTimeThrottler extends AbstractThrottler implements Throttler
{
    /**
     * @var float
     */
    private $requestDelay = 0.0;

    /**
     * @var int
     */
    private $concurrency = 0;

    /**
     * @var int
     */
    private $emergencyPause = 0;

    /**
     * {@inheritdoc}
     */
    protected function getDefaultConfig(): array
    {
        return [
            /* TTFB should be kept below this value or throttling starts */
            'target_ttfb' => 10.0,
            /* Request concurrency */
            'target_concurrency' => 10,
            /* How much to multiply slowdown delay */
            'slowdown_delay_multiplier' => 1.0,
            /* How much to delay execution per request fail */
            'fail_delay' => 10,
        ];
    }

    private function formatRelativeSlowdown(float $relativeSlowdown): string
    {
        return sprintf('%s%s% %s',
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
        $slowdown = $stats->getAverageCacheMissTransferTime() - $this->getSetting('target_ttfb');
        $relativeSlowdown = $slowdown / $this->getSetting('target_ttfb');

        if ($relativeSlowdown > 0.0) {
            $this->logger->warning(sprintf('Slowdown: %s %.2fs - throttling...', $this->formatRelativeSlowdown($relativeSlowdown), $slowdown));

            $suggestedConcurrency = max(1, floor($this->concurrency / ceil($relativeSlowdown)));

            if ($suggestedConcurrency < 1) {
                $suggestedConcurrency = 1;
            }

            $relativeConcurrencyDecrease = ($this->concurrency - $suggestedConcurrency) / $this->concurrency;

            if ($suggestedConcurrency < $this->concurrency) {
                $this->concurrency = $suggestedConcurrency;
                $this->logger->warning(sprintf('Throttle by decreasing concurrency to %d', $this->concurrency));
            }

            $slowdownLeftAfterConcurrencyDecrease = $relativeSlowdown - $relativeConcurrencyDecrease;

            if ($slowdownLeftAfterConcurrencyDecrease < 0.0) {
                $this->requestDelay = abs($slowdownLeftAfterConcurrencyDecrease) * $this->getSetting('slowdown_delay_multiplier');
                $this->logger->warning(sprintf('Throttle by adding request delay %.2fs', $this->requestDelay));
            }
        } else {
            $this->concurrency = $this->getSetting('target_concurrency');
            $this->requestDelay = 0;

            $this->logger->debug(sprintf('Slowdown: %s %.2fs', $this->formatRelativeSlowdown($relativeSlowdown), $slowdown));
        }

        $failCount =
            $stats->getFailReasonCount(Job::FAILED_REASON_TIMEOUT) +
            $stats->getFailReasonCount(Job::FAILED_REASON_UNAVAILABLE)
        ;

        if ($failCount > 0) {
            $this->emergencyPause = $this->getSetting('fail_delay') * $failCount;
            $this->logger->warning(sprintf('Got %d fails, implementing emergency pause of %.2fs', $this->emergencyPause));
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