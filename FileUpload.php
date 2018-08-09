<?php

/*
 * (c) 2018 Dennis Birkholz <dennis@birkholz.org>
 *
 * $Id$
 * Author:    $Format:%an <%ae>, %ai$
 * Committer: $Format:%cn <%ce>, %ci$
 */

namespace Iqb\Cabinet\GDrive;

/**
 * @author Dennis Birkholz <dennis@birkholz.org>
 */
class FileUpload
{
    const DEFAULT_CHUNKSIZE = 32*1024*1024;

    /**
     * @var Driver
     */
    private $driver;

    /**
     * @var Folder
     */
    private $folder;

    /**
     * @var string
     */
    private $name;

    /**
     * @var int
     */
    private $fileSize;

    /**
     * @var int
     */
    private $chunkSize;

    /**
     * @var \Google_Http_MediaFileUpload
     */
    private $uploadHandle;

    /**
     * @var string
     */
    private $data;

    /**
     * @var \Google_Service_Drive_DriveFile|false
     */
    private $status;


    public function __construct(Driver $driver, Folder $folder, string $name, int $fileSize, int $chunkSize = self::DEFAULT_CHUNKSIZE)
    {
        $this->driver = $driver;
        $this->folder = $folder;
        $this->name = $name;
        $this->fileSize = $fileSize;
        $this->chunkSize = $chunkSize;
    }


    private function doUpload(string $chunk)
    {
        $this->driver->refreshConnection();

        if (!$this->uploadHandle) {
            $file = new \Google_Service_Drive_DriveFile([
                'name' => $this->name,
                'parents' => [ $this->folder->id ],
            ]);

            $this->driver->getClient()->setDefer(true);
            $request = $this->driver->getDriveService()->files->create($file, [
                'fields' => Driver::FILE_FETCH_FIELDS,
            ]);

            // Create a media file upload to represent our upload process.
            $this->uploadHandle = new \Google_Http_MediaFileUpload(
                $this->driver->getClient(),
                $request,
                Driver::DEFAULT_MIME_TYPE,
                null,
                true
            );
            $this->uploadHandle->setFileSize($this->fileSize);
        }

        $this->status = $this->uploadHandle->nextChunk($chunk);
    }


    public function update(string $data)
    {
        $remainingLength = $this->chunkSize - \strlen($this->data);
        $this->data .= \substr($data, 0, $remainingLength);
        if (\strlen($data) >= $remainingLength) {
            $this->doUpload($this->data);
            $this->data = \substr($data, $remainingLength);
        }
    }


    /**
     * @return File
     */
    public function final()
    {
        if (\strlen($this->data)) {
            $this->doUpload($this->data);
        }

        if ($this->status) {
            return $this->driver->fileFactory($this->status->name, $this->folder, $this->status->id, $this->status->size, $this->status->md5Checksum);
        } else {
            throw new \RuntimeException("File upload failed");
        }
    }
}
