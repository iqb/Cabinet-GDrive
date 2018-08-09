<?php

/*
 * This file is part of Cabinet - file access abstraction library for PHP.
 * (c) 2017 by Dennis Birkholz
 * All rights reserved.
 * For the license to use this library, see the provided LICENSE file.
 */

namespace Iqb\Cabinet\GDrive;

use Iqb\Cabinet\EntryInterface;
use Iqb\Cabinet\FolderInterface;

class Folder extends Entry implements FolderInterface
{
    /**
     * Id => Entry mapping of children
     *
     * @var Entry[]
     */
    public $entries = [];



    /** @inheritdoc */
    final public function getChildren(): array
    {
        return $this->entries;
    }


    /** @inheritdoc */
    final public function isParent(EntryInterface $child): bool
    {
        return \in_array($child, $this->entries, true);
    }


    /** @inheritdoc */
    final public function hasChild(string $name): bool
    {
        foreach ($this->entries as $entry) {
            if ($entry->name === $name) {
                return true;
            }
        }

        return false;
    }


    /** @inheritdoc */
    final public function getChild(string $name) : EntryInterface
    {
        foreach ($this->entries as $entry) {
            if ($entry->name === $name) {
                return $entry;
            }
        }

        return null;
    }


    /** @inheritdoc */
    final public function getSize(): int
    {
        $size = 0;
        foreach ($this->getChildren() as $child) {
            $size += $child->getSize();
        }
        return $size;
    }


    /** @inheritdoc */
    final public function delete(): bool
    {
        foreach ($this->getChildren() as $child) {
            $child->delete();
        }

        return parent::delete();
    }


    /**
     * Incrementally upload a file to the specified folder
     *
     * @param string $fileName
     * @param int $fileSize
     * @return FileUpload
     */
    public function upload(string $fileName, int $fileSize) : FileUpload
    {
        return new FileUpload($this->driver, $this, $fileName, $fileSize);
    }


    public function dump(int $level = 0)
    {
        parent::dump($level);

        foreach ($this->entries as $entry) {
            $entry->dump($level+1);
        }
    }
}
