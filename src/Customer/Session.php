<?php

namespace MageSuite\PageCacheWarmerCrawlWorker\Customer;

use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Cookie\SetCookie;
use Psr\Http\Message\ResponseInterface;

class Session
{
    const MAGENTO_VARY_COOKIE = 'X-Magento-Vary';

    /* This is magento's default */
    const DEFAULT_MAX_AGE = 3600;

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
     * Time of last update/refresh.
     *
     * If null then sessions is clean, was never used.
     *
     * @var \DateTime|null
     */
    protected $updated;

    /**
     * @var CookieJar
     */
    protected $cookies;

    /**
     * MaxAge in seconds
     *
     * @var int
     */
    protected $maxAge = self::DEFAULT_MAX_AGE;

    /**
     * @var string
     */
    private $filename;

    /**
     * Sessions is created invalid as it has not yet been used and is "clear".
     *
     * @var bool
     */
    private $isValid = false;

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
        $this->updated = null;
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
        $session->updated = new \DateTime($data['updated']);
        $session->maxAge = $data['max_age'];

        return $session;
    }

    public function toArray(): array
    {
        return [
            'created' => '@' . $this->created->getTimestamp(),
            'updated' => '@' . $this->created->getTimestamp(),
            'max_age' => $this->maxAge,
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
     * @return \DateTime
     */
    public function getUpdated(): \DateTime
    {
        return $this->updated;
    }

    /**
     * @return CookieJar
     */
    public function getCookies(): CookieJar
    {
        return $this->cookies;
    }

    /**
     * @return bool
     */
    private function hasExpired(): bool
    {
        return $this->getAge() > $this->getMaxAge();
    }

    /**
     * @return int
     */
    public function getMaxAge(): int
    {
        return $this->maxAge;
    }

    /**
     * Returns current (now - last update) age in seconds.
     *
     * @return int
     */
    public function getAge(): int
    {
        return time() - $this->updated->getTimestamp();
    }

    /**
     * Returns true if the response has magento's vary cookie.
     *
     * For this cookie to be present these conditions have to be fullfilled:
     *  - Session has a customer logged in
     *  - Response is a cache miss or uncacheable page
     *  - The page has content that varies for logged in users
     *
     * Useful for checking if the login succeeded or if the session is still valid.
     *
     * @param ResponseInterface $response
     * @return bool
     */
    public static function doesResponseHaveCustomerVary(ResponseInterface $response): bool
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

    /**
     * True if sessions has not expired or been forcibly invalidated.
     *
     * @return bool
     */
    public function isValid(): bool
    {
        return $this->isValid && !$this->hasExpired();
    }

    public function __toString()
    {
        return sprintf('Sess { customerGroup: %s, host: %s, updated: %s, age: %s, %s }',
            $this->customerGroup ? $this->customerGroup : 'anon',
            $this->host,
            $this->updated->format('Y.m.d H:i:s'),
            $this->getAge(),
            $this->isValid() ? 'VALID' : 'EXPIRED'
        );
    }

    public function __destruct()
    {
        $this->save();
    }
}