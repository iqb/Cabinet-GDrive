<?php

/*
 * (c) 2018 Dennis Birkholz <dennis@birkholz.org>
 *
 * $Id$
 * Author:    $Format:%an <%ae>, %ai$
 * Committer: $Format:%cn <%ce>, %ci$
 */

namespace Iqb\Cabinet\GDrive;

use GuzzleHttp\Psr7\Request;
use Psr\Log\LoggerInterface;

/**
 * This class wraps the actual GDrive http client.
 * It catches authentication errors and automatically retries after refreshing the authentication token.
 * It can be replaced by a mock for testing.
 *
 * @author Dennis Birkholz <dennis@birkholz.org>
 */
class HttpWrapper
{
    const FILE_MIME_TYPE   = 'application/octet-stream';
    const FOLDER_MIME_TYPE = 'application/vnd.google-apps.folder';

    /**
     * File containing the client secret data.
     * File can be obtained from Google Developer Console for this application.
     * @var string
     */
    private $clientSecretFile;

    /**
     * The array structure containing the client secret.
     * File can be obtained from Google Developer Console for this application.
     * @var array
     */
    private $clientSecret;

    /**
     * The path to a JSON encoded file containing the access and refresh token.
     * If set, the file is refreshed whenever updated tokens are received.
     *
     * @var string
     */
    private $accessTokenFile;

    /**
     * The access token (incl. refresh token).
     * @var array
     */
    private $accessToken;

    /**
     * Optional application name to submit when accessing Google API
     * @var string
     */
    private $applicationName;

    /**
     * @var \Google_Client
     */
    private $client;

    /**
     * @var \Google_Service_Drive
     */
    private $service;

    /**
     * @var LoggerInterface
     */
    private $logger;

    public $tries = 10;


    public function __construct($clientSecret, $accessToken = null)
    {
        if (\is_string($clientSecret)) {
            $this->clientSecretFile = $clientSecret;
        } else {
            $this->clientSecret = $clientSecret;
        }

        if (\is_string($accessToken)) {
            $this->accessTokenFile = $accessToken;
        } elseif (\is_array($accessToken)) {
            $this->accessToken  = $accessToken;
        }
    }


    /**
     * Set the logger used for debug output
     *
     * @param LoggerInterface|null $logger
     */
    public function setLogger(LoggerInterface $logger = null)
    {
        $this->logger = $logger;
    }


    /**
     * Prepare a new Google HTTP API client or return a previously assembled instance if available
     *
     * @return \Google_Client
     */
    private function getClient() : \Google_Client
    {
        if (!$this->client) {
            $this->client = new \Google_Client();
            if ($this->applicationName) {
                $this->client->setApplicationName($this->applicationName);
            }
            $this->client->setScopes([\Google_Service_Drive::DRIVE]);
            $this->client->setAuthConfig($this->clientSecretFile ? $this->clientSecretFile : $this->clientSecret);
            $this->client->setAccessType('offline');

            $this->refreshAccessToken();
        }

        return $this->client;
    }


    private function refreshAccessToken($forceRefresh = false)
    {
        if (!$this->client) {
            return;
        }

        $changed = false;

        if (!$this->accessToken) {
            if ($this->accessTokenFile && \file_exists($this->accessTokenFile)) {
                $this->accessToken = \json_decode(\file_get_contents($this->accessTokenFile), true);
            }

            else {
                $this->accessToken = $this->getAccessTokenFromAuthCode();
                $changed = true;
            }
        }
        $this->client->setAccessToken($this->accessToken);

        if ($forceRefresh || $this->client->isAccessTokenExpired()) {
            $this->logger && $this->logger->debug(__FUNCTION__ . ': new access token acquired');
            $this->accessToken = $this->client->fetchAccessTokenWithRefreshToken();
            $changed = true;
        }

        if ($changed && $this->accessTokenFile) {
            $this->logger && $this->logger->debug(__FUNCTION__ . ': access token persisted');
            \file_put_contents($this->accessTokenFile, \json_encode($this->accessToken));
        }
    }


