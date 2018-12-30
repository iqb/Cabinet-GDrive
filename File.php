<?php

/*
 * This file is part of Cabinet - file access abstraction library for PHP.
 * (c) 2017 by Dennis Birkholz
 * All rights reserved.
 * For the license to use this library, see the provided LICENSE file.
 */

namespace Iqb\Cabinet\GDrive;

use Iqb\Cabinet\FileInterface;

class File extends Entry implements FileInterface
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


    public function __construct(Driver $driver, Folder $parent, string $id, string $name, \DateTimeInterface $created, \DateTimeInterface $modified, int $size, string $md5, array $properties = null)
    {
        parent::__construct($driver, $parent, $id, $name, $created, $modified, $properties);
        $this->size = $size;
        $this->md5 = $md5;
    }


    /** @inheritdoc */
    final public function getSize(): int
    {
        return $this->size;
    }


    /** @inheritdoc */
    final public function hasHash(): bool
    {
        return true;
    }


    /** @inheritdoc */
    final public function getHash(): string
    {
        return $this->md5;
    }
}
