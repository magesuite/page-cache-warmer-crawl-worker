<?php

namespace MageSuite\PageCacheWarmerCrawlWorker;

interface CredentialsProvider
{
    /**
     * @param string $customerGroup
     * @return array Array of form [$username, $password]
     */
    public function getCredentials(string $customerGroup): array;
}