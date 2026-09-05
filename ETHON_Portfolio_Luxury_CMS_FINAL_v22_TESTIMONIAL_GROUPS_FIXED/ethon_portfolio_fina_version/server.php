<?php
declare(strict_types=1);

/* ETHON Portfolio CMS — portable PHP backend.
 * Same API contract as the previous Node server, but usable on Apache/shared hosting,
 * PHP built-in server, VPS and Render via the included Dockerfile.
 */

const ROOT = __DIR__;
const DATA_FILE = ROOT . '/data.json';
const UPLOAD_DIR = ROOT . '/uploads';
const RUNTIME_DIR = ROOT . '/runtime';
const SESSION_TTL = 28800;
const MAX_BODY = 12_000_000;

if (!is_dir(UPLOAD_DIR)) @mkdir(UPLOAD_DIR, 0755, true);
if (!is_dir(RUNTIME_DIR)) @mkdir(RUNTIME_DIR, 0755, true);

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$path = rawurldecode($path);

function envv(string $key, string $fallback = ''): string {
    $v = getenv($key);
    return $v === false ? $fallback : (string)$v;
}
function json_out(int $status, array $data): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, max-age=0');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function raw_out(int $status, string $body, string $type, array $headers=[]): never {
    http_response_code($status);
    header('Content-Type: '.$type);
    foreach ($headers as $k=>$v) header($k.': '.$v);
    echo $body;
    exit;
}
function fail(string $message, int $status=400): never { json_out($status, ['error'=>$message]); }
function read_body(): array {
    $len = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($len > MAX_BODY) fail('Request payload is too large.', 413);
    $raw = file_get_contents('php://input') ?: '';
    if (strlen($raw) > MAX_BODY) fail('Request payload is too large.', 413);
    if ($raw === '') return [];
    $data = json_decode($raw, true);
    if (!is_array($data)) fail('Invalid JSON body.', 400);
    return $data;
}
function now_iso(): string { return gmdate('c'); }
function new_id(): string { return bin2hex(random_bytes(10)); }
function client_ip(): string { return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'; }
function base64url(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function sign_token(string $payload, string $secret): string { return base64url($payload).'.'.base64url(hash_hmac('sha256', $payload, $secret, true)); }
function verify_token(string $token, string $secret): bool {
    $parts = explode('.', $token, 2); if (count($parts)!==2) return false;
    $raw = base64_decode(strtr($parts[0], '-_', '+/').str_repeat('=', (4-strlen($parts[0])%4)%4), true);
    if ($raw===false || !hash_equals(base64url(hash_hmac('sha256',$raw,$secret,true)), $parts[1])) return false;
    $data = json_decode($raw, true);
    return is_array($data) && !empty($data['exp']) && (int)$data['exp'] > time();
}
function admin_secret(): string {
    $p = envv('ADMIN_PASSWORD');
    if ($p === '') return '';
    return envv('APP_SECRET', hash('sha256', $p.'|ethon-cms-secret', true));
}
function cookies(): array {
    $out=[]; foreach (explode(';', $_SERVER['HTTP_COOKIE'] ?? '') as $piece) {
        $i=strpos($piece,'='); if($i===false) continue;
        $out[trim(substr($piece,0,$i))]=urldecode(trim(substr($piece,$i+1)));
    } return $out;
}
function is_admin(): bool {
    $secret=admin_secret(); if($secret==='') return false;
    return verify_token(cookies()['ethon_admin'] ?? '', $secret);
}
function require_admin(): void { if(!is_admin()) fail('Unauthorized',401); }
function secure_cookie_options(): array { return ['expires'=>time()+SESSION_TTL,'path'=>'/','secure'=>(!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off') || (($_SERVER['HTTP_X_FORWARDED_PROTO']??'')==='https'),'httponly'=>true,'samesite'=>'Lax']; }
function set_admin_cookie(string $token): void { setcookie('ethon_admin',$token,secure_cookie_options()); }
function clear_admin_cookie(): void { setcookie('ethon_admin','', ['expires'=>time()-3600,'path'=>'/','secure'=>secure_cookie_options()['secure'],'httponly'=>true,'samesite'=>'Lax']); }
function rate_limit(string $bucket, int $limit, int $window=60): void {
    $file=RUNTIME_DIR.'/rate.json'; $fp=@fopen($file,'c+'); if(!$fp) return;
    flock($fp,LOCK_EX); $raw=stream_get_contents($fp); $all=$raw?json_decode($raw,true):[]; if(!is_array($all))$all=[];
    $key=$bucket.'|'.client_ip(); $now=time(); $arr=array_values(array_filter($all[$key]??[],fn($t)=>$now-(int)$t<$window));
    if(count($arr)>=$limit){flock($fp,LOCK_UN);fclose($fp);header('Retry-After',(string)$window);fail('Too many requests. Please try again shortly.',429);}
    $arr[]=$now; $all[$key]=$arr; if(count($all)>300){$all=array_slice($all,-250,true);} ftruncate($fp,0);rewind($fp);fwrite($fp,json_encode($all));fflush($fp);flock($fp,LOCK_UN);fclose($fp);
}
function read_json_file(string $file, array $fallback=[]): array { if(!is_file($file)) return $fallback; $x=json_decode(file_get_contents($file),true); return is_array($x)?$x:$fallback; }
function atomic_json_write(string $file, array $data): void {
    $tmp=$file.'.tmp'; $fp=fopen($tmp,'wb'); if(!$fp) throw new RuntimeException('Unable to write data.');
    fwrite($fp,json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)); fflush($fp); if(function_exists('fsync')) @fsync($fp); fclose($fp); rename($tmp,$file);
}
function supabase_enabled(): bool { return envv('SUPABASE_URL')!=='' && envv('SUPABASE_SERVICE_ROLE_KEY')!==''; }
function supabase_request(string $endpoint, string $method='GET', ?array $body=null, array $extraHeaders=[]): mixed {
    $url=rtrim(envv('SUPABASE_URL'),'/').$endpoint; $ch=curl_init($url); if(!$ch) throw new RuntimeException('Supabase unavailable.');
    $headers=['apikey: '.envv('SUPABASE_SERVICE_ROLE_KEY'),'Authorization: Bearer '.envv('SUPABASE_SERVICE_ROLE_KEY'),'Content-Type: application/json'];
    foreach($extraHeaders as $h)$headers[]=$h;
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers,CURLOPT_TIMEOUT=>20]);
    if($body!==null)curl_setopt($ch,CURLOPT_POSTFIELDS,json_encode($body,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
    $resp=curl_exec($ch); $code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); $err=curl_error($ch); curl_close($ch);
    if($resp===false || $err)throw new RuntimeException('Supabase request failed.');
    if($code<200||$code>=300)throw new RuntimeException('Supabase '.$code.': '.$resp);
    if($resp==='')return null; $decoded=json_decode($resp,true); return $decoded===null?null:$decoded;
}
function default_state(): array {
    $projects=read_json_file(ROOT.'/seed-projects.json',[]); $tests=read_json_file(ROOT.'/seed-testimonials.json',[]); $local=read_json_file(DATA_FILE,[]);
    return ['projects'=>$local['projects']??$projects,'testimonials'=>$local['testimonials']??$tests,'messages'=>$local['messages']??[],'conversations'=>$local['conversations']??[],'settings'=>$local['settings']??[]];
}
function load_db(): array {
    $local=default_state();
    if(!supabase_enabled()) return $local;
    try{
        $rows=supabase_request('/rest/v1/cms_state?id=eq.1&select=data');
        if(is_array($rows)&&!empty($rows[0]['data'])&&is_array($rows[0]['data'])) return array_replace_recursive($local,$rows[0]['data']);
    }catch(Throwable $e){ error_log($e->getMessage()); }
    return $local;
}
function save_db(array $db): void {
    atomic_json_write(DATA_FILE,$db);
    if(!supabase_enabled()) return;
    $payload=['id'=>1,'data'=>$db,'updated_at'=>now_iso()];
    try{ supabase_request('/rest/v1/cms_state?id=eq.1','PATCH',$payload,['Prefer: return=minimal']); }
    catch(Throwable $e){ error_log($e->getMessage()); }
}
function public_settings(array $s): array {
    foreach(['paymentBank','paymentAccountName','paymentAccount','paymentSwift','paymentRouting','paymentBankLabel','paymentAccountNameLabel','paymentAccountLabel','paymentSwiftLabel','paymentRoutingLabel'] as $k) unset($s[$k]);
    return $s;
}
function upload_data_url(string $data, string $name='media'): string {
    if(!preg_match('#^data:([^;]+);base64,(.+)$#s',$data,$m)) fail('Invalid upload.');
    $mime=strtolower($m[1]); $allowed=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif','image/svg+xml'=>'svg','video/mp4'=>'mp4','video/webm'=>'webm','video/quicktime'=>'mov','application/pdf'=>'pdf'];
    if(!isset($allowed[$mime])) fail('Unsupported file type.',415);
    $bin=base64_decode($m[2],true); if($bin===false) fail('Invalid upload data.'); if(strlen($bin)>10*1024*1024) fail('File must be under 10MB.',413);
    $safe=preg_replace('/[^A-Za-z0-9._-]+/','-',basename($name ?: 'media.'.$allowed[$mime])); if(!preg_match('/\.[A-Za-z0-9]+$/',$safe))$safe.='.'.$allowed[$mime];
    $fn=date('YmdHis').'-'.bin2hex(random_bytes(5)).'-'.$safe;
    if(supabase_enabled()){
        $bucket=envv('SUPABASE_STORAGE_BUCKET','portfolio'); $url=rtrim(envv('SUPABASE_URL'),'/').'/storage/v1/object/'.rawurlencode($bucket).'/'.rawurlencode($fn);
        $ch=curl_init($url); curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_HTTPHEADER=>['apikey: '.envv('SUPABASE_SERVICE_ROLE_KEY'),'Authorization: Bearer '.envv('SUPABASE_SERVICE_ROLE_KEY'),'Content-Type: '.$mime,'x-upsert: true'],CURLOPT_POSTFIELDS=>$bin,CURLOPT_TIMEOUT=>30]); $resp=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);if($resp===false||$code<200||$code>=300)fail('Storage upload failed.',502);
        return $url=rtrim(envv('SUPABASE_URL'),'/').'/storage/v1/object/public/'.rawurlencode($bucket).'/'.rawurlencode($fn);
    }
    file_put_contents(UPLOAD_DIR.'/'.$fn,$bin,LOCK_EX); return '/uploads/'.$fn;
}
function serve_file(string $path): never {
    if($path==='/'||$path==='')$path='/index.html'; if(str_contains($path,'..'))fail('Bad path.',400);
    $file=ROOT.$path; if(!is_file($file))fail('Not found.',404);
    $types=['html'=>'text/html; charset=utf-8','js'=>'text/javascript; charset=utf-8','css'=>'text/css; charset=utf-8','json'=>'application/json; charset=utf-8','png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','webp'=>'image/webp','gif'=>'image/gif','svg'=>'image/svg+xml','mp4'=>'video/mp4','webm'=>'video/webm','mov'=>'video/quicktime','pdf'=>'application/pdf'];
    $ext=strtolower(pathinfo($file,PATHINFO_EXTENSION)); $type=$types[$ext]??'application/octet-stream'; $size=filesize($file); $etag=sha1($file.'|'.$size.'|'.filemtime($file));
    header('Content-Type: '.$type); header('ETag: "'.$etag.'"'); if(isset($_SERVER['HTTP_IF_NONE_MATCH'])&&trim($_SERVER['HTTP_IF_NONE_MATCH'])==='"'.$etag.'"'){http_response_code(304);exit;} header('Content-Length: '.(string)$size); readfile($file); exit;
}
function reorder(array $list, array $ids): array { $map=[];foreach($list as $x)$map[(string)($x['id']??'')]=$x;$out=[];foreach($ids as $id){$k=(string)$id;if(isset($map[$k])){$out[]=$map[$k];unset($map[$k]);}}return array_merge($out,array_values($map)); }

