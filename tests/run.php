<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';

$tests=0;
function check(bool $condition,string $name):void{global $tests;$tests++;if(!$condition)throw new RuntimeException('FAIL: '.$name);echo "ok $tests - $name\n";}
function rejects(callable $fn,string $name):void{try{$fn();}catch(Throwable){check(true,$name);return;}check(false,$name);}
function fixture(string $base,string $user,string $dir,string $dsn):array{
    $key=openssl_pkey_new(['private_key_bits'=>2048]);openssl_pkey_export($key,$private);
    return ['base_url'=>$base,'username'=>$user,'display_name'=>'Test photographer','password_hash'=>password_hash('not-a-real-password',PASSWORD_DEFAULT),
        'private_key'=>$private,'public_key'=>openssl_pkey_get_details($key)['key'],'data_dir'=>$dir,'dsn'=>$dsn,
        'db_user'=>getenv('PUP_TEST_DB_USER')?:'root','db_password'=>getenv('PUP_TEST_DB_PASSWORD')?:null,'development'=>false];
}
$dir=sys_get_temp_dir().'/pup-test-'.bin2hex(random_bytes(6));mkdir($dir,0700);mkdir($dir.'/media',0700);mkdir($dir.'/sessions',0700);
$dsn=getenv('PUP_TEST_DSN')?:'sqlite:'.$dir.'/test.sqlite';
if(str_starts_with($dsn,'mysql:')&&!str_contains($dsn,'dbname=pup_test'))throw new RuntimeException('MySQL tests require a dedicated database named pup_test.');
try{
    $app=pup(fixture('https://photos.example','alice',$dir,$dsn));$s=$app->store;$s->migrate();
    if($s->driver==='mysql'){$s->query('DELETE FROM records');$s->query('DELETE FROM jobs');}
    check($s->count('posts')===0,'fresh schema');$s->migrate();check(true,'schema migration is repeatable');
    $s->put('test','one',['text'=>'hello 🐾']);$s->put('test','one',['text'=>'updated 🐾']);check($s->get('test','one')['text']==='updated 🐾','portable upsert and Unicode');
    rejects(fn()=>$s->tx(function()use($s){$s->put('test','rollback',['ok'=>false]);throw new RuntimeException('rollback');}),'transaction aborts');check($s->get('test','rollback')===null,'transaction rollback preserves state');
    check($s->add('test','unique',['n'=>1])&&!$s->add('test','unique',['n'=>2])&&$s->get('test','unique')['n']===1,'atomic insertion deduplicates without overwriting');
    for($i=0;$i<405;$i++)$s->put('bulk',(string)$i,['id'=>(string)$i]);
    foreach($s->each('bulk')as$item)$s->remove('bulk',$item['id']);
    check($s->count('bulk')===0,'deleting during iteration does not skip later pages');
    check($app->links()===[],'new installation has an empty link hub');
    $links=[['label'=>'Music','url'=>'https://music.example/','enabled'=>true],['label'=>'Draft','url'=>'https://draft.example/','enabled'=>false]];
    $app->saveLinks($links);check($app->links()===$links&&$app->links(true)===[$links[0]],'link storage preserves order and filters hidden entries');
    $app->saveLinks(array_reverse($links));check($app->links()===array_reverse($links),'link order changes persist');
    $actor=$app->actor();check($actor['id']==='https://photos.example/users/alice'&&isset($actor['publicKey']['publicKeyPem']),'one actor with stable key');
    check(Pup\Http::validUrl('https://example.com/users/me'),'public HTTPS accepted');
    foreach(['http://example.com','https://user:pass@example.com','https://example.com:22/','file:///etc/passwd',"https://example.com/\r\nx"]as$url)check(!Pup\Http::validUrl($url),'reject unsafe URL '.str_replace(["\r","\n"],'',$url));
    foreach(['127.0.0.1','10.1.2.3','172.16.1.1','192.168.1.1','169.254.169.254','100.100.1.1','198.19.0.1','203.0.113.5','::1','::ffff:127.0.0.1','2001:db8::1','2002:7f00:1::']as$ip)check(!Pup\Http::publicIp($ip),'reject reserved IP '.$ip);
    check(Pup\Http::publicIp('1.1.1.1')&&Pup\Http::publicIp('2606:4700:4700::1111'),'public IPv4 and IPv6 accepted');
    check(Pup\Browser::plain('<p>Hello <b>world</b>.</p><script>bad()</script><p>&lt;img&gt;</p>')==="Hello world.\n<img>",'remote HTML converted to inert text');
    $attachment=['type'=>'Document','mediaType'=>'image/jpeg','url'=>'https://photos.example/media/'.str_repeat('a',40).'.jpg','name'=>'A quiet street'];
    $p=$app->post(['caption'=>'A <script> is just text. #photography #φως'],[$attachment]);
    check($s->count('posts')===1&&count($p['tag'])===2,'publish with Unicode hashtags');
    check(!str_contains($p['content'],'<script>')&&str_contains($p['content'],'&lt;script&gt;'),'local caption escaped');
    check((int)$s->query("SELECT COUNT(*) FROM jobs WHERE state='pending'")->fetchColumn()===1,'post and outbound queue commit together');
    check($s->get('creates',$p['id'])['type']==='Create','outbox create is dereferenceable');
    rejects(fn()=>$app->post(['caption'=>'missing photo']),'photo journal requires a photo');
    rejects(fn()=>$app->post(['caption'=>str_repeat('x',5001)],[$attachment]),'caption length enforced');
    $edited=$app->post(['caption'=>'Corrected caption'],[],$p['id']);check($edited['id']===$p['id']&&$edited['attachment']===$p['attachment']&&isset($edited['updated']),'edit retains identity, photos and publication date');
    $remote=pup(fixture('https://remote.example','bob',$dir,$dsn));$ra=$remote->actor();$ra['_fetched']=time();$s->put('actors',$ra['id'],$ra);
    $follow=['id'=>'https://remote.example/a/follow1','type'=>'Follow','actor'=>$ra['id'],'object'=>$app->actorId()];
    $body=Pup\App::json($follow);$headers=$remote->http->signatureHeaders('POST','https://photos.example/inbox',$body);
    $verified=$app->fed->verify($headers,$body,'/inbox');check($verified['actor']===$ra['id'],'valid RSA-SHA256 inbox signature');
    rejects(fn()=>$app->fed->verify($headers,$body.' ','/inbox'),'tampered body rejected');
    rejects(fn()=>$app->fed->verify($headers,$body,'/other-inbox'),'tampered request target rejected');
    $bad=$headers;$bad['Date']='Thu, 01 Jan 1970 00:00:00 GMT';rejects(fn()=>$app->fed->verify($bad,$body,'/inbox'),'expired signature rejected');
    $app->fed->inbox($headers,$body,'/inbox');$n=(int)$s->query('SELECT COUNT(*) FROM jobs')->fetchColumn();$app->fed->inbox($headers,$body,'/inbox');check((int)$s->query('SELECT COUNT(*) FROM jobs')->fetchColumn()===$n,'duplicate inbox delivery enqueued once');
    $app->fed->receive($follow);check($s->get('followers',$ra['id'])!==null,'follow accepted');
    $app->fed->receive(['actor'=>$ra['id'],'type'=>'Undo','object'=>$follow+['bad'=>true]]);check($s->get('followers',$ra['id'])===null,'Undo Follow removes follower');
    $app->fed->receive($follow);$fake=$follow;$fake['id']='https://remote.example/a/not-the-follow';$app->fed->receive(['actor'=>$ra['id'],'type'=>'Undo','object'=>$fake]);check($s->get('followers',$ra['id'])!==null,'Undo must reference actual Follow');
    $out=['id'=>'https://photos.example/a/ourfollow','type'=>'Follow','actor'=>$app->actorId(),'object'=>$ra['id']];
    $s->put('follows',$ra['id'],['id'=>$ra['id'],'actor'=>$ra,'activity'=>$out,'state'=>'pending']);
    $app->fed->receive(['actor'=>$ra['id'],'type'=>'Accept','object'=>$out]);check($s->get('follows',$ra['id'])['state']==='accepted','Accept matches pending follow');
    $note=['id'=>'https://remote.example/p/1','type'=>'Note','attributedTo'=>$ra['id'],'published'=>gmdate('c'),'content'=>'<p>A remote moment.</p>','attachment'=>[$attachment],'to'=>[Pup\Federation::PUBLIC],'cc'=>[]];
    $incoming=['id'=>'https://remote.example/a/1','actor'=>$ra['id'],'type'=>'Create','object'=>$note];
    $app->fed->receive($incoming);check($s->get('timeline',$note['id'])!==null,'followed public post enters feed');
    $private=$note;$private['id']='https://remote.example/p/private';$private['to']=[$app->actorId()];$app->fed->receive(array_replace($incoming,['object'=>$private]));check($s->get('timeline',$private['id'])===null,'private messages are not retained');
    $forged=$note;$forged['id']='https://victim.example/p/spoof';$app->fed->receive(array_replace($incoming,['object'=>$forged]));check($s->get('timeline',$forged['id'])===null,'post origin must match signed author');
    $s->put('saved',$note['id'],$note);$note['content']='Updated';$app->fed->receive(array_replace($incoming,['type'=>'Update','object'=>$note]));check($s->get('saved',$note['id'])['content']==='Updated','remote Update refreshes saved post');
    $note['to']=[$app->actorId()];$app->fed->receive(array_replace($incoming,['type'=>'Update','object'=>$note]));check(!$s->get('saved',$note['id'])&&!$s->get('timeline',$note['id']),'public post becoming private removed from caches');
    $note['to']=[Pup\Federation::PUBLIC];$app->fed->receive(array_replace($incoming,['object'=>$note]));
    $app->fed->receive(['actor'=>$ra['id'],'type'=>'Delete','object'=>$note['id']]);check($s->get('timeline',$note['id'])===null,'signed remote Delete removes cached post');
    $like=['id'=>'https://remote.example/a/like','actor'=>$ra['id'],'type'=>'Like','object'=>$p['id']];$app->fed->receive($like);check($s->count('reactions')===1,'incoming Like recorded');
    $app->fed->receive(['actor'=>$ra['id'],'type'=>'Undo','object'=>$like]);check($s->count('reactions')===0,'Undo Like removes reaction');
    $comment=$note;$comment['id']='https://remote.example/p/comment';$comment['inReplyTo']=$p['id'];$app->fed->receive(array_replace($incoming,['object'=>$comment]));check($s->get('comments',$comment['id'])!==null,'public replies attached to local post');
    $ms=['id'=>'123','uri'=>'https://remote.example/p/api','url'=>'https://remote.example/@bob/123','created_at'=>gmdate('c'),'visibility'=>'public','content'=>'<p>A photo</p>','media_attachments'=>[['type'=>'image','url'=>'https://remote.example/img.jpg','description'=>'Remote alt text']],'account'=>['url'=>$ra['id'],'display_name'=>'Bob','username'=>'bob','acct'=>'bob@remote.example','avatar_static'=>'https://remote.example/avatar.jpg']];
    $normalized=$app->browser->mastodon($ms);check(count($normalized['photos'])===1&&$normalized['photos'][0]['alt']==='Remote alt text','Mastodon timeline normalized with alt text');
    $ms['visibility']='private';check($app->browser->mastodon($ms)===null,'non-public API statuses ignored');
    $im=imagecreatetruecolor(3000,2000);imagefill($im,0,0,imagecolorallocate($im,20,60,90));$src=$dir.'/source.png';imagepng($im,$src);imagedestroy($im);
    $processed=$app->media->process($src);check($processed['width']===2560&&$processed['height']===1707,'image re-encoded and resized');
    check((new finfo(FILEINFO_MIME_TYPE))->file($processed['_path'])==='image/jpeg','uploads stored as decoded JPEG, never executable source');
    file_put_contents($dir.'/unsafe.svg','<svg onload="bad()"></svg>');rejects(fn()=>$app->media->process($dir.'/unsafe.svg'),'SVG upload rejected');
    $path=$processed['_path'];unset($processed['_path']);$deletable=$app->post(['caption'=>'Temporary'],[$processed]);$app->deletePost($deletable['id']);check(!file_exists($path),'delete removes local media');
    $app->deletePost($p['id']);check(!$s->get('posts',$p['id'])&&$s->get('tombstones',$p['id'])['type']==='Tombstone','delete leaves a federation tombstone');
    // Run a real queued receive without making external network calls.
    $s->query('DELETE FROM jobs');$app->fed->enqueue(['kind'=>'receive','activity'=>['actor'=>$ra['id'],'type'=>'Delete','object'=>'https://remote.example/p/missing']]);
    $result=$app->fed->work(1,5);check($result['processed']===1&&$result['rescheduled_or_failed']===0,'cron worker processes queued activity');
    check($s->get('meta','worker')['last_run']>=time()-5,'worker heartbeat saved');
    echo "PASS: $tests assertions on ".$s->driver."\n";
} finally {
    if(isset($s)&&$s->driver==='mysql'){$s->query('DELETE FROM records');$s->query('DELETE FROM jobs');}
    unset($app,$remote,$s);
    gc_collect_cycles();
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($it as $file){$file->isDir()?rmdir($file->getPathname()):unlink($file->getPathname());}rmdir($dir);
}
