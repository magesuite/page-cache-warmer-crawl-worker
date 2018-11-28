<?php

namespace MageSuite\PageCacheWarmerCrawlWorker\Job;

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
     * @param array $jobs
     */
    public function __construct(array $jobs = [])
    {
        foreach ($jobs as $job) {
            $this->addForJob($job);
        }
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

        foreach ($stats->statusCodes as $code => $count) {
            $this->incrementStatusCode($code, $count);
        }

        foreach ($stats->failReasons as $reason => $count) {
            $this->incrementFailReason($reason, $count);
        }
    }

    private static function formatStatsArray(array $stats): string
    {
        return implode(', ', array_map(function($name, $value) {
            return sprintf('%s: %s', ucfirst($name), $value);
        }, array_keys($stats), array_values($stats)));
    }

    public function asString(bool $extended = false): string
    {
        $str = self::formatStatsArray([
            'total' => $this->total,
            'pending' => $this->pending,
            'completed' => $this->completed,
            'failed' => $this->failed,
            'already warm' => $this->alreadyWarm
        ]);

        if ($extended) {
            if (!empty($this->failReasons)) {
                $str .= "\nFail reasons - " . self::formatStatsArray($this->failReasons);
            }

            if (!empty($this->statusCodes)) {
                $str .= "\nStatus codes - " . self::formatStatsArray($this->statusCodes);
            }
        }

        return $str;
    }

    public function __toString()
    {
        return $this->asString();
    }
}