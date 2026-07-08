<?php

declare(strict_types=1);

return [
    'updater_source_path' => 'src',

    'show_versions_before_login' => false,

    'installer_version' => '',

    'installer_commit' => '',

    'installer_repository' => 'oakengine/installer',

    'project_api_url' => 'https://srcma.eu',

    'project_api_token' => '',

    'github_token' => '',

    'password' => '',

    'api_base_url' => 'https://api.github.com',

    'target_directory' => '../../',

    // Directory where the installer writes its log file (`oak-installer.log`).
    // It MUST live outside the installed project (and therefore outside the
    // `<target>/var/` tree that gets wiped when the application cache is
    // cleared), otherwise cache clears delete the log. Use an absolute path for
    // a custom location, or a relative path resolved against the installer root.
    // Empty = default `<installer-root>/logs`.
    'log_directory' => '',

    'exclude_folders' => [
        '.git',
        '.github',
        '.ai',
        '.developer',
        '.idea',
        '.junie',
        'node_modules',
        'tests',
        'docs',
        'doc',
    ],

    'exclude_files' => [
        '.gitignore',
        '.gitattributes',
        'README.md',
        'LICENSE',
        '.php-cs-fixer.dist.php',
        'phpstan-global.neon',
        'rector.php',
        'phpunit-coverage.xml.dist',
        'phpunit-no-coverage.xml.dist',
        '.env.dev',
        '.env.test',
        'docker-compose.yml',
    ],

    'default_language' => 'en',
];