    private function getAccessTokenFromAuthCode()
    {
        // Request authorization from the user.
        $authUrl = $this->client->createAuthUrl();
        \printf("Open the following link in your browser:\n%s\n", $authUrl);
        print 'Enter verification code: ';
        $authCode = \trim(\fgets(STDIN));

        // Exchange authorization code for an access token.
        return $this->client->fetchAccessTokenWithAuthCode($authCode);
    }


    /**
     * Get the Google Drive API client
     * @return \Google_Service_Drive
     */
    private function getService() : \Google_Service_Drive
    {
        if (!$this->service) {
            $this->service = new \Google_Service_Drive($this->getClient());
        }

        return $this->service;
    }


    /**
     * @param callable $call
     * @param array $params
     * @return mixed
     */
    private function retryApiCall(callable $call, ...$params)
    {
        $timeout = 100000;

        for ($try = 1; $try <= $this->tries; $try++) {
            try {
                $this->refreshAccessToken($try > 1);

                $result = $call(...$params);
                if ($result !== null) {
                    return $result;
                } elseif ($try === $this->tries) {
                    throw new \RuntimeException("Api call retry failed.");
                } else {
                    $this->logger && $this->logger->debug(__FUNCTION__ . ': call returned null, retrying. (try ' . $try . ' of ' . $this->tries . ')');
                }
            } catch (\Google_Service_Exception $e) {
                // The requested file or directory was not found
                if ($e->getCode() === 404) {
                    return null;
                }

                // Authorization error, retry
                elseif ($e->getCode() === 401) {
                    $this->logger && $this->logger->debug(__FUNCTION__ . ': API token expired unexpectedly, refreshing token and retrying. (try ' . $try . ' of ' . $this->tries . ')');
                }

                else {
                    throw $e;
                }
            }

            \usleep($timeout);
            $timeout <<= 1;
        }
    }


    /**
     * Fetch the startPageToken that identified the current state of the drive.
     * Used to page through unseen updates.
     *
     * @return string
     */
    public function getChangesStartPageToken() : string
    {
        return $this->retryApiCall(function() {
            return $this->getService()->changes->getStartPageToken()->getStartPageToken();
        });
    }


    /**
     * Get the root file node of the current Google Drive
     *
     * @param array $optionalParams
     * @return \Google_Service_Drive_DriveFile
     */
    final public function getRootFile(array $optionalParams = []) : \Google_Service_Drive_DriveFile
    {
        return $this->getFile('root', $optionalParams);
    }


    /**
     * Get a file identified by the unique ID from the current Google Drive
     *
     * @param string $fileId
     * @param array $optionalParams
     * @return \Google_Service_Drive_DriveFile|null
     */
    final public function getFile(string $fileId, array $optionalParams = []) : ?\Google_Service_Drive_DriveFile
    {
        return $this->retryApiCall(function() use ($fileId, $optionalParams) {
            $this->getClient()->setDefer(false);
            return $this->getService()->files->get($fileId, $optionalParams);
        });
    }


    /**
     * Fetch a list of files
     *
     * @param array $optionalParams
     * @return \Google_Service_Drive_FileList
     */
    final public function listFiles(array $optionalParams = []) : \Google_Service_Drive_FileList
    {
        return $this->retryApiCall(function() use ($optionalParams) {
            $this->getClient()->setDefer(false);
            return $this->getService()->files->listFiles($optionalParams);
        });
    }


    /**
     * Fetch a list of changes that happens since the drive status identified by $nextPageToken
     *
     * @param string $nextPageToken
     * @param array $optionalParams
     * @return \Google_Service_Drive_ChangeList
     */
    final public function listChanges(string $nextPageToken, array $optionalParams = []) : \Google_Service_Drive_ChangeList
    {
        return $this->retryApiCall(function() use ($nextPageToken, $optionalParams) {
            $this->getClient()->setDefer(false);
            return $this->getService()->changes->listChanges($nextPageToken, $optionalParams);
        });
    }


