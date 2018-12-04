<?php

namespace MageSuite\PageCacheWarmerCrawlWorker\Throttler;

use MageSuite\PageCacheWarmerCrawlWorker\Logging\EventFormattingLogger;
use Psr\Log\LoggerInterface;

abstract class AbstractThrottler implements Throttler
{
    /**
     * @var EventFormattingLogger
     */
    protected $logger;

    /**
     * @var array
     */
    private $config;

    /**
     * @param LoggerInterface $logger
     * @param array $settings
     */
    public function __construct(LoggerInterface $logger, array $settings = [])
    {
        $this->logger = new EventFormattingLogger($logger, 'Throttler');
        $this->config = $this->resolveConfig($settings);
    }

    /**
     * Shall return default throttle config with all possible keys.
     *
     * @return array
     */
    protected function getDefaultConfig(): array
    {
        return [];
    }

    /**
     * @param array $settings
     * @return array
     */
    protected function resolveConfig(array $settings): array
    {
        return array_merge($this->getDefaultConfig(), $settings);
    }

    /**
     * @param string $name
     * @return mixed
     */
    protected function getSetting(string $name)
    {
        if (!array_key_exists($name, $this->config)) {
            throw new \InvalidArgumentException(sprintf('Setting %s does not exist', $name));
        }

        return $this->config[$name];
    }
}