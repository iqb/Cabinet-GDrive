<?php

/*
 * This file is part of Cabinet - file access abstraction library for PHP.
 * (c) by Dennis Birkholz <cabinet@birkholz.org>
 * All rights reserved.
 * For the license to use this library, see the provided LICENSE file.
 */

namespace Iqb\Cabinet\GDrive;

use Iqb\Cabinet\Drawer\FileInterface;

final class File extends Entry implements FileInterface
{
    /**
     * File hash
     * @var string
     */
    private $md5;

    /**
     * File size in bytes
     * @var int
     */
    private $size;


    public function __construct(Driver $driver, string $parentId, string $id, string $name, \DateTimeInterface $created, \DateTimeInterface $modified, int $size, string $md5, array $properties = null)
    {
        parent::__construct($driver, $parentId, $id, $name, $created, $modified, $properties);
        $this->size = $size;
        $this->md5 = $md5;
    }


    /** @inheritDoc */
    public function getContent(int $offset = null, int $bytes = null) : string
    {
        $stream = $this->driver->downloadFile($this, $offset, $bytes);
        return \stream_get_contents($stream);
    }


    /** @inheritDoc */
    public function getContentStream(int $offset = null, int $bytes = null)
    {
        return $this->driver->downloadFile($this, $offset, $bytes);
    }


    /** @inheritDoc */
    public function getHash() : string
    {
        return $this->md5;
    }


    /** @inheritDoc */
    public function getSize() : int
    {
        return $this->size;
    }


    /** @inheritDoc */
    public function hasHash() : bool
    {
        return true;
    }
}
