<?php

/*
 * (c) 2019 Dennis Birkholz <dennis@birkholz.org>
 *
 * $
 * Author:    :%an <%ae>, %ai$
 * Committer: :%cn <%ce>, %ci$
 */

namespace Iqb\Cabinet\GDrive;

use PHPUnit\Framework\TestCase;

/**
 * @author Dennis Birkholz <dennis@birkholz.org>
 */
class DriverIntegrationTest extends TestCase
{
    /**
     * @var Driver
     */
    private $driver;

    /**
     * @var string
     */
    private static $rootId;

    /**
     * @var Folder
     */
    private $root;


    public static function setUpBeforeClass()
    {
        $folderName = 'Integration test run ' . date('Y-m-d H:i:s');

        $driver = Driver::connect("Test Application", __DIR__ . '/.config/');
        $folder = $driver->getRoot()->createFolder($folderName);
        self::$rootId = $folder->getId();
    }


    public function setUp()
    {
        $this->driver = Driver::connect("Test Application", __DIR__ . '/.config/');
        $this->root = $this->driver->getEntryById(self::$rootId);
    }


    public function testGetRoot()
    {
        $this->assertInstanceOf(Folder::class, $this->root);
    }


    public function testCreateFolder()
    {
        $folder1Name = __FUNCTION__ . '-folder1';
        $folder2Name = __FUNCTION__ . '-folder2';
        $folder3Name = __FUNCTION__ . '-folder3';
        $folder4Name = __FUNCTION__ . '-folder4';

        $folder1 = $this->root->createFolder($folder1Name);
        $this->assertInstanceOf(Folder::class, $folder1);
        $this->assertEquals($folder1Name, $folder1->getName());
        $this->assertEquals($this->root->getName(), $folder1->getParent()->getName());

        // Verify existing folder can not be created again
        try {
            $this->root->createFolder($folder1Name);
        } catch (\InvalidArgumentException $e) {
            $this->assertInstanceOf(\InvalidArgumentException::class, $e);
            $this->assertContains("Trying to create folder", $e->getMessage());
            $this->assertContains("that already exists", $e->getMessage());
        }

        // Verify / is invalid without $recursive = true
        try {
            $this->root->createFolder($folder2Name . '/' . $folder3Name);
        } catch (\InvalidArgumentException $e) {
            $this->assertInstanceOf(\InvalidArgumentException::class, $e);
            $this->assertContains("Folder name can not contain '/'.", $e->getMessage());
        }

        // Use $recursive and create two nested directories
        $folder3 = $this->root->createFolder($folder2Name . '/' . $folder3Name, true);
        $this->assertInstanceOf(Folder::class, $folder3);
        $this->assertEquals($folder3Name, $folder3->getName());

        // Verify the intermediate directory was created
        $folder2 = $folder3->getParent();
        $this->assertInstanceOf(Folder::class, $folder2);
        $this->assertEquals($folder2Name, $folder2->getName());
        $this->assertEquals($this->root->getName(), $folder2->getParent()->getName());

        // Use $recursive with existing directories
        $folder4 = $this->root->createFolder($folder2Name . '/' . $folder3Name . '/' . $folder4Name, true);
        $this->assertInstanceOf(Folder::class, $folder4);
        $this->assertEquals($folder4Name, $folder4->getName());

        // Verify intermediate folders have same IDs
        $folder3copy = $folder4->getParent();
        $this->assertEquals($folder3->getId(), $folder3copy->getId());
        $folder2copy = $folder3copy->getParent();
        $this->assertEquals($folder2->getId(), $folder2copy->getId());
    }


    public function testDeleteFolder()
    {
        $folder1Name = __FUNCTION__ . '-folder1';
        $folder2Name = __FUNCTION__ . '-folder2';
        $folder3Name = __FUNCTION__ . '-folder3';
        $folder4Name = __FUNCTION__ . '-folder4';

        $folder4 = $this->root->createFolder($folder1Name . '/' . $folder2Name . '/' . $folder3Name . '/' . $folder4Name, true);
        $folder3 = $folder4->getParent();
        $folder2 = $folder3->getParent();
        $folder1 = $folder2->getParent();

        // Verify deleting a non-empty folder without recursive does not work
        $this->assertFalse($folder3->delete());

        // Verify deleting an empty folder works
        $this->assertTrue($folder4->delete());
        $this->assertEmpty($folder3->getChildren());

        // Verify recursively deleting works
        $this->assertTrue($folder2->delete(true));
        $this->assertEmpty($folder1->getChildren());
    }


