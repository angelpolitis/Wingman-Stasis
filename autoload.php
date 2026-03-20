<?php
    /*/
     * Project Name:    Wingman — Stasis — Autoloader
     * Created by:      Angel Politis
     * Creation Date:   Feb 23 2026
     * Last Modified:   Mar 20 2026
    /*/

    /**
     * PSR-4 standalone autoloader for Wingman packages.
     *
     * Resolves:
     *   - Wingman\<Module>\* → src/{...}.php
     *
     * The module name is derived from the directory this file lives in,
     * making this file a standard drop-in for any Wingman package.
     *
     * Non-optional dependencies declared in manifest.json are bootstrapped
     * automatically by requiring each dependency module's own autoload.php.
     *
     * This file is a no-op when Wingman\Vortex is already active —
     * that package handles all Wingman\* namespaces generically.
     */
    if (class_exists("Wingman\\Vortex\\Autoloader", false)) return;

    $manifestPath = __DIR__ . DIRECTORY_SEPARATOR . "manifest.json";

    if (!is_file($manifestPath) || !is_readable($manifestPath)) return;

    $manifest = json_decode(file_get_contents($manifestPath), true);

    # Derive the canonical module name from manifest "name".
    # This is layout-independent and avoids relying on the directory name.
    $moduleName = implode("-", array_slice(explode("/", $manifest["name"] ?? ""), 1));

    if (empty($moduleName)) return;

    spl_autoload_register(function (string $class) use ($moduleName) : void {
        $parts = explode("\\", $class);

        $moduleSegments = explode("-", $moduleName);
        $depth = count($moduleSegments);

        if (count($parts) < $depth + 2 || $parts[0] !== "Wingman") return;
        if (implode("-", array_slice($parts, 1, $depth)) !== $moduleName) return;

        $path = __DIR__ . DIRECTORY_SEPARATOR . "src" . DIRECTORY_SEPARATOR
            . implode(DIRECTORY_SEPARATOR, array_slice($parts, $depth + 1)) . ".php";

        if (file_exists($path)) require_once $path;
    });

    foreach ($manifest["dependencies"] ?? [] as $dependency) {
        $packageName = is_string($dependency) ? $dependency : ($dependency["package"] ?? null);
        if (!is_string($packageName)) continue;

        $depModule = implode("-", array_slice(explode("/", $packageName), 1));
        if (empty($depModule)) continue;

        $dependencyPath = __DIR__ . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . $depModule . DIRECTORY_SEPARATOR . "autoload.php";
        if (file_exists($dependencyPath)) require_once $dependencyPath;
    }
?>