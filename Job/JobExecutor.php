<?php

namespace MageSuite\PageCacheWarmerCrawlWorker\Job;

use GuzzleHttp\Client as HttpClient;

class JobExecutor
{
    const EXTRA_HEADERS = [];

    /**
     * @var HttpClient
     */
    protected $http;

    public function __construct()
    {
        $this->http = new HttpClient([

        ]);
    }

    public function execute(array $jobs)
    {

    }
}