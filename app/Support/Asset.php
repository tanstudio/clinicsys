<?php

namespace App\Support;

/**
 * Versioned URLs for files under public/.
 * Cloudflare and browsers cache stylesheets and scripts for 4 hours (max-age=14400). A content hash in the
 * query string gives every changed file a new URL, so a deployment takes effect at once.
 */
final class Asset
{
    /** @var array<string, string|null> */
    private static array $versions = [];

    public static function url(string $path): string
    {
        $path = '/'.ltrim($path, '/');
        if (!array_key_exists($path, self::$versions)) {
            $file = public_path(ltrim($path, '/'));
            self::$versions[$path] = is_file($file) ? substr(hash_file('sha256', $file), 0, 12) : null;
        }

        // A missing file keeps its plain URL, so a page still renders if a build step was skipped.
        return self::$versions[$path] === null ? $path : $path.'?v='.self::$versions[$path];
    }
}
