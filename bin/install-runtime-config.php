<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root=dirname(__DIR__);
$source=dirname($root).'/private/config.php';
$target=$root.'/app/config/runtime.private.php';
if (is_file($target)) { echo "Protected runtime configuration already exists; unchanged.\n"; exit; }
if (!is_file($source)) { fwrite(STDERR,"Preserved private configuration is missing.\n"); exit(1); }
$text=file_get_contents($source);
if (!is_string($text)||!str_starts_with(ltrim($text),'<?php')) { fwrite(STDERR,"Invalid preserved configuration.\n");exit(1); }
$guard="\n// Deny direct web requests; this file is loaded only by application code.\nif (PHP_SAPI !== 'cli' && realpath((string) (\$_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) { http_response_code(404); exit; }\n";
$text=preg_replace('/declare\(strict_types=1\);/', 'declare(strict_types=1);'.$guard,$text,1,$count);
if ($count!==1) { fwrite(STDERR,"Unexpected configuration format.\n");exit(1); }
$temp=tempnam(dirname($target),'.runtime-');
if ($temp===false||file_put_contents($temp,$text,LOCK_EX)===false) { fwrite(STDERR,"Cannot write runtime configuration.\n");exit(1); }
chmod($temp,0600);
if (!rename($temp,$target)) { @unlink($temp);fwrite(STDERR,"Cannot install runtime configuration.\n");exit(1); }
echo "Protected PHP runtime configuration installed with owner-only permissions. No values printed.\n";
