<?php

namespace MageSuite\PageCacheWarmerCrawlWorker\Job;

use MageSuite\PageCacheWarmerCrawlWorker\Customer\Session;

class Job
{
    const STATUS_PENDING = 'PENDING';
    const STATUS_FAILED = 'FAILED';
    const STATUS_COMPLETED = 'COMPLETED';

    // Connection timeout exceeded, server overloaded?
    const FAILED_REASON_TIMEOUT = 'CONNECTION_TIMEOUT';

    // Site is not available - codes 502, 503, 504
    const FAILED_REASON_UNAVAILABLE = 'SITE_NOT_AVAILABLE';

    // Invalid status code - other than expected 200, 204
    const FAILED_REASON_INVALID_CODE = 'INVALID_STATUS_CODE';

    // Unspecified connection fail
    const FAILED_REASON_CONNECTION = 'CONNECTION_FAILED';

    // Old session was reused an the new one has been expired apparently
    const FAILED_REASON_SESSION_EXPIRED = 'SESSION_EXPIRED';

    /**
     * @var int
     */
    protected $id;

    /**
     * @var string
     */
    protected $url;

    /**
     * @var string[]
     */
    protected $urlComponents;

    /**
     * @var  int
     */
    protected $entityId;

    /**
     * @var string
     */
    protected $entityType;

    /**
     * Null customer group indicates a not logged in user / public caching
     *
     * @var string|null
     */
    protected $customerGroup;

    /**
     * @var string
     */
    protected $status = self::STATUS_PENDING;

    /**
     * @var int
     */
    protected $statusCode;

    /**
     * @var string
     */
    protected $failReason;

    /**
     * Time it took to perform the request
     *
     * @var float
     */
    protected $transferTime;

    /**
     * @var bool|null
     */
    protected $wasAlreadyWarm = null;

    /**
     * Last session used for this job's execution
     *
     * @var \MageSuite\PageCacheWarmerCrawlWorker\Customer\Session|null
     */
    protected $session;

    /**
     * @param int $id
     * @param string $url
     * @param int $entityId
     * @param string $entityType
     * @param string $customerGroup
     */
    public function __construct(int $id, string $url, int $entityId, string $entityType, string $customerGroup = null)
    {
        $this->id = $id;
        $this->url = $url;
        $this->entityId = $entityId;
        $this->entityType = $entityType;
        $this->customerGroup = $customerGroup;
        $this->urlComponents = parse_url($url);
    }

    /**
     * @return int
     */
    public function getId(): int
    {
        return $this->id;
    }

    /**
     * @return string
     */
    public function getUrl(): string
    {
        return $this->url;
    }

    /**
     * @return int
     */
    public function getEntityId(): int
    {
        return $this->entityId;
    }

    /**
     * @return string
     */
    public function getEntityType(): string
    {
        return $this->entityType;
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
    public function getUrlHost(): string
    {
        return $this->urlComponents['host'];
    }

    /**
     * @return string
     */
    public function getUrlScheme(): string
    {
        return $this->urlComponents['scheme'];
    }

    /**
     * Returns url path with the query string (if present)
     *
     * @return string
     */
    public function getUrlLocation(): string
    {
        return $this->urlComponents['path'] .
            isset($this->urlComponents['query']) ? '?' . $this->urlComponents['query'] : '';
    }

    /**
     * @return string
     */
    public function getStatus(): string
    {
        return $this->status;
    }

    /**
     * @return bool
     */
    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    /**
     * @return bool
     */
    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    /**
     * @return bool
     */
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function markFailed(string $reason, int $statusCode = null)
    {
        if ($this->status !== self::STATUS_PENDING) {
            throw new \DomainException('Only pending status can be changed to failed');
        }

        $this->failReason = $reason;
        $this->status = self::STATUS_FAILED;
        $this->statusCode = $statusCode;
    }

    public function markCompleted(int $statusCode = null, bool $wasAlreadyWarm = false)
    {
        if ($this->status !== self::STATUS_PENDING) {
            throw new \DomainException('Only pending status can be changed to completed');
        }

        $this->status = self::STATUS_COMPLETED;
        $this->statusCode = $statusCode;
        $this->wasAlreadyWarm = $wasAlreadyWarm;
    }

    /**
     * @return int
     */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * @return string
     */
    public function getFailReason(): string
    {
        return $this->failReason;
    }

    /**
     * @return float
     */
    public function getTransferTime(): float
    {
        return $this->transferTime;
    }

    /**
     * @param float $transferTime
     */
    public function setTransferTime(float $transferTime): void
    {
        $this->transferTime = $transferTime;
    }

    /**
     * @return bool
     */
    public function requiresLogin(): bool
    {
        return null !== $this->customerGroup;
    }

    public function toArray(): array
    {
        return [
            'url' => $this->url,
            'customer_group' => $this->customerGroup,
            'status' => $this->status,
            'status_code' => $this->statusCode,
            'fail_reason' => $this->failReason,
            'transfer_time' => $this->transferTime
        ];
    }

    /**
     * @return \MageSuite\PageCacheWarmerCrawlWorker\Customer\Session|null
     */
    public function getSession(): ?Session
    {
        return $this->session;
    }

    /**
     * @param \MageSuite\PageCacheWarmerCrawlWorker\Customer\Session|null $session
     */
    public function setSession(?Session $session): void
    {
        $this->session = $session;
    }

    /**
     * @return bool|null
     */
    public function wasAlreadyWarm(): ?bool
    {
        return $this->wasAlreadyWarm;
    }

    public function __toString()
    {
        return sprintf('Job { id: %s, url: %s, customerGroup: %s, status: %s%s%s%s }',
            $this->id,
            $this->url,
            $this->customerGroup ? $this->customerGroup : 'anon',
            $this->status,
            $this->isFailed() ? sprintf(', failReason: %s', $this->failReason) : '',
            $this->transferTime ? sprintf(', took: %.2fs', $this->transferTime) : '',
            null !== $this->wasAlreadyWarm ? sprintf(', wasAlreadyWarm: %s', $this->wasAlreadyWarm ? 'yes' : 'no') : ''
        );
    }
}