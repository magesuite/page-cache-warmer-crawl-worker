<?php

namespace MageSuite\PageCacheWarmerCrawlWorker;

use Psr\Log\LoggerInterface;
use GuzzleHttp\Client;

class SessionProvider
{
    const MAX_SESSION_VALIDITY = '45 minutes';
    const LOGIN_FORM_PATH = '/customer/account/login/';
    const LOGIN_POST_PATH = '/customer/account/loginPost/';

    /**
     * @var Client
     */
    protected $client;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @var string
     */
    private $storageDir;

    /**
     * @var CredentialsProvider
     */
    private $credentials;

    /**
     * @param CredentialsProvider $credentials
     * @param ClientFactory $clientFactory
     * @param LoggerInterface $logger
     * @param string|null $storageDir
     */
    public function __construct(
        CredentialsProvider $credentials,
        ClientFactory $clientFactory,
        LoggerInterface $logger,
        string $storageDir = null
    ) {
        $this->logger = $logger;
        $this->client = $clientFactory->createClient();
        $this->storageDir = $storageDir;

        if (null == $this->storageDir) {
            $this->storageDir = sprintf(sys_get_temp_dir() . '/magesuite-warmup-sessions');
        }

        if (!is_dir($this->storageDir)) {
            if (!@mkdir($this->storageDir, 0777, true)) {
                throw new \RuntimeException(sprintf('Could not create session storage dir at "%s"'));
            }
        }

        if (!is_writable($this->storageDir)) {
            throw new \RuntimeException(sprintf('Session storage dir "%s" is not writable'));
        }
        $this->credentials = $credentials;
    }

    private function getSessionFilename(string $host, string $customerGroup = null)
    {
        return $this->storageDir . '/' . $host . '-' . (null !== $customerGroup ? 'cg-' . $customerGroup : 'anon') . '.json';
    }

    private function hasSession(string $host, string $customerGroup = null): bool
    {
        return file_exists($this->getSessionFilename($host, $customerGroup));
    }

    private function deleteSession(string $host, string $customerGroup = null)
    {
       if ($this->hasSession($host, $customerGroup)) {
           unlink($this->getSessionFilename($host, $customerGroup));
       }
    }

    private function createUrl(Session $session, string $path): string
    {
        return sprintf('https://%s/%s', $session->getHost(), ltrim($path, '/'));
    }

    private function authorizeSession(Session $session)
    {
        /** Clear old cookies just to be sure */
        $session->getCookies()->clear();

        $response = $this->client->get($this->createUrl($session, self::LOGIN_FORM_PATH), [
            'cookies' => $session->getCookies(),
            'allow_redirects' => [
                'referer' => true
            ]
        ]);

        if ($response->getStatusCode() !== 200) {
            throw new \RuntimeException(sprintf('Could not open log in page for host "%s"', $session->getHost()));
        }

        $formKeyCookie = $session->getCookies()->getCookieByName('form_key');

        if (!$formKeyCookie) {
            throw new \RuntimeException(sprintf('Could not get form key from cookie on host "%s"', $session->getHost()));
        }

        $formKey = $formKeyCookie->getValue();

        list($username, $password) = $this->credentials->getCredentials($session->getCustomerGroup());

        $response = $this->client->post($this->createUrl($session, self::LOGIN_POST_PATH), [
            'cookies' => $session->getCookies(),
            'form_params' => [
                'form_key' => $formKey,
                'login' => [
                    'username' => $username,
                    'password' => $password
                ]
            ],
            'allow_redirects' => [
                'referer' => true
            ]
        ]);

        if ($response->getStatusCode() !== 200) {
            throw new \RuntimeException(sprintf('Unexpected status code received for log in: %d', $response->getStatusCode()));
        }

        if ($session::isResponseLoggedIn($response)) {
            throw new \RuntimeException(sprintf('Did not log in successfully as customer group %s at host %s, no vary cookie found',
                $session->getCustomerGroup(),
                $session->getHost()
            ));
        }

        return $session;
    }

    private function createSession(string $host, string $customerGroup = null): Session
    {
        /* Remove any preexsting sessions with these parameters */
        $this->deleteSession($host, $customerGroup);
        $filename = $this->getSessionFilename($host, $customerGroup);
        $session = new Session($filename, $host, $customerGroup);

        if (null !== $customerGroup) {
            $this->authorizeSession($session);
        }

        /* Force save session so it might be reused at once. */
        $session->save();

        return $session;
    }

    /**
     * @param string $host Shop hostname
     * @param string|null $customerGroup If null then public/anonymous session is returned
     * @param bool $reauthorize If true new authorized session will be created
     * @return Session
     */
    public function getSession(string $host, string $customerGroup = null, bool $reauthorize)
    {
        if (!$this->hasSession($host) || $reauthorize) {
            return $this->createSession($host, $customerGroup);
        }

        $session = Session::load($this->getSessionFilename($host, $customerGroup));

        if ($session->isOlderThan(self::MAX_SESSION_VALIDITY)) {
            return $this->createSession($host, $customerGroup);
        }

        return $session;
    }
}