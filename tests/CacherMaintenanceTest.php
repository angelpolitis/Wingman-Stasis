<?php
    /**
     * Project Name:    Wingman Stasis - CacherMaintenance Tests
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
    use Wingman\Stasis\Cacher;
    use Wingman\Stasis\Exceptions\NonNumericValueException;

    /**
     * Tests covering Cacher maintenance operations: integer counter increment/decrement,
     * garbage collection, registry rebuilding, empty-directory pruning, fingerprint generation,
     * and stats reporting.
     * 
     * Each test uses an isolated temporary directory and cleans up via Cacher::clear().
     * @package Wingman\Stasis\Tests
     * @author Angel Politis <info@angelpolitis.com>
     * @since 1.0
     */
    class CacherMaintenanceTest extends Test {
        /** @var Cacher Fresh instance for each test method. */
        private Cacher $cacher;

        /**
         * Creates a Cacher backed by a unique temp directory before each test.
         */
        public function setUp () : void {
            $this->cacher = new Cacher(root: "temp/tests/" . uniqid());
        }

        /**
         * Cleans up the temp directory after each test.
         */
        public function tearDown () : void {
            $this->cacher->clear();
        }

        // ─── increment() ──────────────────────────────────────────────────────

        #[Group("increment / decrement")]
        #[Define(
            name: "increment From Zero Returns One",
            description: "Calling increment() on a key that does not exist treats the current value as 0 and returns 1."
        )]
        public function testIncrementFromZeroReturnsOne () : void {
            $result = $this->cacher->increment("counter_new");

            $this->assertTrue($result === 1, "increment() on a missing key should initialise to 0 and return 1.");
        }

        #[Group("increment / decrement")]
        #[Define(
            name: "increment Adds The Step To An Existing Numeric Value",
            description: "increment() reads the current numeric value, adds the supplied step, and returns the new total."
        )]
        public function testIncrementAddsStepToExistingValue () : void {
            $this->cacher->set("page_views", 10);

            $result = $this->cacher->increment("page_views", 5);

            $this->assertTrue($result === 15, "increment() should add the step to the existing value.");
        }

        #[Group("increment / decrement")]
        #[Define(
            name: "increment Default Step Is One",
            description: "When no step is supplied, increment() uses a step of 1."
        )]
        public function testIncrementDefaultStepIsOne () : void {
            $this->cacher->set("visits", 7);

            $result = $this->cacher->increment("visits");

            $this->assertTrue($result === 8, "The default increment step should be 1.");
        }

        // ─── decrement() ──────────────────────────────────────────────────────

        #[Group("increment / decrement")]
        #[Define(
            name: "decrement From Zero Returns Negative One",
            description: "Calling decrement() on a missing key treats the current value as 0 and returns -1."
        )]
        public function testDecrementFromZeroReturnsNegativeOne () : void {
            $result = $this->cacher->decrement("counter_empty");

            $this->assertTrue($result === -1, "decrement() on a missing key should return -1.");
        }

        #[Group("increment / decrement")]
        #[Define(
            name: "decrement Subtracts The Step From An Existing Value",
            description: "decrement() reads the current numeric value, subtracts the supplied step, and returns the new total."
        )]
        public function testDecrementSubtractsStepFromExistingValue () : void {
            $this->cacher->set("tokens", 20);

            $result = $this->cacher->decrement("tokens", 8);

            $this->assertTrue($result === 12, "decrement() should subtract the step from the existing value.");
        }

        #[Group("increment / decrement")]
        #[Define(
            name: "increment Throws For Non-Numeric Stored Value",
            description: "increment() throws NonNumericValueException when the existing cached value cannot be interpreted as a number."
        )]
        public function testIncrementThrowsForNonNumericValue () : void {
            $this->cacher->set("name", "Alice");

            $this->assertThrows(NonNumericValueException::class, function () {
                $this->cacher->increment("name");
            });
        }

        #[Group("increment / decrement")]
        #[Define(
            name: "decrement Throws For Non-Numeric Stored Value",
            description: "decrement() throws NonNumericValueException when the existing cached value is not numeric."
        )]
        public function testDecrementThrowsForNonNumericValue () : void {
            $this->cacher->set("label", "pending");

            $this->assertThrows(NonNumericValueException::class, function () {
                $this->cacher->decrement("label");
            });
        }

        // ─── collectGarbage() ─────────────────────────────────────────────────

        #[Group("collectGarbage")]
        #[Define(
            name: "collectGarbage Returns Array With Expected Keys",
            description: "collectGarbage() always returns an array with the 'files', 'indices', and 'dirs' keys."
        )]
        public function testCollectGarbageReturnsExpectedKeys () : void {
            $stats = $this->cacher->collectGarbage();

            $this->assertArrayHasKey("files", $stats, "collectGarbage() result should contain 'files'.");
            $this->assertArrayHasKey("indices", $stats, "collectGarbage() result should contain 'indices'.");
            $this->assertArrayHasKey("dirs", $stats, "collectGarbage() result should contain 'dirs'.");
        }

        #[Group("collectGarbage")]
        #[Define(
            name: "collectGarbage Removes Expired Entries",
            description: "collectGarbage() deletes cache files whose TTL has elapsed and reports the count under the 'files' key."
        )]
        public function testCollectGarbageRemovesExpiredEntries () : void {
            $this->cacher->set("ephemeral_a", "val1", ttl: 1);
            $this->cacher->set("ephemeral_b", "val2", ttl: 1);
            sleep(2);

            $stats = $this->cacher->collectGarbage();

            $this->assertTrue($stats["files"] >= 2, "collectGarbage() should report at least 2 deleted files.");
            $this->assertTrue(!$this->cacher->has("ephemeral_a"), "Expired entry 'ephemeral_a' should no longer be accessible.");
            $this->assertTrue(!$this->cacher->has("ephemeral_b"), "Expired entry 'ephemeral_b' should no longer be accessible.");
        }

        #[Group("collectGarbage")]
        #[Define(
            name: "collectGarbage Preserves Non-Expired Entries",
            description: "collectGarbage() leaves entries whose TTL has not elapsed untouched."
        )]
        public function testCollectGarbagePreservesNonExpiredEntries () : void {
            $this->cacher->set("permanent", "value", ttl: 0);
            $this->cacher->set("long_lived", "value", ttl: 3600);

            $this->cacher->collectGarbage();

            $this->assertTrue($this->cacher->has("permanent"), "TTL=0 entry should survive collectGarbage().");
            $this->assertTrue($this->cacher->has("long_lived"), "Long-lived entry should survive collectGarbage().");
        }

        // ─── rebuildRegistry() ────────────────────────────────────────────────

        #[Group("rebuildRegistry")]
        #[Define(
            name: "rebuildRegistry Returns Count Of Indexed Items",
            description: "rebuildRegistry() scans the cache directory and returns the number of valid cache files indexed."
        )]
        public function testRebuildRegistryReturnsItemCount () : void {
            $this->cacher->set("r1", "val1");
            $this->cacher->set("r2", "val2");
            $this->cacher->set("r3", "val3");

            $count = $this->cacher->rebuildRegistry();

            $this->assertTrue($count === 3, "rebuildRegistry() should return 3 for a cache with 3 entries.");
        }

        #[Group("rebuildRegistry")]
        #[Define(
            name: "rebuildRegistry Returns Zero For Empty Cache",
            description: "rebuildRegistry() returns 0 when no cache files exist."
        )]
        public function testRebuildRegistryReturnsZeroForEmptyCache () : void {
            $count = $this->cacher->rebuildRegistry();

            $this->assertTrue($count === 0, "rebuildRegistry() should return 0 for an empty cache.");
        }

        #[Group("rebuildRegistry")]
        #[Define(
            name: "rebuildRegistry Is Idempotent",
            description: "Calling rebuildRegistry() multiple times on the same cache produces the same count."
        )]
        public function testRebuildRegistryIsIdempotent () : void {
            $this->cacher->set("stable_key", "stable_value");

            $first = $this->cacher->rebuildRegistry();
            $second = $this->cacher->rebuildRegistry();

            $this->assertTrue($first === $second, "rebuildRegistry() should produce the same count on repeated calls.");
        }

        // ─── pruneEmptyDirectories() ──────────────────────────────────────────

        #[Group("pruneEmptyDirectories")]
        #[Define(
            name: "pruneEmptyDirectories Returns A Non-Negative Integer",
            description: "pruneEmptyDirectories() always returns a non-negative integer representing the number of directories removed."
        )]
        public function testPruneEmptyDirectoriesReturnsNonNegativeInteger () : void {
            $this->cacher->set("entry", "value");
            $this->cacher->delete("entry");

            $removed = $this->cacher->pruneEmptyDirectories();

            $this->assertTrue(is_int($removed) && $removed >= 0, "pruneEmptyDirectories() should return a non-negative integer.");
        }

        #[Group("pruneEmptyDirectories")]
        #[Define(
            name: "pruneEmptyDirectories Reports Zero When Nothing To Prune",
            description: "pruneEmptyDirectories() returns 0 when all shard directories are non-empty."
        )]
        public function testPruneEmptyDirectoriesReturnsZeroWhenNothingToPrune () : void {
            $this->cacher->set("full", "value");

            $removed = $this->cacher->pruneEmptyDirectories();

            $this->assertTrue($removed === 0, "pruneEmptyDirectories() should report 0 when no empty directories exist.");
        }

        // ─── getStats() ───────────────────────────────────────────────────────

        #[Group("getStats")]
        #[Define(
            name: "getStats Returns The Minimum Required Keys",
            description: "getStats() returns an array that contains at minimum the keys: total_files, total_size, storage_root, adapter, status."
        )]
        public function testGetStatsReturnsRequiredKeys () : void {
            $this->cacher->set("data", "value");

            $stats = $this->cacher->getStats();

            foreach (["total_files", "total_size", "storage_root", "adapter", "status"] as $key) {
                $this->assertArrayHasKey($key, $stats, "getStats() result must contain the '$key' key.");
            }
        }

        #[Group("getStats")]
        #[Define(
            name: "getStats Reports Correct Total Files After Writes",
            description: "getStats()['total_files'] reflects the actual number of files present in the cache directory."
        )]
        public function testGetStatsReportsTotalFiles () : void {
            $this->cacher->set("f1", "v1");
            $this->cacher->set("f2", "v2");

            $stats = $this->cacher->getStats();

            $this->assertTrue($stats["total_files"] >= 2, "total_files should be at least 2 after writing 2 entries.");
        }

        #[Group("getStats")]
        #[Define(
            name: "getStats Reports Online Status When Cache Directory Exists",
            description: "getStats()['status'] is 'Online' when the cache root directory exists and is accessible."
        )]
        public function testGetStatsReportsOnlineStatus () : void {
            $this->cacher->set("online_check", "value");

            $stats = $this->cacher->getStats();

            $this->assertTrue($stats["status"] === "Online", "status should be 'Online' when the cache directory exists.");
        }

        // ─── getCacheFiles() ──────────────────────────────────────────────────

        #[Group("getCacheFiles")]
        #[Define(
            name: "getCacheFiles Returns Only Cache Files",
            description: "getCacheFiles() returns the paths of all .cache files, excluding tag index files."
        )]
        public function testGetCacheFilesReturnsOnlyCacheFiles () : void {
            $this->cacher->set("cf_item1", "v1");
            $this->cacher->set("cf_item2", "v2");

            $files = $this->cacher->getCacheFiles();

            $this->assertCount(2, $files, "getCacheFiles() should return exactly 2 files.");

            foreach ($files as $file) {
                $this->assertTrue(str_ends_with($file, ".cache"), "Every path returned by getCacheFiles() should end with .cache.");
            }
        }

        #[Group("getCacheFiles")]
        #[Define(
            name: "getCacheFiles Returns Empty Array Before Any Writes",
            description: "getCacheFiles() returns an empty array when no entries have been created yet."
        )]
        public function testGetCacheFilesReturnsEmptyArrayBeforeAnyWrites () : void {
            $files = $this->cacher->getCacheFiles();

            $this->assertCount(0, $files, "getCacheFiles() should return [] when the cache is empty.");
        }
    }
?>
