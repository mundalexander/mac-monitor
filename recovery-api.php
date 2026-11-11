<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
function reply(int $code, array $body): void { http_response_code($code); echo json_encode($body, JSON_UNESCAPED_UNICODE); exit; }
$doc = realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: __DIR__;
$path = getenv('MM_RECOVERY_CONFIG') ?: dirname($doc) . '/mac-monitor-private/config.php';
if (!is_file($path)) reply(503, ['error'=>'Private Konfiguration fehlt. INSTALL.md beachten.']);
$config = require $path;
foreach (['browser_token','agent_token','storage_dir'] as $k) if (!isset($config[$k])) reply(503,['error'=>'Konfiguration unvollstaendig']);
if (strlen($config['browser_token'])<32 || strlen($config['agent_token'])<32 || $config['browser_token']===$config['agent_token'] || strpos($config['browser_token'],'REPLACE')===0 || strpos($config['agent_token'],'REPLACE')===0) reply(503,['error'=>'Zwei unterschiedliche zufaellige Tokens konfigurieren']);
$storage=realpath($config['storage_dir']);
$configReal=realpath($path);
if (!$storage || $storage===$doc || strpos($storage,$doc.DIRECTORY_SEPARATOR)===0 || strpos($configReal,$doc.DIRECTORY_SEPARATOR)===0) reply(503,['error'=>'Konfiguration und Storage muessen ausserhalb des Webroots liegen']);
$auth=$_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
$token=strpos($auth,'Bearer ')===0?substr($auth,7):'';
$agent=hash_equals($config['agent_token'],$token); $browser=hash_equals($config['browser_token'],$token);
if (!$agent && !$browser) reply(401,['error'=>'Token ungueltig']);
if (($_GET['machine'] ?? '')!=='evo-x3') reply(400,['error'=>'Unbekannte Maschine']);
$method=$_SERVER['REQUEST_METHOD'];
if (!in_array($method,['GET','POST'],true)) reply(405,['error'=>'Methode nicht erlaubt']);
$body=null;
if ($method==='POST') {
 if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0)>131072) reply(413,['error'=>'Payload zu gross']);
 $raw=file_get_contents('php://input',false,null,0,131073);
 if (strlen($raw)>131072) reply(413,['error'=>'Payload zu gross']);
 $body=json_decode($raw,true);
 if (!is_array($body)) reply(400,['error'=>'JSON ungueltig']);
 if (($body['kind'] ?? '')==='desired') {
  if (!$browser) reply(403,['error'=>'Browser-Token erforderlich']);
  if (!in_array($body['desired'] ?? '',['off','flash','27b'],true)) reply(400,['error'=>'Profil ungueltig']);
  if ($body['desired']!=='off' && ($body['confirm_cleanup'] ?? false)!==true) reply(400,['error'=>'Notfallbereinigung bestaetigen']);
 } elseif (($body['kind'] ?? '')==='status') {
  if (!$agent || !is_array($body['status'] ?? null)) reply(403,['error'=>'Agent-Status erforderlich']);
 } else reply(400,['error'=>'Aktion ungueltig']);
}
// Separate lock file permits atomic state replacement without losing mutual exclusion.
$lock=fopen($storage.'/evo-x3.lock','c');
if (!$lock || !flock($lock,LOCK_EX)) reply(503,['error'=>'Storage gesperrt']);
chmod($storage.'/evo-x3.lock',0600);
$file=$storage.'/evo-x3.json';
$raw=is_file($file)?file_get_contents($file):'';
$state=$raw===''?['control'=>['revision'=>0,'desired'=>'off','updated_at'=>time()],'status'=>null,'received_at'=>0]:json_decode($raw,true);
if (!is_array($state) || !isset($state['control'])) {flock($lock,LOCK_UN);fclose($lock);reply(503,['error'=>'State beschaedigt; manuell pruefen']);}
if ($method==='POST') {
 if ($body['kind']==='desired') {
  if ((int)($body['expected_revision'] ?? -1)!==(int)$state['control']['revision']) {flock($lock,LOCK_UN);fclose($lock);reply(409,['error'=>'Status geaendert. Aktualisieren und erneut versuchen.']);}
  $state['control']=['revision'=>$state['control']['revision']+1,'desired'=>$body['desired'],'updated_at'=>time()];
 } else {$state['status']=$body['status'];$state['received_at']=time();}
 $tmp=tempnam($storage,'state-');chmod($tmp,0600);
 $ok=file_put_contents($tmp,json_encode($state,JSON_UNESCAPED_UNICODE))!==false && rename($tmp,$file);
 if (!$ok) {if(is_file($tmp))unlink($tmp);flock($lock,LOCK_UN);fclose($lock);reply(503,['error'=>'Speichern fehlgeschlagen']);}
}
flock($lock,LOCK_UN);fclose($lock);$state['server_time']=time();reply(200,$state);
