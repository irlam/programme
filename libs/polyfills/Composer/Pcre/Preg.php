<?php
declare(strict_types=1);

namespace Composer\Pcre;

class PregException extends \RuntimeException {}
class RegexException extends PregException {}

final class Preg
{
    private static function throwOnError($result): int
    {
        if ($result === false) {
            $err = function_exists('preg_last_error_msg') ? preg_last_error_msg() : ('preg error code '.preg_last_error());
            throw new RegexException($err);
        }
        return (int) $result;
    }

    /** Returns true if pattern matches subject */
    public static function isMatch(string $pattern, string $subject, $flags = 0, $offset = 0): bool
    {
        $flags  = (int) ($flags  ?? 0);
        $offset = (int) ($offset ?? 0);
        $m = [];
        $r = @preg_match($pattern, $subject, $m, $flags, $offset);
        if ($r === false) {
            $err = function_exists('preg_last_error_msg') ? preg_last_error_msg() : ('preg error code '.preg_last_error());
            throw new RegexException($err);
        }
        return $r === 1;
    }

    /** Proxy for preg_match */
    public static function match(string $pattern, string $subject, ?array &$matches = null, $flags = 0, $offset = 0): int
    {
        $flags  = (int) ($flags  ?? 0);
        $offset = (int) ($offset ?? 0);
        $r = @preg_match($pattern, $subject, $matches, $flags, $offset);
        return self::throwOnError($r);
    }

    /** Proxy for preg_match_all */
    public static function matchAll(string $pattern, string $subject, ?array &$matches = null, $flags = 0, $offset = 0): int
    {
        $flags  = (int) ($flags  ?? 0);
        $offset = (int) ($offset ?? 0);
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
