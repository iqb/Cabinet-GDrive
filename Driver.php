<?php

namespace Iqb\Cabinet\GDrive;

use Iqb\Cabinet\DriverHandlerTrait;
use Iqb\Cabinet\DriverInterface;
use Iqb\Cabinet\FileInterface;
use Iqb\Cabinet\FolderInterface;

class Driver implements DriverInterface
{
    use DriverHandlerTrait;

    const AUTH_TOKEN_FILE = 'auth_token.json';
    const CLIENT_SECRET_FILE = 'client_id.json';
    const FILE_CACHE_FILE = 'files.json';

    const DEFAULT_CHUNK_SIZE = 64 * 1024 * 1024;
    const DEFAULT_MIME_TYPE = 'application/octet-stream';
    const FOLDER_MIME_TYPE = 'application/vnd.google-apps.folder';

    const FILE_FETCH_FIELDS = 'id, name, md5Checksum, parents, size, mimeType, modifiedTime, originalFilename, trashed';

    /**
     * @var string
     */
    private $configDir;

    /**
     * @var \Google_Client
     */
    public $client;

    /**
     * @var \Google_Service_Drive
     */
    public $service;

    /**
     * @var Folder
     */
    private $root;

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
     * Create a connection to google drive.
     *
     * The supplied $configDir must contain a file with name self::CLIENT_SECRET_FILE
     * This file should be downloaded from the "Download JSON" button on in the Google Developer Console.
     *
     * @param string $configDir
     * @param string|null $applicationName
     */
    public function __construct(string $configDir = __DIR__ . '/../', string $applicationName = null)
    {
        $this->configDir = \realpath($configDir);

        $this->client = new \Google_Client();
        if ($applicationName) {
            $this->client->setApplicationName($applicationName);
        }
        $this->client->setScopes([\Google_Service_Drive::DRIVE]);
        $this->client->setAuthConfig($this->configDir . '/' . self::CLIENT_SECRET_FILE);
        $this->client->setAccessType('offline');

        // Load previously authorized credentials from a file.
        $credentialsPath = $this->configDir . '/' . self::AUTH_TOKEN_FILE;
        if (\file_exists($credentialsPath)) {
            $accessToken = \json_decode(\file_get_contents($credentialsPath), true);
        }

        else {
            // Request authorization from the user.
            $authUrl = $this->client->createAuthUrl();
            \printf("Open the following link in your browser:\n%s\n", $authUrl);
            print 'Enter verification code: ';
            $authCode = \trim(\fgets(STDIN));

            // Exchange authorization code for an access token.
            $accessToken = $this->client->fetchAccessTokenWithAuthCode($authCode);

            // Store the credentials to disk.
            \file_put_contents($credentialsPath, \json_encode($accessToken));
            \printf("Credentials saved to %s\n", $credentialsPath);
        }
        $this->client->setAccessToken($accessToken);

        // Refresh the token if it's expired.
        if ($this->client->isAccessTokenExpired()) {
            $this->client->fetchAccessTokenWithRefreshToken($this->client->getRefreshToken());
            \file_put_contents($credentialsPath, \json_encode($this->client->getAccessToken()));
        }

        $this->service = new \Google_Service_Drive($this->client);
    }


    /** @inheritdoc */
    final public function fileFactory(string $fileName, FolderInterface $parent = null, string $id = null, int $size = null, string $md5 = null) : FileInterface
    {
        if ($this->fileFactory) {
            $file = ($this->fileFactory)($this, $parent, $id, $fileName, $size, $md5);
        } else {
            $file = new File($this, $parent, $id, $fileName, $size, $md5);
        }

        $this->notifyFileLoaded($file);
        return $file;
    }


