<?php

/*
 * This file is part of Cabinet - file access abstraction library for PHP.
 * (c) by Dennis Birkholz <cabinet@birkholz.org>
 * All rights reserved.
 * For the license to use this library, see the provided LICENSE file.
 */

namespace Iqb\Cabinet\GDrive;

use GuzzleHttp\Psr7\Request;
use Iqb\Cabinet\Drawer\EntryInterface;
use Iqb\Cabinet\Drawer\FileInterface;
use Iqb\Cabinet\Drawer\FolderInterface;
use Psr\Log\LoggerInterface;

class Driver implements DriverInterface
{
    const CLIENT_SECRET_FILE = 'gdrive_client_id.json';
    const ACCESS_TOKEN_FILE  = 'gdrive_access_token.json';
    const FILES_CACHE_FILE   = 'files.cache';

    const DEFAULT_CHUNK_SIZE = 64 * 1024 * 1024;
    const DEFAULT_MIME_TYPE  = 'application/octet-stream';
    const FOLDER_MIME_TYPE   = 'application/vnd.google-apps.folder';

    const FILE_FETCH_FIELDS  = 'id, name, md5Checksum, parents, size, mimeType, createdTime, modifiedTime, originalFilename, trashed, properties';

    const DATE_FORMAT        = 'Y-m-d\TH:i:s.uP';

    /** @var LoggerInterface */
    private $logger;

    /** @var ServiceWrapper */
    private $serviceWrapper;

    public $tries = 10;


    public function connect(string $applicationName, string $configDir, LoggerInterface $logger = null) : self
    {
        $configDir = \realpath($configDir);

        return new self(
            new ServiceWrapper($applicationName, $configDir . '/' . self::CLIENT_SECRET_FILE, $configDir . '/' . self::ACCESS_TOKEN_FILE, $logger),
            $logger
        );
    }


    /**
     * Create a connection to google drive.
     *
     * The supplied $configDir must contain a file with name self::CLIENT_SECRET_FILE
     * This file should be downloaded from the "Download JSON" button on in the Google Developer Console.
     *
     * @param ServiceWrapper $serviceWrapper
     * @param LoggerInterface|null $logger
     */
    public function __construct(ServiceWrapper $serviceWrapper, LoggerInterface $logger = null)
    {
        $this->serviceWrapper = $serviceWrapper;
        $this->logger = $logger;
    }


