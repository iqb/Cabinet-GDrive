<?php

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


    public function __construct(Driver $driver, Folder $parent, string $id, string $name, int $size, string $md5)
    {
        parent::__construct($driver, $parent, $id, $name);
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
