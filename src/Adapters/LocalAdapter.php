<?php
    /**
     * Project Name:    Wingman Stasis - Local Adapter
     * Created by:      Angel Politis
     * Creation Date:   Feb 22 2026
     * Last Modified:   Mar 20 2026
     * 
     * Copyright (c) 2026-2026 Angel Politis <info@angelpolitis.com>
     * This Source Code Form is subject to the terms of the Mozilla Public License, v. 2.0.
     * If a copy of the MPL was not distributed with this file, You can obtain one at http://mozilla.org/MPL/2.0/.
     */

    # Use the Cacher namespace.
    namespace Wingman\Stasis\Adapters;

    # Import the following classes to the current scope.
    use RecursiveDirectoryIterator;
    use RecursiveIteratorIterator;
    use Wingman\Stasis\Exceptions\StorageException;
    use Wingman\Stasis\Interfaces\AdapterInterface;
    use Wingman\Stasis\Interfaces\LockableInterface;
    use Wingman\Stasis\Interfaces\PrunableInterface;

    /**
     * Implements the AdapterInterface for local filesystem operations.
     * @package Wingman\Stasis\Adapters
     * @author Angel Politis <info@angelpolitis.com>
     * @since 1.0
     */
    class LocalAdapter implements AdapterInterface, LockableInterface, PrunableInterface {
        /**
         * Open file handles for all currently held locks, keyed by the lock file path.
         * Handles must be retained so that `flock()` can be called on them by `releaseLock()`.
         * @var resource[]
         */
        private array $lockHandles = [];

        /**
         * The octal permission used when creating directories.
         * @var int
         */
        private int $permission;

        /**
         * Creates a new local adapter.
         * @param int $permission The octal permission to use when creating directories.
         */
        public function __construct (int $permission = 0755) {
            $this->permission = $permission;
        }

        /**
         * Recursively scans a directory tree and removes empty leaf directories.
         * @param string $directory The current directory to inspect.
         * @param string[] $excluded Basenames to skip.
         * @return int The number of directories removed.
         */
        private function pruneDirectory (string $directory, array $excluded) : int {
            if (!is_dir($directory)) return 0;

            $items = scandir($directory);

            if ($items === false) return 0;

            $count = 0;

            foreach ($items as $item) {
                if ($item === '.' || $item === "..") continue;
                if (in_array($item, $excluded, true)) continue;

                $fullPath = $directory . DIRECTORY_SEPARATOR . $item;

                if (!is_dir($fullPath)) continue;

                $count += $this->pruneDirectory($fullPath, $excluded);

                $remaining = scandir($fullPath);

                if ($remaining !== false && count($remaining) === 2 && @rmdir($fullPath)) {
                    $count++;
                }
            }

            return $count;
        }

        /**
         * Ensures that a directory exists, creating it if necessary.
         * @param string $directory The path to the directory.
         * @throws StorageException If the directory cannot be created.
         */
        protected function ensureDirectory (string $directory) : void {
            if (!is_dir($directory)) {
                if (!mkdir($directory, $this->permission, true) && !is_dir($directory)) {
                    throw new StorageException("Could not create directory: $directory");
                }
            }
        }
        
        /**
         * Attempts to acquire an exclusive lock on a sidecar `.lock` file for the given cache key
         * using `flock(LOCK_EX | LOCK_NB)`. The lock is non-blocking: if another process holds it
         * this method returns `false` immediately rather than waiting.
         *
         * The lock is automatically released after `$ttl` seconds by the OS if the process holding
         * the lock exits or the handle is garbage collected, but callers should always pair a
         * successful `acquireLock()` call with a corresponding `releaseLock()` call.
         * @param string $key The logical cache key the lock protects.
         * @param int $ttl Maximum seconds before the lock auto-releases (used as a safety comment
         *                 only; the OS will release the flock handle on process exit regardless).
         * @return bool Whether the lock was acquired by this process.
         */
        public function acquireLock (string $key, int $ttl = 30) : bool {
            $lockPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . "stasis_" . md5($key) . ".lock";
            $handle = @fopen($lockPath, 'c');

            if ($handle === false) return false;

            if (!flock($handle, LOCK_EX | LOCK_NB)) {
                fclose($handle);
                return false;
            }

            $this->lockHandles[$lockPath] = $handle;
            return true;
        }

        /**
         * Appends content to a cache file at the given path.
         * @param string $path The path to the cache file.
         * @param string $contents The content to append to the cache file.
         * @return bool Whether the append operation was successful.
         */
        public function append (string $path, string $contents) : bool {
            $this->ensureDirectory(dirname($path));
            return file_put_contents($path, $contents, FILE_APPEND | LOCK_EX) !== false;
        }

        /**
         * Deletes a file or recursively deletes a directory.
         * @param string $path The path to the file or directory.
         * @return bool Whether the deletion was successful.
         */
        public function delete (string $path) : bool {
            if (!$this->exists($path)) return true;

            if (is_dir($path)) {
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::CHILD_FIRST
                );

                foreach ($iterator as $item) {
                    $item->isDir() ? rmdir($item->getRealPath()) : unlink($item->getRealPath());
                }
                return rmdir($path);
            }

            return unlink($path);
        }

        /**
         * Checks if a file or directory exists.
         * @param string $path The path to the file or directory.
         * @return bool Whether the file or directory exists.
         */
        public function exists (string $path) : bool {
            return file_exists($path);
        }

        /**
         * Lists all files under a directory recursively.
         * @param string $path The path to the directory.
         * @return string[] An array of file paths.
         */
        public function list (string $path) : array {
            if (!is_dir($path)) return [];

            $directory = new RecursiveDirectoryIterator($path);
            $iterator = new RecursiveIteratorIterator($directory);
            $files = [];

            foreach ($iterator as $info) {
                if ($info->isFile()) {
                    $files[] = $info->getPathname();
                }
            }
            return $files;
        }

        /**
         * Recursively removes empty directories within the given root, skipping any directory
         * whose basename appears in the `$excluded` list.
         * @param string $rootDir The absolute path of the root directory to prune.
         * @param string[] $excluded Basenames (not full paths) of directories to skip entirely.
         * @return int The number of directories successfully removed.
         */
        public function pruneEmptyDirectories (string $rootDir, array $excluded = []) : int {
            return $this->pruneDirectory($rootDir, $excluded);
        }

        /**
         * Reads the contents of a file.
         * @param string $path The path to the file.
         * @return string The contents of the file.
         * @throws StorageException If the file cannot be read.
         */
        public function read (string $path) : string {
            $contents = file_get_contents($path);

            if ($contents === false) {
                throw new StorageException("Could not read file: $path");
            }

            return $contents;
        }

        /**
         * Writes contents to a file.
         * @param string $path The path to the file.
         * @param string $contents The contents to write.
         * @return bool Whether the write operation was successful.
         */
        public function write (string $path, string $contents) : bool {
            $this->ensureDirectory(dirname($path));
            return file_put_contents($path, $contents, LOCK_EX) !== false;
        }

        /**
         * Releases a previously acquired lock by calling `flock(LOCK_UN)` on its file handle.
         * @param string $key The logical cache key whose lock should be released.
         * @return bool Whether the lock was released.
         */
        public function releaseLock (string $key) : bool {
            $lockPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . "stasis_" . md5($key) . ".lock";

            if (!isset($this->lockHandles[$lockPath])) return false;

            $result = flock($this->lockHandles[$lockPath], LOCK_UN);
            fclose($this->lockHandles[$lockPath]);
            unset($this->lockHandles[$lockPath]);
            return $result;
        }
    }
?>