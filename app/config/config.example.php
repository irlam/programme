<?php
// Copy outside the public document root to ../private/config.php, or set
// PROGRAMME_CONFIG_FILE to a private path. Never place real values in Git.
return ['db' => [
    'dsn' => 'mysql:host=localhost;port=3306;dbname=programme;charset=utf8mb4',
    'user' => 'programme',
    'pass' => 'replace-in-private-file',
    'options' => [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false],
]];
