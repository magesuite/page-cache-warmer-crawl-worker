<?php

namespace MageSuite\PageCacheWarmerCrawlWorker\Customer;

use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Cookie\SetCookie;
use Psr\Http\Message\ResponseInterface;

class Session
{
    const MAGENTO_VARY_COOKIE = 'X-Magento-Vary';
    const DEFAULT_MAX_VALIDITY = '45 minutes';

    /**
     * @var string
     */
    protected $customerGroup;

    /**
     * @var string
     */
    protected $host;

    /**
     * @var \DateTime
     */
    protected $created;

    /**
     * @var CookieJar
     */
    protected $cookies;

    /**
     * @var string
     */
    private $filename;

    /**
     * @var bool
     */
    private $isValid = true;

    /**
     * @param string $filename
     * @param string $host
     * @param string|null $customerGroup
     * @param array $cookies
     */
    public function __construct(string $filename, string $host, string $customerGroup = null, array $cookies = [])
    {
        $this->filename = $filename;
        $this->created = new \DateTime();
        $this->cookies = new CookieJar(false, $cookies);
        $this->host = $host;
        $this->customerGroup = $customerGroup;
    }

    public function save()
    {
        self::writeWithLock($this->filename, json_encode($this->toArray(), JSON_PRETTY_PRINT));
    }

    /**
     * Read a file with shared lock. This is necessary in case more than
     * one warmup process is running on the same machine.
     *
     * @param string $filename
     * @return string
     */
    private static function readWithLock(string $filename): string
    {
        $f = fopen($filename, 'r');

        // Lock file in shared mode to ensure we wait until any write completes
        flock($f, LOCK_SH);
        $data = fread($f, filesize($filename));
        flock($f, LOCK_UN);
        fclose($f);

        return $data;
    }

    /**
     * Write a file with exclusive lock. This is necessary in case more than
     * one warmup process is running on the same machine.
     *
     * @param string $filename
     * @param string $content
     */
    private static function writeWithLock(string $filename, string $content)
    {
        $f = fopen($filename, 'w');

        // Lock file exclusively to ensure nothing can read or write
        flock($f, LOCK_EX);
        fwrite($f, $content);
        flock($f, LOCK_UN);
        fclose($f);
    }

    public static function load(string $filename): Session
    {
        if (!is_readable($filename)) {
            throw new \RuntimeException(sprintf('Cannot read session file "%s"', $filename));
        }

        return static::createFromArray($filename, json_decode(self::readWithLock($filename), true));
    }

    public static function createFromArray(string $filename, array $data): Session
    {
        $session = new static($filename, $data['host'], $data['customerGroup'], $data['cookies']);

        $session->isValid = $data['is_valid'];
        $session->created = new \DateTime($data['created']);

        return $session;
    }

    public function toArray(): array
    {
        return [
            'created' => '@' . $this->created->getTimestamp(),
            'is_valid' => $this->isValid,
            'host' => $this->host,
            'customerGroup' => $this->customerGroup,
            'cookies' => $this->cookies->toArray(),
        ];
    }

    /**
     * @return string
     */
    public function getCustomerGroup(): string
    {
        return $this->customerGroup;
    }

    /**
     * @return string
     */
    public function getHost(): string
    {
        return $this->host;
    }

    /**
     * @return \DateTime
     */
    public function getCreated(): \DateTime
    {
        return $this->created;
    }

    /**
     * @return CookieJar
     */
    public function getCookies(): CookieJar
    {
        return $this->cookies;
    }

    /**
     * @param string $timePeriodSpecifier
     * @return bool
     */
    private function isOlderThan(string $timePeriodSpecifier): bool
    {
        $threshold = new \DateTime();
        $threshold->modify('-' . $timePeriodSpecifier);

        return $this->created < $threshold;
    }

    /**
     * Returns true if the response indicates a logged in user.
     * Useful for checking if the login succeeded or if the session is still valid.
     *
     * @param ResponseInterface $response
     * @return bool
     */
    public static function isResponseLoggedIn(ResponseInterface $response): bool
    {
        foreach ($response->getHeader('Set-Cookie') as $cookieHeaderValue) {
            $cookie = SetCookie::fromString($cookieHeaderValue);

            /* Magento sets this cookie's value to "deleted" on log out so let's keep it safe */
            if ($cookie->getName() === self::MAGENTO_VARY_COOKIE && $cookie->getValue() !== 'deleted') {
                return true;
            }
        }

        return false;
    }

    /**
     * Marks the session as invalidated so it should be recreated and reauthenticated
     * and no longer used for any requests.
     */
    public function invalidate()
    {
        $this->isValid = false;
        $this->save();
    }

    public function isValid()
    {
        return $this->isValid && !$this->isOlderThan(self::DEFAULT_MAX_VALIDITY);
    }

    public function __toString()
    {
        return sprintf('Sess { customerGroup: %s, host: %s, created: %s, %s }',
            $this->customerGroup ? $this->customerGroup : 'anon',
            $this->host,
            $this->created->format('Y.m.d H:i:s'),
            $this->isValid() ? 'VALID' : 'INVALIDATED'
        );
    }

    public function __destruct()
    {
        $this->save();
    }
}