    public function testCreateFile()
    {
        $folderName = __FUNCTION__ . '-folder';
        $file1Name = __FUNCTION__ . '-lorem.txt';

        $data = \file_get_contents(__DIR__ . '/loremipsum.txt');

        $folder = $this->root->createFolder($folderName);
        $file1 = $folder->createFile($file1Name, $data);
        $this->assertEquals($file1Name, $file1->getName());
        $this->assertEquals(\strlen($data), $file1->getSize());
        $this->assertEquals(\md5($data), $file1->getHash());
        $this->assertEquals($data, $file1->getContent());
    }


    public function testDownloadFileChunk()
    {
        $folderName = __FUNCTION__ . '-folder';
        $file1Name = __FUNCTION__ . '-lorem.txt';

        $data = \file_get_contents(__DIR__ . '/loremipsum.txt');

        $folder = $this->root->createFolder($folderName);
        $file1 = $folder->createFile($file1Name, $data);
        $this->assertEquals(\md5($data), $file1->getHash());
        $this->assertEquals(\strlen($data), $file1->getSize());

        $this->assertEquals(
            \substr($data, 50, 100),
            \stream_get_contents($this->driver->downloadFile($file1, 50, 100))
        );

        $this->assertEquals(
            \substr($data, \strlen($data)-934),
            \stream_get_contents($this->driver->downloadFile($file1, \strlen($data)-934))
        );
    }


    public function testMoveRenameEntry()
    {
        $folder1Name = __FUNCTION__ . '-folder1';
        $folder2Name = __FUNCTION__ . '-dir2';
        $folder3Name = __FUNCTION__ . '-folder3';
        $folder4Name = __FUNCTION__ . '-dir4';
        $folder5Name = __FUNCTION__ . '-folder5';
        $folder6Name = __FUNCTION__ . '-dir6';

        // Verify the folder is renamed and has the same ID but another name
        $folder1 = $this->root->createFolder($folder1Name);
        $this->assertEquals($folder1Name, $folder1->getName());
        $folder2 = $folder1->rename($folder2Name);
        $this->assertEquals($folder2Name, $folder2->getName());
        $this->assertEquals($folder1->getId(), $folder2->getId());

        $folder3 = $this->root->createFolder($folder3Name);
        // Verify renaming to former name of folder1 is possible
        $folder3b = $folder3->rename($folder1Name);
        $this->assertEquals($folder3->getId(), $folder3b->getId());
        $this->assertEquals($folder1Name, $folder3->getName());
        $this->assertEquals($folder1Name, $folder3b->getName());

        // Verify renaming to existing name is not possible
        try {
            $folder3->rename($folder2Name);
        } catch (\InvalidArgumentException $e) {
            $this->assertInstanceOf(\InvalidArgumentException::class, $e);
            $this->assertContains("Rename target", $e->getMessage());
            $this->assertContains("already exists.", $e->getMessage());
        }

        // Verify renaming to existing name with $overwrite = true is possible
        $folder2b = $folder3->rename($folder2Name, true);
        $this->assertEquals($folder3->getId(), $folder2b->getId());
        $this->assertEquals($folder2Name, $folder2b->getName());
        $this->assertNull($this->driver->getEntryById($folder1->getId()));

        $folder1 = $this->root->createFolder($folder1Name);
        $folder4 = $folder1->createFolder($folder4Name);

        // Verify replacing a non-empty folder is not possible even with $overwrite = true
        try {
            $folder3->rename($folder1Name);
        } catch (\InvalidArgumentException $e) {
            $this->assertInstanceOf(\InvalidArgumentException::class, $e);
            $this->assertContains("Can not overwrite non-empty folder", $e->getMessage());
        }

        $folder5 = $this->root->createFolder($folder5Name);
        $this->assertEquals($folder5Name, $folder5->getName());
        $this->assertEquals($this->root->getId(), $folder5->getParent()->getId());

        // Move a folder into another
        $folder5b = $folder5->move($folder1);
        $this->assertEquals($folder5Name, $folder5->getName());
        $this->assertEquals($folder1->getId(), $folder5b->getParent()->getId());

        // Move folder and rename
        $folder6 = $folder5->move($this->root, $folder6Name);
        $this->assertEquals($folder5->getId(), $folder6->getId());
        $this->assertEquals($folder6Name, $folder6->getName());
        $this->assertEquals($this->root->getId(), $folder6->getParent()->getId());
    }
}
