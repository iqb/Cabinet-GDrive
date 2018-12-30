<?php

/*
 * This file is part of Cabinet - file access abstraction library for PHP.
 * (c) 2017 by Dennis Birkholz
 * All rights reserved.
 * For the license to use this library, see the provided LICENSE file.
 */

namespace Iqb\Cabinet\GDrive;

use Iqb\Cabinet\DriverHandlerTrait;
use Iqb\Cabinet\DriverInterface;
use Iqb\Cabinet\FileInterface;
use Iqb\Cabinet\FolderInterface;
use Psr\Log\LoggerInterface;

class Driver implements DriverInterface
{
    use DriverHandlerTrait;

    const CLIENT_SECRET_FILE = 'gdrive_client_id.json';
    const ACCESS_TOKEN_FILE  = 'gdrive_access_token.json';
    const FILES_CACHE_FILE   = 'files.cache';

    const DEFAULT_CHUNK_SIZE = 64 * 1024 * 1024;
    const DEFAULT_MIME_TYPE  = 'application/octet-stream';
    const FOLDER_MIME_TYPE   = 'application/vnd.google-apps.folder';

    const FILE_FETCH_FIELDS  = 'id, name, md5Checksum, parents, size, mimeType, modifiedTime, originalFilename, trashed, properties';

    /** @var LoggerInterface */
    private $logger;

    /** @var string */
    private $configDir;

    /** @var string */
    private $applicationName;

    /** @var Folder */
    private $root;

    /** @var Entry[] */
    private $entryList = [];

    private $updateToken;

    /**
     * Factory callable to create a new File object
     * @var callable
     * @see DriverInterface::createFile()
     */
    private $fileFactory;

    /**
     * Factory callable to create a new Folder object
     * @var callable
     * @see DriverInterface::createFolder()
     */
    private $folderFactory;

    /**
     * @var HttpWrapper
     */
    private $clientWrapper;


    /**
     * Restore a connection for the supplied config dir or create a new connection.
     *
     * @param string $configDir
     * @param string|null $applicationName
     * @return Driver
     */
    public static function connect(string $configDir = __DIR__ . '/../', string $applicationName = null) : self
    {
        if (\file_exists($configDir . \DIRECTORY_SEPARATOR . self::FILES_CACHE_FILE)) {
            $data = \igbinary_unserialize(\file_get_contents($configDir . \DIRECTORY_SEPARATOR . self::FILES_CACHE_FILE));
            /* @var $root Folder */
            $root = $data['root'];
            $gDrive = $root->getDriver();
            $gDrive->updateToken = $data['token'];
        }

        else {
            $gDrive = new self($configDir, $applicationName);
        }

        return $gDrive;
    }


    /**
     * Create a connection to google drive.
     *
     * The supplied $configDir must contain a file with name self::CLIENT_SECRET_FILE
     * This file should be downloaded from the "Download JSON" button on in the Google Developer Console.
     *
     * @param string $configDir
     * @param string|null $applicationName
     */
    private function __construct(string $configDir = __DIR__ . '/../', string $applicationName = null)
    {
        $this->configDir = \realpath($configDir);
        $this->applicationName = $applicationName;
        $this->clientWrapper = new HttpWrapper($configDir . '/' . self::CLIENT_SECRET_FILE, $configDir . '/' . self::ACCESS_TOKEN_FILE);
    }


