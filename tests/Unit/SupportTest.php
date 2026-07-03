<?php

namespace InjaOnline\FTPDeployer\Tests\Unit;

use InjaOnline\FTPDeployer\Commands\DeployCommand;
use InjaOnline\FTPDeployer\FTPDeployer;
use InjaOnline\FTPDeployer\Support\ArchiveBuilder;
use InjaOnline\FTPDeployer\Support\Exclusions;
use InjaOnline\FTPDeployer\Support\FrontendDetector;
use InjaOnline\FTPDeployer\Support\Manifest;
use InjaOnline\FTPDeployer\Support\PathMapper;
use InjaOnline\FTPDeployer\Support\ReleaseBuilder;
use InjaOnline\FTPDeployer\Support\Runner;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use ZipArchive;

class SupportTest extends TestCase
{
    public function test_exclusions_match_paths_and_globs(): void
    {
        $this->assertTrue(Exclusions::matches('storage/framework/cache/foo.php', ['storage/framework/cache/']));
        $this->assertTrue(Exclusions::matches('bootstrap/cache/config.php', ['bootstrap/cache/*.php']));
        $this->assertFalse(Exclusions::matches('app/Models/User.php', ['storage/']));
    }

    public function test_manifest_diff_and_vendor_detection(): void
    {
        $local = ['inputs' => ['composer' => ['composer_json' => 'a', 'composer_lock' => 'b']], 'groups' => ['frontend' => ['builds' => []]], 'files' => ['a.php' => ['hash' => '1', 'remote_path' => 'app/a.php']]];
        $remote = ['inputs' => ['composer' => ['composer_json' => 'a', 'composer_lock' => 'x']], 'groups' => ['frontend' => ['builds' => []]], 'files' => ['a.php' => ['hash' => '1', 'remote_path' => 'old-release/a.php'], 'old.php' => ['hash' => '9', 'remote_path' => 'app/old.php']]];

        $diff = Manifest::diff($local, $remote);

        $this->assertArrayHasKey('a.php', $diff['uploads']);
        $this->assertSame(['app/old.php'], $diff['deletes']);
        $this->assertTrue(Manifest::vendorChanged($local, $remote));
        $this->assertSame('vendor-a-b.zip', Manifest::vendorArchiveName($local));
    }

    public function test_manifest_deletes_frontend_assets_only_inside_configured_outputs(): void
    {
        $local = [
            'groups' => ['frontend' => ['builds' => ['app' => ['manifest_hash' => 'new', 'output_paths' => ['public/build/'], 'assets' => ['public/build/new.js' => 'public_html/build/new.js']]]]],
            'files' => [],
        ];
        $remote = [
            'groups' => ['frontend' => ['builds' => ['app' => ['manifest_hash' => 'old', 'assets' => ['public/build/old.js' => 'public_html/build/old.js', 'public/keep.txt' => 'public_html/keep.txt']]]]],
            'files' => [],
        ];

        $this->assertSame(['public_html/build/old.js'], Manifest::diff($local, $remote)['deletes']);
    }

    public function test_frontend_detection_variants(): void
    {
        $root = $this->tmp();
        file_put_contents($root.'/package.json', '{}');
        mkdir($root.'/public/build', 0777, true);
        file_put_contents($root.'/public/build/manifest.json', '{}');

        $detected = (new FrontendDetector($root, ['enabled' => true, 'auto_detect' => true, 'builds' => []]))->detect();

        $this->assertSame('vite', $detected['builds']['app']['type']);
        $this->assertSame(['public/build/'], $detected['builds']['app']['output_paths']);
    }

    public function test_frontend_warns_when_package_exists_without_manifest(): void
    {
        $root = $this->tmp();
        file_put_contents($root.'/package.json', '{}');

        $detected = (new FrontendDetector($root, ['enabled' => true, 'auto_detect' => true, 'builds' => []]))->detect();

        $this->assertSame(['package.json found but no build manifest detected — did you run your build step?'], $detected['warnings']);
    }