    /** @inheritdoc */
    final public function folderFactory(string $folderName, FolderInterface $parent = null, string $id = null) : FolderInterface
    {
        if ($this->folderFactory) {
            $folder = ($this->folderFactory)($this, $parent, $id, $folderName);
        } else {
            $folder = new Folder($this, $parent, $id, $folderName);
        }

        $this->notifyFolderLoaded($folder);
        return $folder;
    }


    /** @inheritdoc */
    final public function setFileFactory(callable $fileFactory)
    {
        $this->fileFactory = $fileFactory;
    }


    /** @inheritdoc */
    final public function setFolderFactory(callable $folderFactory)
    {
        $this->folderFactory = $folderFactory;
    }


    /**
     * Get the complete hierarchy of files from the google drive.
     *
     * @return Folder
     */
    final public function getRoot() : Folder
    {
        if ($this->root) {
            return $this->root;
        }

        // Load file entries from cache file and pull an update or load a complete file list
        $cacheFile = $this->configDir . '/' . self::FILE_CACHE_FILE;
        if (\file_exists($cacheFile)) {
            $oldFileList = \json_decode(\file_get_contents($cacheFile), true);
            $fileList = $this->updateFileList($oldFileList);
        } else {
            $fileList = $this->fetchFileList();
        }

        if (!isset($oldFileList) || $oldFileList['status'] !== $fileList['status']) {
            \file_put_contents($cacheFile, \json_encode($fileList, \JSON_PRETTY_PRINT), \LOCK_EX);
        }

        // Build the Root/File/Folder structure of the files
        $entries = [];

        foreach ($fileList['files'] as $file) {
            if (!isset($entries[$file['parentId']])) {
                if ($this->root) {
                    echo "Removing detached '" . $file['name'] . "' ...", PHP_EOL;
                    $this->client->setDefer(false);
                    $this->service->files->delete($file['id']);
                    continue;
                }

                $entry = $this->folderFactory($file['name'], null, $file['id']);
                $this->root = $entry;
            }

            else {
                $parent = $entries[$file['parentId']];

                if ($file['mimeType'] === self::FOLDER_MIME_TYPE) {
                    $entry = $this->folderFactory($file['name'], $parent, $file['id']);
                }

                else {
                    $entry = $this->fileFactory($file['name'], $parent, $file['id'], $file['size'], $file['md5']);
                }
            }

            $entries[$entry->id] = $entry;
        }

        return $this->root;
    }


