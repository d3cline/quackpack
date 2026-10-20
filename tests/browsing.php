<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
use Pup\App;
use Pup\Http;
use Pup\Remote;
use Pup\Federation;
$checks=0;
function check(bool $ok,string $name):void{if(!$ok)throw new RuntimeException($name);echo 'ok '.(++$GLOBALS['checks']).' - '.$name."\n";}
function rejects(callable $fn,string $name):void{try{$fn();}catch(Throwable){check(true,$name);return;}check(false,$name);}
$dir=sys_get_temp_dir().'/quackpack-browser-'.bin2hex(random_bytes(6));mkdir($dir,0700);
$key=openssl_pkey_new(['private_key_bits'=>2048]);openssl_pkey_export($key,$private);
$config=['base_url'=>'https://photos.example','username'=>'alice','display_name'=>'Alice','password_hash'=>'unused','data_dir'=>$dir,'dsn'=>'sqlite:'.$dir.'/test.sqlite','routing'=>'query','private_key'=>$private,'public_key'=>openssl_pkey_get_details($key)['key']];
if($dsn=getenv('PUP_TEST_DSN')){if(!str_contains($dsn,'dbname=pup_test'))throw new RuntimeException('Use pup_test.');$config['dsn']=$dsn;$config['db_user']=getenv('PUP_TEST_DB_USER')?:'root';$config['db_password']=getenv('PUP_TEST_DB_PASSWORD')?:null;}
function profile(string $host,string $user='bob',string $type='Person'):array{return ['id'=>'https://'.$host.'/users/'.$user,'type'=>$type,'name'=>'A public profile','preferredUsername'=>$user,'inbox'=>'https://'.$host.'/inbox','outbox'=>'https://'.$host.'/outbox/'.$user];}
function note(string $host,string $id,string $type='Note'):array{return ['id'=>'https://'.$host.'/p/'.$id,'type'=>$type,'attributedTo'=>'https://'.$host.'/users/bob','to'=>[Federation::PUBLIC],'published'=>gmdate('c'),'name'=>'A public '.$type,'content'=>'<p>Public content</p>','attachment'=>[['type'=>'Image','url'=>'https://'.$host.'/image.jpg','mediaType'=>'image/jpeg','name'=>'A photo']]];}
try{
 $app=pup($config);$s=$app->store;$s->migrate();if($s->driver==='mysql')foreach(['records','jobs','discovery_queue'] as $table)$s->query('DELETE FROM '.$table);
 $answers=[];$calls=[];
 $put=function(string $url,mixed $data,int $status=200,string $mime='application/activity+json')use(&$answers){$answers[$url]=['status'=>$status,'body'=>is_string($data)?$data:App::json($data),'headers'=>['content-type'=>$mime]];};
 $node=function(string $host,string $name)use($put){$put('https://'.$host.'/.well-known/nodeinfo',['links'=>[['rel'=>'http://nodeinfo.diaspora.software/ns/schema/2.0','href'=>'https://'.$host.'/nodeinfo/2.0']]],200,'application/json');$put('https://'.$host.'/nodeinfo/2.0',['software'=>['name'=>$name]],200,'application/json');};
 $app->http=new Http($app,function($url,$method,$body,$headers)use(&$answers,&$calls){$calls[]=compact('url','method','body','headers');return $answers[$url]??['status'=>404,'body'=>'{"error":"No public endpoint"}','headers'=>['content-type'=>'application/json']];});
 $node('mastodon.social','mastodon');$put('https://mastodon.social/api/v1/timelines/public?limit=24&only_media=true&local=true',['error'=>'This method requires an authenticated user'],422,'application/json');
 $put('https://mastodon.social/api/v1/trends/statuses?limit=40',['error'=>'Not available'],403,'application/json');
 $account=['id'=>'7','url'=>'https://mastodon.social/@bob','uri'=>'https://mastodon.social/users/bob','username'=>'bob','display_name'=>'Bob'];
 $put('https://mastodon.social/api/v1/directory?local=true&order=active&limit=12',[$account],200,'application/json');
 $r=$app->browser->server('mastodon.social');check($r['posts']===[]&&count($r['people'])===1&&$r['access_required'],'mastodon.social opens its public people even when both timeline and highlights are restricted');
 $ms=['id'=>'9','uri'=>'https://mastodon.social/users/bob/statuses/9','url'=>'https://mastodon.social/@bob/9','visibility'=>'public','created_at'=>gmdate('c'),'content'=>'<p>Public photo</p>','account'=>$account,'media_attachments'=>[['type'=>'image','url'=>'https://mastodon.social/image.jpg','description'=>'Photo alt']]];
 $put('https://mastodon.social/api/v1/accounts/7',$account,200,'application/json');$put('https://mastodon.social/api/v1/accounts/7/statuses?limit=24&only_media=true&exclude_reblogs=true',[$ms,array_replace($ms,['visibility'=>'private'])],200,'application/json');
 $before=count($calls);$r=$app->browser->account('mastodon.social','7','mastodon');
 check(count($r['posts'])===1&&$r['actor']['id']===$account['uri'],'opening a directory person reads public posts without an account token');
 check(!array_filter(array_slice($calls,$before),static fn($c)=>isset($c['headers']['Authorization'])||isset($c['headers']['Signature'])),'public REST profile reads need neither OAuth nor federation signatures');
 rejects(fn()=>$app->browser->account('mastodon.social','../../inbox','mastodon'),'native profile identifiers cannot change the API path');
 $node('pixel.example','pixelfed');$put('https://pixel.example/api/v1/timelines/public?limit=24&only_media=true&local=true',[$ms],200,'application/json');
 $put($ms['uri'],['id'=>$ms['uri'],'type'=>'Note','attributedTo'=>$account['uri'],'to'=>[Federation::PUBLIC],'content'=>$ms['content'],'published'=>$ms['created_at'],'attachment'=>[['type'=>'Image','mediaType'=>'image/jpeg','url'=>'https://mastodon.social/image.jpg']]]);$put($account['uri'],profile('mastodon.social'));
 check(count($app->browser->server('pixel.example')['posts'])===1,'Pixelfed uses its compatible photo timeline');
 $node('shark.example','sharkey');$u=['id'=>'u1','username'=>'bob','name'=>'Bob','avatarUrl'=>'https://shark.example/avatar.jpg'];
 $n=['id'=>'n1','text'=>'A note <script>as text</script>','visibility'=>'public','createdAt'=>gmdate('c'),'user'=>$u,'files'=>[['type'=>'image/png','url'=>'https://shark.example/image.png','comment'=>'A duck','isSensitive'=>true]]];
 $put('https://shark.example/api/notes/local-timeline',[$n,array_replace($n,['id'=>'private','visibility'=>'specified'])],200,'application/json');$put('https://shark.example/api/users',[$u],200,'application/json');
 $r=$app->browser->server('shark.example');check(count($r['posts'])===1&&$r['posts'][0]['photos'][0]['alt']==='A duck'&&$r['posts'][0]['sensitive'],'Misskey-family notes retain public visibility, photos, alt text and warnings');
 check(count($r['people'])===1&&$r['people'][0]['family']==='misskey','Sharkey exposes public people through the shared Misskey adapter');
 $posted=array_values(array_filter($calls,static fn($c)=>$c['url']==='https://shark.example/api/notes/local-timeline'))[0];
 check($posted['method']==='POST'&&json_decode($posted['body'],true)['withFiles']===true&&!isset($posted['headers']['Signature']),'Misskey read-only API uses its documented JSON POST shape');
 $put('https://shark.example/api/users/show',$u,200,'application/json');$put('https://shark.example/api/users/notes',[$n],200,'application/json');
 check(count($app->browser->account('shark.example','u1','misskey')['posts'])===1,'Misskey people open their own public notes');
 $put('https://shark.example/api/notes/local-timeline',['error'=>'Authentication required'],403,'application/json');$put('https://shark.example/api/notes/featured',[$n],200,'application/json');
 $r=$app->browser->server('shark.example');check(count($r['posts'])===1&&$r['listing']==='Public highlights'&&count($r['people'])===1,'restricted Misskey timelines still open public highlights and people');
 $node('tube.example','peertube');$channel=['id'=>12,'url'=>'https://tube.example/video-channels/ducks','name'=>'ducks','displayName'=>'Duck videos','host'=>'tube.example'];
 $video=['uuid'=>'video-1','url'=>'https://tube.example/videos/watch/video-1','name'=>'Ducks on a pond','description'=>'A public video','publishedAt'=>gmdate('c'),'privacy'=>['id'=>1],'channel'=>$channel,'thumbnailPath'=>'/thumbs/duck.jpg'];
 $put('https://tube.example/api/v1/videos?count=24&start=0&sort=-publishedAt&isLocal=true',['data'=>[$video],'total'=>1],200,'application/json');$put('https://tube.example/api/v1/video-channels?count=12&start=0',['data'=>[$channel]],200,'application/json');
 $r=$app->browser->server('tube.example');check(count($r['posts'])===1&&$r['posts'][0]['kind']==='Video'&&$r['posts'][0]['preview']==='https://tube.example/thumbs/duck.jpg'&&!$r['photos_applied'],'PeerTube shows videos with preview links instead of an empty photo-only page');
 check(count($r['people'])===1&&$r['people'][0]['name']==='Duck videos','PeerTube channels are browseable places');
 $put('https://tube.example/api/v1/video-channels/12',$channel,200,'application/json');$put('https://tube.example/api/v1/video-channels/12/videos?count=24&start=0',['data'=>[$video],'total'=>1],200,'application/json');
 check(count($app->browser->account('tube.example','12','peertube')['posts'])===1,'opening a PeerTube channel lists its public videos');
 $node('lemmy.example','lemmy');$community=['id'=>3,'actor_id'=>'https://lemmy.example/c/ducks','name'=>'ducks','title'=>'Duck discussions'];
 $view=['post'=>['ap_id'=>'https://lemmy.example/post/9','name'=>'A question about ducks','body'=>'What kind of duck?','published'=>gmdate('c')],'creator'=>['actor_id'=>'https://lemmy.example/u/bob','name'=>'bob'],'community'=>$community];
 $put('https://lemmy.example/api/v3/post/list?type_=Local&sort=Active&limit=24&page=1',['posts'=>[$view]],200,'application/json');$put('https://lemmy.example/api/v3/community/list?type_=Local&sort=Active&limit=12',['communities'=>[['community'=>$community]]],200,'application/json');
 $r=$app->browser->server('lemmy.example');check(count($r['posts'])===1&&$r['posts'][0]['kind']==='Page'&&!$r['photos_applied']&&count($r['people'])===1,'Lemmy exposes public discussions and communities in the same browser');
 $node('odd.example','writefreely');$actor=profile('odd.example');$put('https://odd.example/','<html><head><link href="/users/bob" type="application/activity+json" rel="alternate"></head><script>neverRun()</script></html>',200,'text/html');$put($actor['id'],$actor);
 $article=note('odd.example','article','Article');$article['attachment']=[];$article['name']='A longer public story';
 $put($actor['outbox'],['type'=>'OrderedCollection','first'=>['type'=>'OrderedCollectionPage','orderedItems'=>[['type'=>'Create','object'=>$article]]]]);
 $r=$app->browser->server('odd.example');check(count($r['posts'])===1&&str_contains($r['posts'][0]['content'],'A longer public story')&&!$r['photos_applied'],'unknown software opens HTML-advertised actors and embedded article collections');
 check($r['actor']['id']===$actor['id'],'generic browsing preserves the canonical actor for follow actions');
 $put('https://odd.example/story','<link rel="alternate" type="application/activity+json" href="/p/article">',200,'text/html');$put($article['id'],$article);
 $r=$app->browser->person('https://odd.example/story');check(count($r['posts'])===1&&$r['posts'][0]['id']===$article['id'],'an HTML article URL resolves its canonical ActivityPub object');
 $put('https://odd.example/link-header','<html>No alternate element</html>',200,'text/html');$answers['https://odd.example/link-header']['headers']['link']='<https://odd.example/users/bob>; rel="alternate"; type="application/activity+json"';
 check($app->remote->document('https://odd.example/link-header')['id']===$actor['id'],'HTTP Link headers can advertise a profile');
 $collection='https://odd.example/collection';$items=[];for($i=0;$i<13;$i++){$p=note('odd.example','linked'.$i);$items[]=$p['id'];$put($p['id'],$p);}
 $put($collection,['type'=>'OrderedCollection','id'=>$collection,'orderedItems'=>$items]);
 $r=$app->browser->person($collection);check(count($r['posts'])===6&&str_starts_with($r['cursor'],'ap_'),'string-valued outboxes fetch a bounded batch and retain a continuation');
 $r2=$app->browser->person($collection,$r['cursor']);$r3=$app->browser->person($collection,$r2['cursor']);
 check(count($r2['posts'])===6&&count($r3['posts'])===1&&$r3['cursor']==='','collection continuation reads every linked item without losing a page');
 check(!array_intersect(array_column($r['posts'],'id'),array_column($r2['posts'],'id')),'bounded collection pages avoid duplicate objects');
 rejects(fn()=>$app->browser->person($collection,$r['cursor'],false),'collection cursors are bound to the photo filter');
 rejects(fn()=>$app->browser->person($collection,'https://other.example/page'),'collection cursors stay on their declared host');
 $p=note('odd.example','private');$p['to']=[$actor['id']];$put('https://odd.example/private-collection',['type'=>'Collection','items'=>[['type'=>'Create','to'=>[Federation::PUBLIC],'object'=>$p]]]);
 check($app->browser->person('https://odd.example/private-collection')['posts']===[],'a public wrapper cannot promote an explicitly private object');
 $p=note('odd.example','inherited');unset($p['to']);$put('https://odd.example/audience',['type'=>'Collection','items'=>[['type'=>'Create','to'=>[Federation::PUBLIC],'object'=>$p]]]);
 check(count($app->browser->person('https://odd.example/audience')['posts'])===1,'Create addressing supplies an omitted object audience');
 $poll=note('odd.example','poll','Question');$poll['attachment']=[];$poll['oneOf']=[['name'=>'Duck'],['name'=>'Goose']];$put('https://odd.example/polls',['type'=>'Collection','items'=>[$poll]]);
 $r=$app->browser->person('https://odd.example/polls');check(str_contains($r['posts'][0]['content'],'Duck · Goose'),'public polls remain readable without a platform-specific API');
 $cross=note('objects.example','delegated','Article');$cross['attributedTo']=$actor['id'];$put($actor['outbox'],['type'=>'OrderedCollection','orderedItems'=>[$cross]]);$s->remove('cache','ap-page:'.hash('sha256',$actor['outbox']));
 check(count($app->browser->person($actor['id'])['posts'])===1,'a canonical actor can authorize a delegated outbox with different object origins');
 $forged=note('victim.example','forged');$forged['content']='Spoofed';$real=$forged;$real['content']='Actual canonical content';$put($real['id'],$real);$put('https://odd.example/boosts',['type'=>'Collection','items'=>[['type'=>'Announce','object'=>$forged]]]);
 $boost=$app->browser->person('https://odd.example/boosts')['posts'][0]['content'];check(str_contains($boost,'Actual canonical content')&&!str_contains($boost,'Spoofed'),'remote Announce objects are fetched from their actual origin before display');
 $group=profile('group.example','bob','Group');$put($group['id'],$group);$put($group['outbox'],['type'=>'OrderedCollection','orderedItems'=>[['type'=>'Create','object'=>$forged]]]);
 check(str_contains($app->browser->person($group['id'])['posts'][0]['content'],'Actual canonical content'),'Group collections can include another author after fetching the canonical public object');
 $node('unknown.example','unusual-software');$put('https://unknown.example/','<html>No public index</html>',200,'text/html');$r=$app->browser->server('unknown.example');
 check($r['needs_profile']&&$r['source']==='unknown.example','unindexed software gets a usable source page with a public URL action');
 $s->put('blocks','odd.example',['domain'=>'odd.example']);rejects(fn()=>$app->browser->person($collection),'local blocks also apply to cached collection pages');
 check(Remote::absolute('javascript:alert(1)','https://odd.example/')===''&&Remote::absolute('//private.example/','https://odd.example/')==='','alternate links cannot change to unsafe URL schemes');
 check(Remote::absolute('../users/bob','https://odd.example/a/article')==='https://odd.example/users/bob','relative alternate links resolve without script execution');
 echo 'PASS: '.$checks.' cross-platform browser assertions on '.$s->driver."\n";
}finally{
 if(isset($s)&&$s->driver==='mysql')foreach(['records','jobs','discovery_queue'] as $table)$s->query('DELETE FROM '.$table);
 unset($app,$s);$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($it as $file)$file->isDir()?rmdir($file->getPathname()):unlink($file->getPathname());rmdir($dir);
}
