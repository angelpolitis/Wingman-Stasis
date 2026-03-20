<?php
    /**
     * Project Name:    Wingman Stasis - PathUtils Tests
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
    use Wingman\Stasis\Exceptions\PathEscapeException;
    use Wingman\Stasis\PathUtils;

    /**
     * Tests for the PathUtils static utility class, covering separator normalisation,
     * trailing-separator enforcement, fragment joining, relative-path computation, and
     * the strict path-traversal guard in resolvePath().
     * @package Wingman\Stasis\Tests
     * @author Angel Politis <info@angelpolitis.com>
     * @since 1.0
     */
    class PathUtilsTest extends Test {
        // ─── fix() ───────────────────────────────────────────────────────────

        #[Group("fix")]
        #[Define(
            name: "fix Returns Null For Null Input",
            description: "fix() must return null unchanged when a null path is supplied."
        )]
        public function testFixReturnsNullForNull () : void {
            $this->assertTrue(PathUtils::fix(null) === null, "fix(null) should return null.");
        }

        #[Group("fix")]
        #[Define(
            name: "fix Normalises Backslashes To Native Separator",
            description: "fix() replaces all backslash separators with DIRECTORY_SEPARATOR."
        )]
        public function testFixNormalisesBackslashes () : void {
            $result = PathUtils::fix("foo\\bar\\baz", "/");

            $this->assertTrue($result === "foo/bar/baz", "fix() should replace backslashes with the target separator.");
        }

        #[Group("fix")]
        #[Define(
            name: "fix Normalises Forward Slashes To Custom Separator",
            description: "fix() replaces all forward slashes with a custom separator when one is specified."
        )]
        public function testFixNormalisesForwardSlashes () : void {
            $result = PathUtils::fix("foo/bar/baz", "\\");

            $this->assertTrue($result === "foo\\bar\\baz", "fix() should replace forward slashes with the custom separator.");
        }

        #[Group("fix")]
        #[Define(
            name: "fix Leaves Already-Normalised Paths Unchanged",
            description: "A path that already uses the target separator is returned without modification."
        )]
        public function testFixLeavesNormalisedPathUnchanged () : void {
            $path = "foo" . DIRECTORY_SEPARATOR . "bar";
            $result = PathUtils::fix($path);

            $this->assertTrue($result === $path, "fix() should not modify an already-normalised path.");
        }

        // ─── forceTrailingSeparator() ─────────────────────────────────────────

        #[Group("forceTrailingSeparator")]
        #[Define(
            name: "Adds Trailing Separator When Absent",
            description: "forceTrailingSeparator() appends exactly one trailing separator if the path does not already end with one."
        )]
        public function testAddsTrailingSeparatorWhenAbsent () : void {
            $result = PathUtils::forceTrailingSeparator("foo/bar", "/");

            $this->assertTrue($result === "foo/bar/", "Trailing separator should be appended.");
        }

        #[Group("forceTrailingSeparator")]
        #[Define(
            name: "Does Not Duplicate Trailing Separator",
            description: "forceTrailingSeparator() leaves a path that already ends with a separator unchanged (no double-separator)."
        )]
        public function testDoesNotDuplicateTrailingSeparator () : void {
            $result = PathUtils::forceTrailingSeparator("foo/bar/", "/");

            $this->assertTrue($result === "foo/bar/", "Trailing separator should not be duplicated.");
        }

        #[Group("forceTrailingSeparator")]
        #[Define(
            name: "Empty String Returns Single Separator",
            description: "forceTrailingSeparator() returns a single separator when the input is an empty string."
        )]
        public function testEmptyStringReturnsSingleSeparator () : void {
            $result = PathUtils::forceTrailingSeparator("", "/");

            $this->assertTrue($result === "/", "Empty path should result in a single separator.");
        }

        #[Group("forceTrailingSeparator")]
        #[Define(
            name: "Normalises Mixed Separators Before Adding Trailing",
            description: "forceTrailingSeparator() normalises internal separators before enforcing the trailing one."
        )]
        public function testNormalisesMixedSeparators () : void {
            $result = PathUtils::forceTrailingSeparator("foo\\bar/baz", "/", ['\\', '/']);

            $this->assertTrue($result === "foo/bar/baz/", "Mixed separators should be normalised and a trailing separator added.");
        }

        // ─── join() ───────────────────────────────────────────────────────────

        #[Group("join")]
        #[Define(
            name: "join Assembles Fragments With Native Separator",
            description: "join() combines path fragments using DIRECTORY_SEPARATOR without duplicating internal separators."
        )]
        public function testJoinAssemblesFragments () : void {
            $result = PathUtils::join("root", "sub", "file.txt");
            $expected = PathUtils::fix("root/sub/file.txt");

            $this->assertTrue($result === $expected, "join() should assemble fragments correctly.");
        }

        #[Group("join")]
        #[Define(
            name: "join Strips Internal Duplicate Separators",
            description: "join() trims leading and trailing separators from non-first fragments to avoid duplication."
        )]
        public function testJoinStripsInternalSeparators () : void {
            $result = PathUtils::join("root/", "/sub/", "/file.txt");
            $expected = PathUtils::fix("root/sub/file.txt");

            $this->assertTrue($result === $expected, "join() should not produce duplicate separators.");
        }

        #[Group("join")]
        #[Define(
            name: "join Skips Empty Fragments",
            description: "join() silently discards empty string fragments so they do not introduce spurious separators."
        )]
        public function testJoinSkipsEmptyFragments () : void {
            $result = PathUtils::join("root", "", "file.txt");
            $expected = PathUtils::fix("root/file.txt");

            $this->assertTrue($result === $expected, "join() should skip empty fragments.");
        }

        // ─── resolvePath() ───────────────────────────────────────────────────

        #[Group("resolvePath")]
        #[Define(
            name: "Strict Mode Throws For Path Traversal",
            description: "resolvePath() throws PathEscapeException in strict mode when the resolved path escapes the specified root."
        )]
        public function testStrictModeThrowsForTraversal () : void {
            $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . "stasis_test_" . uniqid();
            mkdir($root, 0755, true);

            try {
                # Use the test file itself — an absolute path that exists but sits outside $root.
                $this->assertThrows(PathEscapeException::class, function () use ($root) {
                    PathUtils::resolvePath($root, __FILE__, true);
                });
            }
            finally {
                @rmdir($root);
            }
        }

        #[Group("resolvePath")]
        #[Define(
            name: "Non-Strict Mode Returns Clamped Path",
            description: "resolvePath() does not throw in non-strict mode even when the path would escape the root."
        )]
        public function testNonStrictModeDoesNotThrow () : void {
            $root = sys_get_temp_dir();
            $escape = "../../etc/passwd";

            $thrown = false;
            try {
                PathUtils::resolvePath($root, $escape, false);
            }
            catch (PathEscapeException $e) {
                $thrown = true;
            }

            $this->assertTrue(!$thrown, "resolvePath() should not throw in non-strict mode.");
        }

        #[Group("resolvePath")]
        #[Define(
            name: "Relative Path Is Resolved Against Root",
            description: "resolvePath() joins a relative path against the given root and returns the canonical absolute path."
        )]
        public function testRelativePathResolvedAgainstRoot () : void {
            $root = sys_get_temp_dir();
            $result = PathUtils::resolvePath($root, "subdir/file.txt", false);

            $this->assertTrue(
                str_starts_with($result, PathUtils::fix($root)),
                "Resolved path should begin with the root directory."
            );
        }

        // ─── getRelativePath() ───────────────────────────────────────────────

        #[Group("getRelativePath")]
        #[Define(
            name: "Returns Already-Relative Path Unchanged",
            description: "getRelativePath() returns a relative path as-is without prepending the source directory."
        )]
        public function testReturnsRelativePathUnchanged () : void {
            $relative = "temp/cache";
            $result = PathUtils::getRelativePath("/some/root", $relative);

            $this->assertTrue($result === PathUtils::fix($relative), "An already-relative path should be returned as-is.");
        }

        #[Group("getRelativePath")]
        #[Define(
            name: "Computes Relative Path Between Two Absolute Paths",
            description: "getRelativePath() produces the shortest relative path from the source directory to the target."
        )]
        public function testComputesRelativePathBetweenAbsolutePaths () : void {
            $from = "/var/www/html";
            $to = "/var/www/html/cache/items";

            $result = PathUtils::getRelativePath($from, $to);

            $this->assertTrue($result === PathUtils::fix("cache/items"), "getRelativePath() should compute the correct relative path.");
        }
    }
?>
