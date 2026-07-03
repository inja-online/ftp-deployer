<?php

return [
    'default' => env('FTP_DEPLOYER_PROFILE', 'production'),

    'profiles' => [
        'production' => [
            'ftp' => [
                'host' => env('FTP_DEPLOYER_HOST'),
                'username' => env('FTP_DEPLOYER_USERNAME'),
                'password' => env('FTP_DEPLOYER_PASSWORD'),
                'port' => (int) env('FTP_DEPLOYER_PORT', 21),
                'ssl' => (bool) env('FTP_DEPLOYER_SSL', false),
                'passive' => (bool) env('FTP_DEPLOYER_PASSIVE', true),
                'timeout' => (int) env('FTP_DEPLOYER_TIMEOUT', 90),
            ],

            'paths' => [
                'mode' => env('FTP_DEPLOYER_MODE', 'simple'),
                'ftp_root' => env('FTP_DEPLOYER_FTP_ROOT', '/'),
                'app_root' => env('FTP_DEPLOYER_APP_ROOT', 'app'),
                'public_root' => env('FTP_DEPLOYER_PUBLIC_ROOT', 'app/public'),
                'app_url' => env('FTP_DEPLOYER_APP_URL'),
                'filesystem_root' => env('FTP_DEPLOYER_FILESYSTEM_ROOT'),
                'release_root' => env('FTP_DEPLOYER_RELEASE_ROOT', '../app/releases'),
                'shared_root' => env('FTP_DEPLOYER_SHARED_ROOT', '../app/shared'),
                'current_path' => env('FTP_DEPLOYER_CURRENT_PATH', '../app/current'),
            ],

            'exclusions' => [
                '.git/',
                '.github/',
                '.idea/',
                '.vscode/',
                'node_modules/',
                'tests/',
                'storage/app/public/',
                'storage/framework/cache/',
                'storage/framework/sessions/',
                'storage/framework/testing/',
                'storage/framework/views/',
                'storage/logs/',
                'bootstrap/cache/*.php',
                '.env',
                '.env.*',
                '.ftp-deployer/',
            ],

            'hooks' => [
                'before_deploy' => [],
                'after_deploy' => [],
            ],

            'remote_commands' => [
                'migrate --force',
                'optimize:clear',
                'optimize',
                ['command' => 'storage:link', 'ignore_failures' => true],
            ],

            'archive' => [
                'enabled' => (bool) env('FTP_DEPLOYER_ARCHIVE_ENABLED', true),
            ],

            'frontend' => [
                'enabled' => true,
                'auto_detect' => true,
                'builds' => [],
                'warn_if_inputs_changed_without_output_change' => true,
            ],
        ],
    ],
];
