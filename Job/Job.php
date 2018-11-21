<?php

namespace MageSuite\PageCacheWarmerCrawlWorker\Job;

class Job
{
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
     * @var string
     */
    protected $customerGroup;

    /**
     * @param int $id
     * @param string $url
     * @param int $entityId
     * @param string $entityType
     * @param string $customerGroup
     */
    public function __construct(int $id, string $url, int $entityId, string $entityType, string $customerGroup)
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
     * @return string
     */
    public function getCustomerGroup(): string
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

}