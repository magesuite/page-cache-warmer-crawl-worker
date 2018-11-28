<?php

namespace MageSuite\PageCacheWarmerCrawlWorker\Customer;

class PreconfiguredCredentialsProvider implements CredentialsProvider
{
    const DOMAIN_SUFFIX = '.wu.magesuite.io';

    /**
     * @var string
     */
    private $password;

    /**
     * @var string
     */
    private $domain;

    /**
     * @var string
     */
    private $domainSuffix;

    /**
     * @param string $password In this scenario it's fixed and provided in configuration
     * @param string $domain
     * @param string $domainSuffix
     */
    public function __construct(string $password, string $domain, string $domainSuffix = self::DOMAIN_SUFFIX)
    {
        $this->password = $password;
        $this->domain = $domain;
        $this->domainSuffix = $domainSuffix;
    }

    /**
     * @param string $customerGroup
     * @return string
     */
    private function getUsername(string $customerGroup): string
    {
        return md5($customerGroup) . '@' . $this->domain . $this->domainSuffix;
    }

    /**
     * {@inheritdoc}
     */
    public function getCredentials(string $customerGroup): array
    {
        return [$this->getUsername($customerGroup), $this->password];
    }
}