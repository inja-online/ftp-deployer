<?php

namespace InjaOnline\FTPDeployer\Support;

class Exclusions
{
    /** @param list<string> $patterns */
    public static function matches(string $path, array $patterns): bool
    {
        $path = trim(str_replace('\\', '/', $path), '/');
        foreach ($patterns as $pattern) {
            $pattern = trim(str_replace('\\', '/', $pattern), '/');
            if ($pattern === '') {
                continue;
            }
            if (str_ends_with($pattern, '/') && str_starts_with($path.'/', $pattern)) {
                return true;
            }
            if ($path === $pattern || str_starts_with($path.'/', rtrim($pattern, '/').'/')) {
                return true;
            }
            if (fnmatch($pattern, $path)) {
                return true;
            }
        }

        return false;
    }
}
