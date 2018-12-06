<?php

namespace MageSuite\PageCacheWarmerCrawlWorker\Logging;

use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

class EventFormattingLogger extends AbstractLogger implements LoggerInterface
{
    /**
     * @var string
     */
    private $componentName;

    /**
     * @var LoggerInterface
     */
    private $upstreamLogger;

    public function __construct(LoggerInterface $upstreamLogger, string $componentName = null)
    {
        $this->componentName = $componentName;
        $this->upstreamLogger = $upstreamLogger;
    }

    protected function formatDataItem($val)
    {
        if ($val instanceof \DateTime) {
            return $val->format('d.m.Y H:i:s');
        }

        if (is_array($val)) {
            $newArr = [];

            foreach ($val as $childKey => $childVal) {
                $newArr[$childKey] = $this->formatDataItem($childVal);
            }

            return $newArr;
        }

        return $val;
    }

    protected function formatData(array $data): string
    {
        return json_encode($this->formatDataItem($data),
            JSON_PRETTY_PRINT + JSON_UNESCAPED_SLASHES + JSON_UNESCAPED_UNICODE);
    }

    public function logEvent(string $level, string $name, array $data = [], string $note = '')
    {
        if ($this->componentName) {
            $action = strtoupper($this->componentName) . ':' . $name;
        } else {
            $action = $name;
        }

        $msg = "[$action] ";

        if (!empty($note)) {
            $msg .= "$note\n";
        }

        if (!empty($data)) {
            $msg .= "\n" . $this->formatData($data);
        }

        $this->log($level, $msg);
    }

    public function alertEvent(string $name, array $data = [], string $note = '')
    {
        $this->logEvent(LogLevel::ALERT, $name, $data, $note);
    }

    public function criticalEvent(string $name, array $data = [], string $note = '')
    {
        $this->logEvent(LogLevel::CRITICAL, $name, $data, $note);
    }

    public function errorEvent(string $name, array $data = [], string $note = '')
    {
        $this->logEvent(LogLevel::ERROR, $name, $data, $note);
    }

    public function warningEvent(string $name, array $data = [], string $note = '')
    {
        $this->logEvent(LogLevel::WARNING, $name, $data, $note);
    }

    public function noticeEvent(string $name, array $data = [], string $note = '')
    {
        $this->logEvent(LogLevel::NOTICE, $name, $data, $note);
    }

    public function infoEvent(string $name, array $data = [], string $note = '')
    {
        $this->logEvent(LogLevel::INFO, $name, $data, $note);
    }

    public function debugEvent(string $name, array $data = [], string $note = '')
    {
        $this->logEvent(LogLevel::DEBUG, $name, $data, $note);
    }

    public function log($level, $message, array $context = [])
    {
        $this->upstreamLogger->log($level, $message, $context);
    }
}