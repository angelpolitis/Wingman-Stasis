<?php
    /**
     * Project Name:    Wingman Stasis - Cache Tests
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
    use DateTimeImmutable;
    use DateTimeInterface;
    use JsonSerializable;
    use Wingman\Argus\Attributes\Define;
    use Wingman\Argus\Attributes\Group;
    use Wingman\Argus\Test;
    use Wingman\Stasis\Cache;

    /**
     * Comprehensive tests for the `Cache` value object, covering construction,
     * all public accessors, TTL semantics (including the TTL=0 never-expire sentinel),
     * freshness checks, tag membership, serialisation round-trips, and JSON output.
     * @package Wingman\Stasis\Tests
     * @author Angel Politis <info@angelpolitis.com>
     * @since 1.0
     */
    class CacheTest extends Test {
        // ─── Construction ────────────────────────────────────────────────────

        #[Group("Construction")]
        #[Define(
            name: "Constructor Stores All Arguments",
            description: "All constructor arguments (location, content, TTL, tags, metadata, creation date) are correctly stored and retrievable."
        )]
        public function testConstructionWithAllArgs () : void {
            $date = new DateTimeImmutable("2026-01-15 12:00:00");
            $cache = new Cache("some/path.cache", ["key" => "value"], 3600, ["tag1", "tag2"], ["source" => "test"], $date);

            $this->assertTrue($cache->getLocation() === "some/path.cache", "Location should match constructor argument.");
            $this->assertTrue($cache->getContent() === ["key" => "value"], "Content should match constructor argument.");
            $this->assertTrue($cache->getTTL() === 3600, "TTL should match constructor argument.");
            $this->assertTrue($cache->getTags() === ["tag1", "tag2"], "Tags should match constructor argument.");
            $this->assertTrue($cache->getMetadata() === ["source" => "test"], "Metadata should match constructor argument.");
            $this->assertTrue($cache->getCreationDate()->format(DateTimeInterface::ATOM) === $date->format(DateTimeInterface::ATOM), "Creation date should match constructor argument.");
        }

        #[Group("Construction")]
        #[Define(
            name: "Default Creation Date Is Now",
            description: "When no creation date is supplied, the cache uses the current datetime."
        )]
        public function testDefaultCreationDateIsNow () : void {
            $before = new DateTimeImmutable();
            $cache = new Cache("path.cache", "value", 3600);
            $after = new DateTimeImmutable();

            $ts = $cache->getCreationDate()->getTimestamp();

            $this->assertTrue(
                $ts >= $before->getTimestamp() && $ts <= $after->getTimestamp(),
                "Default creation date should be within the window of construction."
            );
        }

        #[Group("Construction")]
        #[Define(
            name: "Empty Tags And Metadata Default To Empty Arrays",
            description: "When tags and metadata are omitted, getTags() and getMetadata() return empty arrays."
        )]
        public function testEmptyTagsAndMetadataDefaultToArrays () : void {
            $cache = new Cache("path.cache", "value", 3600);

            $this->assertTrue($cache->getTags() === [], "Tags should default to an empty array.");
            $this->assertTrue($cache->getMetadata() === [], "Metadata should default to an empty array.");
        }

        #[Group("Construction")]
        #[Define(
            name: "Creation Date Returned As DateTimeImmutable",
            description: "getCreationDate() always returns a DateTimeImmutable regardless of the type passed to the constructor."
        )]
        public function testCreationDateIsImmutable () : void {
            $cache = new Cache("path.cache", "value", 3600, [], [], new DateTimeImmutable());

            $this->assertInstanceOf(DateTimeImmutable::class, $cache->getCreationDate(), "getCreationDate() should return a DateTimeImmutable.");
        }

        // ─── TTL Semantics ───────────────────────────────────────────────────

        #[Group("TTL Semantics")]
        #[Define(
            name: "Positive TTL Produces Future Expiry Date",
            description: "getExpiryDate() returns a date in the future equal to creation date plus TTL seconds."
        )]
        public function testPositiveTtlExpiryDate () : void {
            $date = new DateTimeImmutable("2026-01-01 00:00:00");
            $cache = new Cache("path.cache", "value", 3600, [], [], $date);

            $expected = $date->modify("+3600 seconds");
            $this->assertTrue(
                $cache->getExpiryDate()->getTimestamp() === $expected->getTimestamp(),
                "Expiry date should be creation date plus TTL."
            );
        }

        #[Group("TTL Semantics")]
        #[Define(
            name: "Zero TTL Returns Max Datetime Sentinel",
            description: "getExpiryDate() returns a datetime of 9999-12-31 when TTL is 0 (never-expire sentinel)."
        )]
        public function testZeroTtlExpiryDateIsSentinel () : void {
            $cache = new Cache("path.cache", "value", 0);

            $this->assertTrue(
                $cache->getExpiryDate()->format("Y") === "9999",
                "TTL=0 expiry date year should be 9999."
            );
        }

        #[Group("TTL Semantics")]
        #[Define(
            name: "Positive TTL Expiry Timestamp Is Epoch-Based",
            description: "getExpiryTimestamp() returns a Unix timestamp matching creation date plus TTL."
        )]
        public function testPositiveTtlExpiryTimestamp () : void {
            $date = new DateTimeImmutable("2026-01-01 00:00:00");
            $cache = new Cache("path.cache", "value", 3600, [], [], $date);

            $expected = $date->getTimestamp() + 3600;
            $this->assertTrue($cache->getExpiryTimestamp() === $expected, "Expiry timestamp should equal creation timestamp plus TTL.");
        }

        #[Group("TTL Semantics")]
        #[Define(
            name: "Zero TTL Expiry Timestamp Is PHP_INT_MAX",
            description: "getExpiryTimestamp() returns PHP_INT_MAX when TTL is 0 so that the never-expire condition holds under all time comparisons."
        )]
        public function testZeroTtlExpiryTimestampIsMax () : void {
            $cache = new Cache("path.cache", "value", 0);

            $this->assertTrue($cache->getExpiryTimestamp() === PHP_INT_MAX, "TTL=0 expiry timestamp should be PHP_INT_MAX.");
        }

        // ─── Freshness ───────────────────────────────────────────────────────

        #[Group("Freshness")]
        #[Define(
            name: "Fresh Entry Is Fresh",
            description: "A newly-created cache with a long TTL is considered fresh immediately after construction."
        )]
        public function testFreshEntryIsFresh () : void {
            $cache = new Cache("path.cache", "value", 3600);

            $this->assertTrue($cache->isFresh(), "A newly created entry should be fresh.");
            $this->assertTrue(!$cache->isExpired(), "A newly created entry should not be expired.");
        }

        #[Group("Freshness")]
        #[Define(
            name: "Expired Entry Is Not Fresh",
            description: "An entry whose creation date is older than its TTL is not fresh and is considered expired."
        )]
        public function testExpiredEntryIsNotFresh () : void {
            $pastDate = new DateTimeImmutable("-2 hours");
            $cache = new Cache("path.cache", "value", 3600, [], [], $pastDate);

            $this->assertTrue(!$cache->isFresh(), "An entry older than its TTL should not be fresh.");
            $this->assertTrue($cache->isExpired(), "An entry older than its TTL should be expired.");
        }

        #[Group("Freshness")]
        #[Define(
            name: "Zero TTL Entry Is Always Fresh",
            description: "An entry with TTL=0 is permanently fresh regardless of how much time has passed."
        )]
        public function testZeroTtlIsAlwaysFresh () : void {
            $veryOldDate = new DateTimeImmutable("1970-01-01 00:00:00");
            $cache = new Cache("path.cache", "value", 0, [], [], $veryOldDate);

            $this->assertTrue($cache->isFresh(), "TTL=0 entry should always be fresh.");
            $this->assertTrue(!$cache->isExpired(), "TTL=0 entry should never be expired.");
        }

        #[Group("Freshness")]
        #[Define(
            name: "isFresh Accepts A Reference Date",
            description: "isFresh() evaluates freshness against a caller-supplied reference date rather than 'now'."
        )]
        public function testIsFreshAcceptsReferenceDate () : void {
            $creation = new DateTimeImmutable("2026-01-01 00:00:00");
            $cache = new Cache("path.cache", "value", 3600, [], [], $creation);

            $withinTtl = new DateTimeImmutable("2026-01-01 00:30:00");
            $beyondTtl = new DateTimeImmutable("2026-01-01 01:30:00");

            $this->assertTrue($cache->isFresh($withinTtl), "Entry should be fresh 30 minutes after creation with 1-hour TTL.");
            $this->assertTrue(!$cache->isFresh($beyondTtl), "Entry should not be fresh 90 minutes after creation with 1-hour TTL.");
        }

        #[Group("Freshness")]
        #[Define(
            name: "isFresh Accepts A Date String",
            description: "isFresh() accepts a parseable date/time string as its reference point."
        )]
        public function testIsFreshAcceptsDateString () : void {
            $creation = new DateTimeImmutable("2026-01-01 00:00:00");
            $cache = new Cache("path.cache", "value", 3600, [], [], $creation);

            $this->assertTrue($cache->isFresh("2026-01-01 00:59:00"), "Entry should be fresh 59 minutes in using a string reference.");
        }

        // ─── Remaining Time ──────────────────────────────────────────────────

        #[Group("Remaining Time")]
        #[Define(
            name: "Remaining Time Decreases Over Time",
            description: "getRemainingTime() returns the number of seconds left before the entry expires at a given reference point."
        )]
        public function testRemainingTimeDecreases () : void {
            $creation = new DateTimeImmutable("2026-01-01 00:00:00");
            $cache = new Cache("path.cache", "value", 3600, [], [], $creation);

            $halfway = new DateTimeImmutable("2026-01-01 00:30:00");
            $this->assertTrue($cache->getRemainingTime($halfway) === 1800, "Remaining time should be 1800 seconds at the halfway point.");
        }

        #[Group("Remaining Time")]
        #[Define(
            name: "Remaining Time Is Zero For Expired Entry",
            description: "getRemainingTime() returns 0 when the entry has expired — it never goes negative."
        )]
        public function testRemainingTimeIsZeroWhenExpired () : void {
            $oldDate = new DateTimeImmutable("-2 hours");
            $cache = new Cache("path.cache", "value", 3600, [], [], $oldDate);

            $this->assertTrue($cache->getRemainingTime() === 0, "Remaining time should be 0 for an expired entry.");
        }

        #[Group("Remaining Time")]
        #[Define(
            name: "Zero TTL Entry Has Remaining Time Of PHP_INT_MAX",
            description: "getRemainingTime() returns PHP_INT_MAX for TTL=0 (never-expire) entries, consistent with getExpiryTimestamp()."
        )]
        public function testZeroTtlRemainingTimeIsZero () : void {
            $cache = new Cache("path.cache", "value", 0);

            $this->assertTrue($cache->getRemainingTime() === PHP_INT_MAX, "TTL=0 entry should return PHP_INT_MAX remaining time.");
        }

        // ─── Tags ────────────────────────────────────────────────────────────

        #[Group("Tags")]
        #[Define(
            name: "hasTag Returns True For Existing Tag",
            description: "hasTag() returns true when the tag is present in the entry's tag list."
        )]
        public function testHasTagReturnsTrueForExistingTag () : void {
            $cache = new Cache("path.cache", "value", 3600, ["products", "homepage"]);

            $this->assertTrue($cache->hasTag("products"), "hasTag() should return true for a tag that was registered.");
        }

        #[Group("Tags")]
        #[Define(
            name: "hasTag Returns False For Missing Tag",
            description: "hasTag() returns false when the tag is not present in the entry's tag list."
        )]
        public function testHasTagReturnsFalseForMissingTag () : void {
            $cache = new Cache("path.cache", "value", 3600, ["products"]);

            $this->assertTrue(!$cache->hasTag("users"), "hasTag() should return false for a tag that was not registered.");
        }

        #[Group("Tags")]
        #[Define(
            name: "getTags Returns All Registered Tags",
            description: "getTags() returns the complete list of tags exactly as supplied to the constructor."
        )]
        public function testGetTagsReturnsAllTags () : void {
            $tags = ["products", "homepage", "featured"];
            $cache = new Cache("path.cache", "value", 3600, $tags);

            $this->assertTrue($cache->getTags() === $tags, "getTags() should return the same tag list passed to the constructor.");
        }

        // ─── JSON Serialisation ──────────────────────────────────────────────

        #[Group("JSON Serialisation")]
        #[Define(
            name: "Implements JsonSerializable",
            description: "Cache implements JsonSerializable so it can be embedded directly in json_encode() calls."
        )]
        public function testImplementsJsonSerializable () : void {
            $cache = new Cache("path.cache", "value", 3600);

            $this->assertImplements(JsonSerializable::class, $cache, "Cache should implement JsonSerializable.");
        }

        #[Group("JSON Serialisation")]
        #[Define(
            name: "jsonSerialize Returns Expected Keys",
            description: "jsonSerialize() must return an array with the canonical set of keys: location, content, metadata, tags, ttl, creation_date, expiry_date, is_fresh, remaining."
        )]
        public function testJsonSerializeContainsExpectedKeys () : void {
            $cache = new Cache("path.cache", "value", 3600);

            $data = $cache->jsonSerialize();

            foreach (["location", "content", "metadata", "tags", "ttl", "creation_date", "expiry_date", "is_fresh", "remaining"] as $key) {
                $this->assertArrayHasKey($key, $data, "jsonSerialize() output should contain key '$key'.");
            }
        }

        #[Group("JSON Serialisation")]
        #[Define(
            name: "jsonSerialize Reflects Correct Values",
            description: "The values in the jsonSerialize() output match the arguments provided to the constructor."
        )]
        public function testJsonSerializeReflectsCorrectValues () : void {
            $cache = new Cache("path.cache", "hello", 3600, ["tag1"], ["key" => 1]);

            $data = $cache->jsonSerialize();

            $this->assertTrue($data["location"] === "path.cache", "jsonSerialize location should match.");
            $this->assertTrue($data["content"] === "hello", "jsonSerialize content should match.");
            $this->assertTrue($data["ttl"] === 3600, "jsonSerialize ttl should match.");
            $this->assertTrue($data["tags"] === ["tag1"], "jsonSerialize tags should match.");
            $this->assertTrue($data["is_fresh"] === true, "A fresh entry should report is_fresh = true.");
        }

        // ─── PHP Serialisation Round-Trip ────────────────────────────────────

        #[Group("Serialisation Round-Trip")]
        #[Define(
            name: "Serialize And Unserialize Preserves Scalar Content",
            description: "A Cache object can be serialized to a string and deserialised back to an equivalent object with matching scalar content."
        )]
        public function testSerialiseRoundTripScalarContent () : void {
            $cache = new Cache("path.cache", "hello world", 3600, ["t"], ["k" => "v"]);

            $raw = serialize($cache);
            $restored = unserialize($raw, ["allowed_classes" => [Cache::class]]);

            $this->assertInstanceOf(Cache::class, $restored, "Deserialised value should be a Cache instance.");
            $this->assertTrue($restored->getContent() === "hello world", "Content should survive a serialise/unserialise round-trip.");
            $this->assertTrue($restored->getTTL() === 3600, "TTL should survive a serialise/unserialise round-trip.");
            $this->assertTrue($restored->getTags() === ["t"], "Tags should survive a serialise/unserialise round-trip.");
        }

        #[Group("Serialisation Round-Trip")]
        #[Define(
            name: "Serialize And Unserialize Preserves Object Content",
            description: "An object stored as cache content is correctly restored after a round-trip using the double-serialisation strategy."
        )]
        public function testSerialiseRoundTripObjectContent () : void {
            $payload = new \stdClass();
            $payload->name = "Alice";
            $payload->score = 99;

            $cache = new Cache("path.cache", $payload, 3600);

            $raw = serialize($cache);
            $restored = unserialize($raw, ["allowed_classes" => [Cache::class]]);

            $this->assertInstanceOf(\stdClass::class, $restored->getContent(), "Object content should be preserved after round-trip.");
            $this->assertTrue($restored->getContent()->name === "Alice", "Object content property should match after round-trip.");
        }

        #[Group("Serialisation Round-Trip")]
        #[Define(
            name: "Serialise With Restricted Allowed Classes Preserves Cache Envelope",
            description: "Restricting allowed_classes to [Cache::class] in unserialize() still correctly restores the Cache envelope and arbitrary object content via the double-serialisation strategy."
        )]
        public function testSerialiseWithRestrictedAllowedClasses () : void {
            $payload = new \stdClass();
            $payload->value = 42;

            $cache = new Cache("path.cache", $payload, 3600);

            $raw = serialize($cache);
            $restored = unserialize($raw, ["allowed_classes" => [Cache::class]]);

            $this->assertInstanceOf(Cache::class, $restored, "Cache envelope should be preserved with restricted allowed_classes.");
            $this->assertTrue($restored->getContent() instanceof \stdClass, "stdClass content should be preserved via double-serialisation.");
        }
    }
?>
