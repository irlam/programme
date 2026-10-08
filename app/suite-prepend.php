<?php
declare(strict_types=1);
// Configure as auto_prepend_file only for dedicated Suite-managed instances.
// Do not configure this on the existing shared Programme deployment.
if (PHP_SAPI === 'cli') return;
header('Cache-Control: no-store');
try {
    $root=realpath(dirname(__DIR__));
    require_once __DIR__.'/Lib/SuiteBinding.php';
    $binding=App\Lib\SuiteBinding::load((string)$root);
    foreach (['SuiteGateway','SuiteUserMap','SuiteSession','SuiteHttp'] as $class) require_once __DIR__.'/Lib/'.$class.'.php';
    require_once __DIR__.'/config/DB.php';
    $script=realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? ''));
    if (!$script || !str_starts_with($script,$root.DIRECTORY_SEPARATOR)) throw new RuntimeException('Invalid request path.');
    $route='/'.str_replace(DIRECTORY_SEPARATOR,'/',substr($script,strlen($root)+1));
    $gateway=new App\Lib\SuiteGateway($binding);
    $sessions=new App\Lib\SuiteSession($gateway,new App\Lib\SuiteUserMap(App\Config\DB::pdo(),$binding),$binding);
    (new App\Lib\SuiteHttp($gateway,$sessions,$binding))->run($route);
} catch (Throwable $e) {
    http_response_code(503); header('Content-Type: application/json');
    echo '{"ok":false,"error":"access_unavailable"}'; exit;
}
