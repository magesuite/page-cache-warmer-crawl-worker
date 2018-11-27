<?php

namespace MageSuite\PageCacheWarmerCrawlWorker\Tests\Utils;

use PHPUnit\Framework\TestCase as BaseTestCase;
use Psr\Log\LoggerInterface;

class TestCase extends BaseTestCase
{
    protected function createLogger(): LoggerInterface
    {
        return $this->getMockBuilder(LoggerInterface::class)
            ->setMethods([
                'emergency',
                'alert',
                'critical',
                'error',
                'notice',
                'warning',
                'info',
                'debug',
                'log'
            ])
            ->getMock();
    }
}