    /**
     * Enable logging
     *
     * @param LoggerInterface $logger
     */
    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;
        $this->clientWrapper->setLogger($logger);
    }


    /** @inheritdoc */
    final public function fileFactory(string $fileName, FolderInterface $parent = null, string $id = null, int $size = null, string $md5 = null, array $properties = null) : FileInterface
    {
        if ($this->fileFactory) {
            $file = ($this->fileFactory)($this, $parent, $id, $fileName, $size, $md5, $properties);
        } else {
            $file = new File($this, $parent, $id, $fileName, $size, $md5, $properties);
        }

        $this->notifyFileLoaded($file);
        return $file;
    }


    /** @inheritdoc */
    final public function setFileFactory(callable $fileFactory)
    {
        $this->fileFactory = $fileFactory;
    }


    /** @inheritdoc */
    final public function folderFactory(string $folderName, FolderInterface $parent = null, string $id = null, array $properties = null) : FolderInterface
    {
        if ($this->folderFactory) {
            $folder = ($this->folderFactory)($this, $parent, $id, $folderName, $properties);
        } else {
            $folder = new Folder($this, $parent, $id, $folderName, $properties);
        }

        $this->notifyFolderLoaded($folder);
        return $folder;
    }


    /** @inheritdoc */
    final public function setFolderFactory(callable $folderFactory)
    {
        $this->folderFactory = $folderFactory;
    }


    /** @inheritdoc */
    final public function hashFile(FileInterface $file): string
    {
        throw new \Exception("GDrive driver does not support hash calculation.");
    }


    /** @inheritdoc */
    final public function setHashFunction(callable $hashFunction)
    {
        throw new \Exception("GDrive driver does not support hash calculation.");
    }


    /**
     * Get the complete hierarchy of files from the google drive.
     *
     * @return Folder
     */
    final public function getRoot() : Folder
    {
        $this->createOrUpdateEntries();
        return $this->root;
    }

    /**
     * @param string $fileOrFolderId
     * @param callable|null $propertyChecker
     * @return bool
     */
    final protected function waitForUpdate(string $fileOrFolderId, callable $propertyChecker = null) : bool
    {
        $waitForCreate = !isset($this->entryList[$fileOrFolderId]);
        $waitForUpdate = (!$waitForCreate && ($propertyChecker !== null));

        for ($i=0; $i<60; $i++) {
            $this->logger && $this->logger->debug(__FUNCTION__ . " waiting for Google to see the " . ($waitForCreate ? 'creation' : ($waitForUpdate ? 'update' : 'deletion')) . " of file/folder $fileOrFolderId");
            $this->createOrUpdateEntries();

            if ($waitForCreate) {
                if (isset($this->entryList[$fileOrFolderId])) {
                    return true;
                }
            }

            elseif ($waitForUpdate) {
                if ($propertyChecker($this->entryList[$fileOrFolderId])) {
                    return true;
                }
            }

            else {
                if (empty($this->entryList[$fileOrFolderId])) {
                    return true;
                }
            }

            \usleep(500000);
        }

        return false;
    }


    /**
     * Create or update the Entry structure representing the files in the GDrive.
     * If an updateToken is supplied, the structure is updated, otherwise created.
     * Finally the cache file is persisted.
     */
    public function createOrUpdateEntries()
    {
        $entryGenerator = ($this->updateToken ? $this->fetchUpdateList() : $this->fetchFileList());
        $setName = function(string $newName) { return $this->setName($newName); };
        $setParent = function(FolderInterface $newParent) { return $this->setParent($newParent); };

        // Store deletes here and invoke later
        $deletes = [];

        $this->logger && $this->logger->debug(__FUNCTION__ . ": fetching " . ($this->updateToken ? "file updates" : "files"));
        $processedUpdates = 0;

        $dangling = [];

        foreach ($entryGenerator as $file) {
            $processedUpdates++;
            $this->logger && (($processedUpdates % 1000) === 0)
                && $this->logger->debug(__FUNCTION__ . ": processed $processedUpdates " . ($this->updateToken ? "file updates" : "files"));

            // Handle changes to existing files
            if (isset($this->entryList[$file['id']])) {
                $entry = $this->entryList[$file['id']];

                // Cleanup deleted files
                if (\array_key_exists('deleted', $file)) {
                    if ($this->entryList[$file['id']]) {
                        unset($this->entryList[$file['id']]);
                        $deletes[] = $entry;
                    }
                    continue;
                }

                // Move
                if ($entry->hasParents() && $entry->getParent()->id !== $file['parentId']) {
                    $setParentBound = $setParent->bindTo($entry, $entry);
                    $setParentBound($this->entryList[$file['parentId']]);
                }

                // Rename
                if ($entry->getName() !== $file['name']) {
                    $setNameBound = $setName->bindTo($entry, $entry);
                    $setNameBound($file['name']);
                }

                $entry->properties = $file['properties'];
            }

            // Ignore deletes if deleted file does not exist any more
            elseif (\array_key_exists('deleted', $file)) {
            }

            // Root
            elseif (!$file['parentId']) {
                $this->entryList[$file['id']] = $this->root = $this->folderFactory($file['name'], null, $file['id'], $file['properties']);
            }

            else {
                if (isset($this->entryList[$file['parentId']])) {
                    $parent = $this->entryList[$file['parentId']];
                } else {
                    $parent = null;
                    $dangling[$file['id']] = $file['parentId'];
                }

                if ($file['mimeType'] === self::FOLDER_MIME_TYPE) {
                    $this->entryList[$file['id']] = $this->folderFactory($file['name'], $parent, $file['id'], $file['properties']);
                }

                else {
                    $this->entryList[$file['id']] = $this->fileFactory($file['name'], $parent, $file['id'], $file['size'], $file['md5'], $file['properties']);
                }
            }
        }

        if (\count($dangling)) {
            $accessor = function(Folder $parent) { $this->setParent($parent); };

            foreach ($dangling as $fileId => $parentId) {
                if (isset($this->entryList[$parentId])) {
                    $accessor->call($this->entryList[$fileId], $this->entryList[$parentId]);
                } else {
                    $this->logger && $this->logger->warning(__FUNCTION__ . ": unreachable parent $parentId for entry $fileId (" . $this->entryList[$fileId]->getName() . ")");
                }
            }
        }

        foreach($deletes as $delete) {
            $delete->delete();
        }

        $this->updateToken = $entryGenerator->getReturn();
        $this->logger && $this->logger->debug(__FUNCTION__ . ": finished $processedUpdates " . ($this->updateToken ? "file updates" : "files") . ", new status: " . $this->updateToken);

        if (\file_put_contents($this->configDir . \DIRECTORY_SEPARATOR . self::FILES_CACHE_FILE . '-' . $this->updateToken, \igbinary_serialize(['token' => $this->updateToken, 'root' => $this->root]))) {
            \copy($this->configDir . \DIRECTORY_SEPARATOR . self::FILES_CACHE_FILE . '-' . $this->updateToken, $this->configDir . \DIRECTORY_SEPARATOR . self::FILES_CACHE_FILE);
        }
    }


    /**
     * Fetch a complete list of all files from GDrive.
     * The result is a Traversable for arrays containing file information in the format provided by fileToArray().
     * The token required to fetch updates from state of the GDrive is returned.
     *
     * @return \Traversable
     */
    private function fetchFileList() : \Traversable
    {
        // Get current state of the drive
        $lastChangesStartPageToken = $this->clientWrapper->getChangesStartPageToken();

        // Get root folder
        yield $this->clientWrapper->getRootFile([
            'fields' => self::FILE_FETCH_FIELDS,
        ]);

        $nextPageToken = null;
        do {
            $results = $this->clientWrapper->listFiles([
                'orderBy' => 'createdTime',
                'pageSize' => 1000,
                'fields' => 'nextPageToken, files(' . self::FILE_FETCH_FIELDS . ')',
                'pageToken' => $nextPageToken,
            ]);

            /* @var $file \Google_Service_Drive_DriveFile */
            foreach ($results->getFiles() as $file) {
                if ($file->trashed) {
                    continue;
                }

                yield $this->fileToArray($file);
            }

            $nextPageToken = $results->getNextPageToken();
        } while ($nextPageToken);

        return $lastChangesStartPageToken;
    }


    /**
     * Fetches a list of file updates from the state identified by the $startPageToken.
     * The result is a Traversable of arrays containing file information in the format provided by fileToArray().
     * The final array contains the token to fetch all updates from the current state.
     *
     * NOTE:
     * If too many changes accumulated since the last sync, they may come in an order that can not be used
     *  to update the folder cache. In that case, delete the folder cache file and do a complete sync.
     *
     * @return \Generator
     */
    private function fetchUpdateList() : \Generator
    {
        $nextPageToken = $this->updateToken;
        do {
            $results = $this->clientWrapper->listChanges($nextPageToken, [
                'spaces' => 'drive',
                'pageSize' => 1000,
                'fields' => 'newStartPageToken, nextPageToken, changes/type, changes/removed, changes/fileId, changes/time, changes/file(' . self::FILE_FETCH_FIELDS . ')',
            ]);

            /* @var $change \Google_Service_Drive_Change */
            foreach ($results->getChanges() as $change) {
                /* @var $file \Google_Service_Drive_DriveFile */
                $file = $change->getFile();

                if ($change->type !== 'file') {
                    //echo "Skipping change with ignored type '" . $change->type . "'" . \PHP_EOL;
                }

                elseif ($change->removed || ($file && $file->trashed)) {
                    $this->logger && $this->logger->debug(\sprintf("%s: file %s deleted", __FUNCTION__, $change->fileId));
                    yield ['id' => $change->fileId, 'deleted' => true];
                }

                else {
                    $fileData = $this->fileToArray($file);
                    $this->logger && $this->logger->debug(\sprintf("%s: file %s changed or created", __FUNCTION__, $change->fileId), $fileData);

                    yield $fileData;
                }
            }

            if ($results->newStartPageToken != null) {
                return $results->newStartPageToken;
            }

            $nextPageToken = $results->getNextPageToken();
        } while ($nextPageToken);
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
    final public function uploadFile(Folder $parent, FileInterface $sourceFile, string $overrideFileName = null, int $chunkSize = self::DEFAULT_CHUNK_SIZE, callable $progressCallback = null) : File
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
            $status = $upload(chunk);

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
    final public function uploadFileChunked(Folder $parent, string $fileName, int $fileSize, int $chunkSize = self::DEFAULT_CHUNK_SIZE) : callable
    {
        $mediaFileUpload = $this->clientWrapper->fileUploadStart(
            $fileName,
            $parent->id,
            $fileSize,
            $chunkSize,
            [
                'fields' => self::FILE_FETCH_FIELDS,
            ]
        );

        $processedBytes = 0;
        $buffer = "";

        return function(string $chunk) use ($parent, $fileName, $fileSize, $chunkSize, $mediaFileUpload, &$processedBytes, &$buffer) {
            $buffer .= $chunk;
            $processedBytes += \strlen($chunk);

            // Upload only complete chunk and last chunk
            if (\strlen($buffer) >= $chunkSize || ($processedBytes >= $fileSize)) {
                $result = $this->clientWrapper->fileUploadNextChunk($mediaFileUpload, $buffer);
                $buffer = "";

                if ($result instanceof \Google_Service_Drive_DriveFile) {
                    if ($this->waitForUpdate($result->getId())) {
                        return $this->entryList[$result->getId()];
                    } else {
                        throw new \RuntimeException("Timeout waiting for new File to appear in Google Drive.");
                    }
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
     * @return resource
     */
    final public function downloadFile(File $file)
    {
        return $this->clientWrapper->fileDownload($file->id);
    }


    /**
     * Create a new remote folder
     *
     * @param Folder $parent
     * @param $name
     * @return Folder
     */
    final public function createFolder(Folder $parent, $name) : Folder
    {
        $folder = $this->clientWrapper->createFolder($parent->id, $name, [
            'fields' => self::FILE_FETCH_FIELDS,
        ]);

        if ($folder && $this->waitForUpdate($folder->getId())) {
            return $this->entryList[$folder->getId()];
        } else {
            throw new \RuntimeException('Failed to create folder "' . $name . "'");
        }
    }


    /**
     * Delete a file by ID.
     *
     * @param string $id
     * @see Entry::delete()
     */
    final protected function deleteFile(string $id)
    {
        if (isset($this->entryList[$id])) {
            $this->clientWrapper->deleteFile($id);
            $this->waitForUpdate($id);
        }
    }


    /**
     * Modify the name and/or the parents of a file
     *
     * @param string $id Unique identifier of the file
     * @param string $name New name of the file
     * @param array $oldParents List of all parent IDs
     * @param array $newParents List of all wanted parent IDs
     * @return bool
     */
    final protected function moveOrRenameFile(string $id, string $name, array $oldParents, array $newParents) : bool
    {
        $file = new \Google_Service_Drive_DriveFile([
            'name' => $name,
            'originalFilename' => $name,
            'properties' => $this->entryList[$id]->properties,
        ]);

        $params = [];
        if ($oldParents !== $newParents) {
            $params['addParents'] = \implode(',', \array_diff($newParents, $oldParents));
            $params['removeParents'] = \implode(',', \array_diff($oldParents, $newParents));
        }

        return $this->clientWrapper->updateFileMetadata($id, $file, $params);
    }


    /**
     * Helper function to copy relevant fields from a DriveFile class into an array
     *
     * @param \Google_Service_Drive_DriveFile $file
     * @return array
     */
    final private function fileToArray(\Google_Service_Drive_DriveFile $file) : array
    {
        return [
            'id' => $file->id,
            'name' => $file->name,
            'md5' => $file->md5Checksum,
            'size' => $file->size,
            'modified' => $file->modifiedTime,
            'mimeType' => $file->mimeType,
            'properties' => $file->properties,
            'parentId' => (\is_array($file->parents) && \count($file->parents) ? $file->parents[0] : null),
        ];
    }


    public function __sleep()
    {
        $fields = [
            'configDir',
            'root',
            'entryList',
            'clientWrapper',
            'fileFactory',
            'folderFactory',
        ];

        // Handlers with Closures can not be serialized!
        foreach (['fileLoadedHandlers','folderLoadedHandlers','folderScannedHandlers',] as $handlerType) {
            $skipHandlers = false;
            foreach ($this->$handlerType as $handler) {
                if ($handler instanceof \Closure) {
                    \trigger_error("'Found closure in $handlerType while serializing, ignoring handlers.", \E_USER_NOTICE);
                    $skipHandlers = true;
                    break;
                }
            }
            if (!$skipHandlers) {
                $fields[] = $handlerType;
            }
        }

        return $fields;
    }
}
