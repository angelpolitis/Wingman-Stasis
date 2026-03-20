<?php
    /**
     * Project Name:    Wingman Stasis - CacherTagging Tests
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

    /**
     * Tests for Cacher's tag-based invalidation surface: set() with tags, clearByTags(),
     * getItemsByTag(), and setMultiple() with a shared tag.  Each test uses an isolated
     * temporary directory and cleans up via Cacher::clear().
     * @package Wingman\Stasis\Tests
     * @author Angel Politis <info@angelpolitis.com>
     * @since 1.0
     */
    class CacherTaggingTest extends Test {
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

        // ─── clearByTags() ────────────────────────────────────────────────────

        #[Group("clearByTags")]
        #[Define(
            name: "clearByTags Removes Items Tagged With The Given Tag",
            description: "After clearByTags() is called, entries that were stored with the matching tag are no longer retrievable."
        )]
        public function testClearByTagsRemovesTaggedItems () : void {
            $this->cacher->set("item1", "val1", tags: ["group_a"]);
            $this->cacher->set("item2", "val2", tags: ["group_a"]);

            $this->cacher->clearByTags("group_a");

            $this->assertTrue(!$this->cacher->has("item1"), "item1 should be removed after clearByTags('group_a').");
            $this->assertTrue(!$this->cacher->has("item2"), "item2 should be removed after clearByTags('group_a').");
        }

        #[Group("clearByTags")]
        #[Define(
            name: "clearByTags Returns The Count Of Deleted Files",
            description: "clearByTags() returns the number of cache files that were actually deleted."
        )]
        public function testClearByTagsReturnsCount () : void {
            $this->cacher->set("a", "alpha", tags: ["batch"]);
            $this->cacher->set("b", "beta", tags: ["batch"]);
            $this->cacher->set("c", "gamma", tags: ["batch"]);

            $count = $this->cacher->clearByTags("batch");

            $this->assertTrue($count === 3, "clearByTags() should report 3 deleted files.");
        }

        #[Group("clearByTags")]
        #[Define(
            name: "clearByTags Does Not Affect Untagged Items",
            description: "Items that were not stored with the cleared tag remain present after clearByTags()."
        )]
        public function testClearByTagsDoesNotAffectUntaggedItems () : void {
            $this->cacher->set("tagged", "val", tags: ["removable"]);
            $this->cacher->set("untagged", "safe");

            $this->cacher->clearByTags("removable");

            $this->assertTrue($this->cacher->has("untagged"), "An untagged entry should survive clearByTags().");
        }

        #[Group("clearByTags")]
        #[Define(
            name: "clearByTags Accepts Multiple Tags At Once",
            description: "When an array of tags is supplied, all entries matching any of those tags are deleted."
        )]
        public function testClearByTagsAcceptsMultipleTags () : void {
            $this->cacher->set("x", "val_x", tags: ["tag_x"]);
            $this->cacher->set("y", "val_y", tags: ["tag_y"]);
            $this->cacher->set("both", "val_both", tags: ["tag_x", "tag_y"]);

            $count = $this->cacher->clearByTags(["tag_x", "tag_y"]);

            $this->assertTrue($count >= 2, "clearByTags() with multiple tags should clear at least as many items as the number of distinct tagged entries.");
            $this->assertTrue(!$this->cacher->has("x"), "Entry tagged with tag_x should be cleared.");
            $this->assertTrue(!$this->cacher->has("y"), "Entry tagged with tag_y should be cleared.");
        }

        #[Group("clearByTags")]
        #[Define(
            name: "clearByTags Returns Zero For Non-Existent Tag",
            description: "clearByTags() returns 0 when no entries have been stored under the supplied tag."
        )]
        public function testClearByTagsReturnsZeroForNonExistentTag () : void {
            $count = $this->cacher->clearByTags("phantom_tag_" . uniqid());

            $this->assertTrue($count === 0, "clearByTags() should return 0 when no entries carry the specified tag.");
        }

        #[Group("clearByTags")]
        #[Define(
            name: "clearByTags Preserves Items With Different Tags",
            description: "An entry tagged exclusively with tag_b is not affected when clearByTags() is called with tag_a."
        )]
        public function testClearByTagsPreservesItemsWithDifferentTags () : void {
            $this->cacher->set("keep_me", "value", tags: ["retain"]);
            $this->cacher->set("wipe_me", "value", tags: ["discard"]);

            $this->cacher->clearByTags("discard");

            $this->assertTrue($this->cacher->has("keep_me"), "Items tagged with 'retain' should not be affected by clearByTags('discard').");
        }

        // ─── getItemsByTag() ──────────────────────────────────────────────────

        #[Group("getItemsByTag")]
        #[Define(
            name: "getItemsByTag Yields Fresh Items Matching The Tag",
            description: "getItemsByTag() yields each fresh cache item that carries the requested tag."
        )]
        public function testGetItemsByTagYieldsFreshItems () : void {
            $this->cacher->set("item_one", "v1", tags: ["list_me"]);
            $this->cacher->set("item_two", "v2", tags: ["list_me"]);
            $this->cacher->set("other", "unrelated");

            $results = iterator_to_array($this->cacher->getItemsByTag("list_me"));

            $this->assertCount(2, $results, "getItemsByTag() should yield exactly 2 items tagged with 'list_me'.");
            $this->assertContains("v1", $results, "v1 should appear in the result set.");
            $this->assertContains("v2", $results, "v2 should appear in the result set.");
        }

        #[Group("getItemsByTag")]
        #[Define(
            name: "getItemsByTag Skips Expired Items",
            description: "getItemsByTag() does not yield entries whose TTL has elapsed."
        )]
        public function testGetItemsByTagSkipsExpiredItems () : void {
            $this->cacher->set("stale", "value", ttl: 1, tags: ["check_expiry"]);
            $this->cacher->set("fresh", "value", ttl: 0, tags: ["check_expiry"]);
            sleep(2);

            $results = iterator_to_array($this->cacher->getItemsByTag("check_expiry"));

            $this->assertCount(1, $results, "Only the non-expired entry should be yielded.");
        }

        #[Group("getItemsByTag")]
        #[Define(
            name: "getItemsByTag Returns Empty For Unknown Tag",
            description: "getItemsByTag() yields nothing when no entries carry the supplied tag."
        )]
        public function testGetItemsByTagReturnsEmptyForUnknownTag () : void {
            $this->cacher->set("item", "value");

            $results = iterator_to_array($this->cacher->getItemsByTag("unknown_tag_" . uniqid()));

            $this->assertCount(0, $results, "getItemsByTag() should yield an empty iterable for an unknown tag.");
        }

        // ─── setMultiple() With Tags ──────────────────────────────────────────

        #[Group("setMultiple with tags")]
        #[Define(
            name: "setMultiple Applies A Shared Tag To All Entries",
            description: "When a tag array is supplied to setMultiple(), every persisted entry is invalidated by that tag."
        )]
        public function testSetMultipleAppliesSharedTagToAllEntries () : void {
            $this->cacher->setMultiple(
                ["product_1" => "Alpha", "product_2" => "Beta", "product_3" => "Gamma"],
                tags: ["products"]
            );

            $count = $this->cacher->clearByTags("products");

            $this->assertTrue($count === 3, "All 3 entries set via setMultiple() should be invalidated by the shared tag.");
        }
    }
?>
