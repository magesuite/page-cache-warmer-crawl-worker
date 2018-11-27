<?php

namespace  MageSuite\PageCacheWarmerCrawlWorker\Tests\Integration\Job;

use MageSuite\PageCacheWarmerCrawlWorker\Tests\Utils\TestCase;
use MageSuite\PageCacheWarmerCrawlWorker\Tests\Utils\VarnishStubServer;
use MageSuite\PageCacheWarmerCrawlWorker\Job\Job;
use MageSuite\PageCacheWarmerCrawlWorker\Job\JobExecutor;

class JobExecutorTest extends TestCase
{
    /**
     * @var VarnishStubServer
     */
    private $server;

    public function setUp()
    {
        /* Create server for every test so it's "cache" is flushed */
        $this->server = new VarnishStubServer();
        $this->server->start();
    }

    public function tearDown()
    {
        $this->server->stop();
    }



    public function testJobExecutor()
    {
        $jobs = [];

        for ($i = 0; $i < 500; ++$i) {
            $jobs[] = new Job($i, $this->server->getBaseUrl() . '/product/' . $i, $i, 'product', 1);
        }

        $executor = new JobExecutor($this->createLogger(), 'cookie');
        $executor->execute($jobs, 10);
    }
}