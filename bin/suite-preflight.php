<?php
declare(strict_types=1);
// Never publish or execute this diagnostic through the web.
if (PHP_SAPI!=='cli') {http_response_code(404);exit;}
ini_set('display_errors','0');
ini_set('log_errors','0');
$checks=[];
ob_start();
try {
    $app=realpath(__DIR__.'/../app')?:realpath(__DIR__.'/../httpdocs/app');
    if (!$app) throw new RuntimeException();
    $root=dirname($app);
    $checks['php_runtime']=PHP_VERSION_ID>=80200 && extension_loaded('pdo') && extension_loaded('curl') && extension_loaded('ctype');
    require_once $app.'/Lib/SuiteBinding.php';
    try {$binding=App\Lib\SuiteBinding::load($root);$checks['private_binding_file']=true;}
    catch (Throwable $e) {$checks['private_binding_file']=false;throw $e;}
    require_once $app.'/Lib/SuiteGateway.php';
    require_once $app.'/Lib/SuitePreflight.php';
    require_once $app.'/config/DB.php';
    try {$pdo=App\Config\DB::pdo();$checks['database_connection']=true;}
    catch (Throwable $e) {$checks['database_connection']=false;throw $e;}
    $checks+=App\Lib\SuitePreflight::database($pdo,$binding);
} catch (Throwable $e) {$checks['preflight_completed']=false;}
ob_end_clean();
$passed=$checks!==[] && !in_array(false,$checks,true);
echo json_encode(['local_checks_passed'=>$passed,'tenant_ready'=>false,'checks'=>$checks,
    'manual_verification_required'=>['web_php_runtime_and_prepend','https_callback_and_host_cookies','suite_inventory_binding','independent_database_privileges','private_static_paths','alpha_beta_import_export_and_role_revocation','browser_mobile_and_restore']],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($passed?0:1);
