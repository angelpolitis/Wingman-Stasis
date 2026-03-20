<?php
    /**
     * Project Name:    Wingman Stasis - LocalAdapter Tests
     * Created by:      Angel Politis
     * Creation Date:   Mar 20 2026
     * Last Modified:   Mar 20 2026
     * 
     * Copyright (c) 2026-2026 Angel Politis <info@angelpolitis.com>
     * This Source Code Form is subject to the terms of the Mozilla Public License, v. 2.0.
     * If a copy of the MPL was not distributed with this file, You can obtain one at http://mozilla.org/MPL/2.0/.
     */

    # Use the Stasis.Tests namespace.
    namespace Wingman\Stasis\Tests;

    # Import the following classes to the current scope.
    use Wingman\Argus\Attributes\Define;
    use Wingman\Argus\Attributes\Group;
    use Wingman\Argus\Test;
    use Wingman\Stasis\Adapters\LocalAdapter;

    /**
     * Tests for the LocalAdapter, verifying all filesystem CRUD operations, locking
     * primitives, directory listing, and empty-directory pruning against a temporary
     * directory that is fully cleaned up after every test method.
     * @package Wingman\Stasis\Tests
     * @author Angel Politis <info@angelpolitis.com>
     * @since 1.0
     */
    class LocalAdapterTest extends Test {
        /** @var LocalAdapter The adapter instance used across all tests. */
        private LocalAdapter $adapter;

        /** @var string Root of the isolated temporary directory for each test. */
        private string $tmpDir;

        /**
         * Creates a fresh temporary directory and a new LocalAdapter before each test.
         */
        public function setUp () : void {
            $this->tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . "stasis_adapter_" . uniqid();
            mkdir($this->tmpDir, 0755, true);
            $this->adapter = new LocalAdapter();
        }

        /**
         * Recursively deletes the temporary directory after each test.
         */
        public function tearDown () : void {
            $this->adapter->delete($this->tmpDir);
        }

        // ─── write() ─────────────────────────────────────────────────────────

        #[Group("write")]
        #[Define(
            name: "write Creates File With Correct Contents",
            description: "write() creates the target file and writes the supplied string verbatim."
        )]
        public function testWriteCreatesFileWithCorrectContents () : void {
            $path = $this->tmpDir . "/cache/test.txt";
            $result = $this->adapter->write($path, "hello world");

            $this->assertTrue($result, "write() should return true on success.");
            $this->assertTrue(file_exists($path), "The target file should exist after write().");
            $this->assertTrue(file_get_contents($path) === "hello world", "File contents should match the written value.");
        }

        #[Group("write")]
        #[Define(
            name: "write Creates Missing Parent Directories",
            description: "write() automatically creates any missing parent directories before writing."
        )]
        public function testWriteCreatesParentDirectories () : void {
            $path = $this->tmpDir . "/a/b/c/file.txt";

            $this->adapter->write($path, "data");

            $this->assertTrue(is_dir($this->tmpDir . "/a/b/c"), "Parent directories should be created automatically.");
        }

        #[Group("write")]
        #[Define(
            name: "write Overwrites Existing File",
            description: "write() replaces the contents of an already-existing file."
        )]
        public function testWriteOverwritesExistingFile () : void {
            $path = $this->tmpDir . "/overwrite.txt";
            $this->adapter->write($path, "original");
            $this->adapter->write($path, "updated");

            $this->assertTrue(file_get_contents($path) === "updated", "write() should overwrite the previous contents.");
        }

        // ─── read() ───────────────────────────────────────────────────────────

        #[Group("read")]
        #[Define(
            name: "read Returns Written Contents",
            description: "read() returns the exact string that was previously written to the file."
        )]
        public function testReadReturnsWrittenContents () : void {
            $path = $this->tmpDir . "/readable.txt";
            file_put_contents($path, "test content");

            $result = $this->adapter->read($path);

            $this->assertTrue($result === "test content", "read() should return the file contents verbatim.");
        }

        // ─── exists() ─────────────────────────────────────────────────────────

        #[Group("exists")]
        #[Define(
            name: "exists Returns True For Existing File",
            description: "exists() returns true when the path points to an existing file."
        )]
        public function testExistsReturnsTrueForFile () : void {
            $path = $this->tmpDir . "/exists.txt";
            file_put_contents($path, "");

            $this->assertTrue($this->adapter->exists($path), "exists() should return true for an existing file.");
        }

        #[Group("exists")]
        #[Define(
            name: "exists Returns True For Existing Directory",
            description: "exists() returns true when the path points to an existing directory."
        )]
        public function testExistsReturnsTrueForDirectory () : void {
            $this->assertTrue($this->adapter->exists($this->tmpDir), "exists() should return true for an existing directory.");
        }

        #[Group("exists")]
        #[Define(
            name: "exists Returns False For Missing Path",
            description: "exists() returns false when the path does not exist on the filesystem."
        )]
        public function testExistsReturnsFalseForMissingPath () : void {
            $path = $this->tmpDir . "/nonexistent_" . uniqid() . ".txt";

            $this->assertTrue(!$this->adapter->exists($path), "exists() should return false for a missing path.");
        }

        // ─── delete() ─────────────────────────────────────────────────────────

        #[Group("delete")]
        #[Define(
            name: "delete Removes An Existing File",
            description: "delete() unlinks an existing file and returns true."
        )]
        public function testDeleteRemovesExistingFile () : void {
            $path = $this->tmpDir . "/to-delete.txt";
            file_put_contents($path, "bye");

            $result = $this->adapter->delete($path);

            $this->assertTrue($result, "delete() should return true for a file that existed.");
            $this->assertTrue(!file_exists($path), "The file should no longer exist after delete().");
        }

        #[Group("delete")]
        #[Define(
            name: "delete Returns True For Non-Existent Path",
            description: "delete() is idempotent: it returns true even when the target path does not exist."
        )]
        public function testDeleteReturnsTrueForNonExistentPath () : void {
            $path = $this->tmpDir . "/ghost_" . uniqid() . ".txt";

            $result = $this->adapter->delete($path);

            $this->assertTrue($result, "delete() should return true when the path does not exist.");
        }

        #[Group("delete")]
        #[Define(
            name: "delete Recursively Removes A Directory",
            description: "delete() removes an entire directory tree and returns true."
        )]
        public function testDeleteRecursivelyRemovesDirectory () : void {
            $dir = $this->tmpDir . "/subtree";
            mkdir($dir . "/nested", 0755, true);
            file_put_contents($dir . "/nested/file.txt", "data");

            $result = $this->adapter->delete($dir);

            $this->assertTrue($result, "delete() should return true after removing the directory.");
            $this->assertTrue(!is_dir($dir), "The directory tree should no longer exist after delete().");
        }

        // ─── append() ─────────────────────────────────────────────────────────

        #[Group("append")]
        #[Define(
            name: "append Creates File When It Does Not Exist",
            description: "append() creates the target file (and parent directories) when called on a missing path."
        )]
        public function testAppendCreatesFilenWhenMissing () : void {
            $path = $this->tmpDir . "/new/appended.txt";

            $result = $this->adapter->append($path, "first line\n");

            $this->assertTrue($result, "append() should return true when creating a new file.");
            $this->assertTrue(file_exists($path), "append() should create the file if it was missing.");
        }

        #[Group("append")]
        #[Define(
            name: "append Appends Content To An Existing File",
            description: "append() adds the new content after what is already stored without overwriting."
        )]
        public function testAppendAppendsToExistingFile () : void {
            $path = $this->tmpDir . "/appendable.txt";
            file_put_contents($path, "line1\n");

            $this->adapter->append($path, "line2\n");

            $contents = file_get_contents($path);
            $this->assertTrue($contents === "line1\nline2\n", "append() should not overwrite existing content.");
        }

        // ─── list() ───────────────────────────────────────────────────────────

        #[Group("list")]
        #[Define(
            name: "list Returns All Files Recursively",
            description: "list() recursively scans a directory and returns the absolute path of every file found."
        )]
        public function testListReturnsAllFilesRecursively () : void {
            $dir = $this->tmpDir . "/listing";
            mkdir($dir . "/sub", 0755, true);
            file_put_contents($dir . "/a.txt", "");
            file_put_contents($dir . "/sub/b.txt", "");

            $files = $this->adapter->list($dir);

            $this->assertCount(2, $files, "list() should return exactly two files for this tree.");
        }

        #[Group("list")]
        #[Define(
            name: "list Returns Empty Array For Missing Directory",
            description: "list() returns an empty array when the supplied path does not exist."
        )]
        public function testListReturnsEmptyForMissingDirectory () : void {
            $missing = $this->tmpDir . "/ghost_dir_" . uniqid();
            $files = $this->adapter->list($missing);

            $this->assertCount(0, $files, "list() should return [] for a missing directory.");
        }

        // ─── acquireLock() & releaseLock() ────────────────────────────────────

        #[Group("Locking")]
        #[Define(
            name: "acquireLock Returns True On Success",
            description: "acquireLock() returns true when the lock file can be created and the exclusive lock obtained."
        )]
        public function testAcquireLockReturnsTrue () : void {
            $key = "stasis_lock_test_" . uniqid();
            $acquired = $this->adapter->acquireLock($key);

            $this->assertTrue($acquired, "acquireLock() should return true when the lock is granted.");

            $this->adapter->releaseLock($key);
        }

        #[Group("Locking")]
        #[Define(
            name: "releaseLock Returns True After Successful Acquisition",
            description: "releaseLock() returns true when releasing a lock that was previously acquired by this adapter."
        )]
        public function testReleaseLockReturnsTrueAfterAcquire () : void {
            $key = "stasis_release_test_" . uniqid();
            $this->adapter->acquireLock($key);

            $result = $this->adapter->releaseLock($key);

            $this->assertTrue($result, "releaseLock() should return true for a lock held by this adapter.");
        }

        #[Group("Locking")]
        #[Define(
            name: "releaseLock Returns False When Lock Not Held",
            description: "releaseLock() returns false when called for a key whose lock was never acquired by this adapter."
        )]
        public function testReleaseLockReturnsFalseWhenNotHeld () : void {
            $key = "stasis_unheld_lock_" . uniqid();
            $result = $this->adapter->releaseLock($key);

            $this->assertTrue(!$result, "releaseLock() should return false when the lock is not held.");
        }

        // ─── pruneEmptyDirectories() ──────────────────────────────────────────

        #[Group("pruneEmptyDirectories")]
        #[Define(
            name: "pruneEmptyDirectories Removes Empty Leaf Directories",
            description: "pruneEmptyDirectories() deletes directories that contain no files and returns the count removed."
        )]
        public function testPruneEmptyDirectoriesRemovesEmptyLeaves () : void {
            $root = $this->tmpDir . "/prune";
            mkdir($root . "/empty_a", 0755, true);
            mkdir($root . "/empty_b", 0755, true);

            $removed = $this->adapter->pruneEmptyDirectories($root);

            $this->assertTrue($removed >= 2, "pruneEmptyDirectories() should remove both empty directories.");
            $this->assertTrue(!is_dir($root . "/empty_a"), "empty_a should have been removed.");
            $this->assertTrue(!is_dir($root . "/empty_b"), "empty_b should have been removed.");
        }

        #[Group("pruneEmptyDirectories")]
        #[Define(
            name: "pruneEmptyDirectories Preserves Non-Empty Directories",
            description: "pruneEmptyDirectories() does not remove a directory that still contains files."
        )]
        public function testPrunePreservesNonEmptyDirectories () : void {
            $root = $this->tmpDir . "/keepme";
            mkdir($root, 0755, true);
            file_put_contents($root . "/data.txt", "keep");

            $removed = $this->adapter->pruneEmptyDirectories($this->tmpDir . "/keepme");

            $this->assertTrue(is_dir($root), "Non-empty directory should not be removed by pruneEmptyDirectories().");
        }

        #[Group("pruneEmptyDirectories")]
        #[Define(
            name: "pruneEmptyDirectories Respects Excluded Basenames",
            description: "A directory whose basename appears in the exclusion list is skipped even if it is empty."
        )]
        public function testPruneRespectsExcludedBasenames () : void {
            $root = $this->tmpDir . "/prune_exclude";
            mkdir($root . "/excluded", 0755, true);
            mkdir($root . "/removable", 0755, true);

            $this->adapter->pruneEmptyDirectories($root, ["excluded"]);

            $this->assertTrue(is_dir($root . "/excluded"), "Excluded directory should survive pruning.");
            $this->assertTrue(!is_dir($root . "/removable"), "Non-excluded empty directory should be pruned.");
        }
    }
?>
