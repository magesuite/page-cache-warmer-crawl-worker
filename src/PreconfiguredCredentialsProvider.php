<?php

namespace MageSuite\PageCacheWarmerCrawlWorker;

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
     * @param string $password In this scenario it's fixed and provided in configuration
     * @param string $domain
     */
    public function __construct(string $password, string $domain)
    {
        $this->password = $password;
        $this->domain = $domain;
    }

    /**
     * @param string $customerGroup
     * @return string
     */
    private function getUsername(string $customerGroup): string
    {
        return md5($customerGroup) . '@' . $this->domain . self::DOMAIN_SUFFIX;
    }

    /**
     * {@inheritdoc}
     */
    public function getCredentials(string $customerGroup): array
    {
        return [$this->getUsername($customerGroup), $this->password];
    }
}