<?php

namespace MageSuite\PageCacheWarmerCrawlWorker\Job;

/* We need to have a separate entity for this, as we don't want to keep jobs
 * with sessions and unnecessary data in memory during the whole runtime.
 * Additionally this is kind of nice to return a read-only view-model :) */
class JobSummary
{
    private $id;
    private $isCompleted;
    private $transferTime;
    private $statusCode;
    private $failReason;
}