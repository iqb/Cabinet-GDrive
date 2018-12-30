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

abstract class Entry implements EntryInterface
{
    /**
     * Full path of the directory this file belongs to
     * @var
     */
    private $path;

    /**
     * Identifier at the remote storage
     * @var string
     */
    public $id;

    /**
     * @var string
     */
    public $name;

    /**
     * @var Folder
     */
    public $parent;

    /**
     * @var \DateTimeImmutable
     */
    private $created;

    /**
     * @var \DateTimeImmutable
     */
    private $modified;

    /**
     * @var string[]
     */
    public $properties;

    /**
     * @var Driver
     */
    protected $driver;


    public function __construct(Driver $driver, Folder $parent = null, string $id, string $name, \DateTimeInterface $created, \DateTimeInterface $modified, array $properties = null)
    {
        $this->driver = $driver;
        $this->parent = $parent;
        $this->id = $id;
        $this->name = $name;
        $this->setCreated($created);
        $this->setModified($modified);
        $this->properties = ($properties ?: []);

        $parent->entries[$id] = $this;
    }

    public function dump(int $level = 0)
    {
        echo \str_repeat('  ', $level), $this->name, ($this->name !== $this->id ? ' (' . $this->id . ')' : ''), (isset($this->md5) && $this->md5 ? ' (md5: ' . $this->md5 . ')' : ''), \PHP_EOL;
    }


    /** @inheritdoc */
    public function getPath(): string
    {
        if ($this->path === null) {
            if ($this->parent === null) {
                $this->path = "";
            } else {
                $this->path = $this->getParent()->getPath();
            }
        }

        return $this->path . \DIRECTORY_SEPARATOR . $this->name;
    }


    /** @inheritdoc */
    final public function getName(): string
    {
        return $this->name;
    }


    /**
     * Change the name of the Entry
     *
     * @param string $newName
     */
    final protected function setName(string $newName)
    {
        $this->name = $newName;
    }


    /**
     * @return \DateTimeInterface
     */
    final public function getCreated() : \DateTimeInterface
    {
        return $this->created;
    }


    final protected function setCreated(\DateTimeInterface $created)
    {
        $this->created = ($created instanceof \DateTimeImmutable ? $created : \DateTimeImmutable::createFromMutable($created));
    }


    /**
     * @return \DateTimeInterface
     */
    final public function getModified() : \DateTimeInterface
    {
        return $this->modified;
    }


    final protected function setModified(\DateTimeInterface $modified)
    {
        $this->modified = ($modified instanceof \DateTimeImmutable ? $modified : \DateTimeImmutable::createFromMutable($modified));
    }


    /** @inheritdoc */
    final public function hasParents(): bool
    {
        return ($this->parent !== null);
    }


    /** @inheritdoc */
    final public function getParent(): FolderInterface
    {
        return $this->parent;
    }


    /** @inheritdoc */
    final public function getParents(): array
    {
        return [ $this->parent ];
    }


    final protected function setParent(Folder $parent)
    {
        if ($this->parent) {
            unset($this->parent->entries[$this->id]);
        }
        $this->parent = $parent;
        $this->parent->entries[$this->id] = $this;
    }


    /** @inheritdoc */
    public function delete(): bool
    {
        // Do not try to delete a deleted item on the remote
        if ($this->id) {
            $deletor = (function(string $id) { return $this->deleteFile($id); })->bindTo($this->getDriver(), $this->getDriver());
            $deletor($this->id);
        }

        if ($this->parent) {
            unset($this->parent->entries[$this->id]);
        }

        $this->id = null;
        $this->parent = null;
        return true;
    }

    final public function getDriver() : Driver
    {
        return $this->driver;
    }


    /** @inheritdoc */
    final public function rename(string $newName)
    {
        $setter = (function(string $id, string $newName) { $this->moveOrRenameFile($id, $newName, [], []); })->bindTo($this->getDriver(), $this->getDriver());
        $setter($this->id, $newName);
        $this->name = $newName;
    }


    /** @inheritdoc */
    final public function move(FolderInterface $newParent, string $newName = null)
    {
        $oldParentIds = [];
        $newParentIds = [];

        if ($newParent) {
            if (!$newParent instanceof Folder || $this->getDriver() !== $newParent->getDriver()) {
                throw new \InvalidArgumentException("Can not move file to a different root!");
            }
            $oldParentIds[] = $this->getParent()->id;
            $newParentIds[] = $newParent->id;
        }

        if (!$newName) {
            $newName = $this->getName();
        }

        $setter = (function(string $id, string $newName, array $oldParentIds, array $newParentIds) { $this->moveOrRenameFile($id, $newName, $oldParentIds, $newParentIds); })->bindTo($this->getDriver(), $this->getDriver());
        $setter($this->id, $newName, $oldParentIds, $newParentIds);
        $this->name = $newName;
        $this->setParent($newParent);
    }
}
