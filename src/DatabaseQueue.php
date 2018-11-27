<?php

namespace MageSuite\PageCacheWarmerCrawlWorker;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;

use Doctrine\DBAL\Driver\Statement;
use Doctrine\DBAL\FetchMode;
use MageSuite\PageCacheWarmerCrawlWorker\Job;
use MageSuite\PageCacheWarmerCrawlWorker\Queue;

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
     * @throws \Doctrine\DBAL\DBALException
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
        $date = new \DateTime('now', new \DateTimeZone('UTF'));

        if (null !== $offset) {
            $date->modify($offset);
        }

        return $date;
    }

    /**
     * @param array $data
     * @return Job
     */
    protected function createJob(array $data): Job
    {
        return new Job(
            $data['id'],
            $data['url'],
            $data['entity_id'],
            $data['entity_type'],
            $data['customer_group']
        );
    }

    /**
     * @param int $count Max items to be fetched
     * @return Statement
     * @throws \Doctrine\DBAL\DBALException
     */
    protected function getAcquireJobsStatement(int $count): Statement
    {
        $statement = $this->connection->prepare(
            'SELECT * FROM :table WHERE ' .
                'processing_started_at IS NULL OR processing_started_at < :threshold ' .
                'ORDER BY priority DESC LIMIT 0, :count'
        );

        $statement->bindValue('table', self::JOB_TABLE);
        $statement->bindValue('threshold', $this->createDatabaseDate(self::RETRY_THRESHOLD));
        $statement->bindValue('count', $count);

        return $statement;
    }

    /**
     * @param array $ids
     * @return Statement
     * @throws \Doctrine\DBAL\DBALException
     */
    protected function getStartJobsStatement(array $ids): Statement
    {
        $statement = $this->connection->prepare(
            'UPDATE :table SET processing_started_at = :timestamp WHERE id IN (:ids)'
        );

        $statement->bindValue('table', self::JOB_TABLE);
        $statement->bindValue('timestamp', $this->createDatabaseDate());
        $statement->bindValue('ids', $ids, Connection::PARAM_INT_ARRAY);

        return $statement;
    }

    /**
     * @param array $ids
     * @return Statement
     * @throws \Doctrine\DBAL\DBALException
     */
    protected function getFinishJobsStatement(array $ids): Statement
    {
        $statement = $this->connection->prepare(
            'DELETE FROM :table WHERE id IN (:ids)'
        );

        $statement->bindValue('table', self::JOB_TABLE);
        $statement->bindValue('ids', $ids, Connection::PARAM_INT_ARRAY);

        return $statement;
    }

    /**
     * {@inheritdoc}
     *
     * @throws \Doctrine\DBAL\ConnectionException
     * @throws \Exception
     */
    public function acquireJobs(int $count): array
    {
        $this->connection->beginTransaction();

        try {
            $acquireStatement = $this->getAcquireJobsStatement($count);
            $acquireStatement->execute();
            $jobData = $acquireStatement->fetchAll(FetchMode::ASSOCIATIVE);

            $jobs = array_map([$this, 'createJob'], $jobData);
            $ids = array_map(function(array $data) { return $data['id']; }, $jobData);

            $startStatement = $this->getStartJobsStatement($ids);
            $startStatement->execute();

            $this->connection->commit();
        } catch (\Exception $exception) {
            $this->connection->rollBack();

            throw $exception;
        }

        return $jobs;
    }

    /**
     * @param array $jobs
     * @throws \Doctrine\DBAL\DBALException
     */
    public function markCompleted(array $jobs)
    {
        $statement = $this->getFinishJobsStatement(
            array_map(function(Job $job) { return $job->getId(); }, $jobs)
        );

        $statement->execute();
    }
}