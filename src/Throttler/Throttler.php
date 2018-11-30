<?php

namespace MageSuite\PageCacheWarmerCrawlWorker\Throttler;

use MageSuite\PageCacheWarmerCrawlWorker\Job\Stats;

/**
 * Interface for throttling strategy algorithm.
 */
interface Throttler
{
    /**
     * This function shall ingest batch statiscs and adjust it's internal
     * parameters accordingly for the next run.
     *
     * This function shall not perform any sleep itself.
     *
     * @param Stats $stats
     */
    public function processBatchStats(Stats $stats);

    /**
     * Shall return the time that application should wait between subsequent requests
     * or concurrent batches of them (if concurrency is > 0).
     * 
     * Calling this method shall not change internal throttler state.
     * Subsequent calls between `ingestBatchStats` should return the same value.
     *
     * @return float
     */
    public function getSuggestedRequestDelay(): float;

    /**
     * Shall return suggested request concurrency.
     *
     * Cannot return value lower than 1.
     *
     * Calling this method shall not change internal throttler state.
     * Subsequent calls between `ingestBatchStats` should return the same value.
     *
     * @return int
     */
    public function getSuggestedConcurrency(): int;

    /**
     * If server downtime is suspected then return > 0 so the work is paused.
     *
     * Calling this method shall not change internal throttler state.
     * Subsequent calls between `ingestBatchStats` should return the same value.
     *
     * @return int
     */
    public function getSuggestedEmergencyPause(): int;
}