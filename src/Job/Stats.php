<?php

namespace MageSuite\PageCacheWarmerCrawlWorker\Job;

use Symfony\Component\Stopwatch\Stopwatch;

class Stats
{
    /**
     * @var int
     */
    private $total = 0;

    /**
     * @var int
     */
    private $completed = 0;

    /**
     * @var int
     */
    private $pending = 0;

    /**
     * @var int
     */
    private $failed = 0;

    /**
     * Jobs that received cache hit.
     *
     * @var int
     */
    private $alreadyWarm = 0;

    /**
     * @var array
     */
    private $failReasons = [];

    /**
     * @var array
     */
    private $statusCodes = [];

    /**
     * Number of successfully completed job which were cache misses.
     *
     * @var int
     */
    private $cacheMissTransferCount = 0;

    /**
     * Sum of transfer times of successfully completed jobs
     * which were cache misses, so real work was done.
     *
     * @var float
     */
    private $cacheMissTransferTime = 0;

    /**
     * Number of requests that resulted in a response.
     *
     * @var int
     */
    private $totalTransferCount = 0;

    /**
     * Sum of transfer time of all requests that returned.
     *
     * @var float
     */
    private $totalTransferTime = 0;

    /**
     * @var Stopwatch
     */
    private $stopwatch;
    /**
     * @var string
     */
    private $name;

    /**
     * @param string $name Descriptive name of this stats
     * @param array $jobs
     */
    public function __construct(string $name, array $jobs = [])
    {
        foreach ($jobs as $job) {
            $this->addForJob($job);
        }

        $this->name = $name;
    }

    public function startTimer()
    {
        $this->stopwatch = new Stopwatch();
        $this->stopwatch->start('work');
    }

    public function stopTimer()
    {
        $this->stopwatch->stop('work');
    }

    public function getDuration(): float
    {
        if (!$this->stopwatch) {
            return 0.0;
        }

        return floatval($this->stopwatch->getEvent('work')->getDuration() / 1E3);
    }

    private function incrementFailReason(string $failReason = null, int $count = 1)
    {
        if (null === $failReason) {
            return;
        }

        if (!isset($this->failReasons[$failReason])) {
            $this->failReasons[$failReason] = 0;
        }

        $this->failReasons[$failReason] += $count;
    }

    private function incrementStatusCode(int $statusCode = null, int $count = 1)
    {
        if (null === $statusCode) {
            return;
        }

        if (!isset($this->statusCodes[$statusCode])) {
            $this->statusCodes[$statusCode] = 0;
        }

        $this->statusCodes[$statusCode] += $count;
    }

    public function addForJob(Job $job)
    {
        $this->total++;



        if ($job->isCompleted()) {
            $this->completed++;

            if ($job->wasAlreadyWarm()) {
                $this->alreadyWarm++;
            } elseif ($job->getTransferTime() !== 0.0) {
                /* Only store transfer time for successfull cache misses,
                 * the rest is useless for assesing server load. */
                $this->cacheMissTransferTime += $job->getTransferTime();
                $this->cacheMissTransferCount++;
            }
        } elseif ($job->isFailed()) {
            $this->failed++;
            $this->incrementFailReason($job->getFailReason());
            $this->incrementStatusCode($job->getStatusCode());
        } elseif ($job->isPending()) {
            $this->pending++;
        }
    }

    public function add(Stats $stats)
    {
        $this->total += $stats->total;
        $this->completed += $stats->completed;
        $this->pending += $stats->pending;
        $this->failed += $stats->failed;
        $this->alreadyWarm += $stats->alreadyWarm;
        $this->totalTransferCount += $stats->totalTransferCount;
        $this->totalTransferTime += $stats->totalTransferTime;
        $this->cacheMissTransferCount += $stats->cacheMissTransferCount;
        $this->cacheMissTransferTime += $stats->cacheMissTransferTime;

        foreach ($stats->statusCodes as $code => $count) {
            $this->incrementStatusCode($code, $count);
        }

        foreach ($stats->failReasons as $reason => $count) {
            $this->incrementFailReason($reason, $count);
        }
    }

    public function getFailReasonCount(string $failReason): int
    {
        if (!isset($this->failReasons[$failReason])) {
            return 0;
        }

        return $this->failReasons[$failReason];
    }

    /**
     * Returns average transfer time for successfully completed jobs
     * that resulted in a cache miss (performed real warmup).
     *
     * @return float
     */
    public function getAverageCacheMissTransferTime(): float
    {
        if ($this->cacheMissTransferCount === 0) {
            return 0;
        }

        return $this->cacheMissTransferTime / $this->cacheMissTransferCount;
    }

    /**
     * @return int
     */
    public function getCacheMissTransferCount(): int
    {
        return $this->cacheMissTransferCount;
    }

    /**
     * Returns average transfer time for all requests that have returned.
     *
     * @return float
     */
    public function getAverageTransferTime(): float
    {
        if ($this->totalTransferCount === 0) {
            return 0;
        }

        return $this->totalTransferTime / $this->totalTransferCount;
    }

    /**
     * @return int
     */
    public function getTotal(): int
    {
        return $this->total;
    }

    /**
     * @return int
     */
    public function getCompleted(): int
    {
        return $this->completed;
    }

    /**
     * @return int
     */
    public function getPending(): int
    {
        return $this->pending;
    }

    /**
     * @return int
     */
    public function getFailed(): int
    {
        return $this->failed;
    }

    /**
     * @return int
     */
    public function getAlreadyWarm(): int
    {
        return $this->alreadyWarm;
    }

    /**
     * @return array
     */
    public function getFailReasons(): array
    {
        return $this->failReasons;
    }

    /**
     * @return array
     */
    public function getStatusCodes(): array
    {
        return $this->statusCodes;
    }

    /**
     * @return float
     */
    public function getCacheMissTransferTime(): float
    {
        return $this->cacheMissTransferTime;
    }

    /**
     * @return int
     */
    public function getTotalTransferCount(): int
    {
        return $this->totalTransferCount;
    }

    /**
     * @return float
     */
    public function getTotalTransferTime(): float
    {
        return $this->totalTransferTime;
    }

    public function getSummaryArray(): array
    {
        return [
            'total' => $this->total,
            'pending' => $this->pending,
            'completed' => $this->completed,
            'failed' => $this->failed,
            'already_warm' => $this->alreadyWarm,
            'average_transfer_time' => $this->getAverageTransferTime(),
            'average_cache_miss_transfer_time' => $this->getAverageCacheMissTransferTime()
        ];
    }
}