    public function test_frontend_input_hashes_cover_lockfiles_and_config_for_stale_warnings(): void
    {
        $root = $this->tmp();
        file_put_contents($root.'/package.json', '{}');
        file_put_contents($root.'/package-lock.json', 'npm');
        file_put_contents($root.'/yarn.lock', 'yarn');
        file_put_contents($root.'/pnpm-lock.yaml', 'pnpm');
        file_put_contents($root.'/bun.lock', 'bun');
        file_put_contents($root.'/bun.lockb', 'bunb');
        file_put_contents($root.'/vite.config.js', 'vite');
        file_put_contents($root.'/webpack.mix.js', 'mix');
        mkdir($root.'/public/build', 0777, true);
        file_put_contents($root.'/public/build/manifest.json', '{}');

        $inputs = (new FrontendDetector($root, ['enabled' => true]))->detect()['inputs'];

        $this->assertSame(md5('{}'), $inputs['package_json']);
        $this->assertSame(['package-lock.json', 'yarn.lock', 'pnpm-lock.yaml', 'bun.lock', 'bun.lockb'], array_keys($inputs['lock_files']));
        $this->assertSame(['webpack.mix.js', 'vite.config.js'], array_keys($inputs['config_files']));
    }

    public function test_frontend_stale_build_warning_detection(): void
    {
        $local = ['inputs' => ['frontend' => ['package_json' => 'new']], 'groups' => ['frontend' => ['builds' => ['app' => ['manifest_hash' => 'same']]]]];
        $remote = ['inputs' => ['frontend' => ['package_json' => 'old']], 'groups' => ['frontend' => ['builds' => ['app' => ['manifest_hash' => 'same']]]]];

        $this->assertTrue(Manifest::frontendInputsChangedWithoutBuildChange($local, $remote));

        $local['groups']['frontend']['builds']['app']['manifest_hash'] = 'changed';
        $this->assertFalse(Manifest::frontendInputsChangedWithoutBuildChange($local, $remote));
    }

    public function test_path_mapping_simple_and_versioned(): void
    {
        $simple = new PathMapper(['mode' => 'simple', 'ftp_root' => 'ftp-root', 'app_root' => 'app', 'public_root' => 'public_html']);
        $versioned = new PathMapper(['mode' => 'versioned', 'release_root' => 'app/releases', 'public_root' => 'public_html']);

        $this->assertSame('ftp-root/app/routes/web.php', $simple->remotePath('routes/web.php'));
        $this->assertSame('ftp-root/public_html/build/app.js', $simple->remotePath('public/build/app.js'));
        $this->assertStringStartsWith('app/releases/', $versioned->remotePath('routes/web.php'));
        $this->assertSame('public_html/build/app.js', $versioned->remotePath('public/build/app.js'));
    }

    public function test_versioned_path_mapping_strips_filesystem_root_from_absolute_paths(): void
    {
        $mapper = new PathMapper([
            'mode' => 'versioned',
            'ftp_root' => '/public_html/laravelapp.inja.online',
            'filesystem_root' => '/home/tinabwoo/public_html/laravelapp.inja.online',
            'release_root' => '/home/tinabwoo/public_html/laravelapp.inja.online/app/releases',
            'shared_root' => '/home/tinabwoo/public_html/laravelapp.inja.online/app/shared',
            'current_path' => '/home/tinabwoo/public_html/laravelapp.inja.online/app/current',
            'app_root' => '/home/tinabwoo/public_html/laravelapp.inja.online/app',
            'public_root' => 'public_html',
        ]);

        $this->assertSame('public_html/laravelapp.inja.online/app/releases/'.$mapper->releaseId().'/routes/web.php', $mapper->remotePath('routes/web.php'));
        $this->assertSame('/home/tinabwoo/public_html/laravelapp.inja.online/app/releases/'.$mapper->releaseId(), $mapper->releaseRoot());
        $this->assertSame('public_html/laravelapp.inja.online/app/current', $mapper->configuredRemotePath('/home/tinabwoo/public_html/laravelapp.inja.online/app/current'));
    }

