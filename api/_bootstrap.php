<?php
declare(strict_types=1);

$ROOT = dirname(__DIR__, 1);  // /httpdocs

spl_autoload_register(function(string $class) use ($ROOT) {
  $prefix = 'App\\';
  if (strncmp($class, $prefix, strlen($prefix)) !== 0) return;
  $rel = substr($class, strlen($prefix));
  $primary = $ROOT . '/app/' . str_replace('\\','/',$rel) . '.php';
  $fallback = $ROOT . '/app/' . strtolower(str_replace('\\','/',$rel)) . '.php';
  if (is_file($primary)) { require $primary; return; }
  if (is_file($fallback)) { require $fallback; return; }
});

require $ROOT . '/app/Config/DB.php';
require $ROOT . '/app/Config/config.php';

@session_start();
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));

function current_user(): ?array { return $_SESSION['user'] ?? null; }
function user_role(): ?string { return $_SESSION['user']['role'] ?? null; }
function is_logged_in(): bool { return !empty($_SESSION['user']); }

function require_role($roles): void {
  if (!is_array($roles)) $roles = [$roles];
  $role = user_role();
  if (!$role || !in_array($role, $roles, true)) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['ok'=>false,'error'=>'forbidden']);
    exit;
  }
}
function csrf_check(): void {
  $given = $_SERVER['HTTP_X_CSRF'] ?? ($_POST['csrf'] ?? '');
  if (!$given || !hash_equals($_SESSION['csrf'], $given)) {
    http_response_code(419);
    header('Content-Type: application/json');
    echo json_encode(['ok'=>false,'error'=>'csrf_failed']);
    exit;
  }
}

// Prefer Composer autoload if present; otherwise use manual PhpSpreadsheet autoload.
$__autoloaded = false;
$__vendor = __DIR__ . '/../vendor/autoload.php';
if (is_file($__vendor)) { require $__vendor; $__autoloaded = true; }
$__manual = __DIR__ . '/../libs/autoload-phpss.php';
if (is_file($__manual)) { require_once $__manual; $__autoloaded = true; }
// Optional: if you want to see when it's missing
if (!$__autoloaded) {
  // error_log('Warning: no autoloader found (vendor/ or libs/).');
}
unset($__vendor, $__manual, $__autoloaded);
