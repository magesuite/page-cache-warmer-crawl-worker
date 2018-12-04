<?php

namespace MageSuite\PageCacheWarmerCrawlWorker\Customer;

use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Cookie\SetCookie;
use Psr\Http\Message\ResponseInterface;

class Session
{
    const VARY_COOKIE_NAME = 'X-Magento-Vary';
    const SESSION_COOKIE_NAME = 'PHPSESSID';

    /**
     * @var string|null
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
     * @var string
     */
    private $filename;

    /**
     * @var CookieJar
     */
    private $cookies;

    /**
     * @var bool
     */
    private $invalidated = true;

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

        $session->invalidated = $data['is_valid'];
        $session->created = new \DateTime($data['created']);

        return $session;
    }

    public function toArray(): array
    {
        return [
            'created' => '@' . $this->created->getTimestamp(),
            'is_valid' => $this->invalidated,
            'host' => $this->host,
            'customerGroup' => $this->customerGroup,
            'cookies' => $this->cookies->toArray(),
        ];
    }

    /**
     * @return string|null
     */
    public function getCustomerGroup(): ?string
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
     * Warning - returns true if no session cookie was found too.
     *
     * @return bool
     */
    private function hasExpired(): bool
    {
        if (!($sessionCookie = $this->getSessionCookie())) {
            return true;
        }

        $expires = $sessionCookie->getExpires();

        if (!$expires) {
            throw new \Exception(sprintf('Session cookie doesn\'t does not have max-age or expires, cannot determine session validity'));
        }

        return time() > $sessionCookie->getExpires();

    }

    /**
     * Warning returns true if no vary hash found.
     *
     * @return bool
     */
    private function hasVaryHashExpired(): bool
    {
        if (!($varyCookie = $this->getVaryCookie())) {
            return true;
        }

        $expires = $varyCookie->getExpires();

        if (!$expires) {
            throw new \Exception(sprintf('Vary cookie doesn\'t does not have max-age or expires, cannot determine login validity'));
        }

        return time() > $varyCookie->getExpires();
    }

    /**
     * @return SetCookie|null
     */
    private function getSessionCookie(): ?SetCookie
    {
        return $this->cookies->getCookieByName(self::SESSION_COOKIE_NAME);
    }

    /**
     * @return int|null
     */
    public function getMaxAge(): ?int
    {
        return $this->getSessionCookie() ? $this->getSessionCookie()->getMaxAge() : null;
    }

    /**
     * @return null|string
     */
    public function getSessionId(): ?string
    {
        return $this->getSessionCookie() ? $this->getSessionCookie()->getValue() : null;
    }

    /**
     * @return SetCookie|null
     */
    private function getVaryCookie(): ?SetCookie
    {
        if (!($cookie = $this->cookies->getCookieByName(self::VARY_COOKIE_NAME))) {
            return null;
        }

        if (strtolower(trim($cookie->getValue())) === 'deleted') {
            return null;
        }

        return $cookie;
    }

    /**
     * @return bool
     */
    public function hasVaryHash(): bool
    {
        return null !== $this->getVaryCookie();
    }

    /**
     * @return null|string
     */
    public function getVaryHash(): ?string
    {
        return $this->getVaryCookie() ? $this->getVaryCookie()->getValue() : null;
    }

    /**
     * @return bool
     */
    public function isInitialized(): bool
    {
        return null !== $this->getSessionCookie();
    }

    /**
     * Marks the session as invalidated so it should be recreated and reauthenticated
     * and no longer used for any requests.
     */
    public function invalidate()
    {
        $this->invalidated = false;
        $this->save();
    }

    /**
     * Returns true if:
     *  - Session has been initialized
     *  - Has non-expired session cookie
     *  - Has not been forcibly invalidated
     *  - Is an anonymous session or vary cookie is not expired
     *
     * @return bool
     */
    public function isValid(): bool
    {
        return $this->invalidated && !$this->hasExpired() && ($this->isAnonymous() || !$this->hasVaryHashExpired());
    }

    /**
     * Clears cookies essentially making the session a clean slate.
     */
    public function reset(): void
    {
        $this->cookies->clear();
        $this->invalidated = true;

        /* Reset the created time as the previous one provides no real information after reset */
        $this->created = new \DateTime();
    }

    /**
     * Returns true if session is for guest user - for public cache warming.
     *
     * @return bool
     */
    public function isAnonymous(): bool
    {
        return null === $this->customerGroup;
    }

    public function getBasicDataArray(): array
    {
        return [
            'host' => $this->host,
            'customer_group' => $this->customerGroup,
            'expires_at' => $this->isInitialized() ? new \DateTime('@' . $this->getSessionCookie()->getExpires()) : null,
            'is_initialized' => $this->isInitialized(),
            'is_valid' => $this->isValid()
        ];
    }

    public function __destruct()
    {
        $this->save();
    }
}