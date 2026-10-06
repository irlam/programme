<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$file=tempnam(sys_get_temp_dir(),'programme-config-');
try {
    file_put_contents($file, '<?php return ["db"=>["dsn"=>"sqlite::memory:","user"=>"fixture","pass"=>"fixture"]];');
    putenv('PROGRAMME_CONFIG_FILE='.$file);
    $cfg=require $root.'/app/config/config.php';
    if ($cfg['db']['dsn'] !== 'sqlite::memory:') throw new RuntimeException('Private config not loaded');
    putenv('PROGRAMME_CONFIG_FILE='.$file.'-missing');
    putenv('PROGRAMME_DB_DSN=sqlite::memory:'); putenv('PROGRAMME_DB_USER=fixture'); putenv('PROGRAMME_DB_PASSWORD=fixture');
    $cfg=require $root.'/app/config/config.php';
    if ($cfg['db']['options'][PDO::ATTR_EMULATE_PREPARES] !== false) throw new RuntimeException('Environment options invalid');
    putenv('PROGRAMME_DB_DSN'); putenv('PROGRAMME_DB_USER'); putenv('PROGRAMME_DB_PASSWORD');
    try { require $root.'/app/config/config.php'; throw new LogicException('Missing config accepted'); }
    catch (RuntimeException $e) {}
    file_put_contents($file, '<?php return [];'); putenv('PROGRAMME_CONFIG_FILE='.$file);
    try { require $root.'/app/config/config.php'; throw new LogicException('Malformed config accepted'); }
    catch (RuntimeException $e) {}
    echo "PASS: Private and environment configuration; missing/malformed config fails closed.\n";
} finally { @unlink($file); }
