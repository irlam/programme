<?php
declare(strict_types=1);
header('Content-Type: text/plain; charset=utf-8');
echo "SAFE PROBE\n";

// 1) Check autoload file presence
$libAuto = __DIR__ . '/../libs/autoload-phpss.php';
echo "libs/autoload-phpss.php: " . (is_file($libAuto) ? "exists\n" : "MISSING\n");

// 2) Try including the autoloader only
if (is_file($libAuto)) {
  require $libAuto;
  echo "Included manual autoloader.\n";
  echo "Class Composer\\Pcre\\Preg exists? " . (class_exists('Composer\\Pcre\\Preg') ? "YES\n" : "NO\n");
  echo "Class PhpOffice\\PhpSpreadsheet\\Spreadsheet exists? " . (class_exists('PhpOffice\\PhpSpreadsheet\\Spreadsheet') ? "YES\n" : "NO\n");
}

// 3) Now include your normal bootstrap
$boot = __DIR__ . '/../api/_bootstrap.php';
if (is_file($boot)) {
  require $boot;
  echo "_bootstrap loaded.\n";
} else {
  echo "_bootstrap.php MISSING\n";
}

echo "Done.\n";