    /**
     * Prepare for uploading a new file
     *
     * @param string $name
     * @param string $parent
     * @param string $mimeType
     * @param int $fileSize
     * @param int $chunkSize
     * @param array $optionalParams
     * @return \Google_Http_MediaFileUpload
     */
    final public function fileUploadStart(string $name, string $parent, string $mimeType, int $fileSize, int $chunkSize, array $optionalParams = []) : \Google_Http_MediaFileUpload
    {
        $file = new \Google_Service_Drive_DriveFile([
            'name' => $name,
            'parents' => [ $parent ],
        ]);

        $this->getClient()->setDefer(true);

        // Create a media file upload to represent our upload process.
        $media = new \Google_Http_MediaFileUpload(
            $this->getClient(),
            $this->getService()->files->create($file, $optionalParams),
            $mimeType,
            null,
            true,
            $chunkSize
        );
        $media->setFileSize($fileSize);

        return $media;
    }


    /**
     * Upload the next chunk
     *
     * @param \Google_Http_MediaFileUpload $fileUpload
     * @param string $chunk
     * @return \Google_Service_Drive_DriveFile
     */
    final public function fileUploadNextChunk(\Google_Http_MediaFileUpload $fileUpload, string $chunk) : ?\Google_Service_Drive_DriveFile
    {
        $reply = $this->retryApiCall(function() use ($fileUpload, $chunk) {
            return $fileUpload->nextChunk($chunk);
        });

        if ($reply instanceof \Google_Service_Drive_DriveFile) {
            return $reply;
        } else {
            return null;
        }
    }


    /**
     * Get a download stream to a file
     *
     * @param string $fileId
     * @param int|null $offset
     * @param int|null $bytes
     * @return resource
     */
    final public function fileDownload(string $fileId, int $offset = null, int $bytes = null)
    {
        return $this->retryApiCall(function() use ($fileId, $offset, $bytes) {
            $httpClient = $this->getClient()->authorize();
            $this->getClient()->setDefer(true);
            /* @var $fileRequest Request */
            $fileRequest = $this->getService()->files->get($fileId, ['alt' => 'media']);

            if ($offset !== null) {
                $fileRequest = $fileRequest->withAddedHeader('Range', 'bytes=' . $offset . '-' . ($bytes !== null ? $offset+$bytes-1 : ''));
            }

            $response = $httpClient->send($fileRequest, ['stream' => true]);
            return $response->getBody()->detach();
        });
    }


    /**
     * Create a new folder
     *
     * @param string $parentId
     * @param string $name
     * @param array $optionalParams
     * @return \Google_Service_Drive_DriveFile|null
     */
    final public function createFolder(string $parentId, string $name, array $optionalParams = []) : ?\Google_Service_Drive_DriveFile
    {
        return $this->retryApiCall(function() use ($parentId, $name, $optionalParams) {
            $file = new \Google_Service_Drive_DriveFile([
                'name' => $name,
                'mimeType' => self::FOLDER_MIME_TYPE,
                'parents' => [ $parentId ],
            ]);

            $this->getClient()->setDefer(false);
            $result = $this->getService()->files->create($file, $optionalParams);
            if ($result instanceof \Google_Service_Drive_DriveFile) {
                return $result;
            }
        });
    }


    /**
     * Delete a file or folder.
     * This operation is not recursive.
     *
     * @param string $fileId
     * @return mixed
     */
    final public function deleteFile(string $fileId)
    {
        return $this->retryApiCall(function() use ($fileId) {
            $this->getClient()->setDefer(false);
            return $this->getService()->files->delete($fileId);
        });
    }


    /**
     * Modify a files meta data.
     *
     * @param string $fileId
     * @param \Google_Service_Drive_DriveFile $file
     * @param array $optionalParameters
     * @return \Google_Service_Drive_DriveFile|null
     */
    final public function updateFileMetadata(string $fileId, \Google_Service_Drive_DriveFile $file, array $optionalParameters = []) : ?\Google_Service_Drive_DriveFile
    {
        return $this->retryApiCall(function() use ($fileId, $file, $optionalParameters) {
            return ($this->getService()->files->update($fileId, $file, $optionalParameters) ?: null);
        });
    }


    /**
     * Prevent instances of Google clients to be serialized
     * @return array
     */
    public function __sleep()
    {
        return [
            'clientSecret',
            'clientSecretFile',
            'accessToken',
            'accessTokenFile',
            'applicationName',
            'tries',
        ];
    }
}
