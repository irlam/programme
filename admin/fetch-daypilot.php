<?php
declare(strict_types=1);
$ROOT = dirname(__DIR__, 1);
$target = $ROOT . '/assets/js/daypilot-lite.min.js';
$url = $_GET['url'] ?? 'https://cdn.jsdelivr.net/npm/@daypilot/daypilot-lite-javascript@4.3.0/daypilot-javascript.min.js';
function fetch_url(string $url): string {
  if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_TIMEOUT => 30,
      CURLOPT_USERAGENT => 'DayPilotFetcher/1.0'
    ]);
    $data = curl_exec($ch);
    if ($data === false) { $err = curl_error($ch); curl_close($ch); throw new \RuntimeException($err); }
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($status >= 400) throw new \RuntimeException("HTTP $status");
    return (string)$data;
  } else {
    $data = @file_get_contents($url);
    if ($data === false) throw new \RuntimeException("file_get_contents failed");
    return (string)$data;
  }
}
header('Content-Type: text/plain');
try {
  $js = fetch_url($url);
  if (!is_dir(dirname($target))) {
    if (!@mkdir(dirname($target), 0775, true)) throw new \RuntimeException("Cannot create assets/js dir");
  }
  if (@file_put_contents($target, $js) === false) throw new \RuntimeException("Write failed");
  @chmod($target, 0644);
  echo "OK: Saved DayPilot to $target";
} catch (\Throwable $e) {
  http_response_code(500);
  echo "ERROR: " . $e->getMessage();
}

