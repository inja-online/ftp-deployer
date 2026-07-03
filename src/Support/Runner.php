<?php

namespace InjaOnline\FTPDeployer\Support;

use RuntimeException;

class Runner
{
    /** @var (callable(string,string,array<string,mixed>): array<string,mixed>)|null */
    public static $callUsing = null;
    /** @param array<string, mixed> $paths @return array{filename:string,token:string,content:string} */
    public static function generate(array $paths): array
    {
        $token = bin2hex(random_bytes(32));
        $filename = 'install-'.bin2hex(random_bytes(8)).'.php';
        $stub = file_get_contents(__DIR__.'/../../stubs/install.php.stub');
        if ($stub === false) {
            throw new RuntimeException('Runner stub missing.');
        }

        return [
            'filename' => $filename,
            'token' => $token,
            'content' => strtr($stub, [
                '{{TOKEN}}' => $token,
                '{{APP_ROOT_RELATIVE}}' => self::relativePath((string) ($paths['public_root'] ?? 'public'), (string) $paths['app_root']),
                '{{MODE}}' => (string) ($paths['mode'] ?? 'simple'),
                '{{VENDOR_AUTOLOAD}}' => (string) ($paths['vendor_autoload'] ?? ''),
                '{{SHARED_ROOT}}' => (string) ($paths['shared_root'] ?? ''),
                '{{RELEASE_ROOT}}' => (string) ($paths['release_root'] ?? ''),
                '{{FTP_ROOT_RELATIVE}}' => self::relativePath((string) ($paths['public_root'] ?? 'public'), ''),
                '{{PUBLIC_DIR}}' => trim((string) ($paths['public_root'] ?? 'public'), '/'),
            ]),
        ];
    }

    public static function bootloader(array $paths, string $releaseId): string
    {
        $stub = file_get_contents(__DIR__.'/../../stubs/versioned-index.php.stub');
        if ($stub === false) {
            throw new RuntimeException('Bootloader stub missing.');
        }

        return strtr($stub, [
            '{{RELEASE_ID}}' => $releaseId,
            '{{RELEASE_ROOT}}' => (string) $paths['release_root'],
            '{{SHARED_ROOT}}' => (string) $paths['shared_root'],
            '{{VENDOR_AUTOLOAD}}' => (string) ($paths['vendor_autoload'] ?? ''),
        ]);
    }

    /** @param list<array{cmd:string,fail_on_error:bool}> $steps @return array<string,mixed> */
    public static function call(string $appUrl, string $filename, string $token, array $steps): array
    {
        return self::callPayload($appUrl, $filename, ['token' => $token, 'commands' => $steps]);
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public static function callPayload(string $appUrl, string $filename, array $payload): array
    {
        $appUrl = self::baseUrl($appUrl);
        if (self::$callUsing !== null) {
            return (self::$callUsing)($appUrl, $filename, $payload);
        }

        $url = rtrim($appUrl, '/').'/'.$filename;
        $payload = json_encode($payload) ?: '{}';
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\nAccept: application/json\r\n",
                'content' => $payload,
                'ignore_errors' => true,
                'timeout' => 120,
            ],
        ]);

        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            throw new RuntimeException("Runner request failed: {$url}");
        }
        $json = json_decode($body, true);
        if (!is_array($json)) {
            throw new RuntimeException(self::invalidJsonMessage($url, $body));
        }
        if (($json['ok'] ?? false) !== true) {
            throw new RuntimeException('Runner failed: '.json_encode($json));
        }

        return $json;
    }

    private static function baseUrl(string $appUrl): string
    {
        return preg_replace('#/install-(?:\*|[a-f0-9]{16})\.php/?$#', '', rtrim($appUrl, '/')) ?? $appUrl;
    }

    private static function relativePath(string $from, string $to): string
    {
        $fromParts = self::pathParts($from);
        $toParts = self::pathParts($to);
        while ($fromParts && $toParts && $fromParts[0] === $toParts[0]) {
            array_shift($fromParts);
            array_shift($toParts);
        }
        $relative = array_merge(array_fill(0, count($fromParts), '..'), $toParts);

        return $relative ? implode('/', $relative) : '.';
    }

    /** @return list<string> */
    private static function pathParts(string $path): array
    {
        return array_values(array_filter(explode('/', trim(str_replace('\\', '/', $path), '/')), fn ($part) => $part !== '' && $part !== '.'));
    }

    private static function invalidJsonMessage(string $url, string $body): string
    {
        $snippet = trim(preg_replace('/\s+/', ' ', strip_tags($body)) ?? $body);
        $snippet = mb_substr($snippet, 0, 500);

        return "Runner returned invalid JSON from {$url}: {$snippet}";
    }
}
