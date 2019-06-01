<?php

/*
 * This file is part of Cabinet - file access abstraction library for PHP.
 * (c) by Dennis Birkholz <cabinet@birkholz.org>
 * All rights reserved.
 * For the license to use this library, see the provided LICENSE file.
 */

namespace Iqb\Cabinet\GDrive;

use Psr\Log\LoggerInterface;

/**
 * This class encapsulates the \Google_Client class,
 *  prevents accidental serialization
 *  and persists the user access token
 *
 * @author Dennis Birkholz <dennis@birkholz.org>
 */
final class ServiceWrapper
{
    /**
     * @var string
     */
    private $applicationName;

    /**
     * @var string
     */
    private $clientSecretPath;

    /**
     * @var string
     */
    private $accessTokenPath;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var \Google_Client
     */
    private $client;

    /**
     * @var \Google_Service_Drive
     */
    private $service;


    public function __construct(string $applicationName, string $clientSecretPath, string $accessTokenPath, LoggerInterface $logger = null)
    {
        $this->applicationName = $applicationName;
        $this->clientSecretPath = $clientSecretPath;
        $this->accessTokenPath = $accessTokenPath;
    }


    /**
     * Prepare a new Google HTTP API client or return a previously assembled instance if available
     *
     * @return \Google_Client
     */
    public function getClient(): \Google_Client
    {
        if (!$this->client) {
            $this->client = new \Google_Client();
            $this->client->setApplicationName($this->applicationName);
            $this->client->setScopes([\Google_Service_Drive::DRIVE]);
            $this->client->setAuthConfig($this->clientSecretPath);
            $this->client->setAccessType('offline');
        }

        return $this->client;
    }


    /**
     * Get the Google Drive API client
     *
     * @return \Google_Service_Drive
     */
    public function getService() : \Google_Service_Drive
    {
        if (!$this->service) {
            $this->service = new \Google_Service_Drive($this->getClient());
        }

        return $this->service;
    }


    /**
     * Refresh the access token. Should be called before any API call
     *
     * @param bool $forceRefresh
     */
    public function refreshAccessToken($forceRefresh = false)
    {
        if (!$this->client) {
            $this->getClient();
        }

        $changedAccessToken = null;

        if (!$this->client->getAccessToken()) {
            // Load access token from file
            if (\file_exists($this->accessTokenPath)) {
                $this->client->setAccessToken(\json_decode(\file_get_contents($this->accessTokenPath), true));
            }
            // Fetch a new access token, requires interaction
            else {
                // Request authorization from the user.
                $authUrl = $this->client->createAuthUrl();
                \printf("Open the following link in your browser:\n%s\n", $authUrl);
                print 'Enter verification code: ';
                $authCode = \trim(\fgets(\STDIN));

                // Exchange authorization code for an access token.
                $changedAccessToken = $this->client->fetchAccessTokenWithAuthCode($authCode);
            }
        }

        if ($forceRefresh || $this->client->isAccessTokenExpired()) {
            $this->logger && $this->logger->debug(__FUNCTION__ . ': new access token acquired');
            $changedAccessToken = $this->client->fetchAccessTokenWithRefreshToken();
        }

        if ($changedAccessToken) {
            $this->logger && $this->logger->debug(__FUNCTION__ . ': access token persisted');
            \file_put_contents($this->accessTokenPath, \json_encode($changedAccessToken));
        }
    }


    public function __sleep()
    {
        return [
            'clientSecretPath',
            'accessTokenPath',
        ];
    }
}
