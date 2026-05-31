<?php

declare(strict_types=1);

/**
 * @param array<string, mixed> $config
 * @param array<string, mixed> $versionMeta
 */
function handleAuthentication(array $config, bool $showVersionsBeforeLogin = false, array $versionMeta = []): void
{
    /** @var string $password */
    $password = '';
    if (isset($config['password']) && is_scalar($config['password'])) {
        $password = (string) $config['password'];
    }

    if ('' === $password) {
        return;
    }

    if (isset($_GET['logout'])) {
        if (PHP_SAPI !== 'cli') {
            session_destroy();
            session_start();
        }
        $_SESSION = [];
        header('Location: ?');
        exit;
    }

    if (isset($_SESSION['oak_installer_authenticated']) && true === $_SESSION['oak_installer_authenticated']) {
        return;
    }

    if ('POST' === $_SERVER['REQUEST_METHOD'] && isset($_POST['password']) && is_string($_POST['password'])) {
        $inputPassword = (string) $_POST['password'];

        if (password_verify($inputPassword, $password) || hash_equals($password, $inputPassword)) {
            $_SESSION['oak_installer_authenticated'] = true;
            $_SESSION['oak_installer_auth_time'] = time();
            header('Location: ?');
            exit;
        }

        renderLoginForm(__('incorrect_password'), $showVersionsBeforeLogin ? $versionMeta : []);
        exit;
    }

    renderLoginForm('', $showVersionsBeforeLogin ? $versionMeta : []);
    exit;
}
