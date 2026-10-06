<?php
declare(strict_types=1);
date_default_timezone_set('Europe/London');

// Deployment configuration lives outside the public document root. Never
// commit real connection values; environment variables are the portable path.
$privateConfig = getenv('PROGRAMME_CONFIG_FILE') ?: (is_file(__DIR__ . '/runtime.private.php') ? __DIR__ . '/runtime.private.php' : dirname(__DIR__, 3) . '/private/config.php');
if (@is_file($privateConfig)) {
    $config = require $privateConfig;
    if (!is_array($config) || !isset($config['db']['dsn'], $config['db']['user'], $config['db']['pass'])) {
        throw new RuntimeException('Programme private database configuration is invalid.');
    }
    return $config;
}

$dsn = trim((string) getenv('PROGRAMME_DB_DSN'));
$user = trim((string) getenv('PROGRAMME_DB_USER'));
$password = getenv('PROGRAMME_DB_PASSWORD');
if ($dsn === '' || $user === '' || $password === false) {
    throw new RuntimeException('Programme database configuration is missing. Configure the private file or environment variables.');
}
return ['db' => ['dsn' => $dsn, 'user' => $user, 'pass' => $password, 'options' => [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]]];