    public function test_path_mapper_treats_dot_public_root_as_ftp_root(): void
    {
        $mapper = new PathMapper(['mode' => 'versioned', 'ftp_root' => '/', 'app_root' => 'app', 'public_root' => '.', 'release_root' => 'app/releases', 'shared_root' => 'app/shared', 'current_path' => 'app/current']);

        $this->assertSame('install-test.php', $mapper->publicRemotePath('install-test.php'));
        $this->assertSame('index.php', $mapper->publicRemotePath('index.php'));
    }

    public function test_runner_generation_has_token_and_json_shape(): void
    {
        $runner = Runner::generate(['app_root' => 'app', 'mode' => 'simple']);

        $this->assertMatchesRegularExpression('/^install-[a-f0-9]{16}\.php$/', $runner['filename']);
        $this->assertSame(64, strlen($runner['token']));
        $this->assertStringContainsString($runner['token'], $runner['content']);
        $this->assertStringContainsString("const SIMPLE_APP_ROOT = __DIR__.'/../app'", $runner['content']);
        $this->assertStringContainsString('Content-Type: application/json', $runner['content']);
    }

    public function test_runner_call_strips_accidental_install_php_from_app_url(): void
    {
        Runner::$callUsing = fn (string $appUrl, string $filename, array $payload): array => compact('appUrl', 'filename', 'payload');

        try {
            $result = Runner::callPayload('https://example.test/install-*.php', 'install-abc.php', ['token' => 't']);
        } finally {
            Runner::$callUsing = null;
        }

        $this->assertSame('https://example.test', $result['appUrl']);
    }

    public function test_runner_invalid_json_error_includes_response_snippet(): void
    {
        $method = new \ReflectionMethod(Runner::class, 'invalidJsonMessage');
        $method->setAccessible(true);

        $message = $method->invoke(null, 'https://example.test/install.php', "<html>\n<body>500 Server Error</body>\n</html>");

        $this->assertSame('Runner returned invalid JSON from https://example.test/install.php: 500 Server Error', $message);
    }

    public function test_runner_generation_supports_domain_root_public_path(): void
    {
        $runner = Runner::generate(['app_root' => 'app', 'public_root' => '.', 'mode' => 'simple'])['content'];

        $this->assertStringContainsString("const SIMPLE_APP_ROOT = __DIR__.'/app'", $runner);
    }

    public function test_bootloader_generation_and_versioned_runner_paths(): void
    {
        $paths = ['mode' => 'versioned', 'app_root' => 'app', 'release_root' => 'app/releases/abc', 'shared_root' => 'app/shared', 'current_path' => 'app/current', 'vendor_autoload' => 'app/releases/abc/20260702210855/vendor/autoload.php'];
        $runner = Runner::generate($paths)['content'];
        $bootloader = Runner::bootloader($paths, '20260702210855');

        $this->assertStringContainsString("const MODE = 'versioned'", $runner);
        $this->assertStringContainsString("const RELEASE_ROOT = 'app/releases/abc'", $runner);
        $this->assertStringContainsString('$envPath = $sharedRoot', $runner);
        $this->assertStringContainsString('@unlink($cacheFile)', $runner);
        $this->assertStringContainsString("$"."release = rtrim('app/releases/abc', '/').'/20260702210855'", $bootloader);
        $this->assertStringNotContainsString("file_get_contents($"."currentFile)", $bootloader);
        $this->assertStringContainsString("$"."shared = rtrim('app/shared'", $bootloader);
        $this->assertStringContainsString("require_once 'app/releases/abc/20260702210855/vendor/autoload.php'", $bootloader);
        $this->assertStringContainsString("$"."app = require_once $"."release.'/bootstrap/app.php'", $bootloader);
    }

