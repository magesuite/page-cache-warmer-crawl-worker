<?php

namespace MageSuite\PageCacheWarmerCrawlWorker\Customer;

use MageSuite\PageCacheWarmerCrawlWorker\Http\ClientFactory;
use Psr\Log\LoggerInterface;
use GuzzleHttp\Client;

class SessionProvider
{
    const LOGIN_FORM_PATH = '/customer/account/login/';
    const LOGIN_POST_PATH = '/customer/account/loginPost/';
    const FORM_KEY_REGEX = '/name="form_key"\s+type="hidden"\s+value="([^"]+)"/';

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
     * @param int $requestTimeout
     * @param string|null $storageDir
     */
    public function __construct(
        CredentialsProvider $credentials,
        ClientFactory $clientFactory,
        LoggerInterface $logger,
        int $requestTimeout = ClientFactory::DEFAULT_TIMEOUT,
        string $storageDir = null
    ) {
        $this->logger = $logger;
        $this->client = $clientFactory->createClient($requestTimeout);
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
        /* TODO: Infer the scheme somehow? */
        return sprintf('http://%s/%s', $session->getHost(), ltrim($path, '/'));
    }

    private function getFormKey(Session $session): string
    {
        $response = $this->client->get($this->createUrl($session, self::LOGIN_FORM_PATH), [
            'cookies' => $session->getCookies(),
            'allow_redirects' => [
                'max' => 4,
                'strict' => false,
                'referer' => true,
            ]
        ]);

        if ($response->getStatusCode() !== 200) {
            throw new \RuntimeException(sprintf('Could not open log in page for host "%s"', $session->getHost()));
        }

        if (!preg_match(self::FORM_KEY_REGEX, $response->getBody()->getContents(), $matches)) {
            throw new \RuntimeException(sprintf('Could not get login form key "%s"', $session->getHost()));
        }

        return trim($matches[1]);
    }

    private function authorizeSession(Session $session)
    {
        $this->logger->debug(sprintf('Authorizing session: %s', $session));

        /** Clear old cookies just to be sure */
        $session->getCookies()->clear();

        $formKey = $this->getFormKey($session);

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
                'max' => 4,
                'strict' => false,
                'referer' => true,
            ]
        ]);

        if ($response->getStatusCode() !== 200) {
            throw new \RuntimeException(sprintf('Unexpected status code received for log in: %d', $response->getStatusCode()));
        }

        if (!$session::doesResponseHaveCustomerVary($response)) {
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

        $this->logger->debug(sprintf('Created: %s', (string)$session));

        if (null !== $customerGroup) {
            $this->authorizeSession($session);
        }

        $this->logger->debug(sprintf('Authorized: %s', (string)$session));

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
    public function getSession(string $host, string $customerGroup = null, bool $reauthorize = false)
    {
        if (!$this->hasSession($host, $customerGroup) || $reauthorize) {
            return $this->createSession($host, $customerGroup);
        }

        $session = Session::load($this->getSessionFilename($host, $customerGroup));

        $this->logger->debug(sprintf('Loaded: %s', (string)$session));

        if (!$session->isValid()) {
            /* Session was invalidated or has expired */
            return $this->createSession($host, $customerGroup);
        }

        return $session;
    }
}