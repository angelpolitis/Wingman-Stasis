<?php
    /**
     * Project Name:    Wingman Stasis - Console Bridge - Repair Command
     * Created by:      Angel Politis
     * Creation Date:   Feb 22 2026
     * Last Modified:   Mar 13 2026
     * 
     * Copyright (c) 2026-2026 Angel Politis <info@angelpolitis.com>
     * This Source Code Form is subject to the terms of the Mozilla Public License, v. 2.0.
     * If a copy of the MPL was not distributed with this file, You can obtain one at http://mozilla.org/MPL/2.0/.
     */

    # Use the Cacher.Bridge.Console.Commands namespace.
    namespace Wingman\Stasis\Bridge\Console\Commands;

    # Import the following classes to the current scope.
    use Throwable;
    use Wingman\Stasis\Cacher;
    use Wingman\Stasis\Exceptions\MissingDependencyException;
    use Wingman\Console\Attributes\Command as Cmd;
    use Wingman\Console\Attributes\Flag;
    use Wingman\Console\Command;
    use Wingman\Console\Interfaces\Benchmarkable;
    use Wingman\Console\Style;

    if (!class_exists(Command::class)) {
        throw new MissingDependencyException("Wingman-Console is required to use commands.");
    }

    # Ensure the Cacher class is loaded before defining the command.
    if (!class_exists(Cacher::class)) {
        require_once __DIR__ . str_repeat(DIRECTORY_SEPARATOR . "..", 2) . DIRECTORY_SEPARATOR . "autoload.php";
    }

    /**
     * Performs maintenance and repair operations on the cache storage.
     * @package Wingman\Stasis\Bridge\Console\Commands
     * @author Angel Politis <info@angelpolitis.com>
     */
    #[Cmd(name: "cache:repair", description: "Rebuilds the cache registry, tag indices, and prunes empty directories.")]
    class RepairCommand extends Command implements Benchmarkable {
        /**
         * The cacher instance to use for repair operations.
         * @var Cacher|null
         */
        protected ?Cacher $cacher = null;

        /**
         * Whether to skip the confirmation prompt.
         * @var bool
         */
        #[Flag(name: "force", alias: 'f', description: "Execute repair without confirmation")]
        protected bool $force = false;

        /**
         * Stores the results of the repair operation for benchmarking purposes.
         * @var array
         */
        protected array $repairResults = [];

        /**
         * Renders the repair statistics in a styled table.
         * @param int $registry The number of registry entries recovered.
         * @param int $tags The number of tag indices rebuilt.
         * @param array $garbage The counts of stale files and empty directories removed.
         */
        protected function renderResults (int $registry, int $tags, array $garbage) : void {
            $rows = [
                ["Registry Entries Recovered", $registry],
                ["Tag Indices Rebuilt", $tags],
                ["Stale Files Removed", $garbage["files"] ?? 0],
                ["Empty Directories Pruned", $garbage["dirs"] ?? 0]
            ];

            $this->console->style(function (Style $s) use ($rows) {
                yield PHP_EOL . $s->format(" Cache Maintenance Complete ", "success") . PHP_EOL;
                yield $s->renderTable(["Operation", "Result"], $rows) . PHP_EOL;
            });
        }

        /**
         * Gets the results of the repair operation for benchmarking purposes.
         * @return array The repair results including counts of registry entries, tags rebuilt, stale files removed, and directories pruned.
         */
        public function getResults () : array {
            return $this->repairResults;
        }

        /**
         * Gets the key used to identify the work unit for benchmarking throughput.
         * @return string The key corresponding to the total operations performed during repair.
         */
        public function getWorkUnitKey () : string {
            return "total_ops";
        }

        /**
         * Executes the repair logic.
         * @return int The exit code.
         */
        public function run () : int {
            # 1. Verification
            if (!$this->force && !$this->console->confirm("This will scan all cache files and rebuild indices. Continue?")) {
                $this->console->info("Operation cancelled.");
                return 0;
            }

            try {
                $cacher = $this->cacher ?? new Cacher();
                
                $this->console->info("Starting cache repair...");

                # Start tracking internal state
                $registryCount = $cacher->rebuildRegistry();
                
                # Accessing TagManager via the Cacher instance
                $tagManager = $cacher->getTagManager();
                $tagCount = $tagManager->rebuildTagIndices();
                
                $garbage = $cacher->collectGarbage();

                # 3. Render Results
                $this->renderResults($registryCount, $tagCount, $garbage);

                # Calculate additional metrics for the Benchmark
                $totalWork = $registryCount + $tagCount + $garbage["files"];
                $syncDelta = abs($registryCount - $tagCount);
                
                # Populating the repairResults with deep metrics
                $this->repairResults = [
                    # Counters
                    "registry_entries" => $registryCount,
                    "tags_rebuilt" => $tagCount,
                    "stale_files" => $garbage["files"],
                    "dirs_pruned" => $garbage["dirs"],
                    
                    # Unit Work (for Throughput/Latency calculations)
                    "total_ops" => $totalWork,

                    # Health Metrics
                    "sync_drift" => $syncDelta,
                    "garbage_ratio" => $registryCount > 0 ? round(($garbage["files"] / $registryCount) * 100, 2) . "%" : "0%"
                ];

                return 0;
            }
            catch (Throwable $e) {
                $this->console->error("Repair failed: " . $e->getMessage());
                return 1;
            }
        }
        /**
         * Sets the cacher instance to use for repair operations.
         * @param Cacher $cacher The cacher instance.
         * @return static The current instance.
         */
        public function withCacher (Cacher $cacher) : static {
            $this->cacher = $cacher;
            return $this;
        }
    }
?>