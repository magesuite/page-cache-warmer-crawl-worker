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

    private function formatDataKey(string $key): string
    {
        return ucfirst(
            preg_replace_callback(
                '/([a-z0-9])[_\- ]([a-z0-9])/',
                function($m) { return $m[1] . '-' . ucfirst($m[2]); },
                trim($key)
            )
        );
    }

    protected function formatData(array $data): string
    {
        return implode(', ', array_map(function ($key, $val) {
            if (is_string($val)) {
                $val = "'$val'";
            } elseif(is_null($val)) {
                $val = "null";
            } elseif(is_bool($val)) {
                $val = $val ? 'yes' : 'no';
            } elseif(is_float($val)) {
                $val = sprintf('%.2f', $val);
            } elseif($val instanceof \DateTime) {
                $val = $val->format('d.m.Y H:i:s');
            }

            return $this->formatDataKey($key) . ': ' . $val;
        }, array_keys($data), array_values($data)));
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
            $msg .= $this->formatData($data);
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