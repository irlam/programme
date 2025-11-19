<?php
declare(strict_types=1);
$root = dirname(__DIR__);
header('Content-Type: text/plain; charset=utf-8');
echo "Importer Self-Test\n";
echo "Docroot: $root\n\n";

function line($k,$v){ echo str_pad($k,28).": $v\n"; }

$autoload = $root . '/libs/autoload-phpss.php';
line('autoload-phpss.php', is_file($autoload) ? 'found' : 'MISSING');
if (is_file($autoload)) {
  require_once $autoload;
  line('PhpSpreadsheet class', class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class) ? 'OK' : 'FAIL');
}

$importer = $root . '/app/Lib/ImportLookahead.php';
line('ImportLookahead.php', is_file($importer) ? 'found' : 'MISSING');
if (is_file($importer)) {
  require_once $importer;
  line('App\\Lib\\ImportLookahead', class_exists(\App\Lib\ImportLookahead::class) ? 'OK' : 'FAIL');
}

$preview = $root . '/api/import/preview.php';
line('api/import/preview.php', is_file($preview) ? 'found' : 'MISSING');

echo "\nDone.\n";