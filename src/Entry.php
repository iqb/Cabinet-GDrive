<?php

/*
 * This file is part of Cabinet - file access abstraction library for PHP.
 * (c) by Dennis Birkholz <cabinet@birkholz.org>
 * All rights reserved.
 * For the license to use this library, see the provided LICENSE file.
 */

namespace Iqb\Cabinet\GDrive;

use Iqb\Cabinet\Drawer\EntryInterface;
use Iqb\Cabinet\Drawer\FolderInterface;

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
     * @var string
     */
    public $parentId;

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
     * @var DriverInterface
     */
    protected $driver;


    public function __construct(DriverInterface $driver, string $parentId = null, string $id, string $name, \DateTimeInterface $created, \DateTimeInterface $modified, array $properties = null)
    {
        $this->driver = $driver;
        $this->parentId = $parentId;
        $this->id = $id;
        $this->name = $name;
        $this->created = ($created instanceof \DateTimeImmutable ? $created : \DateTimeImmutable::createFromMutable($created));
        $this->modified = ($modified instanceof \DateTimeImmutable ? $modified : \DateTimeImmutable::createFromMutable($modified));
        $this->properties = ($properties ?: []);
    }

    public function dump(int $level = 0)
    {
        echo \str_repeat('  ', $level), $this->name, ($this->name !== $this->id ? ' (' . $this->id . ')' : ''), (isset($this->md5) && $this->md5 ? ' (md5: ' . $this->md5 . ')' : ''), \PHP_EOL;
    }

    ######################################################################
    # Getter                                                             #
    ######################################################################

    /** @inheritDoc */
    final public function getCreatedTime() : \DateTimeInterface
    {
        return $this->created;
    }

    /** @inheritDoc */
    final public function getId() : string
    {
        return $this->id;
    }

    /** @inheritDoc */
    final public function getModifiedTime() : \DateTimeInterface
    {
        return $this->modified;
    }

    /** @inheritDoc */
    final public function getName() : string
    {
        return $this->name;
    }

    /** @inheritDoc */
    final public function getParent() : ?FolderInterface
    {
        return ($this->parentId ? $this->driver->getEntryById($this->parentId) : null);
    }

    /** @inheritDoc */
    final public function getParents() : array
    {
        if ($this->parentId) {
            return [ $this->getParent() ];
        } else {
            return [];
        }
    }

    /** @inheritDoc */
    final public function getPath() : string
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
    final public function getProperties() : array
    {
        return $this->properties;
    }

    ######################################################################
    # Setter                                                             #
    ######################################################################

    /** @inheritDoc */
    final public function setCreatedTime(\DateTimeInterface $createdTime) : EntryInterface
    {
        throw new \InvalidArgumentException("GDrive does not support changing the created timestamp.");
    }


    /** @inheritDoc */
    final public function setModifiedTime(\DateTimeInterface $modifiedTime): EntryInterface
    {
        $this->modified = clone $modifiedTime;
        return $this->driver->updateEntry($this);
    }


    final protected function setParent(Folder $parent)
    {
        if ($this->parent) {
            unset($this->parent->entries[$this->id]);
        }
        $this->parent = $parent;
        $this->parent->entries[$this->id] = $this;
    }

    ######################################################################
    # Other mutators                                                     #
    ######################################################################

    /** @inheritDoc */
    public function delete() : bool
    {
        return $this->driver->deleteEntry($this);
    }

    final public function getDriver() : DriverInterface
    {
        return $this->driver;
    }


    /**
     * @inheritDoc
     * @return Entry
     */
    final public function rename(string $newName, bool $overwrite = false) : EntryInterface
    {
        return $this->move($this->getParent(), $newName, $overwrite);
    }


    /**
     * @inheritDoc
     * @param Folder $newParent
     */
    final public function move(FolderInterface $newParent, string $newName = null, bool $overwrite = false) : EntryInterface
    {
        if (!$newParent instanceof Folder) {
            throw new \InvalidArgumentException("Can not move file across file systems.");
        }

        $newName = $newName ?? $this->name;

        // Nothing to do
        if ($newParent->getId() === $this->parentId && $this->name === $newName) {
            return $this;
        }

        // Verify target is valid
        if ($duplicate = $newParent->getChild($newName)) {
            if ($duplicate instanceof Folder && \count($duplicate->getChildren())) {
                throw new \InvalidArgumentException("Can not overwrite non-empty folder '$newName'.");
            }

            elseif ($overwrite) {
                $duplicate->delete();
            }

            else {
                throw new \InvalidArgumentException("Rename target '$newName' already exists.");
            }
        }

        // Rename
        $this->name = $newName;

        // Move to other directory
        $params = [];
        if ($newParent->getId() !== $this->parentId) {
            $params['removeParents'] = $this->parentId;
            $params['addParents'] = $this->parentId = $newParent->getId();
        }

        return $this->driver->updateEntry($this, $params);
    }
}
