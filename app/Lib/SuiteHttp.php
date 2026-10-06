<?php
declare(strict_types=1);
namespace App\Lib;

use RuntimeException;
use Throwable;

/** Opt-in instance front gate. Must be prepended by the server to every PHP request. */
final class SuiteHttp
{
    public function __construct(
        private readonly SuiteGateway $gateway,
        private readonly SuiteSession $sessions,
        private readonly array $binding
    ) {
        // Legacy reports default/hardcode project 1. Do not support another binding yet.
        if (($binding['local_project_id'] ?? null) !== 1) {
            throw new RuntimeException('HTTP adapter currently requires local project 1.');
        }
    }

    public function run(string $route): void
    {
        header('Cache-Control: no-store');
        header('Pragma: no-cache');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');
        $host = parse_url($this->binding['origin'], PHP_URL_HOST);
        // Never trust forwarded host/protocol headers on this direct-host deployment.
        if (($_SERVER['HTTPS'] ?? '') !== 'on'
            || strtolower((string)($_SERVER['HTTP_HOST'] ?? '')) !== $host) {
            $this->deny(403);
        }
        if (session_status() !== PHP_SESSION_NONE) $this->deny(503);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        session_name('__Host-programme_suite');
        session_set_cookie_params(['lifetime'=>0,'path'=>'/','domain'=>'',
            'secure'=>true,'httponly'=>true,'samesite'=>'Lax']);
        if (!session_start()) $this->deny(503);

        if ($route === '/suite-login.php') $this->login();
        $allowed = [
            '/api/auth.php','/api/workspace.php','/api/tasks.php','/api/comments.php',
            '/api/dependencies.php','/api/baselines.php','/api/calendar.php',
            '/api/contractors.php','/api/templates.php','/api/gantt.php',
            '/api/analytics.php','/api/lookahead.php',
            '/api/import/preview.php','/api/import/commit.php',
            '/api/export/csv.php','/api/export/excel.php','/api/export/lookahead_xlsx.php',
            '/api/export/tasklist_xlsx.php','/api/export/shortterm.php',
            '/api/templates/lookahead.php','/api/templates/tasklist.php','/api/templates/tasklist_csv.php',
            '/admin/index.php','/admin/baselines.php','/admin/holidays.php','/admin/templates.php'
        ];
        if (!in_array($route, $allowed, true)) $this->deny(404);
        foreach (['debug','selftest','probe'] as $flag) if (isset($_GET[$flag])) $this->deny(404);
        $action = $_GET['action'] ?? 'whoami';
        $method = $_SERVER['REQUEST_METHOD'] ?? '';
        if ($route === '/api/auth.php') {
            if (!in_array($action, ['whoami','logout'], true)) $this->deny(403);
            // Logout should clear the local session even when Suite is unavailable.
            if ($action === 'logout') {
                if ($method !== 'POST') $this->deny(405);
                $this->csrf();
                try {$this->sessions->logout($_SESSION);} catch (Throwable $e) {
                    $this->destroy(); $this->deny(503);
                }
                $this->destroy(); $this->json(['ok'=>true]);
            }
        }
        try {$user = $this->sessions->current($_SESSION);} catch (Throwable $e) {
            $this->destroy(); $this->deny(401);
        }
        if ($route === '/api/auth.php') {
            if ($method !== 'GET') $this->deny(405);
            $this->json(['ok'=>true,'user'=>$user,'csrf'=>$_SESSION['csrf']]);
        }
        if (!in_array($method, ['GET','POST','DELETE'], true)) $this->deny(405);
        if ($method !== 'GET') {
            $roles = $route === '/api/comments.php' ? ['admin','planner','commenter'] : ['admin','planner'];
            if (!in_array($user['role'], $roles, true)) $this->deny(403);
            $this->csrf();
        }
        $this->projects($_GET);
        $this->projects($_POST);
        if ($method !== 'GET' && str_starts_with(strtolower($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) {
            $body = file_get_contents('php://input', false, null, 0, 1048577);
            if ($body === false || strlen($body) > 1048576) $this->deny(413);
            try {$data=json_decode($body,true,32,JSON_THROW_ON_ERROR);} catch (Throwable $e) {$this->deny(400);}
            if (!is_array($data)) $this->deny(400);
            $this->projects($data);
        }
        // Allowed application file now executes, using only freshly mapped $_SESSION['user'].
    }

    private function login(): never
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? '';
        if ($method === 'GET') {
            $begin = $this->gateway->begin();
            setcookie('__Host-programme_state', $begin['state'], ['expires'=>time()+60,
                'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'None']);
            header('Location: '.$begin['url'], true, 303); exit;
        }
        if ($method !== 'POST') $this->deny(405);
        $code = $_POST['code'] ?? null; $state = $_POST['state'] ?? null;
        $cookie = $_COOKIE['__Host-programme_state'] ?? '';
        setcookie('__Host-programme_state', '', ['expires'=>1,'path'=>'/',
            'secure'=>true,'httponly'=>true,'samesite'=>'None']);
        if (!is_string($code) || !is_string($state) || !is_string($cookie)) {
            $_SESSION=[]; $this->destroy(); $this->deny(400);
        }
        // Rotate before accepting the redeemed identity, including an existing local session.
        if (!session_regenerate_id(true)) {$_SESSION=[]; $this->destroy(); $this->deny(503);}
        try {$this->sessions->accept($_SESSION,$code,$state,$cookie);} catch (Throwable $e) {
            $this->destroy(); $this->deny(401);
        }
        header('Location: /',true,303); exit;
    }

    private function csrf(): void
    {
        $given = $_SERVER['HTTP_X_CSRF'] ?? ($_POST['csrf'] ?? '');
        $expected = $_SESSION['csrf'] ?? '';
        if (!is_string($given) || !is_string($expected) || $given === '' || $expected === ''
            || !hash_equals($expected,$given)) $this->deny(419);
    }

    private function projects(array $data): void
    {
        foreach ($data as $key=>$value) {
            if (in_array($key, ['project','project_id'], true)) {
                if ((!is_int($value) && !is_string($value))
                    || !preg_match('/^[1-9][0-9]*$/D',(string)$value)
                    || (string)$value !== (string)$this->binding['local_project_id']) $this->deny(403);
            }
            if (is_array($value)) $this->projects($value);
        }
    }

    private function destroy(): void
    {
        $_SESSION=[]; session_destroy();
        setcookie('__Host-programme_suite','',['expires'=>1,'path'=>'/',
            'secure'=>true,'httponly'=>true,'samesite'=>'Lax']);
    }

    private function deny(int $status): never
    {
        http_response_code($status); $this->json(['ok'=>false,'error'=>'access_unavailable']);
    }

    private function json(array $data): never
    {
        header('Content-Type: application/json'); echo json_encode($data); exit;
    }
}
