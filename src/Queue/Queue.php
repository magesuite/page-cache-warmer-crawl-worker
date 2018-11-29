<?php

namespace MageSuite\PageCacheWarmerCrawlWorker\Queue;

use MageSuite\PageCacheWarmerCrawlWorker\Job\Job;

interface Queue
{
    /**
     * Acquires new jobs for processing.
     *
     * At the same time it marks it as dispatched for processing.
     *
     * @param int $count Max jobs to be returned
     * @return Job[]
     */
    public function acquireJobs(int $count): array;

    /**
     * Updates job status in the queue.
     *
     * @param \MageSuite\PageCacheWarmerCrawlWorker\Job\Job[] $jobs
     */
    public function updateStatus(array $jobs);
}