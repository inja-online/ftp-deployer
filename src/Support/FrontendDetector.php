<?php

namespace InjaOnline\FTPDeployer\Support;

class FrontendDetector
{
    /** @param array<string, mixed> $config */
    public function __construct(private readonly string $root, private readonly array $config)
    {
    }

    /** @return array{builds:array<string,array<string,mixed>>,inputs:array<string,mixed>,warnings:list<string>} */
    public function detect(): array
    {
        $inputs = $this->inputs();
        $warnings = [];
        $builds = [];

        if (($this->config['enabled'] ?? true) === false) {
            return compact('builds', 'inputs', 'warnings');
        }

        if (!empty($this->config['builds'])) {
            foreach ($this->config['builds'] as $name => $build) {
                $builds[$name] = $this->build($build['type'] ?? 'custom', $build['manifest'], $build['outputs']);
            }

            return compact('builds', 'inputs', 'warnings');
        }

        if (!($this->config['auto_detect'] ?? true) || !file_exists($this->root.'/package.json')) {
            return compact('builds', 'inputs', 'warnings');
        }

        foreach ([
            ['vite', 'public/build/manifest.json', ['public/build/']],
            ['vite', 'public/build/.vite/manifest.json', ['public/build/']],
            ['mix', 'public/mix-manifest.json', ['public/css/', 'public/js/']],
        ] as [$type, $manifest, $outputs]) {
            if (file_exists($this->root.'/'.$manifest)) {
                $builds['app'] = $this->build($type, $manifest, $outputs);

                return compact('builds', 'inputs', 'warnings');
            }
        }

        $warnings[] = 'package.json found but no build manifest detected — did you run your build step?';

        return compact('builds', 'inputs', 'warnings');
    }

    /** @param list<string> $outputs */
    private function build(string $type, string $manifest, array $outputs): array
    {
        return [
            'type' => $type,
            'manifest_file' => $manifest,
            'manifest_hash' => is_file($this->root.'/'.$manifest) ? md5_file($this->root.'/'.$manifest) : null,
            'output_paths' => $outputs,
        ];
    }

    private function inputs(): array
    {
        return [
            'package_json' => $this->hash('package.json'),
            'lock_files' => array_filter([
                'package-lock.json' => $this->hash('package-lock.json'),
                'yarn.lock' => $this->hash('yarn.lock'),
                'pnpm-lock.yaml' => $this->hash('pnpm-lock.yaml'),
                'bun.lock' => $this->hash('bun.lock'),
                'bun.lockb' => $this->hash('bun.lockb'),
            ]),
            'config_files' => array_filter([
                'webpack.mix.js' => $this->hash('webpack.mix.js'),
                'vite.config.js' => $this->hash('vite.config.js'),
                'vite.config.ts' => $this->hash('vite.config.ts'),
            ]),
        ];
    }

    private function hash(string $path): ?string
    {
        return is_file($this->root.'/'.$path) ? md5_file($this->root.'/'.$path) : null;
    }
}