header('X-Content-Type-Options: nosniff'); header('Referrer-Policy: strict-origin-when-cross-origin'); header('X-Frame-Options: SAMEORIGIN'); header('Permissions-Policy: camera=(), microphone=(), geolocation=()');

$db=load_db();
$db['projects']=array_values($db['projects']??[]); $db['testimonials']=array_values($db['testimonials']??[]); $db['messages']=array_values($db['messages']??[]); $db['conversations']=array_values($db['conversations']??[]); $db['settings']=is_array($db['settings']??null)?$db['settings']:[];

try {
    if($method==='GET' && ($path==='/admin'||$path==='/admin/')) serve_file('/admin.html');
    if($method==='GET' && ($path==='/health'||$path==='/api/health')) json_out(200,['ok'=>true,'service'=>'ethon-cms','chat'=>true,'storage'=>supabase_enabled()?'supabase':'local']);

    if($method==='POST' && $path==='/api/admin/login'){
        rate_limit('login',8,300); $b=read_body(); $pass=envv('ADMIN_PASSWORD'); if($pass===''||!isset($b['password'])||!hash_equals($pass,(string)$b['password']))fail('Invalid password',401);
        $payload=json_encode(['iat'=>time(),'exp'=>time()+SESSION_TTL,'nonce'=>bin2hex(random_bytes(8))]); $token=sign_token($payload,admin_secret()); set_admin_cookie($token); json_out(200,['ok'=>true]);
    }
    if($method==='POST' && $path==='/api/admin/logout'){clear_admin_cookie();json_out(200,['ok'=>true]);}
    if(str_starts_with($path,'/api/admin/')) require_admin();

    if($method==='GET' && $path==='/api/public'){
        json_out(200,['projects'=>array_values(array_filter($db['projects'],fn($x)=>($x['published']??true)!==false)),'testimonials'=>array_values(array_filter($db['testimonials'],fn($x)=>($x['published']??true)!==false)),'settings'=>public_settings($db['settings'])]);
    }
    if($method==='POST' && $path==='/api/track/visit'){
        rate_limit('visit',30,60); $db['settings']['visitorCount']=(int)($db['settings']['visitorCount']??0)+1; $db['settings']['profileViews']=(int)($db['settings']['profileViews']??0)+1; $h=is_array($db['settings']['visitorHistory']??null)?$db['settings']['visitorHistory']:[]; $h[]=['at'=>now_iso(),'count'=>$db['settings']['visitorCount']];$db['settings']['visitorHistory']=array_slice($h,-30);save_db($db);json_out(200,['visitorCount'=>$db['settings']['visitorCount'],'profileViews'=>$db['settings']['profileViews']]);
    }
    if($method==='GET' && $path==='/api/track/cv'){
        $db['settings']['cvDownloads']=(int)($db['settings']['cvDownloads']??0)+1;save_db($db);$target='/assets/ui_ux_cv.pdf';header('Cache-Control: no-store');header('Location: '.$target,true,302);exit;
    }
    if($method==='POST' && $path==='/api/contact'){
        rate_limit('contact',8,300);$b=read_body();$name=trim((string)($b['name']??''));$email=trim((string)($b['email']??''));$message=trim((string)($b['message']??''));if($name===''||!filter_var($email,FILTER_VALIDATE_EMAIL)||$message==='')fail('Valid name, email and message are required.');if(strlen($message)>5000)fail('Message is too long.');$now=now_iso();$m=['id'=>new_id(),'name'=>substr($name,0,120),'email'=>substr($email,0,180),'phone'=>substr((string)($b['phone']??''),0,60),'service'=>substr((string)($b['service']??''),0,120),'budget'=>substr((string)($b['budget']??''),0,80),'message'=>$message,'createdAt'=>$now,'read'=>false];array_unshift($db['messages'],$m);$idx=null;foreach($db['conversations'] as $i=>$c){if(strtolower((string)($c['email']??''))===strtolower($email)){$idx=$i;break;}}if($idx===null){$db['conversations'][]=['id'=>new_id(),'name'=>$name,'email'=>$email,'createdAt'=>$now,'updatedAt'=>$now,'messages'=>[],'source'=>'contact'];$idx=count($db['conversations'])-1;}$c=&$db['conversations'][$idx];$c['messages'][]=['id'=>new_id(),'from'=>'visitor','channel'=>'contact','text'=>$message,'createdAt'=>$now];$c['updatedAt']=$now;save_db($db);json_out(201,['ok'=>true]);
    }
    if($method==='POST' && $path==='/api/payment'){
        rate_limit('payment',8,300);$b=read_body();$name=trim((string)($b['name']??''));$email=trim((string)($b['email']??''));$amount=(float)($b['amount']??0);$methodName=trim((string)($b['method']??''));if($name===''||!filter_var($email,FILTER_VALIDATE_EMAIL)||$amount<=0||$methodName==='')fail('Name, email, amount and payment method are required.');$currency=strtoupper((string)($b['currency']??$db['settings']['paymentCurrency']??'USD'));$currency=in_array($currency,['USD','BDT'],true)?$currency:'USD';$now=now_iso();$m=['id'=>new_id(),'name'=>$name,'email'=>$email,'phone'=>substr((string)($b['phone']??''),0,60),'service'=>substr((string)($b['service']??''),0,120),'amount'=>$amount,'currency'=>$currency,'budget'=>$amount.' '.$currency,'message'=>'Payment request · '.$methodName.' · '.$amount.' '.$currency.(!empty($b['message'])?' · '.trim((string)$b['message']):''),'createdAt'=>$now,'read'=>false,'type'=>'payment','paymentMethod'=>$methodName,'status'=>'pending'];array_unshift($db['messages'],$m);$c=null;foreach($db['conversations'] as &$cv){if(strtolower((string)($cv['email']??''))===strtolower($email)){$c=&$cv;break;}}if($c===null){$db['conversations'][]=['id'=>new_id(),'name'=>$name,'email'=>$email,'createdAt'=>$now,'updatedAt'=>$now,'messages'=>[],'source'=>'payment'];$c=&$db['conversations'][array_key_last($db['conversations'])];}$c['messages'][]=['id'=>new_id(),'from'=>'visitor','channel'=>'payment','text'=>$m['message'],'createdAt'=>$now];$c['updatedAt']=$now;save_db($db);json_out(201,['ok'=>true,'paymentRequestId'=>$m['id']]);
    }
    if($method==='POST' && $path==='/api/chat/start'){
        rate_limit('chat-start',10,300);$b=read_body();$name=trim((string)($b['name']??''));$email=trim((string)($b['email']??''));if($name===''||!filter_var($email,FILTER_VALIDATE_EMAIL))fail('Valid name and email are required.');$idx=null;foreach($db['conversations'] as $i=>$c){if(strtolower((string)($c['email']??''))===strtolower($email)){$idx=$i;break;}}$now=now_iso();if($idx===null){$db['conversations'][]=['id'=>new_id(),'name'=>$name,'email'=>$email,'createdAt'=>$now,'updatedAt'=>$now,'messages'=>[],'chatUnread'=>0,'source'=>'chat'];$idx=count($db['conversations'])-1;}else{$db['conversations'][$idx]['source']='chat';$db['conversations'][$idx]['chatUnread']=(int)($db['conversations'][$idx]['chatUnread']??0);}save_db($db);json_out(200,['conversationId'=>$db['conversations'][$idx]['id']]);
    }
    if($method==='GET' && preg_match('#^/api/chat/([^/]+)$#',$path,$m)){
        $cid=$m[1];foreach($db['conversations'] as $c){if((string)($c['id']??'')===$cid)json_out(200,$c['messages']??[]);}json_out(200,[]);
    }
    if($method==='POST' && preg_match('#^/api/chat/([^/]+)$#',$path,$m)){
        rate_limit('chat-send',30,300);$cid=$m[1];$b=read_body();$text=trim((string)($b['text']??''));if($text==='')fail('Message is required.');if(strlen($text)>4000)fail('Message is too long.');$idx=null;foreach($db['conversations'] as $i=>$c){if((string)($c['id']??'')===$cid){$idx=$i;break;}}if($idx===null)fail('Conversation not found.',404);$now=now_iso();$db['conversations'][$idx]['messages'][]=['id'=>new_id(),'from'=>'visitor','channel'=>'chat','text'=>$text,'createdAt'=>$now];$db['conversations'][$idx]['chatUnread']=(int)($db['conversations'][$idx]['chatUnread']??0)+1;$db['conversations'][$idx]['source']='chat';$db['conversations'][$idx]['updatedAt']=$now;save_db($db);json_out(201,$db['conversations'][$idx]['messages'][array_key_last($db['conversations'][$idx]['messages'])]);
    }
    if($method==='GET' && $path==='/api/admin/overview') json_out(200,$db);
    if($method==='PATCH' && preg_match('#^/api/admin/messages/([^/]+)$#',$path,$m)){
        foreach($db['messages'] as &$msg){if((string)($msg['id']??'')===$m[1]){$b=read_body();foreach($b as $k=>$v)$msg[$k]=$v;save_db($db);json_out(200,$msg);}}fail('Not found.',404);
    }
    if($method==='POST' && preg_match('#^/api/admin/conversations/([^/]+)/read$#',$path,$m)){
        foreach($db['conversations'] as &$c){if((string)($c['id']??'')===$m[1]){$c['chatUnread']=0;save_db($db);json_out(200,['ok'=>true]);}}fail('Not found.',404);
    }
    if($method==='POST' && preg_match('#^/api/admin/conversations/([^/]+)$#',$path,$m)){
        $b=read_body();$text=trim((string)($b['text']??''));if($text==='')fail('Message is required.');foreach($db['conversations'] as &$c){if((string)($c['id']??'')===$m[1]){$msg=['id'=>new_id(),'from'=>'admin','channel'=>'chat','text'=>$text,'createdAt'=>now_iso()];$c['messages'][]=$msg;$c['chatUnread']=0;$c['updatedAt']=$msg['createdAt'];save_db($db);json_out(201,$msg);}}fail('Not found.',404);
    }
    if($method==='POST' && $path==='/api/admin/projects/reorder'){ $b=read_body();$db['projects']=reorder($db['projects'],is_array($b['ids']??null)?$b['ids']:[]);save_db($db);json_out(200,['ok'=>true]); }
    if($method==='POST' && $path==='/api/admin/testimonials/reorder'){ $b=read_body();$db['testimonials']=reorder($db['testimonials'],is_array($b['ids']??null)?$b['ids']:[]);save_db($db);json_out(200,['ok'=>true]); }
    if($method==='POST' && $path==='/api/admin/projects'){
        $b=read_body();$x=['id'=>new_id(),'title'=>trim((string)($b['title']??'')),'category'=>trim((string)($b['category']??'')),'role'=>trim((string)($b['role']??'')),'description'=>trim((string)($b['description']??'')),'image'=>!empty($b['imageData'])?upload_data_url((string)$b['imageData'],(string)($b['imageName']??'media')):(string)($b['image']??''),'caseStudy'=>(string)($b['caseStudy']??''),'live'=>(string)($b['live']??''),'featured'=>!empty($b['featured']),'published'=>($b['published']??true)!==false,'source'=>'admin'];if($x['title']==='')fail('Project title is required.');$db['projects'][]=$x;save_db($db);json_out(201,$x);
    }
    if(preg_match('#^/api/admin/projects/([^/]+)$#',$path,$m) && ($method==='PUT'||$method==='DELETE')){
        $idx=null;foreach($db['projects'] as $i=>$x){if((string)($x['id']??'')===$m[1]){$idx=$i;break;}}if($idx===null)fail('Not found.',404);if($method==='DELETE'){array_splice($db['projects'],$idx,1);save_db($db);json_out(200,['ok'=>true]);}$b=read_body();$allowed=['title','category','role','description','caseStudy','live','featured','published'];foreach($allowed as $k)if(array_key_exists($k,$b))$db['projects'][$idx][$k]=$b[$k];if(!empty($b['imageData']))$db['projects'][$idx]['image']=upload_data_url((string)$b['imageData'],(string)($b['imageName']??'media'));save_db($db);json_out(200,['ok'=>true]);
    }
    if($method==='POST' && $path==='/api/admin/testimonials'){
        $b=read_body();$x=['id'=>new_id(),'name'=>trim((string)($b['name']??'')),'role'=>trim((string)($b['role']??'')),'text'=>trim((string)($b['text']??'')),'rating'=>(float)($b['rating']??5),'avatar'=>!empty($b['imageData'])?upload_data_url((string)$b['imageData'],(string)($b['imageName']??'media')):(string)($b['avatar']??''),'location'=>trim((string)($b['location']??'')),'flagSvg'=>(string)($b['flagSvg']??''),'published'=>($b['published']??true)!==false,'source'=>'admin'];if($x['name']===''||$x['text']==='')fail('Name and testimonial text are required.');$db['testimonials'][]=$x;save_db($db);json_out(201,$x);
    }
    if(preg_match('#^/api/admin/testimonials/([^/]+)$#',$path,$m) && ($method==='PUT'||$method==='DELETE')){
        $idx=null;foreach($db['testimonials'] as $i=>$x){if((string)($x['id']??'')===$m[1]){$idx=$i;break;}}if($idx===null)fail('Not found.',404);if($method==='DELETE'){array_splice($db['testimonials'],$idx,1);save_db($db);json_out(200,['ok'=>true]);}$b=read_body();foreach(['name','role','text','rating','location','flagSvg','published'] as $k)if(array_key_exists($k,$b))$db['testimonials'][$idx][$k]=$b[$k];if(!empty($b['imageData']))$db['testimonials'][$idx]['avatar']=upload_data_url((string)$b['imageData'],(string)($b['imageName']??'media'));save_db($db);json_out(200,['ok'=>true]);
    }
    if($method==='POST' && $path==='/api/admin/media'){rate_limit('media',30,300);$b=read_body();json_out(201,['url'=>upload_data_url((string)($b['data']??''),(string)($b['name']??'media'))]);}
    if($method==='PUT' && $path==='/api/admin/settings'){ $b=read_body();$db['settings']=array_merge($db['settings'],$b);save_db($db);json_out(200,$db['settings']); }

    serve_file($path);
} catch(Throwable $e) {
    error_log($e->getMessage()); json_out(500,['error'=>'Server error.']);
}
