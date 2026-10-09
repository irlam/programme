<?php
declare(strict_types=1);
require_once __DIR__ . '/app/suite-prepend.php';

// This endpoint works only with the independently configured instance prepend gate.
// An omitted server-level gate must never fall back to legacy password login.
http_response_code(503);
header('Cache-Control: no-store');
header('Content-Type: application/json');
echo '{"ok":false,"error":"access_unavailable"}';
