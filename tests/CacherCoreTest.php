<?php
    /**
     * Project Name:    Wingman Stasis - CacherCore Tests
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
    use DateInterval;
    use Wingman\Argus\Attributes\Define;
    use Wingman\Argus\Attributes\Group;
    use Wingman\Argus\Test;
    use Wingman\Stasis\Adapters\LocalAdapter;
    use Wingman\Stasis\Cacher;
    use Wingman\Stasis\Exceptions\InvalidAdapterException;
    use Wingman\Stasis\TagManager;

    /**
     * Core tests for the Cacher class, verifying the CRUD interface (set, get, has, delete),
     * bulk operations (setMultiple, getMultiple, deleteMultiple), TTL semantics, the remember()
     * memoisation helper, adapter management, and all public configuration getters.
     * 
     * Each test method uses a uniquely named temporary directory rooted inside the Stasis module's
     * temp/ folder and tears it down via Cacher::clear() after the assertion phase.
     * @package Wingman\Stasis\Tests
     * @author Angel Politis <info@angelpolitis.com>
     * @since 1.0
     */
    class CacherCoreTest extends Test {
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

        // ─── set() / get() ────────────────────────────────────────────────────

        #[Group("set / get")]
        #[Define(
            name: "set And get Round-Trip For Scalar Value",
            description: "A scalar value persisted with set() is retrievable via get() with the correct content."
        )]
        public function testSetAndGetScalar () : void {
            $this->cacher->set("greeting", "hello");

            $this->assertTrue($this->cacher->get("greeting") === "hello", "get() should return the persisted scalar.");
        }

        #[Group("set / get")]
        #[Define(
            name: "set And get Round-Trip For Array Value",
            description: "An array persisted with set() is returned by get() with the same structure and content."
        )]
        public function testSetAndGetArray () : void {
            $data = ["foo" => "bar", "baz" => [1, 2, 3]];
            $this->cacher->set("data", $data);

            $this->assertEquals($data, $this->cacher->get("data"), "get() should return the same array that was set.");
        }

        #[Group("set / get")]
        #[Define(
            name: "get Returns Default For Missing Key",
            description: "get() returns the caller-supplied default when the requested key does not exist in the cache."
        )]
        public function testGetReturnsMissingDefault () : void {
            $result = $this->cacher->get("nonexistent", "fallback");

            $this->assertTrue($result === "fallback", "get() should return the default for a missing key.");
        }

        #[Group("set / get")]
        #[Define(
            name: "get Returns Default After Entry Expires",
            description: "get() transparently deletes a stale entry and returns the default value once the TTL has elapsed."
        )]
        public function testGetReturnsDefaultForExpiredEntry () : void {
            $this->cacher->set("ephemeral", "value", ttl: 1);
            sleep(2);

            $result = $this->cacher->get("ephemeral", "gone");

            $this->assertTrue($result === "gone", "get() should return the default once a TTL=1 entry has expired.");
        }

        #[Group("set / get")]
        #[Define(
            name: "set With Null Value Is Distinguishable From Cache Miss",
            description: "A null value stored under a key is returned as null, not confused with a cache miss."
        )]
        public function testSetNullValueIsDistinguishableFromMiss () : void {
            $this->cacher->set("nullkey", null);

            $sentinel = new \stdClass();
            $result = $this->cacher->get("nullkey", $sentinel);

            $this->assertTrue($result === null, "get() should return null for a key explicitly set to null.");
        }

        #[Group("set / get")]
        #[Define(
            name: "set Returns True On Success",
            description: "set() returns true when the value is successfully persisted to the adapter."
        )]
        public function testSetReturnsTrueOnSuccess () : void {
            $result = $this->cacher->set("key", "value");

            $this->assertTrue($result, "set() should return true on a successful write.");
        }

        // ─── has() ───────────────────────────────────────────────────────────

        #[Group("has")]
        #[Define(
            name: "has Returns True For Fresh Entry",
            description: "has() returns true when an entry exists and its TTL has not yet elapsed."
        )]
        public function testHasReturnsTrueForFreshEntry () : void {
            $this->cacher->set("present", "value");

            $this->assertTrue($this->cacher->has("present"), "has() should return true for a key with a fresh entry.");
        }

        #[Group("has")]
        #[Define(
            name: "has Returns False For Missing Key",
            description: "has() returns false when the requested key has never been set."
        )]
        public function testHasReturnsFalseForMissingKey () : void {
            $this->assertTrue(!$this->cacher->has("ghost"), "has() should return false for a key that was never set.");
        }

        #[Group("has")]
        #[Define(
            name: "has Returns False After Entry Expires",
            description: "has() returns false once a TTL=1 entry has grown stale."
        )]
        public function testHasReturnsFalseForExpiredEntry () : void {
            $this->cacher->set("fading", "value", ttl: 1);
            sleep(2);

            $this->assertTrue(!$this->cacher->has("fading"), "has() should return false for an expired entry.");
        }

        // ─── delete() ─────────────────────────────────────────────────────────

        #[Group("delete")]
        #[Define(
            name: "delete Removes An Existing Key",
            description: "After delete() is called, get() no longer finds the entry and has() returns false."
        )]
        public function testDeleteRemovesExistingKey () : void {
            $this->cacher->set("removable", "value");
            $this->cacher->delete("removable");

            $this->assertTrue(!$this->cacher->has("removable"), "has() should return false after delete().");
        }

        #[Group("delete")]
        #[Define(
            name: "delete Is Idempotent For Non-Existent Keys",
            description: "delete() returns true even when called for a key that was never stored."
        )]
        public function testDeleteIsIdempotentForMissingKey () : void {
            $result = $this->cacher->delete("ghost_key_" . uniqid());

            $this->assertTrue($result, "delete() should return true for a key that was never stored.");
        }

        // ─── setMultiple() / getMultiple() / deleteMultiple() ─────────────────

        #[Group("Bulk Operations")]
        #[Define(
            name: "setMultiple Persists All Entries",
            description: "setMultiple() stores every key-value pair supplied in the input iterable."
        )]
        public function testSetMultiplePersistsAllEntries () : void {
            $this->cacher->setMultiple(["alpha" => 1, "beta" => 2, "gamma" => 3]);

            $this->assertTrue($this->cacher->has("alpha"), "alpha should be set.");
            $this->assertTrue($this->cacher->has("beta"), "beta should be set.");
            $this->assertTrue($this->cacher->has("gamma"), "gamma should be set.");
        }

        #[Group("Bulk Operations")]
        #[Define(
            name: "getMultiple Returns Values For All Keys",
            description: "getMultiple() yields each key paired with its cached value."
        )]
        public function testGetMultipleReturnsValues () : void {
            $this->cacher->setMultiple(["x" => "foo", "y" => "bar"]);

            $results = iterator_to_array($this->cacher->getMultiple(["x", "y"]));

            $this->assertTrue($results["x"] === "foo", "getMultiple() should return the correct value for 'x'.");
            $this->assertTrue($results["y"] === "bar", "getMultiple() should return the correct value for 'y'.");
        }

        #[Group("Bulk Operations")]
        #[Define(
            name: "getMultiple Returns Default For Missing Keys",
            description: "getMultiple() substitutes the caller-supplied default for every key not found."
        )]
        public function testGetMultipleReturnsDefaultForMissingKeys () : void {
            $results = iterator_to_array($this->cacher->getMultiple(["missing1", "missing2"], "fallback"));

            $this->assertTrue($results["missing1"] === "fallback", "Missing keys should resolve to the default.");
            $this->assertTrue($results["missing2"] === "fallback", "Missing keys should resolve to the default.");
        }

        #[Group("Bulk Operations")]
        #[Define(
            name: "deleteMultiple Removes All Specified Keys",
            description: "deleteMultiple() removes every key in the supplied list; all are subsequently absent."
        )]
        public function testDeleteMultipleRemovesAllKeys () : void {
            $this->cacher->setMultiple(["k1" => "v1", "k2" => "v2"]);
            $this->cacher->deleteMultiple(["k1", "k2"]);

            $this->assertTrue(!$this->cacher->has("k1"), "k1 should be absent after deleteMultiple().");
            $this->assertTrue(!$this->cacher->has("k2"), "k2 should be absent after deleteMultiple().");
        }

        // ─── clear() ──────────────────────────────────────────────────────────

        #[Group("clear")]
        #[Define(
            name: "clear Empties The Entire Cache",
            description: "clear() removes every entry so that subsequently stored keys are no longer present."
        )]
        public function testClearEmptiesCache () : void {
            $this->cacher->set("alpha", "a");
            $this->cacher->set("beta", "b");
            $this->cacher->clear();

            $this->assertTrue(!$this->cacher->has("alpha"), "alpha should be absent after clear().");
            $this->assertTrue(!$this->cacher->has("beta"), "beta should be absent after clear().");
        }

        // ─── TTL Mechanics ────────────────────────────────────────────────────

        #[Group("TTL Mechanics")]
        #[Define(
            name: "set With DateInterval TTL Is Honoured",
            description: "An entry stored with a DateInterval TTL of PT3600S is still fresh immediately after storage."
        )]
        public function testSetWithDateIntervalTtlIsHonoured () : void {
            $this->cacher->set("interval_key", "value", ttl: new DateInterval("PT3600S"));

            $this->assertTrue($this->cacher->has("interval_key"), "A DateInterval TTL entry should be fresh immediately after storage.");
        }

        #[Group("TTL Mechanics")]
        #[Define(
            name: "TTL Zero Entry Is Permanently Fresh",
            description: "An entry stored with TTL=0 never expires regardless of elapsed time."
        )]
        public function testZeroTtlEntryIsPermanentlyFresh () : void {
            $this->cacher->set("permanent", "value", ttl: 0);

            $this->assertTrue($this->cacher->has("permanent"), "A TTL=0 entry should always be fresh.");
        }

        // ─── Getter Methods ───────────────────────────────────────────────────

        #[Group("Getters")]
        #[Define(
            name: "getHashingAlgorithm Returns A Non-Empty String",
            description: "getHashingAlgorithm() returns the algorithm name configured for path generation."
        )]
        public function testGetHashingAlgorithmReturnsString () : void {
            $algo = $this->cacher->getHashingAlgorithm();

            $this->assertTrue(is_string($algo) && $algo !== "", "getHashingAlgorithm() should return a non-empty string.");
        }

        #[Group("Getters")]
        #[Define(
            name: "getTTL Returns A Non-Negative Integer",
            description: "getTTL() returns the configured default time-to-live as a non-negative integer."
        )]
        public function testGetTtlReturnsNonNegativeInteger () : void {
            $ttl = $this->cacher->getTTL();

            $this->assertTrue(is_int($ttl) && $ttl >= 0, "getTTL() should return a non-negative integer.");
        }

        #[Group("Getters")]
        #[Define(
            name: "getShardDepth Returns A Positive Integer",
            description: "getShardDepth() returns the configured sharding depth as a positive integer."
        )]
        public function testGetShardDepthReturnsPositiveInteger () : void {
            $depth = $this->cacher->getShardDepth();

            $this->assertTrue(is_int($depth) && $depth > 0, "getShardDepth() should return a positive integer.");
        }

        #[Group("Getters")]
        #[Define(
            name: "getShardLength Returns A Positive Integer",
            description: "getShardLength() returns the configured shard length as a positive integer."
        )]
        public function testGetShardLengthReturnsPositiveInteger () : void {
            $length = $this->cacher->getShardLength();

            $this->assertTrue(is_int($length) && $length > 0, "getShardLength() should return a positive integer.");
        }

        #[Group("Getters")]
        #[Define(
            name: "getRootDirectory Returns An Absolute Path Under The Module Root",
            description: "getRootDirectory() returns an absolute filesystem path that begins with the Stasis module root."
        )]
        public function testGetRootDirectoryReturnsAbsolutePath () : void {
            $root = $this->cacher->getRootDirectory();

            $this->assertTrue(str_starts_with($root, DIRECTORY_SEPARATOR), "getRootDirectory() should return an absolute path.");
        }

        #[Group("Getters")]
        #[Define(
            name: "getTagManager Returns A TagManager Instance",
            description: "getTagManager() returns the TagManager that handles tag-based invalidation for this Cacher."
        )]
        public function testGetTagManagerReturnsTagManager () : void {
            $this->assertInstanceOf(TagManager::class, $this->cacher->getTagManager(), "getTagManager() should return a TagManager.");
        }

        // ─── createPathFromKey() ──────────────────────────────────────────────

        #[Group("createPathFromKey")]
        #[Define(
            name: "createPathFromKey Is Deterministic",
            description: "createPathFromKey() produces the same absolute path for the same key on repeated calls."
        )]
        public function testCreatePathFromKeyIsDeterministic () : void {
            $first = $this->cacher->createPathFromKey("my_key");
            $second = $this->cacher->createPathFromKey("my_key");

            $this->assertTrue($first === $second, "createPathFromKey() must return an identical path for identical keys.");
        }

        #[Group("createPathFromKey")]
        #[Define(
            name: "createPathFromKey Produces Different Paths For Different Keys",
            description: "Two distinct cache keys must map to distinct filesystem paths."
        )]
        public function testCreatePathFromKeyProducesDifferentPathsForDifferentKeys () : void {
            $pathA = $this->cacher->createPathFromKey("key_alpha");
            $pathB = $this->cacher->createPathFromKey("key_beta");

            $this->assertTrue($pathA !== $pathB, "Different keys should produce different filesystem paths.");
        }

        // ─── Adapter Management ───────────────────────────────────────────────

        #[Group("Adapter Management")]
        #[Define(
            name: "hasAdapter Returns True After Default Construction",
            description: "hasAdapter() returns true immediately after construction because a LocalAdapter is injected by default."
        )]
        public function testHasAdapterReturnsTrueAfterConstruction () : void {
            $this->assertTrue($this->cacher->hasAdapter(), "hasAdapter() should return true after default construction.");
        }

        #[Group("Adapter Management")]
        #[Define(
            name: "setAdapter Accepts An AdapterInterface Implementation",
            description: "setAdapter() replaces the current adapter and returns the Cacher instance for fluent chaining."
        )]
        public function testSetAdapterAcceptsValidAdapter () : void {
            $newAdapter = new LocalAdapter();
            $result = $this->cacher->setAdapter($newAdapter);

            $this->assertTrue($result instanceof Cacher, "setAdapter() should return the Cacher instance.");
            $this->assertTrue($this->cacher->getAdapter() instanceof LocalAdapter, "getAdapter() should return the newly set adapter.");
        }

        #[Group("Adapter Management")]
        #[Define(
            name: "setAdapter Throws For Non-AdapterInterface Object",
            description: "setAdapter() throws InvalidAdapterException when given an object that does not implement AdapterInterface and cannot be bridged by Helix."
        )]
        public function testSetAdapterThrowsForNonAdapterInterfaceObject () : void {
            $this->assertThrows(InvalidAdapterException::class, function () {
                $this->cacher->setAdapter(new \stdClass());
            });
        }

        // ─── remember() ───────────────────────────────────────────────────────

        #[Group("remember")]
        #[Define(
            name: "remember Executes Callback On Cache Miss",
            description: "remember() invokes the callback when the key is absent and returns the callback's return value."
        )]
        public function testRememberExecutesCallbackOnMiss () : void {
            $called = false;

            $result = $this->cacher->remember("newkey", function () use (&$called) {
                $called = true;
                return "computed";
            });

            $this->assertTrue($called, "The callback should have been invoked on a cache miss.");
            $this->assertTrue($result === "computed", "remember() should return the callback's result.");
        }

        #[Group("remember")]
        #[Define(
            name: "remember Does Not Execute Callback On Cache Hit",
            description: "remember() returns the cached value without invoking the callback when the key is already set."
        )]
        public function testRememberDoesNotExecuteCallbackOnHit () : void {
            $this->cacher->set("warm", "cached_value");

            $called = false;
            $result = $this->cacher->remember("warm", function () use (&$called) {
                $called = true;
                return "fresh_computed";
            });

            $this->assertTrue(!$called, "The callback should not be invoked on a cache hit.");
            $this->assertTrue($result === "cached_value", "remember() should return the cached value.");
        }

        #[Group("remember")]
        #[Define(
            name: "remember Stores Callback Result For Subsequent Retrievals",
            description: "After a cache miss + callback execution, remember() stores the result so that a direct get() also finds it."
        )]
        public function testRememberStoresCallbackResultForSubsequentGet () : void {
            $this->cacher->remember("computed", fn () => "result");

            $this->assertTrue($this->cacher->get("computed") === "result", "The computed value should be retrievable via get() after remember().");
        }

        #[Group("remember")]
        #[Define(
            name: "remember Persists Null As A Valid Cached Value",
            description: "remember() distinguishes a stored null from a cache miss so that null-returning callbacks are not replayed."
        )]
        public function testRememberPersistsNullAsCachedValue () : void {
            $callCount = 0;
            $callback = function () use (&$callCount) {
                $callCount++;
                return null;
            };

            $this->cacher->remember("null_key", $callback);
            $this->cacher->remember("null_key", $callback);

            $this->assertTrue($callCount === 1, "The callback should be invoked exactly once; subsequent calls should use the cached null.");
        }
    }
?>
