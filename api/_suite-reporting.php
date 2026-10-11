<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
function programme_suite_fail(int $status, string $error): never {
    http_response_code($status); echo json_encode(['ok'=>false,'error'=>$error]); exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') programme_suite_fail(405,'method_not_allowed');
try {
    $reportConfig = require dirname(__DIR__).'/app/config/config.php';
    $expected = trim((string)($reportConfig['suite_reporting_key'] ?? getenv('CONSTRUCTION_SUITE_API_KEY') ?: ''));
    $provided = (string)($_SERVER['HTTP_X_CONSTRUCTION_SUITE_KEY'] ?? '');
    if (strlen($expected) < 32) programme_suite_fail(503,'integration_not_configured');
    if ($provided === '' || !hash_equals($expected,$provided)) programme_suite_fail(401,'unauthorized');
    require_once dirname(__DIR__).'/app/config/DB.php';
    $reportDb = \App\Config\DB::pdo();
} catch (Throwable $error) { programme_suite_fail(503,'reporting_unavailable'); }
