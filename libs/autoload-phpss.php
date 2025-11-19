<?php
declare(strict_types=1);

/**
 * Minimal PSR-4 style autoloader for our manual installs (no Composer).
 * Mapped namespaces:
 *  - PhpOffice\PhpSpreadsheet\        → /libs/phpspreadsheet/src/PhpSpreadsheet/
 *  - PhpOffice\Math\                  → /libs/phpoffice-math/src/
 *  - Psr\SimpleCache\                 → /libs/psr-simple-cache/src/
 *  - ZipStream\                       → /libs/zipstream/src/
 *  - Complex\      (legacy optional)  → /libs/markbaker-complex/src/ or /libs/markbaker-complex/classes/src/
 *  - Matrix\       (legacy optional)  → /libs/markbaker-matrix/src/    or /libs/markbaker-matrix/classes/src/
 */

spl_autoload_register(function (string $class): void {
    static $map = null;

    if ($map === null) {
        $base = __DIR__;

        // IMPORTANT: this must be an ARRAY (your previous file got corrupted into a string).
        $map = [
            'PhpOffice\\PhpSpreadsheet\\' => $base . '/phpspreadsheet/src/PhpSpreadsheet/',
            'PhpOffice\\Math\\'           => $base . '/phpoffice-math/src/',
            'Psr\\SimpleCache\\'          => $base . '/psr-simple-cache/src/',
            'ZipStream\\'                 => $base . '/zipstream/src/',

            // Legacy/optional dependencies used by some PhpSpreadsheet builds
            'Complex\\'                   => $base . '/markbaker-complex/src/',
            'Matrix\\'                    => $base . '/markbaker-matrix/src/',
    'Composer\\Pcre\\'          => __DIR__ . '/polyfills/Composer/Pcre/',
];
    }

    foreach ($map as $prefix => $dir) {
        $len = strlen($prefix);
        if (strncmp($class, $prefix, $len) !== 0) {
            continue;
        }

        $relative = substr($class, $len);
        $file     = $dir . str_replace('\\', '/', $relative) . '.php';
        if (is_file($file)) {
            require $file;
            return;
        }

        // Fallback for MarkBaker packages that sometimes live under /classes/src
        if (str_contains($dir, 'markbaker-')) {
            $alt = str_replace('/src/', '/classes/src/', $dir) . str_replace('\\', '/', $relative) . '.php';
            if (is_file($alt)) {
                require $alt;
                return;
            }
        }
    }
});