    public function test_runner_stub_supports_cached_vendor_extract_and_shared_vendor_autoload(): void
    {
        $runner = Runner::generate(['app_root' => 'app', 'public_root' => 'public', 'mode' => 'versioned', 'release_root' => 'app/releases/20260702210855', 'shared_root' => 'app/shared', 'vendor_autoload' => 'app/releases/20260702210855/vendor/autoload.php'])['content'];

        $this->assertStringContainsString("const VENDOR_AUTOLOAD = 'app/releases/20260702210855/vendor/autoload.php'", $runner);
        $this->assertStringContainsString('Reusing extracted archive at {$destination}', $runner);
        $this->assertStringContainsString('function vendorAutoloadPath(): string', $runner);
        $this->assertStringContainsString('require_once vendorAutoloadPath()', $runner);
    }

    public function test_remote_command_translation(): void
    {
        $deployer = new FTPDeployer(['remote_commands' => ['migrate --force', ['command' => 'storage:link', 'ignore_failures' => true]]]);

        $this->assertSame([
            ['cmd' => 'migrate --force', 'fail_on_error' => true],
            ['cmd' => 'storage:link', 'fail_on_error' => false],
        ], $deployer->remoteCommandSteps());
    }

    public function test_versioned_archive_mode_uploads_vendor_when_archive_missing_and_skips_when_present(): void
    {
        $release = $this->tmp();
        file_put_contents($release.'/artisan', '');
        file_put_contents($release.'/app.php', '<?php echo "ok";');
        mkdir($release.'/vendor', 0777, true);
        file_put_contents($release.'/vendor/autoload.php', '<?php');

        $local = ['inputs' => ['composer' => ['composer_json' => 'aaa', 'composer_lock' => 'bbb']]];
        $deployer = new FTPDeployer(['paths' => ['mode' => 'versioned']]);
        $method = new \ReflectionMethod($deployer, 'buildArchives');
        $method->setAccessible(true);

        $missing = $method->invoke(
            $deployer,
            $release,
            new PathMapper(['mode' => 'versioned', 'ftp_root' => '/public_html', 'app_root' => 'app', 'public_root' => 'public', 'release_root' => 'app/releases', 'shared_root' => 'app/shared', 'current_path' => 'app/current']),
            $local,
            true,
            fn (string $remote) => false,
            true,
            fn (string $line) => null,
        );
        $present = $method->invoke(
            $deployer,
            $release,
            new PathMapper(['mode' => 'versioned', 'ftp_root' => '/public_html', 'app_root' => 'app', 'public_root' => 'public', 'release_root' => 'app/releases', 'shared_root' => 'app/shared', 'current_path' => 'app/current']),
            $local,
            true,
            fn (string $remote) => true,
            true,
            fn (string $line) => null,
        );

        $this->assertSame(['app.zip', 'vendor.zip'], array_column($missing, 'name'));
        $this->assertSame(true, $missing[1]['upload']);
        $this->assertSame(false, $present[1]['upload']);
        $this->assertSame('public_html/.ftp-deployer/archives/vendor-aaa-bbb.zip', $missing[1]['remote']);
        $this->assertStringContainsString('app/releases/', $missing[1]['destination']);
        $this->assertStringEndsWith('/vendor', $missing[1]['destination']);
        $this->assertStringContainsString('app/releases/', $missing[1]['skip_if_exists']);
        $this->assertStringEndsWith('/vendor/autoload.php', $missing[1]['skip_if_exists']);
        $this->assertArrayNotHasKey('link', $missing[1]);
        $this->assertStringContainsString('app/releases/', $present[1]['destination']);
        $this->assertStringEndsWith('/vendor', $present[1]['destination']);
        $this->assertStringContainsString('app/releases/', $present[1]['skip_if_exists']);
        $this->assertStringEndsWith('/vendor/autoload.php', $present[1]['skip_if_exists']);
        $this->assertArrayNotHasKey('link', $present[1]);
        $payloadArchive = array_filter(['archive' => $present[1]['filesystem'], 'destination' => $present[1]['destination'], 'skip_if_exists' => $present[1]['skip_if_exists'] ?? null], fn ($value) => $value !== null);
        $this->assertSame($present[1]['filesystem'], $payloadArchive['archive']);
        $this->assertStringContainsString('app/releases/', $payloadArchive['destination']);
        $this->assertStringEndsWith('/vendor', $payloadArchive['destination']);
        $this->assertStringContainsString('app/releases/', $payloadArchive['skip_if_exists']);
        $this->assertStringEndsWith('/vendor/autoload.php', $payloadArchive['skip_if_exists']);

        @unlink($release.'/vendor/autoload.php');
        @rmdir($release.'/vendor');
        @unlink($release.'/artisan');
        @unlink($release.'/app.php');
        @rmdir($release);
    }

