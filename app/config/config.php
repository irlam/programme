<?php
declare(strict_types=1);
date_default_timezone_set('Europe/London');
return [
  'db' => [
    'dsn'  => 'mysql:host=10.35.233.124;port=3306;dbname=k87747_programme;charset=utf8mb4',
    'user' => 'k87747_programme',
    'pass' => 'Subaru5554346',
    'options' => [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ],
  ],
];
