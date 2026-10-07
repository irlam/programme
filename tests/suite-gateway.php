<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/Lib/SuiteGateway.php';
use App\Lib\SuiteGateway;
$check=static function(bool $ok,string $label):void {if(!$ok)throw new RuntimeException('FAIL: '.$label);};
$deny=static function(callable $f,string $label)use($check):void {try{$f();}catch(RuntimeException $e){return;}$check(false,$label);};
$binding=['instance_id'=>1,'organization_id'=>1,'project_id'=>1,'local_project_id'=>7,'suite_origin'=>'https://suite.defecttracker.uk','origin'=>'https://alpha.programme.defecttracker.uk','key'=>bin2hex(random_bytes(32))];
$identity=['instance_id'=>1,'organization_id'=>1,'project_id'=>1,'module_key'=>'programme','user_id'=>2,'name'=>'Fixture','email'=>'fixture@example.test','role'=>'user','session_expires_at'=>time()+28800,'session_token'=>bin2hex(random_bytes(32))];
$calls=[];$reply=['ok'=>true,'identity'=>$identity];
$gateway=new SuiteGateway($binding,static function($url,$payload,$key)use(&$calls,&$reply,$binding){if($key!==$binding['key'])throw new LogicException('Wrong server key');$calls[]=[$url,$payload];return $reply;});
$begin=$gateway->begin();$state=$begin['state'];$code=bin2hex(random_bytes(32));
$check(strlen($state)===64&&str_starts_with($begin['url'],'https://suite.defecttracker.uk/launch.php?'),'Private launch destination');
$deny(fn()=>$gateway->redeem($code,$state,str_repeat('0',64)),'Browser state mismatch denied');$check($calls===[],'Rejected browser state never reaches server');
$got=$gateway->redeem($code,$state,$state);$check($got['local_role']==='commenter'&&$got['local_project_id']===7,'Limited mapped role and local project');
foreach(['organization_id','project_id','instance_id'] as $field){$reply['identity']=$identity;$reply['identity'][$field]=2;$deny(fn()=>$gateway->validate($identity['session_token']),'Foreign binding denied');}
$reply['identity']=$identity;$reply['identity']['module_key']='permits';$deny(fn()=>$gateway->validate($identity['session_token']),'Foreign module denied');
$reply['identity']=$identity;$reply['identity']['role']='superuser';$deny(fn()=>$gateway->validate($identity['session_token']),'Unknown role denied');
foreach (['session_expires_at'=>time()-1,'role'=>['admin']] as $field=>$value) {
    $reply['identity']=$identity;$reply['identity'][$field]=$value;
    $deny(fn()=>$gateway->validate($identity['session_token']),'Invalid expiry or role type denied');
}
$reply=['ok'=>false];$deny(fn()=>$gateway->validate($identity['session_token']),'Server access revocation denied');
$offline=new SuiteGateway($binding,static function(){throw new RuntimeException('Transport failure');});$deny(fn()=>$offline->validate($identity['session_token']),'Outage fails closed');
$reply=['ok'=>true,'identity'=>$identity];$reply['identity']['role']='admin';$check($gateway->validate($identity['session_token'])['local_role']==='planner','Company admin cannot administer independent app accounts');
$reply=['ok'=>true,'identity'=>$identity];$reply['identity']['role']='viewer';$check($gateway->validate($identity['session_token'])['local_role']==='commenter','Viewer uses a restricted legacy account role');
$reply=['ok'=>true];$gateway->revoke($identity['session_token']);$check(end($calls)[1]['action']==='revoke','Server-side logout');
foreach(['http://alpha.programme.defecttracker.uk','https://programme.defecttracker.uk','https://user@alpha.programme.defecttracker.uk','https://alpha.programme.defecttracker.uk.evil.test'] as $origin){$bad=$binding;$bad['origin']=$origin;$deny(fn()=>new SuiteGateway($bad),'Unsafe origin denied');}
echo "PASS: Programme gateway browser state, immutable identity binding, limited roles, session checks, logout and outage denial.\n";
