<?php
declare(strict_types=1);
// Run once with Plesk's PHP scheduled-task runner BEFORE deploying the private
// config loader. This never prints connection values or executes the source.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
$root = dirname(__DIR__);
$source = $root . '/app/config/config.php;
$directory = dirname($root) . '/private';
$target = $directory . '/config.php';
if (is_file($target)) { echo "Private configuration already exists; left unchanged.\n"; exit(0); }
if (!is_file($source)) { fwrite(STDERR, "Source configuration is missing.\n"); exit(1); }
$text = file_get_contents($source);
if (!is_string($text) || !str_starts_with(ltrim($text), '<?php')) { fwrite(STDERR, "Invalid source configuration.\n"); exit(1); }
// Refuse to back up a replacement loader as though it were the legacy config.
if (str_contains($text, 'private/config.php') || str_contains($text, 'PROGRAMME_CONFIG_FILE')) { fwrite(STDERR, "Legacy configuration must be preserved before the loader is deployed.\n"); exit(1); }

if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) { fwrite(STDERR, "Cannot create private configuration directory.\n"); exit(1); }
$temp = tempnam($directory, '.config-');
if ($temp === false || file_put_contents($temp, $text, LOCK_EX) === false) { fwrite(STDERR, "Cannot write private configuration.\n"); exit(1); }
chmod($temp, 0600);
if (!rename($temp, $target)) { unlink($temp); fwrite(STDERR, "Cannot install private configuration.\n"); exit(1); }
echo "Private configuration preserved outside the document root. Values were not printed.\n";
