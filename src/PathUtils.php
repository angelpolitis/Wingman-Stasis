<?php
    /**
     * Project Name:    Wingman Stasis - Path Utilities
     * Created by:      Angel Politis
     * Creation Date:   Feb 22 2026
     * Last Modified:   Mar 13 2026
     * 
     * Copyright (c) 2026-2026 Angel Politis <info@angelpolitis.com>
     * This Source Code Form is subject to the terms of the Mozilla Public License, v. 2.0.
     * If a copy of the MPL was not distributed with this file, You can obtain one at http://mozilla.org/MPL/2.0/.
     */

    # Use the Cacher namespace.
    namespace Wingman\Stasis;

    # Import the following classes to the current scope.
    use Wingman\Stasis\Exceptions\PathEscapeException;

    /**
     * A static class that groups together various pure path-related operations.
     * @package Wingman\Stasis
     * @author Angel Politis <info@angelpolitis.com>
     * @since 1.0
     */
    final class PathUtils {
        /**
         * Ensures the static class cannot be instantiated.
         */
        private function __construct () {}

        /**
         * Gets a path having replaced with a custom separator all defined separators.
         * @param string|null $path The path to fix.
         * @param string $new The separator to replace the old separators [default: `DIRECTORY_SEPARATOR`].
         * @param string[] $old The separators to be replaced [default: `\`, `/`].
         * @return string|null The fixed path, or `null` if no path was given.
         */
        public static function fix (?string $path, string $new = DIRECTORY_SEPARATOR, array $old = ['\\', '/']) : ?string {
            return is_null($path) ? null : str_replace($old, $new, $path);
		}

        /**
         * Gets a path with the defined trailing separator.
         * @param string $path The path to fix.
         * @param string $new The separator to replace the old separators [default: `DIRECTORY_SEPARATOR`].
         * @param string[] $old The separators, if any, to be replaced [default: `\`, `/`].
         * @return string The fixed path.
         */
        public static function forceTrailingSeparator (string $path, string $new = DIRECTORY_SEPARATOR, array $old = ['\\', '/']) : string {
            return empty($path) ? $new : rtrim(static::fix($path, $new, $old), $new) . $new;
        }

        /**
         * Calculates the relative path from a source directory to a target path.
         * @param string $from Absolute source path (the package).
         * @param string $to Absolute target path (the cache root).
         * @return string The relative path (e.g., "../../src/apps/www").
         */
        public static function getRelativePath (string $from, string $to) : string {
            # 1. Quick Check: Is $to already relative?
            # If it doesn't start with a separator or a Windows drive letter, return as-is.
            if (!static::isAbsolute($to)) {
                return static::fix($to);
            }

            # Standardise separators
            $from = static::fix(realpath($from) ?: $from);
            $to = static::fix(realpath($to) ?: $to);

            $fromParts = explode(DIRECTORY_SEPARATOR, rtrim($from, DIRECTORY_SEPARATOR));
            $toParts = explode(DIRECTORY_SEPARATOR, rtrim($to, DIRECTORY_SEPARATOR));

            # Find where the paths diverge
            while (count($fromParts) && count($toParts) && ($fromParts[0] === $toParts[0])) {
                array_shift($fromParts);
                array_shift($toParts);
            }

            # For every remaining part in 'from', we go up (..)
            # Then we append the remaining parts in 'to'
            return str_repeat(".." . DIRECTORY_SEPARATOR, count($fromParts)) . implode(DIRECTORY_SEPARATOR, $toParts);
        }

        /**
         * Whether a path is absolute.
         * @param string $path The path to check.
         * @return bool Whether the path is absolute.
         */
        private static function isAbsolute (string $path) : bool {
            if ($path === "") return false;
            return $path[0] === DIRECTORY_SEPARATOR || (strlen($path) > 1 && $path[1] === ':');
        }

        /**
         * Joins the fragments of a path into a complete path.
         * @param string ...$pathFraments The fragments of a path.
         * @return string The path.
         */
        public static function join (string ...$pathFragments) : string {
            $fragments = [];
            foreach ($pathFragments as $index => $fragment) {
                $trim = $index > 0 ? "trim" : "rtrim";
                $fragment = $trim($fragment, "\\/");
                if (empty($fragment)) continue;
                $fragments[] = $fragment;
            }
            return self::fix(implode(DIRECTORY_SEPARATOR, $fragments));
        }

        /**
         * Resolve a path against the root directory and ensure it doesn't escape it.
         * @param string $path The path.
         * @param bool $strict Whether the resolution will happen in strict mode.
         * @return string The resolved path.
         */
        public static function resolvePath (string $root, string $path, bool $strict = true) : string {
            # Determine whether the path is absolute:
            # - Starts with "/" (Unix)
            # - Starts with a drive letter like "C:\" (Windows)
            # - UNC path: "\\server\share"
            $isAbsolute = ($path !== "" && $path[0] === DIRECTORY_SEPARATOR) ||
                preg_match('/^[A-Za-z]:[\/\\\\]/', $path) ||
                str_starts_with($path, '\\\\');
        
            # Normalise the path, if absolute.
            if ($isAbsolute) $absolute = realpath($path) ?: $path;

            # Bind the relative path to the root.
            else {
                $absolute = $root . DIRECTORY_SEPARATOR . $path;
                $absolute = realpath($absolute) ?: $absolute;
            }
        
            # Normalise the root and path to the same format.
            $root = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $root), DIRECTORY_SEPARATOR);
            $absolute = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $absolute), DIRECTORY_SEPARATOR);
        
            # Reject the path if it's out of scope in strict mode.
            if ($strict && !str_starts_with($absolute, $root)) {
                if ($path === $absolute) $message = $path;
                else $message = "$path → $absolute";
                throw new PathEscapeException("Path '$message' escapes the cacher root: $root");
            }
        
            return $absolute;
        }
    }
?>