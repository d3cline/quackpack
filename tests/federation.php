<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
use Pup\App;
use Pup\Federation;
use Pup\Http;
use Pup\RemoteError;

$checks=0;
function check(bool $value,string $label):void{if(!$value)throw new RuntimeException($label);echo 'ok '.(++$GLOBALS['checks']).' - '.$label."\n";}
function denied(callable $fn,callable $valid,string $label):void{try{$fn();}catch(Throwable $e){check($valid($e),$label);return;}check(false,$label);}
function identity(string $host,string $user,string $dir):array{
    mkdir($dir,0700);mkdir($dir.'/media',0700);mkdir($dir.'/sessions',0700);
    $key=openssl_pkey_new(['private_key_bits'=>2048]);openssl_pkey_export($key,$private);
    return ['base_url'=>'https://'.$host,'username'=>$user,'display_name'=>$user,'password_hash'=>'unused','data_dir'=>$dir,'dsn'=>'sqlite:'.$dir.'/test.sqlite',
        'routing'=>'query','private_key'=>$private,'public_key'=>openssl_pkey_get_details($key)['key']];
}
$dir=sys_get_temp_dir().'/quackpack-federation-'.bin2hex(random_bytes(6));mkdir($dir,0700);
try{
    $config=identity('photos.example','alice',$dir.'/owner');$peerConfig=identity('peer.example','bob',$dir.'/peer');unset($peerConfig['routing']);
    if($dsn=getenv('PUP_TEST_DSN')){
        if(!str_contains($dsn,'dbname=pup_test'))throw new RuntimeException('Use a dedicated pup_test database.');
        $config['dsn']=$dsn;$config['db_user']=getenv('PUP_TEST_DB_USER')?:'root';$config['db_password']=getenv('PUP_TEST_DB_PASSWORD')?:null;
    }
    $app=pup($config);$peer=pup($peerConfig);$s=$app->store;$s->migrate();$peer->store->migrate();
    if($s->driver==='mysql')foreach(['records','jobs','discovery_queue'] as $table)$s->query('DELETE FROM '.$table);
    $peerActor=$peer->actor();$ownerActor=$app->actor();$ownerActor['_fetched']=time();$peer->store->put('actors',$ownerActor['id'],$ownerActor);
    $calls=[];$deliveries=[];$apiMode='restricted';$badHomepage=false;$badPhoto=false;
    $status=['id'=>'123','uri'=>'https://peer.example/p/photo','url'=>'https://peer.example/@bob/123','visibility'=>'public','created_at'=>gmdate('c'),'content'=>'<p>A public photo</p>',
        'account'=>['url'=>'https://peer.example/@bob','username'=>'bob','display_name'=>'Bob'],'media_attachments'=>[['type'=>'image','url'=>'https://peer.example/photo.jpg','description'=>'A real alt description']]];
    $app->http=new Http($app,function($url,$method,$body,$headers)use($app,$peer,$peerActor,$status,&$calls,&$deliveries,&$apiMode,&$badHomepage,&$badPhoto){
        $calls[]=compact('url','method','headers');$parts=parse_url($url);parse_str($parts['query']??'',$q);$route=$q['route']??$parts['path']??'/';
        $json=static fn($doc,$code=200,$mime='application/activity+json')=>['status'=>$code,'body'=>App::json($doc),'headers'=>['content-type'=>$mime]];
        if($parts['host']==='mastodon.social'){
            if(str_contains($route,'/timelines/')){
                if($apiMode==='invalid')return $json(['error'=>'Invalid parameter'],422,'application/json');
                if(($headers['Authorization']??'')!=='Bearer fixture-read-token')return $json(['error'=>'This method requires an authenticated user'],422,'application/json');
                return $json([$status],200,'application/json');
            }
            if($route==='/api/v1/directory')return $json([['id'=>'7','url'=>'https://mastodon.social/@photo','username'=>'photo','display_name'=>'A photographer']],200,'application/json');
            if($route==='/api/v1/trends/statuses')return $json([$status],200,'application/json');
        }
        if($parts['host']==='photos.example'){
            if($route==='/')return $badHomepage?$json(['error'=>'homepage HTML'],200,'text/html'):$json($app->actor());
            if($route==='/.well-known/webfinger')return ['status'=>301,'body'=>'','headers'=>['location'=>'https://photos.example/.well-known/webfinger/?'.($parts['query']??'')]];
            if($route==='/.well-known/webfinger/')return $json(['subject'=>'acct:alice@photos.example','links'=>[['rel'=>'self','type'=>'application/activity+json','href'=>$app->actorId()]]],200,'application/jrd+json');
            if($route==='/users/alice')return $json($app->actor());
            if($route==='/outbox')return $json(['type'=>'OrderedCollectionPage','orderedItems'=>[]]);
            if(str_starts_with($route,'/p/'))return $badPhoto?$json(['error'=>'missing'],404):$json($app->store->get('posts',$url));
        }
        if($url===$peerActor['id']&&$method==='GET')return $json($peerActor);
        if($url===$status['uri']&&$method==='GET')return $json(['id'=>$status['uri'],'type'=>'Note','attributedTo'=>$peerActor['id'],'to'=>[Federation::PUBLIC],'published'=>$status['created_at'],'content'=>$status['content'],'attachment'=>[['type'=>'Document','mediaType'=>'image/jpeg','url'=>'https://peer.example/photo.jpg','name'=>'A real alt description']]]);
        if($url===$peerActor['inbox']&&$method==='POST'){
            $target=($parts['path']??'/').(isset($parts['query'])?'?'.$parts['query']:'');
            $peer->fed->inbox($headers,$body,$target);$deliveries[]=json_decode($body,true,64,JSON_THROW_ON_ERROR);
            return $json(['accepted'=>true],202,'application/json');
        }
        throw new RuntimeException('Unexpected fixture URL: '.$url);
    });
    $e=Http::responseError(422,'https://mastodon.social/api/v1/timelines/public?token=never-log-this',App::json(['error'=>'This method requires an authenticated user']));
    check($e->authenticationRequired&&str_contains($e->getMessage(),'read access token')&&!str_contains($e->getMessage(),'never-log-this'),'real Mastodon 422 body is classified without logging URL query secrets');
    check(!Http::responseError(422,'https://peer.example/inbox','{"error":"Invalid object"}')->authenticationRequired,'an unrelated 422 remains a protocol error');
    check(!str_contains(Http::responseError(500,'https://peer.example/inbox','<html>upstream detail</html>')->getMessage(),'upstream detail'),'HTML error pages are not copied into diagnostics');
    $e=Http::responseError(429,'https://peer.example/api/v1/timelines/public','{}',['retry-after'=>'120','x-ratelimit-reset'=>gmdate('c',time()+300)]);
    check($e->retryAfter>=295,'rate-reset and Retry-After use the later cooldown');
    $r=$app->browser->timeline('mastodon.social');
    check(count($r['posts'])===1&&$r['access_required']&&$r['listing']==='Public highlights'&&$r['cursor']==='','restricted timeline falls back to a labelled, finite public highlights feed');
    check(str_contains($r['notice'],'other servers')&&$r['posts'][0]['photos'][0]['alt']==='A real alt description','fallback explains its scope and retains photo descriptions');
    $restCalls=array_filter($calls,static fn($c)=>str_contains($c['url'],'mastodon.social/api/'));
    check(count($restCalls)===3&&!array_filter($restCalls,static fn($c)=>($c['headers']['Accept']??'')!=='application/json'||isset($c['headers']['Signature'])),'REST browsing requests JSON without federation signatures');
    $s->put('servers','mastodon.social',['domain'=>'mastodon.social','token'=>'fixture-read-token']);
    $r=$app->browser->timeline('mastodon.social');
    check(!$r['access_required']&&$r['cursor']==='123','adding a read token bypasses the anonymous fallback cache');
    $apiMode='invalid';
    denied(fn()=>$app->browser->timeline('mastodon.social','photos'),static fn($e)=>$e instanceof RemoteError&&$e->status===422&&!$e->authenticationRequired,'validation failures do not masquerade as access restrictions');
    $apiMode='restricted';$s->remove('servers','mastodon.social');
    denied(fn()=>$app->browser->timeline('mastodon.social','photos'),static fn($e)=>$e instanceof RemoteError&&$e->authenticationRequired,'restricted hashtag search offers authentication without unrelated highlights');
    $app->http->getJson('https://photos.example/.well-known/webfinger?resource=acct:alice@photos.example',false,['Accept'=>'application/jrd+json','Authorization'=>'Bearer never-forward']);
    $redirect=end($calls);
    check($redirect['headers']['Accept']==='application/jrd+json'&&!isset($redirect['headers']['Authorization']),'WebFinger redirects retain content negotiation while dropping credentials');
    $before=$app->fed->checkPublic();check($before['ok']&&count($before['checks'])===4,'on-host check verifies WebFinger, actor, homepage and outbox');
    $badHomepage=true;$failed=$app->fed->checkPublic();check(!$failed['ok']&&!$failed['checks'][2]['ok'],'homepage discovery failure is recorded distinctly');$badHomepage=false;
    denied(fn()=>$app->fed->resend(),static fn($e)=>$e instanceof InvalidArgumentException,'resending with no followers gives a concrete next step');
    $photo=['type'=>'Document','mediaType'=>'image/jpeg','url'=>$app->url('/media/'.str_repeat('a',40).'.jpg'),'name'=>'A quiet street','width'=>1200,'height'=>800];
    $old=$app->post(['caption'=>'Before the first follower'],[$photo]);$app->fed->work(20,3);
    check(count($deliveries)===0,'publishing before anyone follows does not send unsolicited activities');
    $follow=$peer->fed->activity('Follow',$app->actorId(),$app->actorId());
    $peer->store->put('follows',$app->actorId(),['id'=>$app->actorId(),'actor'=>$ownerActor,'activity'=>$follow,'state'=>'pending']);
    $body=App::json($follow);$headers=$peer->http->signatureHeaders('POST',$app->url('/inbox'),$body);
    $app->fed->inbox($headers,$body,'/index.php?route=%2Finbox');$app->fed->work(20,3);$peer->fed->work(20,3);
    check($s->count('followers')===1&&$peer->store->get('follows',$app->actorId())['state']==='accepted','signed Follow is processed and signed Accept reaches the peer');
    $post=$app->post(['caption'=>'After the first follower','sensitive'=>true,'summary'=>'A gentle warning'],[$photo]);
    $result=$app->fed->work(20,3);$peer->fed->work(20,3);$received=$peer->store->get('timeline',$post['id']);
    check($result['rescheduled_or_failed']===0&&$received!==null,'published photo reaches a following peer through the actual background job queue');
    check($received['attachment'][0]['url']===$photo['url']&&$received['attachment'][0]['name']==='A quiet street'&&$received['sensitive'],'Create retains public photo URL, alt text and content warning');
    check($s->get('meta','last_delivery')['type']==='Create','successful inbox acceptance records a delivery heartbeat');
    $edited=$app->post(['caption'=>'An edited photo'],[],$post['id']);$app->fed->work(20,3);$peer->fed->work(20,3);
    check($peer->store->get('timeline',$post['id'])['source']['content']==='An edited photo','signed Update refreshes the photo at the peer');
    $count=$app->fed->resend();$app->fed->work(40,3);$peer->fed->work(40,3);
    check($count===2&&$peer->store->get('timeline',$old['id'])!==null&&$peer->store->count('timeline')===2,'resend delivers pre-follow photos without duplicating existing posts');
    $latest=$app->fed->checkPublic();check($latest['ok']&&count($latest['checks'])===5,'public endpoint check includes the latest published object');
    $badPhoto=true;$latest=$app->fed->checkPublic();check(!$latest['ok']&&!$latest['checks'][4]['ok'],'missing public post is reported even when the profile works');$badPhoto=false;
    $app->deletePost($post['id']);$app->fed->work(20,3);$peer->fed->work(20,3);
    check($peer->store->get('timeline',$post['id'])===null,'signed Delete removes the remote photo');
    check(count(array_unique(array_column(array_filter($deliveries,static fn($a)=>$a['type']==='Create'),'id')))===2,'resend preserves original Create identifiers');
    echo 'PASS: '.$checks.' federation/browsing assertions on '.$s->driver."\n";
}finally{
    if(isset($s)&&$s->driver==='mysql')foreach(['records','jobs','discovery_queue'] as $table)$s->query('DELETE FROM '.$table);
    unset($app,$peer,$s);
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($it as $file)$file->isDir()?rmdir($file->getPathname()):unlink($file->getPathname());rmdir($dir);
}
