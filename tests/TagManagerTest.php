<?php
    /**
     * Project Name:    Wingman Stasis - TagManager Tests
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
    use Wingman\Stasis\PathUtils;
    use Wingman\Stasis\TagManager;

    /**
     * Tests for the TagManager, covering configuration getters, tag registration (including
     * in-memory deduplication), full index rebuilds, and index synchronisation after cache
     * file deletion.  All tests use an isolated temporary directory and clean up via Cacher::clear().
     * @package Wingman\Stasis\Tests
     * @author Angel Politis <info@angelpolitis.com>
     * @since 1.0
     */
    class TagManagerTest extends Test {
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

        // ─── Getters ──────────────────────────────────────────────────────────

        #[Group("Getters")]
        #[Define(
            name: "getIndexExtension Returns A Non-Empty String",
            description: "getIndexExtension() returns the configured tag file extension, which is a non-empty string."
        )]
        public function testGetIndexExtensionReturnsString () : void {
            $ext = $this->cacher->getTagManager()->getIndexExtension();

            $this->assertTrue(is_string($ext) && $ext !== "", "getIndexExtension() should return a non-empty string.");
        }

        #[Group("Getters")]
        #[Define(
            name: "getIndexExtension Returns 'idx' By Default",
            description: "The default tag index extension is 'idx'."
        )]
        public function testGetIndexExtensionDefaultsToIdx () : void {
            $ext = $this->cacher->getTagManager()->getIndexExtension();

            $this->assertTrue($ext === "idx", "The default index extension should be 'idx'.");
        }

        #[Group("Getters")]
        #[Define(
            name: "getTagDirectory Returns A Non-Empty String",
            description: "getTagDirectory() returns the name of the directory used to store tag index files."
        )]
        public function testGetTagDirectoryReturnsString () : void {
            $dir = $this->cacher->getTagManager()->getTagDirectory();

            $this->assertTrue(is_string($dir) && $dir !== "", "getTagDirectory() should return a non-empty string.");
        }

        #[Group("Getters")]
        #[Define(
            name: "getTagDirectory Returns 'tags' By Default",
            description: "The default tag directory name is 'tags'."
        )]
        public function testGetTagDirectoryDefaultsToTags () : void {
            $dir = $this->cacher->getTagManager()->getTagDirectory();

            $this->assertTrue($dir === PathUtils::fix("tags"), "The default tag directory should be 'tags'.");
        }

        // ─── registerTags() ───────────────────────────────────────────────────

        #[Group("registerTags")]
        #[Define(
            name: "registerTags Creates A Tag Index File",
            description: "When an item is stored with a tag, a tag index file for that tag must exist within the tags directory."
        )]
        public function testRegisterTagsCreatesIndexFile () : void {
            $tagManager = $this->cacher->getTagManager();
            $adapter = $this->cacher->getAdapter();

            $this->cacher->set("tagged_item", "value", tags: ["category_x"]);

            $tagHash = hash($this->cacher->getHashingAlgorithm(), "category_x");
            $tagFile = $this->cacher->getAbsolutePath(
                PathUtils::join($tagManager->getTagDirectory(), "$tagHash.{$tagManager->getIndexExtension()}")
            );

            $this->assertTrue($adapter->exists($tagFile), "A tag index file should be created for tag 'category_x'.");
        }

        #[Group("registerTags")]
        #[Define(
            name: "registerTags Contains The Cache File Path In The Index",
            description: "The tag index file for a tag contains the relative path of the cache file associated with that tag."
        )]
        public function testRegisterTagsContainsCachePath () : void {
            $tagManager = $this->cacher->getTagManager();
            $adapter = $this->cacher->getAdapter();

            $this->cacher->set("findable", "value", tags: ["searchable"]);

            $tagHash = hash($this->cacher->getHashingAlgorithm(), "searchable");
            $tagFile = $this->cacher->getAbsolutePath(
                PathUtils::join($tagManager->getTagDirectory(), "$tagHash.{$tagManager->getIndexExtension()}")
            );

            $indexContents = $adapter->read($tagFile);

            $expectedPath = PathUtils::getRelativePath($this->cacher->getAbsolutePath(), $this->cacher->createPathFromKey("findable"));
            $this->assertTrue(strpos($indexContents, $expectedPath) !== false, "The tag index should contain the relative path of the cache file.");
        }

        #[Group("registerTags")]
        #[Define(
            name: "registerTags Deduplicates Entries In Standard Mode",
            description: "Storing the same key twice under the same tag should not duplicate the entry in the tag index file."
        )]
        public function testRegisterTagsDeduplicatesEntries () : void {
            $tagManager = $this->cacher->getTagManager();
            $adapter = $this->cacher->getAdapter();

            $this->cacher->set("deduplicated_key", "v1", tags: ["unique"]);
            $this->cacher->set("deduplicated_key", "v2", tags: ["unique"]);

            $tagHash = hash($this->cacher->getHashingAlgorithm(), "unique");
            $tagFile = $this->cacher->getAbsolutePath(
                PathUtils::join($tagManager->getTagDirectory(), "$tagHash.{$tagManager->getIndexExtension()}")
            );

            $rawLines = array_filter(explode(PHP_EOL, trim($adapter->read($tagFile))));
            $uniqueLines = array_unique($rawLines);

            $this->assertCount(count($uniqueLines), $rawLines, "The tag index must not contain duplicate entries for the same cache file.");
        }

        // ─── rebuildTagIndices() ──────────────────────────────────────────────

        #[Group("rebuildTagIndices")]
        #[Define(
            name: "rebuildTagIndices Returns Count Of Tagged Files",
            description: "rebuildTagIndices() returns the number of cache files that had at least one tag re-registered."
        )]
        public function testRebuildTagIndicesReturnsCount () : void {
            $this->cacher->set("t1", "v1", tags: ["group_1"]);
            $this->cacher->set("t2", "v2", tags: ["group_2"]);
            $this->cacher->set("no_tag", "v3");

            $count = $this->cacher->getTagManager()->rebuildTagIndices();

            $this->assertTrue($count === 2, "rebuildTagIndices() should return 2 for 2 tagged files.");
        }

        #[Group("rebuildTagIndices")]
        #[Define(
            name: "rebuildTagIndices Recreates Tag Index Files",
            description: "After rebuildTagIndices(), tag index files exist for every tag associated with current cache entries."
        )]
        public function testRebuildTagIndicesRecreatesIndexFiles () : void {
            $tagManager = $this->cacher->getTagManager();
            $adapter = $this->cacher->getAdapter();

            $this->cacher->set("rebuild_item", "value", tags: ["rebuild_tag"]);

            # Delete the tag directory manually to simulate corruption then rebuild.
            $tagDir = $this->cacher->getAbsolutePath($tagManager->getTagDirectory());
            $adapter->delete($tagDir);

            $tagManager->rebuildTagIndices();

            $tagHash = hash($this->cacher->getHashingAlgorithm(), "rebuild_tag");
            $tagFile = $this->cacher->getAbsolutePath(
                PathUtils::join($tagManager->getTagDirectory(), "$tagHash.{$tagManager->getIndexExtension()}")
            );

            $this->assertTrue($adapter->exists($tagFile), "rebuildTagIndices() should recreate the tag index file.");
        }

        #[Group("rebuildTagIndices")]
        #[Define(
            name: "rebuildTagIndices Returns Zero When No Tagged Entries Exist",
            description: "rebuildTagIndices() returns 0 when the cache contains no tagged entries."
        )]
        public function testRebuildTagIndicesReturnsZeroWhenNoTaggedEntries () : void {
            $this->cacher->set("untagged", "value");

            $count = $this->cacher->getTagManager()->rebuildTagIndices();

            $this->assertTrue($count === 0, "rebuildTagIndices() should return 0 when no entries have tags.");
        }

        // ─── synchroniseIndices() ─────────────────────────────────────────────

        #[Group("synchroniseIndices")]
        #[Define(
            name: "synchroniseIndices Returns Zero When All Paths Are Valid",
            description: "synchroniseIndices() returns 0 when no stale paths are found in any index file."
        )]
        public function testSynchroniseIndicesReturnsZeroWhenAllPathsValid () : void {
            $this->cacher->set("sync_item", "value", tags: ["sync_tag"]);

            $changed = $this->cacher->getTagManager()->synchroniseIndices([]);

            $this->assertTrue($changed === 0, "synchroniseIndices() should return 0 when all indexed paths still exist.");
        }

        #[Group("synchroniseIndices")]
        #[Define(
            name: "synchroniseIndices Removes Stale Paths Via knownDeleted",
            description: "synchroniseIndices() removes paths provided in the knownDeleted list and returns the count of modified indices."
        )]
        public function testSynchroniseIndicesRemovesStalePathsViaKnownDeleted () : void {
            $tagManager = $this->cacher->getTagManager();

            $this->cacher->set("deleted_item", "value", tags: ["stale_check"]);

            $cachePath = $this->cacher->createPathFromKey("deleted_item");
            $relativePath = PathUtils::getRelativePath($this->cacher->getAbsolutePath(), $cachePath);

            $changed = $tagManager->synchroniseIndices([$relativePath]);

            $this->assertTrue($changed >= 1, "synchroniseIndices() should report at least 1 modified index after receiving a knownDeleted path.");
        }

        #[Group("synchroniseIndices")]
        #[Define(
            name: "synchroniseIndices Removes Physically Missing Paths",
            description: "synchroniseIndices() detects and removes entries for files that are no longer present on disk."
        )]
        public function testSynchroniseIndicesRemovesPhysicallyMissingPaths () : void {
            $tagManager = $this->cacher->getTagManager();
            $adapter = $this->cacher->getAdapter();

            $this->cacher->set("ghost_item", "value", tags: ["ghost_tag"]);

            # Delete the physical cache file without updating the tag index.
            $cachePath = $this->cacher->createPathFromKey("ghost_item");
            $adapter->delete($cachePath);

            $changed = $tagManager->synchroniseIndices([]);

            $this->assertTrue($changed >= 1, "synchroniseIndices() should detect and clean up entries for files no longer on disk.");
        }

        #[Group("synchroniseIndices")]
        #[Define(
            name: "synchroniseIndices Returns Zero When No Tag Directory Exists",
            description: "synchroniseIndices() returns 0 immediately when the tags directory has not been created yet."
        )]
        public function testSynchroniseIndicesReturnsZeroWhenTagDirectoryMissing () : void {
            $changed = $this->cacher->getTagManager()->synchroniseIndices([]);

            $this->assertTrue($changed === 0, "synchroniseIndices() should return 0 when the tag directory does not exist.");
        }
    }
?>
