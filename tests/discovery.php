<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
use Pup\Discovery;
use Pup\Federation;
use Pup\RemoteError;
$checks=0;
function check(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException($label);echo 'ok '.(++$checks).' - '.$label."\n";}
function rejects(callable $fn,string $label):void{try{$fn();}catch(Throwable){check(true,$label);return;}check(false,$label);}
$dir=sys_get_temp_dir().'/quackpack-discovery-'.bin2hex(random_bytes(6));mkdir($dir,0700);mkdir($dir.'/sessions',0700);mkdir($dir.'/media',0700);
$key=openssl_pkey_new(['private_key_bits'=>2048]);openssl_pkey_export($key,$private);
$config=['base_url'=>'https://photos.example','username'=>'alice','display_name'=>'Alice','password_hash'=>password_hash('test-password',PASSWORD_DEFAULT),'private_key'=>$private,'public_key'=>openssl_pkey_get_details($key)['key'],'data_dir'=>$dir,'dsn'=>'sqlite:'.$dir.'/test.sqlite','development'=>false,'routing'=>'query'];
if($testDsn=getenv('PUP_TEST_DSN')){
 if(!str_contains($testDsn,'dbname=pup_test'))throw new RuntimeException('Use a dedicated MySQL database named pup_test.');
 $config['dsn']=$testDsn;$config['db_user']=getenv('PUP_TEST_DB_USER')?:'root';$config['db_password']=getenv('PUP_TEST_DB_PASSWORD')?:null;
}
function note(string $id,string $domain='good.example',string $author='bob',int $age=0,bool $photo=true):array{return ['id'=>'https://'.$domain.'/p/'.$id,'type'=>'Note','attributedTo'=>'https://'.$domain.'/users/'.$author,'to'=>[Federation::PUBLIC],'cc'=>[],'published'=>gmdate('c',time()-$age),'content'=>'A public moment '.$id,'attachment'=>$photo?[['type'=>'Document','mediaType'=>'image/jpeg','url'=>'https://'.$domain.'/photo/'.$id.'.jpg','name'=>'A photo']]:[]];}
try{
 $app=pup($config);$s=$app->store;$s->migrate();$answers=[];$calls=[];
 if($s->driver==='mysql'){foreach(['records','jobs','discovery_queue'] as $table)$s->query('DELETE FROM '.$table);}
 $app->discovery=new Discovery($app,function($url,$headers)use(&$answers,&$calls){$calls[]=['url'=>$url,'headers'=>$headers];if(!array_key_exists($url,$answers))throw new RuntimeException('Unexpected fixture request: '.$url);$value=$answers[$url];if($value instanceof Throwable)throw $value;return $value;});
 $d=$app->discovery;
 check(!$d->settings()['enabled'],'background crawling waits for first owner feed visit or explicit start');
 $d->boot();check($d->settings()['enabled']&&$s->count('crawl_servers')===3,'first feed visit seeds three remote sources');
 $d->control('pause');$d->boot();check(!$d->settings()['enabled'],'visiting the feed does not undo an explicit pause');
 $s->put('meta','discovery_settings',['enabled'=>true]);
 check($d->seed('good.example','you'),'new server enters persistent registry');
 $before=(int)$s->query('SELECT COUNT(*) FROM discovery_queue')->fetchColumn();$d->seed('https://good.example/');
 check((int)$s->query('SELECT COUNT(*) FROM discovery_queue')->fetchColumn()===$before,'server and task discovery deduplicate');
 check(!$d->seed('photos.example')&&!$d->seed('127.0.0.1')&&!$d->seed('localhost'),'own domain and literal local targets are not crawled');
 $run=function(string $kind,string $domain='good.example',?string $ref=null)use($s,$d):array{
  $s->query('UPDATE discovery_queue SET due=?',[time()+86400]);$ref=$ref??$domain;
  $s->query('UPDATE discovery_queue SET due=? WHERE kind=? AND ref=?',[time()-1,$kind,$ref]);
  $host=$s->get('crawl_servers',$domain);$host['last_request']=0;$host['cooldown']=0;$s->put('crawl_servers',$domain,$host);
  $r=$d->work(2,1);if($r['processed']!==1)throw new RuntimeException('Task did not execute: '.$kind);return $s->get('crawl_servers',$domain);
 };
 $p=note('first');check($d->ingest($p,'good.example',['engagement'=>10]),'listed public post is ingested');
 check(!$d->ingest($p,'good.example')&&$s->count('discovery_posts')===1,'canonical post ID deduplicates repeated discovery');
 $large=note('compact');$large['unknown_remote_payload']=str_repeat('x',500000);$d->ingest($large,'good.example');
 check(!isset($s->get('discovery_posts',$large['id'])['unknown_remote_payload']),'crawler discards arbitrary remote JSON payload from its display cache');
 $privatePost=note('private');$privatePost['to']=[$app->actorId()];check(!$d->ingest($privatePost,'good.example'),'private post excluded');
 $unlisted=note('unlisted');$unlisted['to']=[];$unlisted['cc']=[Federation::PUBLIC];check(!$d->ingest($unlisted,'good.example'),'unlisted post excluded from public discovery');
 $spoof=note('spoof');$spoof['attributedTo']='https://other.example/users/bob';check(!$d->ingest($spoof,'good.example'),'post and author origins must match');
 check(!$d->ingest(note('old','good.example','bob',8*86400),'good.example'),'old discoveries excluded by retention window');
 check(!$d->ingest(note('own','photos.example'),'photos.example'),'own journal is separate from remote discovery');
 $s->put('blocks','blocked.example',['domain'=>'blocked.example']);check(!$d->seed('blocked.example')&&!$d->ingest(note('blocked','blocked.example'),'good.example'),'local server blocks prevent crawling and ingest');
 $answers['https://good.example/api/v1/instance/peers']=['new.example','photos.example','127.0.0.1','bad host','good.example'];
 $run('peers');$run('expand');check($s->get('crawl_servers','new.example')!==null&&$s->get('crawl_servers','photos.example')===null,'peer expansion discovers remote neighbors and excludes self');
 $s->put('servers','good.example',['domain'=>'good.example','token'=>'fixture-read-token']);
 $statuses=[];foreach(['public','private','unlisted'] as $visibility)$statuses[]=['uri'=>'https://good.example/p/api-'.$visibility,'visibility'=>$visibility,'created_at'=>gmdate('c'),'content'=>'<p>API photo</p>','favourites_count'=>8,'reblogs_count'=>2,'account'=>['url'=>'https://good.example/@bob','username'=>'bob','display_name'=>'Bob'],'media_attachments'=>[['type'=>'image','url'=>'https://good.example/api-photo.jpg']]];
 $answers['https://good.example/api/v1/timelines/public?local=true&limit=40']=$statuses;
 $host=$run('timeline');check($host['health']==='online'&&$s->get('discovery_posts','https://good.example/p/api-public')!==null,'public timeline fills feed and updates health');
 check($s->get('discovery_posts','https://good.example/p/api-private')===null&&$s->get('discovery_posts','https://good.example/p/api-unlisted')===null,'timeline import filters non-public visibility');
 check(end($calls)['headers']['Authorization']==='Bearer fixture-read-token','read token is used for its saved API server');
 check(end($calls)['headers']['Accept']==='application/json','crawler uses REST JSON content negotiation for timeline APIs');
 $answers['https://good.example/api/v1/timelines/public?local=true&limit=40']=Pup\Http::responseError(422,'https://good.example/api/v1/timelines/public','{"error":"This method requires an authenticated user"}');
 $host=$run('timeline');
 check($host['health']==='access-required','authentication-required 422 is an access restriction, not an unreachable server');
 $next=(int)$s->query('SELECT due FROM discovery_queue WHERE kind=? AND ref=?',['timeline','good.example'])->fetchColumn();
 check($next>=time()+21590,'restricted full timelines use a six-hour retry instead of repeated failing polls');
 $d->refreshAccess('good.example');
 check((int)$s->query('SELECT due FROM discovery_queue WHERE kind=? AND ref=?',['timeline','good.example'])->fetchColumn()<=time(),'saving access credentials makes a restricted timeline eligible for its next worker run');
 $answers['https://good.example/api/v1/timelines/public?local=true&limit=40']=$statuses;
 $roster=[];for($i=0;$i<12;$i++)$roster[]=['url'=>'https://good.example/@person'.$i,'username'=>'person'.$i,'followers_count'=>100-$i,'last_status_at'=>gmdate('Y-m-d')];
 $roster[]=['url'=>'https://elsewhere.example/@foreign','followers_count'=>100000];$roster[]=['url'=>'https://good.example/@locked','followers_count'=>100000,'locked'=>true];
 $answers['https://good.example/api/v1/directory?local=true&order=active&limit=80']=$roster;
 $host=$run('roster');check(count($host['roster'])===10&&$host['roster'][0]['url']==='https://good.example/@person0','ten strongest eligible local profiles are selected from the sample');
 check($s->count('follows')===0,'discovery watches profiles without creating public follow actions');
 $answers['https://good.example/@person0']=['id'=>'https://good.example/users/person0','type'=>'Person','outbox'=>'https://good.example/outbox/person0'];
 $run('actor','good.example','https://good.example/@person0');
 $answers['https://good.example/users/person0']=$answers['https://good.example/@person0'];
 $run('actor','good.example','https://good.example/users/person0');
 check($d->status()['watching']===1,'human profile aliases resolve to one watched canonical actor');
 check(!isset(end($calls)['headers']['Authorization']),'API token is not attached to actor/outbox reads');
 $answers['https://good.example/outbox/person0']=['type'=>'OrderedCollection','first'=>'https://good.example/outbox/person0?page=1'];
 $run('outbox','good.example','https://good.example/users/person0');
 $answers['https://good.example/outbox/person0?page=1']=['orderedItems'=>[['type'=>'Create','object'=>note('outbox','good.example','person0')],['type'=>'Create','object'=>$unlisted]]];
 $run('outbox_page','good.example','https://good.example/outbox/person0?page=1');
 check($s->get('discovery_posts','https://good.example/p/outbox')!==null,'generic ActivityPub outbox page contributes public posts');
 $answers['https://good.example/.well-known/nodeinfo']=['links'=>[['rel'=>'http://nodeinfo.diaspora.software/ns/schema/2.0','href'=>'https://good.example/nodeinfo/2.0']]];
 $run('nodeinfo');$answers['https://good.example/nodeinfo/2.0']=['software'=>['name'=>'pixelfed']];$host=$run('nodeinfo_doc','good.example','https://good.example/nodeinfo/2.0');
 check($host['software']==='pixelfed','NodeInfo records server software independently of timeline support');
 $listed=[['domain'=>'p*****.example','digest'=>hash('sha256','photos.example'),'severity'=>'suspend','comment'=>'Published entry']];
 check(Discovery::restriction($listed,'photos.example')['state']==='blocked','published digest match confirms a restriction');
 check(Discovery::restriction([['domain'=>'*.example','severity'=>'suspend']],'photos.example')['state']==='not-listed','censored names are not treated as wildcard proof');
 $answers['https://good.example/api/v1/instance/domain_blocks']=$listed;$host=$run('audit');$confirmed=$host['restriction']['confirmed'];
 check($host['restriction']['state']==='blocked'&&$d->status()['restriction_count']===1,'confirmed published evidence appears in status');
 $answers['https://good.example/api/v1/instance/domain_blocks']=new RemoteError(403,'List is private');$host=$run('audit');
 check($host['restriction']['state']==='unverified'&&$host['restriction']['confirmed']===$confirmed,'private recheck retains last confirmed evidence with stale status');
 check(Discovery::unavailableRestriction(null,'HTTP 403')['state']==='unknown','HTTP 403 alone does not prove a block');
 $answers['https://good.example/api/v1/timelines/public?local=true&limit=40']=new RemoteError(429,'Slow down',300);$host=$run('timeline');
 check($host['health']==='rate-limited'&&$host['cooldown']>=time()+295,'Retry-After creates a persistent per-host cooldown');
 $s->query('UPDATE discovery_queue SET due=? WHERE kind=? AND ref=?',[time()-1,'timeline','good.example']);$count=count($calls);$d->work(1,1);
 check(count($calls)===$count,'other tasks honor the persisted server cooldown');
 $actor=['id'=>$p['attributedTo'],'type'=>'Person','inbox'=>'https://good.example/inbox','_fetched'=>time()];$s->put('actors',$actor['id'],$actor);
 $app->fed->receive(['actor'=>$actor['id'],'type'=>'Delete','object'=>$p['id']]);
 check($s->get('discovery_posts',$p['id'])===null&&!$d->ingest($p,'good.example'),'verified Delete removes discovery copy and prevents stale resurrection');
 $s->query('DELETE FROM records WHERE bucket=?',['discovery_posts']);
 for($i=0;$i<72;$i++)$d->ingest(note('mix'.$i,'world'.($i%4).'.example','person'.($i%9),$i*30,$i%10!==0),'world'.($i%4).'.example',['engagement'=>100]);
 $first=$app->feed->page();check(count($first['posts'])===24&&$first['cursor']!=='','ranked photo feed paginates');
 $domains=array_unique(array_map(static fn($x)=>parse_url($x['actor'],PHP_URL_HOST),$first['posts']));check(count($domains)>=3,'mix spreads exposure across servers');
 $rotated=$app->feed->page();check(count(array_intersect(array_column($first['posts'],'id'),array_column($rotated['posts'],'id')))<12,'fresh mix rotates away from recently served posts');
 $d->ingest(note('brand-new','newest.example'),'newest.example');$second=$app->feed->page($first['cursor']);
 check(!array_intersect(array_column($first['posts'],'id'),array_column($second['posts'],'id')),'stable snapshot avoids duplicates while background posts arrive');
 check(!in_array('https://newest.example/p/brand-new',array_column($second['posts'],'id'),true),'new arrivals wait for a fresh feed snapshot');
 $newest=$app->feed->page('','newest',false);$times=array_map(static fn($x)=>strtotime($x['published']),$newest['posts']);$sorted=$times;rsort($sorted);check($times===$sorted,'Newest mode preserves publication order');
 rejects(fn()=>$app->feed->page('broken'),'malformed feed cursor rejected');
 rejects(fn()=>$app->feed->page($first['cursor'],'newest'),'cursor cannot be reused with changed filters');
 $s->put('blocks','world0.example',['domain'=>'world0.example']);$next=$app->feed->page($first['cursor']);
 check(!array_filter($next['posts'],static fn($p)=>str_contains($p['actor'],'world0.example')),'blocking takes effect even in an existing snapshot');
 $s->query('DELETE FROM records WHERE bucket=?',['discovery_posts']);$s->query('DELETE FROM records WHERE bucket=?',['saved']);
 for($i=0;$i<8;$i++){$p=note('plain'.$i,'affinity.example','plain',3600);$d->ingest($p,'affinity.example');$p=note('favorite'.$i,'affinity.example','favorite',3600);$d->ingest($p,'affinity.example');$s->put('saved',$p['id'],$p);}
 $fav=$app->feed->page();check($fav['posts'][0]['actor']==='https://affinity.example/users/favorite','saved authors influence the mix without an external AI service');
 $s->query('DELETE FROM discovery_queue');
 $s->put('discovery_posts','stale-fixture',['id'=>'stale-fixture'],time()-8*86400);
 $s->tx(function()use($s){for($i=0;$i<20001;$i++)$s->put('discovery_posts','capacity-'.$i,['id'=>'capacity-'.$i]);});
 $d->work(1,1);
 check($s->get('discovery_posts','stale-fixture')===null,'expired discovery history is removed');
 check($s->count('discovery_posts')===20000,'rolling discovery cache stays bounded at twenty thousand posts');
 $before=count($calls);$d->control('pause');$d->work(2,5);check(count($calls)===$before,'pause stops all crawler network work');
 check(!empty($d->status()['history'])&&!empty($d->status()['events']),'front-end telemetry includes recorded history and real event log');
 echo 'PASS: '.$checks." discovery and ranked-feed assertions\n";
}finally{
 $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
 foreach($it as $file){if($file->isDir())rmdir($file->getPathname());else unlink($file->getPathname());}rmdir($dir);
}