    /**
     * Fetch a complete list of all files from gdrive.
     * This can take some time.
     *
     * @return array
     */
    final private function fetchFileList() : array
    {
        // Get current state of the drive
        $lastChangesStartPageToken = $this->service->changes->getStartPageToken()->startPageToken;

        $root = $this->service->files->get('root', [
            'fields' => self::FILE_FETCH_FIELDS,
        ]);
        $root->name = 'gdrive';
        $files = [
            $root->id => $this->fileToArray($root),
        ];

        $nextPageToken = null;
        $this->client->setDefer(false);
        do {
            $results = $this->service->files->listFiles([
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

                $files[$file->id] = $this->fileToArray($file);
            }

            $nextPageToken = $results->getNextPageToken();
        } while ($nextPageToken);

        return [
            'status' => $lastChangesStartPageToken,
            'date' => \ceil(\microtime(true) * 1000),
            'files' => $files,
        ];
    }


    /**
     * Fetch all updates since creation of the supplied $fileList and return an updated version of it
     *
     * @param array $fileList
     * @return array
     */
    final private function updateFileList(array $fileList) : array
    {
        $nextPageToken = $fileList['status'];
        $this->client->setDefer(false);
        do {
            $results = $this->service->changes->listChanges($nextPageToken, [
                'spaces' => 'drive',
                'pageSize' => 1000,
                'fields' => 'newStartPageToken, nextPageToken, changes/type, changes/removed, changes/file(' . self::FILE_FETCH_FIELDS . ')',
            ]);

            /* @var $change \Google_Service_Drive_Change */
            foreach ($results->getChanges() as $change) {
                /* @var $file \Google_Service_Drive_DriveFile */
                $file = $change->getFile();

                if ($change->type !== 'file') {
                    echo "Skipping change with ignored type '" . $change->type . "'" . \PHP_EOL;
                }

                elseif ($change->removed || ($file && $file->trashed)) {
                    unset($fileList['files'][$change->fileId]);
                }

                else {
                    $fileList['files'][$file->id] = $this->fileToArray($file);
                }
            }

            if ($results->newStartPageToken != null) {
                $fileList['status'] = $results->newStartPageToken;
                $fileList['date'] = \ceil(\microtime(true) * 1000);
            }

            $nextPageToken = $results->getNextPageToken();
        } while ($nextPageToken);

        return $fileList;
    }

    /**
     * Upload a file and store it in the folder identified by $parentId
     *
     * @param Folder $parent
     * @param string $sourceFile
     * @param string|null $overrideFileName
     * @param int $chunkSize
     * @param callable|null $progressCallback
     * @return File
     */
    final public function uploadFile(Folder $parent, string $sourceFile, string $overrideFileName = null, int $chunkSize = self::DEFAULT_CHUNK_SIZE, callable $progressCallback = null) : File
    {
        if (!\file_exists($sourceFile)) {
            throw new \InvalidArgumentException('File "' . $sourceFile . '" does not exist!');
        }

        $fileSize = \filesize($sourceFile);
        $file = new \Google_Service_Drive_DriveFile([
            'name' => ($overrideFileName !== null ? $overrideFileName : \basename($sourceFile)),
            'parents' => [ $parent->id ],
        ]);

        $this->client->setDefer(true);
        $request = $this->service->files->create($file, [
            'fields' => self::FILE_FETCH_FIELDS,
        ]);

        // Create a media file upload to represent our upload process.
        $media = new \Google_Http_MediaFileUpload(
            $this->client,
            $request,
            self::DEFAULT_MIME_TYPE,
            null,
            true,
            $chunkSize
        );
        $media->setFileSize($fileSize);

        $readPos = 0;
        $fileHandle = \fopen($sourceFile, 'rb');
        do {
            $chunk = \stream_get_contents($fileHandle, $chunkSize);
            $readPos += \mb_strlen($chunk, '8bit');
            $status = $media->nextChunk($chunk);

            $progressCallback && $progressCallback($sourceFile, $readPos, $fileSize);
        } while (!$status && !\feof($fileHandle));
        \fclose($fileHandle);

        if (!$status) {
            throw new \RuntimeException('Failed to upload file "' . $sourceFile . "'");
        }

        /* @var $status \Google_Service_Drive_DriveFile */
        return new File($parent, $status->id, $status->name, $status->size, $status->md5Checksum);
    }


    /**
     * Start a file download, return a stream resource to its contents
     *
     * @param File $file
     * @return resource
     */
    final public function downloadFile(File $file)
    {
        $this->client->setDefer(true);

        /* @var $httpClient \GuzzleHttp\Client */
        $httpClient = $this->client->authorize();
        $request = $this->service->files->get($file->id, ['alt' => 'media']);
        $response = $httpClient->send($request, ['stream' => true]);
        return $response->getBody()->detach();
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
        $this->client->setDefer(false);

        $file = new \Google_Service_Drive_DriveFile([
            'name' => $name,
            'mimeType' => self::FOLDER_MIME_TYPE,
            'parents' => [ $parent->id ],
        ]);

        $status = $this->service->files->create($file, [
            'fields' => self::FILE_FETCH_FIELDS,
        ]);

        if (!$status) {
            throw new \RuntimeException('Failed to create folder "' . $name . "'");
        }

        /* @var $status \Google_Service_Drive_DriveFile */
        return new Folder($parent, $status->id, $status->name);
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
            'parentId' => (\is_array($file->parents) && \count($file->parents) ? $file->parents[0] : null),
        ];
    }
}