    public function test_versioned_archive_mode_skips_vendor_archive_when_vendor_dir_missing(): void
    {
        $release = $this->tmp();
        file_put_contents($release.'/app.php', '<?php echo "ok";');

        $deployer = new FTPDeployer(['paths' => ['mode' => 'versioned']]);
        $method = new \ReflectionMethod($deployer, 'buildArchives');
        $method->setAccessible(true);
        $archives = $method->invoke(
            $deployer,
            $release,
            new PathMapper(['mode' => 'versioned', 'ftp_root' => '/public_html', 'app_root' => 'app', 'public_root' => 'public', 'release_root' => 'app/releases', 'shared_root' => 'app/shared', 'current_path' => 'app/current']),
            ['inputs' => ['composer' => ['composer_json' => 'aaa', 'composer_lock' => 'bbb']]],
            true,
            fn (string $remote) => false,
            true,
            fn (string $line) => null,
        );

        $this->assertSame(['app.zip'], array_column($archives, 'name'));
        @unlink($archives[0]['local']);
    }

    public function test_archive_cleanup_keeps_vendor_archives_and_deletes_app_archives(): void
    {
        $deployer = new FTPDeployer([]);
        $method = new \ReflectionMethod($deployer, 'shouldDeleteArchiveRemote');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($deployer, ['name' => 'app.zip', 'local' => '/tmp/app.zip', 'remote' => '.ftp-deployer/archives/app-random.zip', 'filesystem' => '/tmp/app.zip', 'destination' => '/app', 'upload' => true]));
        $this->assertFalse($method->invoke($deployer, ['name' => 'vendor.zip', 'local' => '/tmp/vendor.zip', 'remote' => '.ftp-deployer/archives/vendor-aaa-bbb.zip', 'filesystem' => '/tmp/vendor.zip', 'destination' => '/app/vendor', 'upload' => true]));
    }

    public function test_local_hooks_run_and_failures_abort(): void
    {
        $command = new DeployCommand();
        $output = new class () {
            public string $buffer = '';

            public function write(string $buffer): void
            {
                $this->buffer .= $buffer;
            }
        };
        $property = new \ReflectionProperty($command, 'output');
        $property->setAccessible(true);
        $property->setValue($command, $output);
        $method = new \ReflectionMethod($command, 'runHooks');
        $method->setAccessible(true);

        $logs = [];
        $method->invokeArgs($command, [['printf hook-ok'], false, &$logs]);
        $this->assertSame('hook-ok', $output->buffer);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Local hook failed: php -r "exit(7);"');
        $method->invokeArgs($command, [['php -r "exit(7);"'], false, &$logs]);
    }

    public function test_app_archive_includes_public_build_and_excludes_dev_files_vendor_and_sqlite(): void
    {
        $root = $this->tmp();
        mkdir($root.'/public/build', 0777, true);
        mkdir($root.'/bootstrap/cache', 0777, true);
        mkdir($root.'/vendor/pkg', 0777, true);
        mkdir($root.'/database', 0777, true);
        file_put_contents($root.'/app.php', 'app');
        file_put_contents($root.'/.editorconfig', 'root = true');
        file_put_contents($root.'/package.json', '{}');
        file_put_contents($root.'/phpstan.neon', 'parameters:');
        file_put_contents($root.'/phpunit.xml', '<phpunit/>');
        file_put_contents($root.'/pint.json', '{}');
        file_put_contents($root.'/vite.config.js', 'export default {};');
        file_put_contents($root.'/public/build/app.js', 'js');
        file_put_contents($root.'/vendor/pkg/file.php', 'vendor');
        file_put_contents($root.'/database/database.sqlite', 'db');

        $deployer = new FTPDeployer(['paths' => ['mode' => 'simple']]);
        $method = new \ReflectionMethod($deployer, 'buildArchives');
        $method->setAccessible(true);
        $archives = $method->invoke(
            $deployer,
            $root,
            new PathMapper(['mode' => 'simple', 'ftp_root' => '/public_html', 'app_root' => 'app', 'public_root' => 'public']),
            ['inputs' => ['composer' => ['composer_json' => 'aaa', 'composer_lock' => 'bbb']]],
            false,
            fn (string $remote) => false,
            false,
            fn (string $line) => null,
        );

        $zip = new ZipArchive();
        $zip->open($archives[0]['local']);
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        $zip->close();
        @unlink($archives[0]['local']);

        $this->assertContains('app.php', $names);
        $this->assertContains('public/build/app.js', $names);
        $this->assertContains('bootstrap/cache/', $names);
        $this->assertNotContains('.editorconfig', $names);
        $this->assertNotContains('package.json', $names);
        $this->assertNotContains('phpstan.neon', $names);
        $this->assertNotContains('phpunit.xml', $names);
        $this->assertNotContains('pint.json', $names);
        $this->assertNotContains('vite.config.js', $names);
        $this->assertNotContains('vendor/pkg/file.php', $names);
        $this->assertNotContains('database/database.sqlite', $names);
    }

    public function test_archive_builder_rejects_unsafe_paths(): void
    {
        foreach (['/abs.php', '../up.php', 'dir/../up.php', 'C:/x.php', '//server/share.php'] as $path) {
            try {
                ArchiveBuilder::assertSafePath($path);
                $this->fail("Expected unsafe path rejection for {$path}");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('Unsafe ZIP entry path', $e->getMessage());
            }
        }
    }

    public function test_archive_path_mapping_uses_filesystem_root_and_random_archive_names(): void
    {
        $mapper = new PathMapper(['mode' => 'simple', 'ftp_root' => '/public_html/laravelapp.inja.online', 'app_root' => 'app', 'public_root' => 'app/public', 'filesystem_root' => '/home/u/public_html/laravelapp.inja.online']);

        $this->assertSame('public_html/laravelapp.inja.online/.ftp-deployer/archives/app-random.zip', $mapper->archiveRemotePath('app-random.zip'));
        $this->assertSame('/home/u/public_html/laravelapp.inja.online/.ftp-deployer/archives/app-random.zip', $mapper->archiveFilesystemPath('app-random.zip'));
        $this->assertSame('/home/u/public_html/laravelapp.inja.online/app', $mapper->appFilesystemPath());
        $this->assertMatchesRegularExpression('/^app-[a-f0-9]{32}\.zip$/', 'app-'.bin2hex(random_bytes(16)).'.zip');
    }

    public function test_runner_stub_supports_extract_before_requirements_and_commands(): void
    {
        $runner = Runner::generate(['app_root' => 'app', 'mode' => 'simple'])['content'];

        $this->assertStringContainsString("$"."payload['extract']", $runner);
        $this->assertStringContainsString('class_exists(ZipArchive::class)', $runner);
        $this->assertStringContainsString('Unsafe archive entry', $runner);
        $this->assertLessThan(strpos($runner, '$requirements = [];'), strpos($runner, "['extract']"));
        $this->assertStringContainsString("foreach ((\$payload['commands'] ?? []) as \$step)", $runner);
        $this->assertStringContainsString('new StringInput($cmd', $runner);
    }

    private function tmp(): string
    {
        $root = sys_get_temp_dir().'/ftp-deployer-test-'.bin2hex(random_bytes(4));
        mkdir($root, 0777, true);

        return $root;
    }

    private function removeTmp(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }

    public function test_release_builder_creates_empty_laravel_runtime_directories(): void
    {
        $source = $this->tmp();
        file_put_contents($source.'/artisan', '');

        $builder = new ReleaseBuilder($source, [
            'storage/framework/cache/',
            'storage/framework/sessions/',
            'storage/framework/views/',
            'storage/logs/',
            'bootstrap/cache/*.php',
        ]);
        $release = $builder->build();

        try {
            foreach (['storage/framework/cache', 'storage/framework/cache/data', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs', 'bootstrap/cache'] as $path) {
                $this->assertDirectoryExists($release.'/'.$path);
            }
        } finally {
            $builder->cleanup();
            @unlink($source.'/artisan');
            @rmdir($source);
        }
    }

    public function test_release_builder_reuses_cached_vendor_for_same_composer_hash(): void
    {
        $source = $this->tmp();
        $composer = json_encode(['require' => ['missing/package-that-would-fail-if-installed' => '*']], JSON_THROW_ON_ERROR);
        file_put_contents($source.'/composer.json', $composer);
        $cache = sys_get_temp_dir().'/ftp-deployer-vendor-'.md5_file($source.'/composer.json').'-missing';
        $this->removeTmp($cache);
        mkdir($cache.'/vendor', 0777, true);
        file_put_contents($cache.'/vendor/autoload.php', '<?php return true;');
        $logs = [];

        $builder = new ReleaseBuilder($source, [], function (string $line) use (&$logs): void {
            $logs[] = $line;
        });
        $release = $builder->build();

        try {
            $this->assertFileExists($release.'/vendor/autoload.php');
            $this->assertContains("Composer vendor cache hit: {$cache}", $logs);
        } finally {
            $builder->cleanup();
            $this->removeTmp($source);
            $this->removeTmp($cache);
        }
    }

    public function test_release_builder_prunes_dev_only_vendor_from_temp_release(): void
    {
        $source = $this->tmp();
        $package = $this->tmp();
        mkdir($package.'/src', 0777, true);
        file_put_contents($package.'/composer.json', json_encode(['name' => 'acme/dev-only', 'autoload' => ['psr-4' => ['Acme\\DevOnly\\' => 'src/']]], JSON_THROW_ON_ERROR));
        file_put_contents($package.'/src/Tool.php', '<?php namespace Acme\\DevOnly; class Tool {}');
        file_put_contents($source.'/composer.json', json_encode([
            'repositories' => [['type' => 'path', 'url' => $package, 'options' => ['symlink' => true]]],
            'require' => ['php' => '^8.2'],
            'require-dev' => ['acme/dev-only' => '*'],
            'minimum-stability' => 'dev',
        ], JSON_THROW_ON_ERROR));

        exec('composer update --working-dir='.escapeshellarg($source).' --no-interaction 2>&1', $output, $exitCode);
        if ($exitCode !== 0) {
            $this->markTestSkipped('Composer fixture install failed: '.implode("\n", $output));
        }

        $builder = new ReleaseBuilder($source, []);
        $release = $builder->build();

        try {
            $this->assertFileExists($release.'/vendor/autoload.php');
            $this->assertFileDoesNotExist($release.'/vendor/acme/dev-only/composer.json');
        } finally {
            $builder->cleanup();
            $this->removeTmp($source);
            $this->removeTmp($package);
        }
    }

    public function test_release_builder_copies_symlinked_directories(): void
    {
        $source = $this->tmp();
        $targetDir = $this->tmp();

        // Create files in the target directory
        mkdir($targetDir.'/src');
        file_put_contents($targetDir.'/src/File.php', 'file-content');

        // Create symlink inside the source
        mkdir($source.'/vendor');
        symlink($targetDir, $source.'/vendor/mypkg');

        // Build release
        $builder = new ReleaseBuilder($source, []);
        $release = $builder->build();

        try {
            $this->assertDirectoryExists($release.'/vendor/mypkg');
            $this->assertDirectoryExists($release.'/vendor/mypkg/src');
            $this->assertFileExists($release.'/vendor/mypkg/src/File.php');
            $this->assertSame('file-content', file_get_contents($release.'/vendor/mypkg/src/File.php'));
        } finally {
            $builder->cleanup();
            @unlink($source.'/vendor/mypkg');
            @rmdir($source.'/vendor');
            @rmdir($source);
            @unlink($targetDir.'/src/File.php');
            @rmdir($targetDir.'/src');
            @rmdir($targetDir);
        }
    }

    public function test_deployer_temporarily_allows_runner_through_root_htaccess(): void
    {
        $deployer = new FTPDeployer([]);
        $method = new \ReflectionMethod($deployer, 'allowRunnerInHtaccess');
        $method->setAccessible(true);
        $htaccess = "<IfModule mod_rewrite.c>\n".
            "    RewriteEngine On\n\n".
            "    # Prevent directory listing\n".
            "    Options -Indexes\n\n".
            "    # Rewrite all requests to the app/public subdirectory\n".
            "    RewriteCond %{REQUEST_URI} !^/app/public/\n".
            "    RewriteRule ^(.*)$ app/public/$1 [L]\n".
            "</IfModule>\n";

        $patched = $method->invoke($deployer, $htaccess, 'install-abc123.php');

        $this->assertStringContainsString("RewriteCond %{REQUEST_URI} !^/install\\-abc123\\.php$\n    RewriteRule ^(.*)$ app/public/$1 [L]", $patched);
        $this->assertSame($patched, $method->invoke($deployer, $patched, 'install-abc123.php'));
    }

    public function test_runner_ensures_root_htaccess_creation(): void
    {
        $tmp = $this->tmp();
        $ftpRoot = $tmp.'/ftp';
        $appRoot = $ftpRoot.'/app';
        $publicRoot = $appRoot.'/public';
        mkdir($publicRoot, 0777, true);

        $runner = Runner::generate([
            'app_root' => 'app',
            'public_root' => 'app/public',
            'mode' => 'simple',
        ]);

        $content = $runner['content'];
        $this->assertStringContainsString('ensureRootHtaccess', $content);
        $this->assertStringContainsString("'app/public'", $content);
        $this->assertStringContainsString("'../..'", $content);

        // Save the runner in the public root
        $runnerFile = $publicRoot.'/install.php';
        file_put_contents($runnerFile, $content);

        // Extract and run ensureRootHtaccess
        $extracted = '';
        $lines = explode("\n", $content);
        $inFunction = false;
        foreach ($lines as $line) {
            if (str_contains($line, 'function ensureRootHtaccess()')) {
                $inFunction = true;
            }
            if ($inFunction) {
                $extracted .= $line."\n";
                if ($line === '}') {
                    $inFunction = false;
                }
            }
        }

        $runCode = "<?php\n".
            $extracted."\n".
            "print_r(ensureRootHtaccess());\n";

        $runFile = $publicRoot.'/run.php';
        file_put_contents($runFile, $runCode);

        // Run the script
        $output = shell_exec('cd '.escapeshellarg($publicRoot).' && php run.php');

        // The root htaccess should now exist!
        $htaccessPath = $ftpRoot.'/.htaccess';
        $this->assertFileExists($htaccessPath);

        $htaccessContent = file_get_contents($htaccessPath);
        $this->assertStringContainsString('RewriteCond %{REQUEST_URI} !^/app/public/', $htaccessContent);
        $this->assertStringContainsString('RewriteRule ^(.*)$ app/public/$1 [L]', $htaccessContent);
        $this->assertStringContainsString('<FilesMatch "^\.env|composer\.(json|lock)|package\.json">', $htaccessContent);
        $this->assertStringContainsString('Require all denied', $htaccessContent);

        // Clean up
        @unlink($runFile);
        @unlink($runnerFile);
        @unlink($htaccessPath);
        @rmdir($publicRoot);
        @rmdir($appRoot);
        @rmdir($ftpRoot);
        @rmdir($tmp);
    }
}
