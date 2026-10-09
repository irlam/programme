<?php
declare(strict_types=1);
namespace App\Lib;

use RuntimeException;

/** Deployment-owned binding; the single in-tree location has a direct HTTP guard. */
final class SuiteBinding
{

    public static function load(string $root): array
    {
        $root = realpath($root);
        if (!$root) throw new RuntimeException('Private binding unavailable.');
        $default = $root . '/app/config/runtime.suite.private.php';
        $configured = getenv('PROGRAMME_SUITE_CONFIG_FILE');
        $candidate = $configured === false || $configured === '' ? $default : $configured;
        $file = realpath($candidate);
        if (!$file || !is_file($file) || is_link($candidate) || (fileperms($file) & 0077) !== 0) {
            throw new RuntimeException('Private binding unavailable.');
        }
        if (str_starts_with($file, $root . DIRECTORY_SEPARATOR)) {
            // No arbitrary in-tree configuration, symlinked config folder or unguarded PHP.
            if ($file !== $default || is_link($root . '/app') || is_link($root . '/app/config')) {
                throw new RuntimeException('Private binding unavailable.');
            }
            $source = file_get_contents($file);
            $prefix = "<?php\ndeclare(strict_types=1);\n" . self::guard() . "\nreturn ";
            if (!is_string($source) || !str_starts_with($source, $prefix)) {
                throw new RuntimeException('Private binding unavailable.');
            }
            unset($source);
        }
        $level = ob_get_level();
        ob_start();
        try {
            $binding = (static function (string $path): mixed { return require $path; })($file);
        } finally {
            while (ob_get_level() > $level) ob_end_clean();
        }
        if (!is_array($binding)) throw new RuntimeException('Private binding unavailable.');
        return $binding;
    }

    public static function guard(): string
    {
        return "if (PHP_SAPI !== 'cli' && realpath((string) (" . '$' . "_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) { http_response_code(404); exit; }";
    }
}
