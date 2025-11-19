<?php
declare(strict_types=1);
date_default_timezone_set('Europe/London');

$root = dirname(__DIR__);       // /httpdocs
$libs = $root . '/libs';
$poly = $libs . '/polyfills/Composer/Pcre';
@mkdir($poly, 0775, true);

function row($k,$ok,$d=''){echo "<tr><td>$k</td><td style='color:".($ok?'#86efac':'#fca5a5')."'>".($ok?'OK':'FAIL')."</td><td style='color:#9ca3af'>$d</td></tr>";}

echo '<!doctype html><meta charset="utf-8"><title>Composer\\Pcre Polyfill Installer</title>
<style>body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;background:#0f172a;color:#e5e7eb;margin:0;padding:16px}
td{padding:8px;border-bottom:1px solid #1f2937} a{color:#93c5fd}</style>
<h2>Composer\\Pcre Polyfill Installer</h2><table>';

/* 1) Write polyfill class */
$pregFile = $poly . '/Preg.php';
$code = <<<'PHP'
<?php
declare(strict_types=1);

namespace Composer\Pcre;

class PregException extends \RuntimeException {}
class RegexException extends PregException {}

final class Preg
{
    private static function throwOnError(int $result): int
    {
        if ($result === false) {
            $err = function_exists('preg_last_error_msg') ? preg_last_error_msg() : ('preg error code '.preg_last_error());
            throw new RegexException($err);
        }
        return (int) $result;
    }

    /** Returns true if pattern matches subject */
    public static function isMatch(string $pattern, string $subject, int $flags = 0, int $offset = 0): bool
    {
        $r = @preg_match($pattern, $subject, $m, $flags, $offset);
        if ($r === false) {
            $err = function_exists('preg_last_error_msg') ? preg_last_error_msg() : ('preg error code '.preg_last_error());
            throw new RegexException($err);
        }
        return $r === 1;
    }

    /** Proxy for preg_match */
    public static function match(string $pattern, string $subject, ?array &$matches = null, int $flags = 0, int $offset = 0): int
    {
        $r = @preg_match($pattern, $subject, $matches, $flags, $offset);
        return self::throwOnError($r);
    }

    /** Proxy for preg_match_all */
    public static function matchAll(string $pattern, string $subject, ?array &$matches = null, int $flags = 0, int $offset = 0): int
    {
        $r = @preg_match_all($pattern, $subject, $matches, $flags, $offset);
        return self::throwOnError($r);
    }

    /** Proxy for preg_replace */
    public static function replace($pattern, $replacement, $subject, int $limit = -1, ?int &$count = null)
    {
        $r = @preg_replace($pattern, $replacement, $subject, $limit, $count);
        if ($r === null) {
            $err = function_exists('preg_last_error_msg') ? preg_last_error_msg() : ('preg error code '.preg_last_error());
            throw new RegexException($err);
        }
        return $r;
    }

    /** Proxy for preg_split */
    public static function split(string $pattern, string $subject, int $limit = -1, int $flags = 0): array
    {
        $r = @preg_split($pattern, $subject, $limit, $flags);
        if ($r === false) {
            $err = function_exists('preg_last_error_msg') ? preg_last_error_msg() : ('preg error code '.preg_last_error());
            throw new RegexException($err);
        }
        return $r;
    }
}
PHP;

$ok1 = @file_put_contents($pregFile, $code) !== false;
row('write /libs/polyfills/Composer/Pcre/Preg.php', $ok1, $pregFile);

/* 2) Patch manual autoloader to map Composer\Pcre\ */
$autoload = $libs . '/autoload-phpss.php';
$ok2 = is_file($autoload);
row('autoload-phpss.php present', $ok2, $autoload);

$patched = false;
if ($ok2) {
    $src = file_get_contents($autoload);
    // ensure $map is an array with 'Composer\\Pcre\\' => __DIR__.'/polyfills/Composer/Pcre/'
    if (strpos($src, "'Composer\\\\Pcre\\\\'") === false) {
        $new = preg_replace_callback('/\\$map\\s*=\\s*\\[(.*?)\\];/s', function($m){
            $chunk = rtrim($m[1]);
            $add   = "\n    'Composer\\\\Pcre\\\\'          => __DIR__ . '/polyfills/Composer/Pcre/',";
            if (strpos($chunk, 'Composer\\\\Pcre\\\\') !== false) return $m[0];
            return "\$map = [{$chunk}{$add}\n];";
        }, $src, 1);
        if ($new && $new !== $src) {
            $patched = @file_put_contents($autoload, $new) !== false;
        }
    } else {
        $patched = true; // already mapped
    }
}
row('map Composer\\Pcre\\ → /libs/polyfills/Composer/Pcre/', $patched);

/* 3) Quick sanity check */
$ok3 = false;
require_once $autoload;
if (class_exists(\Composer\Pcre\Preg::class, true)) { $ok3 = true; }
row('class_exists(Composer\\Pcre\\Preg)', $ok3);

echo '</table><p>Now test: 
<a href="/api/templates/tasklist.php?debug=1">/api/templates/tasklist.php?debug=1</a> • 
<a href="/api/templates/lookahead.php?debug=1">/api/templates/lookahead.php?debug=1</a></p>
<p style="color:#9ca3af">If still failing, clear PHP OPcache in Plesk or wait 1–2 minutes.</p>';
