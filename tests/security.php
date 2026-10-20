<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
use Pup\App;use Pup\Http;use Pup\Federation;use Pup\Discovery;use Pup\RemoteError;
$checks=0;
function check(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);echo 'ok '.(++$GLOBALS['checks']).' - '.$label."\n";}
function rejected(callable $fn,string $label,?int $status=null):void{try{$fn();}catch(Throwable $e){check($status===null||($e instanceof RemoteError&&$e->status===$status),$label);return;}check(false,$label);}
function identity(string $host,string $user,string $dir):array{mkdir($dir,0700);mkdir($dir.'/media',0700);mkdir($dir.'/sessions',0700);$key=openssl_pkey_new(['private_key_bits'=>2048]);openssl_pkey_export($key,$private);return ['base_url'=>'https://'.$host,'username'=>$user,'display_name'=>$user,'password_hash'=>'unused','data_dir'=>$dir,'dsn'=>'sqlite:'.$dir.'/db','routing'=>'query','private_key'=>$private,'public_key'=>openssl_pkey_get_details($key)['key']];}
function note(string $id,string $author,string $content):array{return ['id'=>$id,'type'=>'Note','attributedTo'=>$author,'to'=>[Federation::PUBLIC],'content'=>$content,'published'=>gmdate('c'),'attachment'=>[]];}
function send(Pup\App $receiver,Pup\App $sender,array $object,string $type='Update'):void{$a=$sender->fed->activity($type,$object);$body=App::json($a);$receiver->fed->inbox($sender->http->signatureHeaders('POST',$receiver->url('/inbox'),$body),$body,'/index.php?route=%2Finbox');$receiver->fed->work(10,2);}
$dir=sys_get_temp_dir().'/quackpack-security-'.bin2hex(random_bytes(6));mkdir($dir,0700);
try{
 $config=identity('photos.example','alice',$dir.'/owner');
 if($dsn=getenv('PUP_TEST_DSN')){if(!str_contains($dsn,'dbname=pup_test'))throw new RuntimeException('Use a dedicated pup_test database.');$config['dsn']=$dsn;$config['db_user']=getenv('PUP_TEST_DB_USER')?:'root';$config['db_password']=getenv('PUP_TEST_DB_PASSWORD')?:null;}
 $app=pup($config);$s=$app->store;$s->migrate();if($s->driver==='mysql')foreach(['records','jobs','discovery_queue'] as $table)$s->query('DELETE FROM '.$table);
 $mallory=pup(identity('shared.example','mallory',$dir.'/mallory'));$bob=pup(identity('shared.example','bob',$dir.'/bob'));$mallory->store->migrate();$bob->store->migrate();$calls=[];$answers=[];
 $app->http=new Http($app,function($url,$method,$body,$headers)use(&$calls,&$answers,$config){$calls[]=$url;$a=$answers[$url]??['id'=>$url,'type'=>'Person','inbox'=>'https://attack.example/inbox','publicKey'=>['id'=>$url.'#key','owner'=>$url,'publicKeyPem'=>$config['public_key']]];return ['status'=>200,'body'=>App::json($a),'headers'=>['content-type'=>'application/activity+json']];});
 $bad=function(string $host,string $name)use($app){$id='https://'.$host.'/users/'.$name;$body=App::json(['id'=>'https://'.$host.'/a/'.$name,'actor'=>$id,'type'=>'Follow','object'=>$app->actorId()]);$h=['Host'=>'photos.example','Date'=>gmdate('D, d M Y H:i:s').' GMT','Digest'=>'SHA-256='.base64_encode(hash('sha256',$body,true)),'Signature'=>'keyId="'.$id.'#key",headers="(request-target) host date digest",signature="'.base64_encode(random_bytes(32)).'"'];$app->fed->inbox($h,$body,'/index.php?route=%2Finbox');};
 for($i=0;$i<3;$i++)rejected(fn()=>$bad('attack.example','bad'.$i),'invalid signature rejected '.$i);
 check(count($calls)===3&&$s->count('actors')===0,'invalid signatures fetch bounded documents without persisting actor records');
 check((int)$s->query('SELECT COUNT(*) FROM jobs')->fetchColumn()===0,'invalid signatures never enter the job queue');
 $lock=fopen($dir.'/owner/inbox-verify.lock','c');flock($lock,LOCK_EX);$before=count($calls);rejected(fn()=>$bad('attack.example','locked'),'concurrent verification fails quickly with retry status',429);flock($lock,LOCK_UN);fclose($lock);
 check(count($calls)===$before,'busy verifier performs no outbound fetch');
 $budget='inbox-keys:'.(int)floor(time()/60);$s->put('attempts',$budget,['count'=>40,'hosts'=>[]]);rejected(fn()=>$bad('another.example','global'),'global first-contact key-fetch budget enforced',429);
 check(count($calls)===$before,'global budget rejection performs no outbound fetch');
 $s->put('attempts',$budget,['count'=>8,'hosts'=>['attack.example'=>8]]);rejected(fn()=>$bad('attack.example','host'),'per-origin first-contact budget enforced',429);
 $s->remove('attempts',$budget);
 $actor=$mallory->actor();$answers[$actor['id']]=$actor;$original=note('https://shared.example/p/bob',$bob->actorId(),'Bob original');
 foreach(['timeline','remote','saved','comments','discovery_posts'] as $bucket)$s->put($bucket,$original['id'],$bucket==='discovery_posts'?$original+['_discovery'=>['source'=>'shared.example']]:$original);
 $s->put('follows',$actor['id'],['id'=>$actor['id'],'state'=>'accepted']);
 $forged=$original;$forged['attributedTo']=$actor['id'];$forged['content']='FORGED';
 send($app,$mallory,$forged);
 check($s->get('actors',$actor['id'])!==null,'valid first-contact signature persists its verified actor');
 foreach(['timeline','remote','saved','comments','discovery_posts'] as $bucket)check($s->get($bucket,$original['id'])['content']==='Bob original','signed same-host Update cannot change ownership in '.$bucket);
 send($app,$mallory,$forged,'Create');check($s->get('saved',$original['id'])['attributedTo']===$bob->actorId(),'Create cannot replace another author either');
 $answers[$bob->actorId()]=$bob->actor();$edited=$original;$edited['content']='Bob edited';send($app,$bob,$edited);
 check($s->get('saved',$original['id'])['content']==='Bob edited','the original signed author can still update its saved post');
 $lock=fopen($dir.'/owner/inbox-verify.lock','c');flock($lock,LOCK_EX);send($app,$bob,$edited);flock($lock,LOCK_UN);fclose($lock);
 check($s->get('saved',$original['id'])['content']==='Bob edited','cached valid senders remain available while an unknown actor fetch is locked');
 $wire=function(Pup\App $sender,array $activity)use($app){$body=App::json($activity);$app->fed->inbox($sender->http->signatureHeaders('POST',$app->url('/inbox'),$body),$body,'/index.php?route=%2Finbox');$app->fed->work(10,2);};
 $collision='https://shared.example/a/collision';$a=$mallory->fed->activity('Update',$forged);$a['id']=$collision;$wire($mallory,$a);$edited['content']='Bob after collision';$a=$bob->fed->activity('Update',$edited);$a['id']=$collision;$wire($bob,$a);
 check($s->get('saved',$original['id'])['content']==='Bob after collision','another signed actor cannot preempt a legitimate activity by reusing its ID');
 $localId=$app->url('/p/'.str_repeat('a',24));$s->put('posts',$localId,note($localId,$app->actorId(),'Local post'));
 foreach([$mallory,$bob] as $sender){$a=$sender->fed->activity('Like',$localId);$a['id']='https://shared.example/a/reaction-collision';$wire($sender,$a);}
 check($s->get('reactions','https://shared.example/a/reaction-collision')['actor']===$mallory->actorId(),'a signed actor cannot replace another actor reaction');
 $before=count($calls);$app->fed->work(0,1);check(count($calls)===$before,'idle worker cleanup performs no network fetch');
 rejected(fn()=>$app->fed->verify([],str_repeat('x',Federation::MAX_INBOX_BYTES+1),'/inbox'),'oversized activities rejected before verification');
 $s->query('DELETE FROM jobs');$s->tx(function()use($s){for($i=0;$i<2000;$i++)$s->query("INSERT INTO jobs(id,payload,attempts,due,state,error,created,updated) VALUES(?,?,0,?,'pending','',?,?)",[str_pad(dechex($i),32,'0',STR_PAD_LEFT),'{}',time()+86400,time(),time()]);});
 rejected(fn()=>$bad('another.example','queue'),'full inbox applies backpressure before fetching a key',429);check(count($calls)===$before,'full inbox does no outbound work');$s->query('DELETE FROM jobs');
 $canonical=note('https://trusted.example/p/1','https://trusted.example/users/bob','Canonical text');$canonicalCalls=[];
 $app->discovery=new Discovery($app,function($url,$headers)use(&$canonicalCalls,&$canonical){$canonicalCalls[]=['url'=>$url,'headers'=>$headers];return $canonical;});
 $spoof=$canonical;$spoof['content']='FORGED';$spoof['attributedTo']='https://trusted.example/users/victim';
 check($app->discovery->ingest($spoof,'evil.example'),'foreign-origin discovery verifies and accepts canonical public content');
 $received=$s->get('discovery_posts',$canonical['id']);check($received['content']==='<p>Canonical text</p>'&&$received['attributedTo']===$canonical['attributedTo'],'untrusted text and author are replaced by canonical origin data');
 check($received['_discovery']['source']==='trusted.example'&&count($canonicalCalls)===1,'verified foreign discovery records its authoritative origin');
 check(!isset($canonicalCalls[0]['headers']['Authorization']),'foreign-origin verification receives no saved API token');
 $answers[$canonical['id']]=$canonical;$answers[$canonical['attributedTo']]=['id'=>$canonical['attributedTo'],'type'=>'Person','inbox'=>'https://trusted.example/inbox'];
 $preview=$app->browser->remember($spoof,['id'=>$spoof['attributedTo'],'name'=>'Forged name'],'evil.example');
 check($preview['content']==='Canonical text'&&$preview['actor']===$canonical['attributedTo'],'interactive API browsing also verifies foreign-origin post content and author');
 check($s->get('profiles',$spoof['attributedTo'])===null,'interactive foreign-origin data cannot poison the claimed author profile');
 $canonical['id']='https://trusted.example/p/wrong';$missing=$spoof;$missing['id']='https://trusted.example/p/2';check(!$app->discovery->ingest($missing,'evil.example'),'canonical object ID mismatch is rejected');
 $canonical=$missing;$canonical['to']=[];check(!$app->discovery->ingest($missing,'evil.example'),'canonical private object cannot enter discovery');
 $s->put('discovery_posts','https://trusted.example/p/old-spoof',note('https://trusted.example/p/old-spoof','https://trusted.example/users/victim','Old forged content')+['_discovery'=>['source'=>'evil.example']]);
 check(!in_array('https://trusted.example/p/old-spoof',array_column($app->feed->page('','newest',false)['posts'],'id'),true),'pre-upgrade foreign-origin poisoned entries are hidden from fresh feeds');
 $s->put('servers','private-interest.example',['domain'=>'private-interest.example','token'=>'FAKE_SECRET']);file_put_contents($dir.'/config.php','<?php return '.var_export($config,true).';');
 $get=function(array $cookies=[])use($dir){$env=getenv();$env['PUP_CONFIG']=$dir.'/config.php';$proc=proc_open([PHP_BINARY,__DIR__.'/request.php'],[['pipe','r'],['pipe','w'],['pipe','w']],$pipes,null,$env);fwrite($pipes[0],App::json(['path'=>'/','cookies'=>$cookies]));fclose($pipes[0]);$html=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);proc_close($proc);preg_match('~id="pup-state">(.*?)</script>~s',$html,$m);return [$html,json_decode($m[1]??'null',true),$err];};
 [$html,$state,$err]=$get();check(($state['owner']??true)===false&&($state['servers']??null)===[]&&!str_contains($html,'private-interest.example'),'signed-out public HTML contains no saved browsing destinations');check(!str_contains($html,'FAKE_SECRET'),'public HTML contains no saved token');
 $sid=bin2hex(random_bytes(16));file_put_contents($dir.'/owner/sessions/sess_'.$sid,'owner|'.serialize(hash('sha256',$config['password_hash'])).'csrf|'.serialize('fixture-csrf'));
 [$html,$state]=$get(['pup_owner'=>$sid]);check(($state['owner']??false)&&($state['servers'][0]['domain']??'')==='private-interest.example','owner retains its saved places');check(!str_contains($html,'FAKE_SECRET'),'owner HTML still redacts saved tokens');
 $s->query('DELETE FROM jobs');$s->query("INSERT INTO jobs(id,payload,attempts,due,state,error,created,updated) VALUES(?,?,8,?,'failed','old',?,?)",[str_repeat('f',32),'{}',time()-8*86400,time()-8*86400,time()-8*86400]);
 $s->put('actors','https://stale.example/users/bob',['id'=>'https://stale.example/users/bob']);$s->query('UPDATE records SET updated=? WHERE bucket=? AND ref=?',[time()-8*86400,'actors','https://stale.example/users/bob']);$app->fed->work(0,1);
 check((int)$s->query('SELECT COUNT(*) FROM jobs')->fetchColumn()===0&&$s->get('actors','https://stale.example/users/bob')===null,'expired actor cache and terminal failed jobs are pruned');
 $s->tx(function()use($s){for($i=$s->count('actors');$i<5002;$i++)$s->put('actors','capacity:'.$i,['id'=>'capacity:'.$i]);});$app->fed->work(0,1);check($s->count('actors')===5000,'actor-cache migration enforces its storage quota');
 $carolConfig=$bob->config;$carolConfig['username']='carol';$carol=pup($carolConfig);$answers[$carol->actorId()]=$carol->actor();send($app,$carol,note('https://shared.example/p/carol',$carol->actorId(),'Carol'));
 check($s->count('actors')===5000&&$s->get('actors',$carol->actorId())!==null,'verified first contact evicts an old cache entry without growing the quota');
 $s->put('posts','owner-fixture',['id'=>'owner-fixture']);$s->put('profiles','old-profile',['id'=>'old-profile']);$s->put('cache','old-snapshot',['id'=>'old-snapshot']);$s->remove('meta','cache_schema_0_5_1');$s->migrate();
 check($s->count('profiles')===0&&$s->count('cache')===0&&$s->count('remote')===0&&$s->count('discovery_posts')===0&&$s->get('posts','owner-fixture')!==null&&$s->get('saved',$original['id'])!==null,'security migration clears only disposable legacy caches and preserves owner and saved posts');
 $s->put('profiles','new-profile',['id'=>'new-profile']);$s->migrate();check($s->get('profiles','new-profile')!==null,'security cache migration runs only once');
 echo 'PASS: '.$checks.' security regression assertions on '.$s->driver."\n";
}finally{foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $f)$f->isDir()?rmdir($f->getPathname()):unlink($f->getPathname());rmdir($dir);}
