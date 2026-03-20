<?php
    /**
     * Project Name:    Wingman Stasis - Cache
     * Created by:      Angel Politis
     * Creation Date:   Nov 24 2025
     * Last Modified:   Mar 13 2026
     * 
     * Copyright (c) 2025-2026 Angel Politis <info@angelpolitis.com>
     * This Source Code Form is subject to the terms of the Mozilla Public License, v. 2.0.
     * If a copy of the MPL was not distributed with this file, You can obtain one at http://mozilla.org/MPL/2.0/.
     */

    # Use the Cacher namespace.
    namespace Wingman\Stasis;

    # Import the following classes to the current scope.
    use DateInterval;
    use DateTimeImmutable;
    use DateTimeInterface;
    use JsonSerializable;

    /**
     * Represents a cache file.
     * @package Wingman\Stasis
     * @author Angel Politis <info@angelpolitis.com>
     * @since 1.0
     */
    class Cache implements JsonSerializable {
        /**
         * The location of a cache file.
         * @var string
         */
        protected string $location;

        /**
         * The original user-facing key used to store this cache entry.
         * @var string
         */
        protected string $key = "";

        /**
         * The content of a cache file.
         * @var mixed
         */
        protected mixed $content;

        /**
         * The metadata of a cache file.
         * @var array
         */
        protected array $metadata = [];

        /**
         * The tags of a cache file.
         * @var array
         */
        protected array $tags = [];

        /**
         * The time-to-live in seconds of a cache file.
         * @var int
         */
        protected int $ttl = 0;

        /**
         * The creation date of a cache file.
         * @var DateTimeImmutable
         */
        protected DateTimeImmutable $creationDate;

        /**
         * Creates a new cache file representation.
         * @param string $location The location of the file.
         * @param mixed $content The content of the file.
         * @param int $ttl The time-to-live of the file in seconds.
         * @param array $tags The tags associated with the file.
         * @param array $metadata The metadata of the file.
         * @param DateTimeInterface|null $creationDate The creation date of the file; the current date will be used, if `null`.
         * @param string $key The original user-facing key passed to `Cacher::set()`; used by `getKey()` and `getItemsByTag()`.
         */
        public function __construct (
            string $location,
            mixed $content,
            int $ttl,
            array $tags = [],
            array $metadata = [],
            ?DateTimeInterface $creationDate = null,
            string $key = ""
        ) {
            $this->location = $location;
            $this->content = $content;
            $this->ttl = $ttl;
            $this->metadata = $metadata;
            $this->tags = $tags;
            $this->key = $key;
            $this->creationDate = $creationDate instanceof DateTimeInterface
                ? new DateTimeImmutable($creationDate->format(DateTimeInterface::ATOM))
                : new DateTimeImmutable();
        }

        /**
         * Serialises a cache object to an array for storage.
         *
         * The content is stored as an independent serialised string rather than being embedded directly
         * in the outer serialisation blob. This allows the outer `unserialize()` call in `Cacher` to
         * safely restrict allowed classes to `Cache::class` only, without inadvertently converting any
         * object stored as content into `__PHP_Incomplete_Class`. The content is restored via a separate
         * inner `unserialize()` pass in `__unserialize()`, which permits all classes.
         * @return array The serialised cache object.
         */
        public function __serialize () : array {
            return [
                'l' => $this->location,
                'c' => serialize($this->content),
                'm' => $this->metadata,
                't' => $this->ttl,
                'g' => $this->tags,
                'd' => $this->creationDate->format(DateTimeInterface::ATOM),
                'k' => $this->key
            ];
        }

        /**
         * Unserialises an array to populate a cache object's properties.
         *
         * The content key holds an independently serialised string (written by `__serialize()`).
         * It is restored here via a dedicated `unserialize()` call with `allowed_classes => true`,
         * keeping content restoration isolated from the envelope's class restrictions.
         * @param array $data The serialised cache object.
         */
        public function __unserialize (array $data) : void {
            $this->location = $data['l'];
            $this->content = unserialize($data['c'], ["allowed_classes" => true]);
            $this->metadata = $data['m'];
            $this->ttl = $data['t'];
            $this->tags = $data['g'];
            $this->key = $data['k'] ?? "";
            $this->creationDate = new DateTimeImmutable($data['d']);
        }

        /**
         * Gets the content of a cache file.
         * @return mixed The content.
         */
        public function getContent () : mixed {
            return $this->content;
        }

        /**
         * Gets the creation date of a cache file.
         * @return DateTimeImmutable The creation date.
         */
        public function getCreationDate () : DateTimeImmutable {
            return $this->creationDate;
        }

        /**
         * Gets the expiry date of a cache file.
         * A TTL of `0` is treated as "never expire" — the returned date is the maximum representable
         * datetime (`9999-12-31 23:59:59`) so that comparisons and display remain meaningful.
         * @return DateTimeImmutable The expiry date.
         */
        public function getExpiryDate () : DateTimeImmutable {
            if ($this->ttl === 0) return new DateTimeImmutable("9999-12-31 23:59:59");
            return $this->creationDate->add(new DateInterval("PT{$this->ttl}S"));
        }

        /**
         * Gets the expiry timestamp of a cache file.
         * A TTL of `0` is treated as "never expire" — returns `PHP_INT_MAX` so that
         * `time() > getExpiryTimestamp()` is never true for permanent entries.
         * @return int The expiry timestamp.
         */
        public function getExpiryTimestamp () : int {
            if ($this->ttl === 0) return PHP_INT_MAX;
            return $this->getExpiryDate()->getTimestamp();
        }

        /**
         * Gets the original user-facing key used to store this cache entry.
         * @return string The key passed to `Cacher::set()`, or an empty string for legacy entries.
         */
        public function getKey () : string {
            return $this->key;
        }

        /**
         * Gets the location of a cache file.
         * @return string The location.
         */
        public function getLocation () : string {
            return $this->location;
        }

        /**
         * Gets the metadata of a cache file.
         * @return array The metadata.
         */
        public function getMetadata () : array {
            return $this->metadata;
        }

        /**
         * Gets the remaining time-to-live of a cache file in seconds.
         * @param DateTimeInterface|null $now Reference time.
         * @return int Seconds remaining (0 if already expired).
         */
        public function getRemainingTime (?DateTimeInterface $now = null) : int {
            if ($this->ttl === 0) return PHP_INT_MAX;
            $now ??= new DateTimeImmutable();
            $elapsed = $now->getTimestamp() - $this->creationDate->getTimestamp();
            $remaining = $this->ttl - $elapsed;
            return max(0, $remaining);
        }

        /**
         * Gets the tags of a cache file.
         * @return array The tags.
         */
        public function getTags () : array {
            return $this->tags ?? [];
        }

        /**
         * Gets the time-to-live in seconds of a cache file.
         * @return int The time-to-live.
         */
        public function getTTL () : int {
            return $this->ttl;
        }

        /**
         * Checks if a cache file has a specific tag.
         * @param string $tag The tag to check for.
         * @return bool Whether the tag exists.
         */
        public function hasTag (string $tag) : bool {
            return in_array($tag, $this->tags, true);
        }

        /**
         * Gets whether a cache file has expired.
         * A more semantic alias for !isFresh().
         * @param DateTimeInterface|string|null $date Reference point.
         * @return bool True if the item is no longer valid.
         */
        public function isExpired (DateTimeInterface|string|null $date = null) : bool {
            return !$this->isFresh($date);
        }

        /**
         * Gets whether a cache file is fresh.
         * A TTL of `0` is treated as "never expire" — the item is always considered fresh.
         * @param DateTimeInterface|string|null $date A date to use as a reference point.
         * @return bool Whether the file is fresh.
         */
        public function isFresh (DateTimeInterface|string|null $date = null) : bool {
            if ($this->ttl === 0) return true;

            if (!$date instanceof DateTimeInterface) {
                $date = new DateTimeImmutable($date ?? "now");
            }

            $age = $date->getTimestamp() - $this->creationDate->getTimestamp();

            # Ensure age isn't negative (clock drift protection) and check against TTL.
            return $age >= 0 && $age < $this->ttl;
        }

        /**
         * Gets a JSON-serialisable representation of a cache object.
         * @return array The JSON-serialisable data.
         */
        public function jsonSerialize () : mixed {
            return [
                "location" => $this->location,
                "content" => $this->content,
                "metadata" => $this->metadata,
                "tags" => $this->tags,
                "ttl" => $this->ttl,
                "creation_date" => $this->creationDate->format(DateTimeInterface::ATOM),
                "expiry_date" => $this->getExpiryDate()->format(DateTimeInterface::ATOM),
                "is_fresh" => $this->isFresh(),
                "remaining" => $this->getRemainingTime()
            ];
        }
        
    }
?>