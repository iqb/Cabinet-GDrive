<?php

namespace Iqb\Cabinet\GDrive;

use Iqb\Cabinet\FileInterface;

class File extends Entry implements FileInterface
{
    /**
     * File hash
     * @var string
     */
    public $md5;

    /**
     * File size in bytes
     * @var int
     */
    public $size;


    public function __construct(Driver $driver, Folder $parent, string $id, string $name, int $size, string $md5 = null)
    {
        parent::__construct($driver, $parent, $id, $name);
        $this->size = $size;
        $this->md5 = $md5;
    }

    public function getSize(): int
    {
        return $this->size;
    }

    public function rename(string $newName)
    {
        // TODO: Implement rename() method.
    }

    public function delete(): bool
    {
        // TODO: Implement delete() method.
    }
}
