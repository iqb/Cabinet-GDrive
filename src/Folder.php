<?php

/*
 * This file is part of Cabinet - file access abstraction library for PHP.
 * (c) by Dennis Birkholz <cabinet@birkholz.org>
 * All rights reserved.
 * For the license to use this library, see the provided LICENSE file.
 */

namespace Iqb\Cabinet\GDrive;

use Iqb\Cabinet\Drawer\EntryInterface;
use Iqb\Cabinet\Drawer\FileInterface;
use Iqb\Cabinet\Drawer\FolderInterface;

final class Folder extends Entry implements FolderInterface
{
    /**
     * @inheritDoc
     * @return File
     */
    public function createFile(string $fileName, string $data) : FileInterface
    {
        $size = \strlen($data);

        $uploader = $this->driver->uploadFileChunked($this, $fileName, $size);
        return $uploader($data);
    }


    /**
     * @inheritDoc
     * @return Folder
     */
    public function createFolder(string $folderName, bool $recursive = false) : FolderInterface
    {
        @list($localPart, $remainingPart) = \explode('/', $folderName, 2);

        if ($remainingPart && !$recursive) {
            throw new \InvalidArgumentException(\sprintf("Folder name can not contain '/'. Use \$recursive = true to create multiple nested folders."));
        }

        $localFolder = $this->getChild($localPart);
        if ($localFolder) {
            if (!$localFolder instanceof Folder) {
                throw new \InvalidArgumentException(\sprintf("Trying to create folder '%s' but a file with that name already exists!", $localPart));
            }

            elseif (!$recursive) {
                throw new \InvalidArgumentException(\sprintf("Trying to create folder '%s' that already exists!", $localPart));
            }
        }

        else {
            $localFolder = $this->driver->createFolder($this, $localPart);
        }

        if ($remainingPart) {
            return $localFolder->createFolder($remainingPart, $recursive);
        } else {
            return $localFolder;
        }
    }


    /**
     * @inheritDoc
     * @return Entry
     */
    public function getChild(string $name) : ?EntryInterface
    {
        return $this->driver->getEntryFromFolder($this, $name);
    }


    /**
     * @inheritDoc
     * @return Entry[]
     */
    public function getChildren() : array
    {
        return \iterator_to_array($this->driver->getEntries($this));
    }


    /** @inheritDoc */
    public function getSize(bool $recursive = false) : int
    {
        $size = 0;
        if ($recursive) {
            foreach ($this->getChildren() as $child) {
                $size += $child->getSize($recursive);
            }
        }
        return $size;
    }


    /** @inheritdoc */
    public function isParent(EntryInterface $child): bool
    {
        return \in_array($child, $this->entries, true);
    }


    /** @inheritdoc */
    public function hasChild(string $name): bool
    {
        foreach ($this->entries as $entry) {
            if ($entry->name === $name) {
                return true;
            }
        }

        return false;
    }


    /** @inheritdoc */
    public function delete(bool $recursive = false): bool
    {
        return $this->driver->deleteEntry($this, $recursive);
    }


    /**
     * Incrementally upload a file to the specified folder
     *
     * @param string $fileName
     * @param int $fileSize
     * @return callable
     */
    public function upload(string $fileName, int $fileSize) : callable
    {
        return $this->driver->uploadFileChunked($this, $fileName, $fileSize);
    }


    public function dump(int $level = 0)
    {
        parent::dump($level);

        foreach ($this->entries as $entry) {
            $entry->dump($level+1);
        }
    }
}
