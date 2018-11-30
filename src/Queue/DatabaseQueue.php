<?php

namespace MageSuite\PageCacheWarmerCrawlWorker\Queue;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;

use Doctrine\DBAL\Driver\Statement;
use Doctrine\DBAL\FetchMode;
use Doctrine\DBAL\ParameterType;
use MageSuite\PageCacheWarmerCrawlWorker\Job\Job;

/**
 * The idea for the queue is very simple, we forgo any status column as it seems
 * not to be needed after all.
 *
 * Jobs are fetched (reverse sort by priority) and locked with "select for update", then
 * current timestamp is written into `processing_started_at` column and lock released.
 *
 * Null in `processing_started_at` column indicates a fresh job that shall be acquired
 * and processed. If the column is not null, but the processing started more than
 * `threshold` ago this indicates a possibly failed job that shall be retried.
 *
 * Finished jobs are deleted from the table completely so no need for finished status.
 */
class DatabaseQueue implements Queue
{
    const JOB_TABLE = 'cache_warmup_queue';
    const RETRY_THRESHOLD = '20 minutes';
    
    /**
     * @var Connection
     */
    protected $connection;

    /**
     * @param array $connectionParams Doctrine DBAL connection params
     */
    public function __construct(array $connectionParams)
    {
        $this->connection = DriverManager::getConnection($connectionParams);
    }

    /**
     * @param string|null $offset
     * @return \DateTime
     */
    private function createDatabaseDate(string $offset = null): \DateTime
    {
        $date = new \DateTime('now', new \DateTimeZone('UTC'));

        if (null !== $offset) {
            $date->modify('-' . $offset);
        }

        return $date;
    }

    /**
     * Quotes array of int ids for DBAL.
     *
     * Unfortunately DBAL cannot do this itself in a prepared statement.
     *
     * @param int[] $ids
     * @return string
     */
    private function quoteIds(array $ids): string
    {
        $connection = $this->connection;

        return implode(',', array_map(function($id) use ($connection) {
            return $connection->quote((int)$id, ParameterType::INTEGER);
        }, $ids));
    }

    /**
     * @param Job[] $jobs
     * @return array
     */
    private function getJobIds(array $jobs): array
    {
        return array_map(function(Job $job) {
            return $job->getId();
        }, $jobs);
    }

    /**
     * @param array $data
     * @return Job
     */
    protected function createJob(array $data): Job
    {
        if (!$data['customer_group']) {
            // Normalize "not logged in" customer group to NULL because it can be also `0`
            $data['customer_group'] = null;
        }

        return new Job(
            $data['id'],
            $data['url'],
            $data['entity_id'],
            $data['entity_type'],
            $data['customer_group']
        );
    }

    /**
     * @param array $dataRows
     * @return Job[]
     */
    private function createJobs(array $dataRows): array
    {
        return array_map([$this, 'createJob'], $dataRows);
    }

    /**
     * @param int $count Max items to be fetched
     * @return Statement
     */
    protected function getAcquireJobsStatement(int $count): Statement
    {
        $statement = $this->connection->prepare(sprintf(
            'SELECT * FROM %s WHERE processing_started_at IS NULL OR processing_started_at < :threshold ORDER BY priority DESC, id ASC LIMIT 0, %s FOR UPDATE',
            self::JOB_TABLE,
            $count
        ));
        
        $statement->bindValue('threshold', $this->createDatabaseDate(self::RETRY_THRESHOLD), 'datetime');
        
        return $statement;
    }

    /**
     * @param array $ids
     * @return Statement
     */
    protected function getStartJobsStatement(array $ids): Statement
    {
        $statement = $this->connection->prepare(sprintf(
            'UPDATE %s SET processing_started_at = :timestamp WHERE id IN (%s)',
            self::JOB_TABLE,
            $this->quoteIds($ids)
        ));

        $statement->bindValue('timestamp', $this->createDatabaseDate(), 'datetime');

        return $statement;
    }

    /**
     * @param array $ids
     * @return Statement
     */
    protected function getFinishJobsStatement(array $ids): Statement
    {
        $statement = $this->connection->prepare(sprintf(
            'DELETE FROM %s WHERE id IN (%s)',
            self::JOB_TABLE,
            $this->quoteIds($ids)
        ));

        $statement->bindValue('ids', implode(',', $ids));

        return $statement;
    }

    /**
     * {@inheritdoc}
     */
    public function acquireJobs(int $count): array
    {
        $this->connection->beginTransaction();

        try {
            $acquireStatement = $this->getAcquireJobsStatement($count);
            $acquireStatement->execute();

            $jobs = $this->createJobs(
                $acquireStatement->fetchAll(FetchMode::ASSOCIATIVE)
            );

            if (!empty($jobs)) {
                $this->getStartJobsStatement($this->getJobIds($jobs))->execute();
            }

            $this->connection->commit();
        } catch (\Exception $exception) {
            $this->connection->rollBack();

            throw $exception;
        }

        return $jobs;
    }

    /**
     * @param array $jobs
     */
    public function updateStatus(array $jobs)
    {
        /* As described at the top - finished jobs are removed from the queue,
         * jobs to be retried are left alone, the `processing_started_at` column
         * has already been updated during execution. */
        $finishedJobs = array_filter($jobs, function (Job $job) {
            return $job->isCompleted();
        });

        $this->getFinishJobsStatement($this->getJobIds($finishedJobs))->execute();
    }

    public function __destruct()
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        $this->connection->close();
    }
}