<?php

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


    public function dump(int $level = 0)
    {
        parent::dump($level);

        foreach ($this->entries as $entry) {
            $entry->dump($level+1);
        }
    }


    public function rename(string $newName)
    {
        // TODO: Implement rename() method.
    }
}
