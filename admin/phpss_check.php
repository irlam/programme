<?php
declare(strict_types=1);
header('Content-Type: text/plain; charset=utf-8');

echo "PhpSpreadsheet Health Check\n";

$libAuto = __DIR__ . '/../libs/autoload-phpss.php';
if (is_file($libAuto)) {
  require_once $libAuto;
  echo "Manual autoloader: loaded\n";
} else {
  echo "Manual autoloader: MISSING (libs/autoload-phpss.php)\n";
}

// Try requiring normal bootstrap after autoloader
$boot = __DIR__ . '/../api/_bootstrap.php';
if (is_file($boot)) {
  require_once $boot;
  echo "_bootstrap: loaded\n";
} else {
  echo "_bootstrap: MISSING\n";
}

$exts = ['zip','xml','mbstring'];
foreach ($exts as $e) {
  echo "ext-$e: " . (extension_loaded($e) ? "OK" : "MISSING") . "\n";
}

echo "Class checks:\n";
echo " - Composer\\Pcre\\Preg: " . (class_exists('Composer\\Pcre\\Preg') ? "OK" : "MISSING") . "\n";
echo " - PhpOffice\\PhpSpreadsheet\\Spreadsheet: " . (class_exists('PhpOffice\\PhpSpreadsheet\\Spreadsheet') ? "OK" : "MISSING") . "\n";
echo " - PhpOffice\\PhpSpreadsheet\\IOFactory: " . (class_exists('PhpOffice\\PhpSpreadsheet\\IOFactory') ? "OK" : "MISSING") . "\n";

if (class_exists('PhpOffice\\PhpSpreadsheet\\IOFactory')) {
  echo "\nReader test:\n";
  try {
    \PhpOffice\PhpSpreadsheet\IOFactory::createReader('Xlsx');
    echo " - Xlsx reader: OK\n";
  } catch (Throwable $e) {
    echo " - Xlsx reader: FAIL (" . $e->getMessage() . ")\n";
  }
}

echo "\nDone.\n";
