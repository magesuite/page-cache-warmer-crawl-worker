<?php

namespace MageSuite\PageCacheWarmerCrawlWorker;

use MageSuite\PageCacheWarmerCrawlWorker\Job;

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
     * Marks the jobs as sucessfully processed.
     *
     * @param Job[] $jobs
     */
    public function markCompleted(array $jobs);
}