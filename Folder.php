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


    /**
     * Get an entry by name
     *
     * @param string $name
     * @return Entry|null
     */
    public function get(string $name)
    {
        foreach ($this->entries as $entry) {
            if ($entry->name === $name) {
                return $entry;
            }
        }

        return null;
    }

    public function dump(int $level = 0)
    {
        parent::dump($level);

        foreach ($this->entries as $entry) {
            $entry->dump($level+1);
        }
    }

    public function getChildren(): array
    {
        return $this->entries;
    }

    public function getSize(): int
    {
        $size = 0;
        foreach ($this->getChildren() as $child) {
            $size += $child->getSize();
        }
        return $size;
    }

    public function getChild(string $name): EntryInterface
    {
        // TODO: Implement getChild() method.
    }

    public function rename(string $newName)
    {
        // TODO: Implement rename() method.
    }

    public function delete(): bool
    {
        // TODO: Implement delete() method.
    }

    public function isParent(EntryInterface $child): bool
    {
        // TODO: Implement isParent() method.
    }
}
