<?php

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

    protected $driver;


    public function __construct(Driver $driver, Folder $parent = null, string $id, string $name)
    {
        $this->driver = $driver;
        $this->parent = $parent;
        $this->id = $id;
        $this->name = $name;

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


    /** @inheritdoc */
    final public function delete(): bool
    {
        $deletor = (function(string $id) { return $this->deleteFile($id); })->bindTo($this->driver, $this->driver);
        $deletor($this->id);

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
}