    /**
     * Enable logging
     *
     * @param LoggerInterface $logger
     */
    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;
        //$this->serviceWrapper->setLogger($logger);
    }


    /** @inheritdoc */
    public function hashFile(FileInterface $file): string
    {
        throw new \Exception("GDrive driver does not support hash calculation.");
    }


    /** @inheritdoc */
    public function setHashFunction(callable $hashFunction)
    {
        throw new \Exception("GDrive driver does not support hash calculation.");
    }


    /** @inheritDoc */
    public function getRoot() : ?FolderInterface
    {
        return $this->getEntryById('root');
    }


    /** @inheritDoc */
    public function getEntryById(string $entryId) : ?EntryInterface
    {
        $optionalParams = ['fields' => self::FILE_FETCH_FIELDS];
        $driveFile = $this->retryApiCall(function() use ($entryId, $optionalParams) {
            $this->serviceWrapper->getClient()->setDefer(false);
            return $this->serviceWrapper->getService()->files->get($entryId, $optionalParams);
        });

        if ($driveFile) {
            return $this->createEntityFromGoogleFile($driveFile);
        } else {
            return null;
        }
    }


    /** @inheritDoc */
    public function getEntryFromFolder(FolderInterface $folder, string $entryName) : ?EntryInterface
    {
        $optionalParams = [
            'fields' => 'files(' . self::FILE_FETCH_FIELDS . ')',
            'q' => "'" . $folder->getId() . "' in parents and name = '" . $entryName . "' and trashed = false",
        ];

        $driveFiles = $this->retryApiCall(function() use ($optionalParams) {
            $this->serviceWrapper->getClient()->setDefer(false);
            return $this->serviceWrapper->getService()->files->listFiles($optionalParams);
        });


        if (!$driveFiles->count()) {
            return null;
        }

        return $this->createEntityFromGoogleFile($driveFiles[0], $folder->getId());
    }


    /** @inheritDoc */
    public function getEntries(FolderInterface $folder) : \Traversable
    {
        $optionalParams = [
            'pageSize' => 1000,
            'pageToken' => null,
            'fields' => 'nextPageToken, files(' . self::FILE_FETCH_FIELDS . ')',
            'q' => "'" . $folder->getId() . "' in parents and trashed = false",
        ];

        do {
            $driveFiles = $this->retryApiCall(function() use ($optionalParams) {
                $this->serviceWrapper->getClient()->setDefer(false);
                return $this->serviceWrapper->getService()->files->listFiles($optionalParams);
            });
            $optionalParams['pageToken'] = $driveFiles->getNextPageToken();

            foreach ($driveFiles as $driveFile) {
                yield $this->createEntityFromGoogleFile($driveFile, $folder->getId());
            }

        } while ($optionalParams['pageToken'] !== null);
    }


    /** @inheritDoc */
    public function createFolder(FolderInterface $parent, string $name) : FolderInterface
    {
        $driveFolder = new \Google_Service_Drive_DriveFile([
            'name' => $name,
            'mimeType' => self::FOLDER_MIME_TYPE,
            'parents' => [ $parent->getId() ],
        ]);
        $optionalParams = [
            'fields' => self::FILE_FETCH_FIELDS,
        ];

        $createdFolder = $this->retryApiCall(function() use ($driveFolder, $optionalParams) {
            $this->serviceWrapper->getClient()->setDefer(false);
            $result = $this->serviceWrapper->getService()->files->create($driveFolder, $optionalParams);
            if ($result instanceof \Google_Service_Drive_DriveFile) {
                return $result;
            }
        });


        if ($createdFolder) {
            return $this->createEntityFromGoogleFile($createdFolder, $parent->getId());
        } else {
            throw new \RuntimeException('Failed to create folder "' . $name . "'");
        }
    }


    /**
     * @inheritDoc
     * @param Entry $entry
     */
    public function deleteEntry(EntryInterface $entry, bool $recursive = false) : bool
    {
        if ($entry instanceof Folder && !$recursive && \count($entry->getChildren())) {
            return false;
        }

        $this->retryApiCall(function() use ($entry) {
            $this->serviceWrapper->getClient()->setDefer(false);
            return $this->serviceWrapper->getService()->files->delete($entry->getId());
        });
        return true;
    }


    /**
     * Upload a file and store it in the folder identified by $parentId
     *
     * @param Folder $parent
     * @param FileInterface $sourceFile
     * @param string|null $overrideFileName
     * @param int $chunkSize
     * @param callable|null $progressCallback
     * @return File
     */
    public function uploadFile(Folder $parent, FileInterface $sourceFile, string $overrideFileName = null, int $chunkSize = self::DEFAULT_CHUNK_SIZE, callable $progressCallback = null) : File
    {
        if (!\file_exists($sourceFile->getPath())) {
            throw new \InvalidArgumentException('File "' . $sourceFile->getPath() . '" does not exist!');
        }

        $fileSize = $sourceFile->getSize();
        $upload = $this->uploadFileChunked(
            $parent,
            ($overrideFileName !== null ? $overrideFileName : $sourceFile->getName()),
            $fileSize,
            $chunkSize
        );

        $readPos = 0;
        $fileHandle = \fopen($sourceFile->getPath(), 'rb');
        do {
            $chunk = \stream_get_contents($fileHandle, $chunkSize);
            $readPos += \mb_strlen($chunk, '8bit');
            $status = $upload($chunk);

            $progressCallback && $progressCallback($sourceFile, $readPos, $fileSize);
        } while (!$status && !\feof($fileHandle));
        \fclose($fileHandle);

        if (!$status) {
            throw new \RuntimeException('Failed to upload file "' . $sourceFile->getName() . "'");
        }

        return $status;
    }


    /**
     * Start an upload for a new file in the $parent folder.
     * Returns a callable that must be called for each chunk of data.
     * The callable will return null until all chunks have been processed,
     *  then it will return the new File object
     *
     * @param Folder $parent
     * @param string $fileName
     * @param int $fileSize
     * @param int $chunkSize
     * @return callable
     */
    public function uploadFileChunked(Folder $parent, string $fileName, int $fileSize, int $chunkSize = self::DEFAULT_CHUNK_SIZE) : callable
    {
        $driveFile = new \Google_Service_Drive_DriveFile([
            'name' => $fileName,
            'parents' => [ $parent->getId() ],
        ]);

        $this->serviceWrapper->getClient()->setDefer(true);

        // Create a media file upload to represent our upload process.
        $mediaFileUpload = new \Google_Http_MediaFileUpload(
            $this->serviceWrapper->getClient(),
            $this->serviceWrapper->getService()->files->create($driveFile, ['fields' => self::FILE_FETCH_FIELDS]),
            self::DEFAULT_MIME_TYPE,
            null,
            true,
            $chunkSize
        );
        $mediaFileUpload->setFileSize($fileSize);

        $processedBytes = 0;
        $buffer = "";

        return function(string $chunk) use ($parent, $fileName, $fileSize, $chunkSize, $mediaFileUpload, &$processedBytes, &$buffer) {
            $buffer .= $chunk;
            $processedBytes += \strlen($chunk);

            // Upload only complete chunk and last chunk
            if (\strlen($buffer) >= $chunkSize || ($processedBytes >= $fileSize)) {
                $result = $this->retryApiCall(function() use ($mediaFileUpload, $buffer) {
                    return $mediaFileUpload->nextChunk($buffer);
                });
                $buffer = "";

                if ($result instanceof \Google_Service_Drive_DriveFile) {
                    return $this->createEntityFromGoogleFile($result);
                }

                elseif ($processedBytes >= $fileSize) {
                    throw new \RuntimeException("Upload failed");
                }
            }
        };
    }


    /**
     * Start a file download, return a stream resource to its contents
     *
     * @param File $file
     * @param int|null $offset
     * @param int|null $bytes
     * @return resource
     */
    public function downloadFile(File $file, int $offset = null, int $bytes = null)
    {
        return $this->retryApiCall(function() use ($file, $offset, $bytes) {
            $httpClient = $this->serviceWrapper->getClient()->authorize();
            $this->serviceWrapper->getClient()->setDefer(true);
            /* @var $fileRequest Request */
            $fileRequest = $this->serviceWrapper->getService()->files->get($file->getId(), ['alt' => 'media']);

            if ($offset !== null) {
                $fileRequest = $fileRequest->withAddedHeader('Range', 'bytes=' . $offset . '-' . ($bytes !== null ? $offset+$bytes-1 : ''));
            }

            $response = $httpClient->send($fileRequest, ['stream' => true]);
            return $response->getBody()->detach();
        });
    }


    public function updateEntry(Entry $entry, array $optionalParams = []) : Entry
    {
        if (!\array_key_exists('fields', $optionalParams)) {
            $optionalParams['fields'] = self::FILE_FETCH_FIELDS;
        }

        $file = new \Google_Service_Drive_DriveFile([
            'modifiedTime' => $entry->getModifiedTime()->format(self::DATE_FORMAT),
            'name' => $entry->getName(),
            'originalFilename' => $entry->getName(),
            'properties' => $entry->getProperties(),
        ]);

        $newEntry = $this->retryApiCall(function() use ($entry, $file, $optionalParams) {
            return ($this->serviceWrapper->getService()->files->update($entry->getId(), $file, $optionalParams) ?: null);
        });

        if ($newEntry) {
            return $this->createEntityFromGoogleFile($newEntry);
        } else {
            throw new \RuntimeException("Update failed");
        }
    }

    ######################################################################
    #                                                                    #
    # Helper methods for communication with GDrive                       #
    #                                                                    #
    ######################################################################

    /**
     * Try an API call and use exponential backoff when increasing the timeout
     *
     * @param callable $call
     * @param array $params
     * @return mixed
     */
    final protected function retryApiCall(callable $call, ...$params)
    {
        $timeout = 100000;

        for ($try = 1; $try <= $this->tries; $try++) {
            try {
                $this->serviceWrapper->refreshAccessToken($try > 1);

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
     * Convert a google api response into an entry
     *
     * @param \Google_Service_Drive_DriveFile $driveFile
     * @param string|null $parentId
     * @return Entry
     */
    final protected function createEntityFromGoogleFile(\Google_Service_Drive_DriveFile $driveFile, string $parentId = null) : Entry
    {
        if (!$parentId) {
            $parentIds = $driveFile->getParents();
            if ($parentIds && \count($parentIds)) {
                $parentId = $parentIds[0];
            }
        }

        if ($driveFile->getMimeType() === self::FOLDER_MIME_TYPE) {
            return new Folder(
                $this,
                $parentId,
                $driveFile->getId(),
                $driveFile->getName(),
                ($driveFile->getCreatedTime() ? \DateTimeImmutable::createFromFormat(self::DATE_FORMAT, $driveFile->getCreatedTime()) : null),
                ($driveFile->getModifiedTime() ? \DateTimeImmutable::createFromFormat(self::DATE_FORMAT, $driveFile->getModifiedTime()) : null),
                $driveFile->getProperties()
            );
        } else {
            return new File(
                $this,
                $parentId,
                $driveFile->getId(),
                $driveFile->getName(),
                ($driveFile->getCreatedTime() ? \DateTimeImmutable::createFromFormat(self::DATE_FORMAT, $driveFile->getCreatedTime()) : null),
                ($driveFile->getModifiedTime() ? \DateTimeImmutable::createFromFormat(self::DATE_FORMAT, $driveFile->getModifiedTime()) : null),
                $driveFile->getSize(),
                $driveFile->getMd5Checksum(),
                $driveFile->getProperties()
            );
        }
    }
}
