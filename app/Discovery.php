<?php
declare(strict_types=1);
namespace Pup;

/** Persistent, resumable discovery. Web requests only read state or enqueue work. */
final class Discovery {
    private ?\Closure $transport;
    private float $deadline=0;
    private const SEEDS=['opalstack.social','pixelfed.social','mastodon.social'];
    public function __construct(private App $app,?callable $transport=null){$this->transport=$transport?\Closure::fromCallable($transport):null;}
    private function ownHost(): string{return strtolower((string)parse_url($this->app->url(),PHP_URL_HOST));}
    public function settings(): array{return $this->app->store->get('meta','discovery_settings')??['enabled'=>false];}
    public function boot(): void{if(!$this->app->store->get('meta','discovery_settings'))$this->control('start');}
    public function control(string $action,string $value=''): array {
        $s=$this->app->store;
        if($action==='start'){
            $s->put('meta','discovery_settings',['enabled'=>true]);
            foreach(self::SEEDS as $domain)$this->seed($domain,'starter');
            foreach($s->each('servers') as $server)$this->seed($server['domain'],'saved place');
        } elseif($action==='pause')$s->put('meta','discovery_settings',['enabled'=>false]);
        elseif($action==='seed')$this->seed($value,'you');
        elseif($action==='watch')$this->watch($value,'you');
        elseif($action==='recheck'){
            $domain=Browser::domain($value);$this->seed($domain,'you');
            $this->queue('audit',$domain,$domain,0,true);
        } else throw new \InvalidArgumentException('Use start, pause, seed, watch, or recheck.');
        return $this->status();
    }
    public function seed(string $value,string $source='peer'): bool {
        try{$domain=Browser::domain($value);}catch(\Throwable){return false;}
        if($domain===$this->ownHost()||filter_var($domain,FILTER_VALIDATE_IP)||$this->app->store->get('blocks',$domain))return false;
        $s=$this->app->store;
        $new=$s->add('crawl_servers',$domain,['domain'=>$domain,'source'=>$source,'found'=>time(),'health'=>'queued','posts'=>0,'last_poll'=>0,'roster'=>[]]);
        if($new){
            $s->put('health_queued',$domain,['domain'=>$domain]);
            $this->event('Found '.$domain,'found',$domain);
        }
        elseif(($host=$s->get('crawl_servers',$domain))&&($host['health']??'')==='blocked-locally'){$host['health']='queued';$this->saveHost($domain,$host);}
        foreach(['timeline','peers','roster','audit','nodeinfo'] as $kind)$this->queue($kind,$domain,$domain);
        return $new;
    }
    public function watch(string $url,string $source='directory'): bool {
        if(!Http::validUrl($url))throw new \InvalidArgumentException('Use a full HTTPS profile URL.');
        $domain=strtolower((string)parse_url($url,PHP_URL_HOST));
        if($domain===$this->ownHost()||$this->app->store->get('blocks',$domain))return false;
        $this->seed($domain,$source);
        if(!$this->app->store->add('crawl_actors',$url,['id'=>$url,'source'=>$source,'state'=>'queued']))return false;
        $this->queue('actor',$url,$domain);return true;
    }
    public function localBlock(string $domain): void {
        $this->app->store->query('DELETE FROM discovery_queue WHERE domain=?',[$domain]);
        if($host=$this->app->store->get('crawl_servers',$domain)){$host['health']='blocked-locally';$this->saveHost($domain,$host);}
    }
    public function refreshAccess(string $domain): void {
        $this->seed($domain,'saved place');
        foreach(['timeline','roster'] as $kind)$this->queue($kind,$domain,$domain,0,true);
    }
    private function queue(string $kind,string $ref,string $domain,int $due=0,bool $replace=false): void {
        $s=$this->app->store;$id=hash('sha256',$kind."\0".$ref);$due=$due?:time();
        $sql='INSERT INTO discovery_queue(id,kind,ref,domain,due,updated) VALUES(?,?,?,?,?,?)';
        $sql.=$s->driver==='mysql'?($replace?' ON DUPLICATE KEY UPDATE due=VALUES(due),updated=VALUES(updated)':' ON DUPLICATE KEY UPDATE id=id'):($replace?' ON CONFLICT(id) DO UPDATE SET due=excluded.due,updated=excluded.updated':' ON CONFLICT(id) DO NOTHING');
        $s->query($sql,[$id,$kind,$ref,$domain,$due,time()]);
    }
    private function event(string $message,string $kind,string $domain): void {
        $s=$this->app->store;$log=$s->get('meta','discovery_events')['items']??[];
        array_unshift($log,['at'=>time(),'message'=>mb_substr($message,0,350),'kind'=>$kind,'domain'=>$domain]);
        $s->put('meta','discovery_events',['items'=>array_slice($log,0,40)]);
    }
    private function saveHost(string $domain,array $host): void {
        $s=$this->app->store;$old=$s->get('crawl_servers',$domain);$state=$host['health']??'queued';
        if(($old['health']??'queued')!==$state)$s->remove('health_'.($old['health']??'queued'),$domain);
        $s->put('health_'.$state,$domain,['domain'=>$domain]);$s->put('crawl_servers',$domain,$host);
        if(in_array($host['restriction']['state']??'',['blocked','limited','unverified'],true))$s->put('crawl_restrictions',$domain,['domain'=>$domain]+$host['restriction']);
        else $s->remove('crawl_restrictions',$domain);
    }
    private function json(string $url,bool $token=false,bool $signed=false): array {
        $headers=['Accept'=>$signed?'application/activity+json, application/ld+json; profile="https://www.w3.org/ns/activitystreams"':'application/json'];
        if($token){$host=strtolower((string)parse_url($url,PHP_URL_HOST));$saved=$this->app->store->get('servers',$host);if(!empty($saved['token']))$headers['Authorization']='Bearer '.$saved['token'];}
        if($this->transport)return ($this->transport)($url,$headers);
        $seconds=max(1,min(8,(int)ceil($this->deadline-microtime(true))));
        $r=$this->app->http->request($url,'GET',null,$headers,$signed,2097152,2,$seconds);
        $data=json_decode($r['body'],true,64,JSON_THROW_ON_ERROR);
        if(!is_array($data))throw new RemoteError(502,'The remote response was not JSON data.');
        return $data;
    }
    public static function restriction(array $entries,string $domain,?array $previous=null,int $now=0): array {
        $now=$now?:time();$domain=strtolower($domain);$match=null;
        foreach($entries as $entry){
            if(!is_array($entry)||!in_array($entry['severity']??'', ['suspend','silence'],true))continue;
            $plain=is_string($entry['domain']??null)?strtolower($entry['domain']):'';$digest=is_string($entry['digest']??null)?strtolower($entry['digest']):'';
            if($plain!==$domain&&!($digest!==''&&hash_equals(hash('sha256',$domain),$digest)))continue;
            if(!$match||$entry['severity']==='suspend')$match=$entry;
        }
        return ['state'=>$match?($match['severity']==='suspend'?'blocked':'limited'):'not-listed','severity'=>$match['severity']??'',
            'comment'=>is_string($match['comment']??null)?mb_substr(Browser::plain($match['comment']),0,1000):'','checked'=>$now,'confirmed'=>$match?$now:0,
            'matched'=>$match?(strtolower((string)($match['domain']??''))===$domain?'domain':'digest'):'','error'=>''];
    }
    public static function unavailableRestriction(?array $previous,string $error,int $now=0): array {
        $old=$previous??[];$confirmed=($old['confirmed']??0)>0;
        return array_replace($old,['state'=>$confirmed?'unverified':'unknown','checked'=>$now?:time(),'error'=>$error]);
    }
    public function ingest(array $post,string $source,array $stats=[]): bool {
        $s=$this->app->store;$id=$post['id']??'';$actor=$post['attributedTo']??'';
        if(!is_string($id)||!is_string($actor)||!Http::validUrl($id)||!Http::validUrl($actor)||!in_array($post['type']??'',['Note','Article','Image'],true))return false;
        if(!in_array(Federation::PUBLIC,(array)($post['to']??[]),true))return false;
        $host=strtolower((string)parse_url($actor,PHP_URL_HOST));
        if($host===$this->ownHost()||$host!==strtolower((string)parse_url($id,PHP_URL_HOST))||$s->get('blocks',$host)||$s->get('blocks',$source)||$s->get('discovery_deleted',$id))return false;
        if(!is_string($post['published']??null)||!is_string($post['content']??''))return false;
        $published=strtotime($post['published']);
        if(!$published||$published<time()-7*86400||$published>time()+300)return false;
        $plain=Browser::plain($post['content']??'');
        if(mb_strlen($plain)>15000)return false;
        // Keep a compact display cache, not arbitrary remote JSON or image bytes.
        $attachments=[];
        foreach(array_slice((array)($post['attachment']??[]),0,10) as $a){
            if(!is_array($a)||!str_starts_with((string)($a['mediaType']??''),'image/'))continue;
            $url=$a['url']??'';if(is_array($url))$url=$url['href']??($url[0]['href']??'');
            if(!is_string($url)||!Http::validUrl($url))continue;
            $attachments[]=['type'=>'Document','mediaType'=>mb_substr((string)$a['mediaType'],0,80),'url'=>$url,
                'name'=>is_string($a['name']??null)?mb_substr($a['name'],0,1000):''];
        }
        $post=['id'=>$id,'type'=>$post['type'],'attributedTo'=>$actor,'to'=>[Federation::PUBLIC],'cc'=>[],
            'published'=>$post['published'],'url'=>is_string($post['url']??null)&&Http::validUrl($post['url'])?$post['url']:$id,
            'content'=>'<p>'.nl2br(htmlspecialchars($plain,ENT_QUOTES,'UTF-8')).'</p>',
            'source'=>['content'=>$plain,'mediaType'=>'text/plain'],'attachment'=>$attachments,
            'sensitive'=>!empty($post['sensitive']),'summary'=>is_string($post['summary']??null)?mb_substr($post['summary'],0,300):'',
            'mediaType'=>is_string($post['mediaType']??null)?mb_substr($post['mediaType'],0,80):'image/jpeg'];
        if(strlen(App::json($post))>32768)return false;
        $old=$s->get('discovery_posts',$id);
        $post['_discovery']=['source'=>$source,'seen'=>$old['_discovery']['seen']??time(),'fetched'=>time(),
            'engagement'=>min(1000000,max(0,(int)($stats['engagement']??0)))];
        $s->put('discovery_posts',$id,$post,$old['_discovery']['seen']??time());return !$old;
    }
    private function task(array $job,array &$host): int {
        $s=$this->app->store;$domain=$job['domain'];$base='https://'.$domain;$kind=$job['kind'];$now=time();
        if($kind==='timeline'){
            $data=$this->json($base.'/api/v1/timelines/public?local=true&limit=40',true);
            if(!array_is_list($data))throw new RemoteError(404,'No compatible public timeline. Public actor outboxes can still be watched.');
            $added=0;
            foreach(array_slice($data,0,40) as $status){
                if(!is_array($status)||($status['visibility']??'')!=='public')continue;
                $status=$status['reblog']??$status;
                if(($status['visibility']??'')!=='public')continue;
                try{$doc=$this->app->browser->mastodonDocument($status);if(!$doc)continue;
                    $stats=['engagement'=>(int)($status['favourites_count']??0)+2*(int)($status['reblogs_count']??0)+(int)($status['replies_count']??0)];
                    $added+=(int)$this->ingest($doc['post'],$domain,$stats);
                    $profile=$doc['profile'];$s->put('profiles',$profile['id'],['id'=>$profile['id'],
                        'name'=>is_string($profile['name'])?mb_substr($profile['name'],0,80):'',
                        'preferredUsername'=>is_string($profile['preferredUsername'])?mb_substr($profile['preferredUsername'],0,80):'',
                        'icon'=>['url'=>is_string($profile['icon']['url'])&&Http::validUrl($profile['icon']['url'])?$profile['icon']['url']:'']]);
                }catch(\InvalidArgumentException|\TypeError){continue;}
            }
            $host['health']='online';$host['last_poll']=$now;$host['posts']=($host['posts']??0)+$added;$host['last_error']='';$host['failures']=0;
            if($added)$this->event($domain.' brought '.$added.' new posts','posts',$domain);
            return 300;
        }
        if($kind==='peers'){
            $peers=$this->json($base.'/api/v1/instance/peers',true);
            if(!array_is_list($peers))throw new RemoteError(502,'No public peer list.');
            $s->put('crawl_peer_pages',$domain,['items'=>array_values(array_filter($peers,'is_string')),'offset'=>0]);
            $this->queue('expand',$domain,$domain);return 86400;
        }
        if($kind==='expand'){
            $page=$s->get('crawl_peer_pages',$domain)??['items'=>[],'offset'=>0];$offset=$page['offset'];
            $s->tx(function()use($page,$offset,$domain){foreach(array_slice($page['items'],$offset,100) as $peer)$this->seed($peer,$domain);});
            $page['offset']+=100;
            if($page['offset']<count($page['items'])){$s->put('crawl_peer_pages',$domain,$page);return 1;}
            $s->remove('crawl_peer_pages',$domain);return 86400;
        }
        if($kind==='roster'){
            $data=$this->json($base.'/api/v1/directory?local=true&order=active&limit=80',true);
            if(!array_is_list($data))throw new RemoteError(404,'No public profile directory.');
            $accounts=array_filter($data,static fn($a)=>is_array($a)&&empty($a['suspended'])&&empty($a['moved'])&&empty($a['locked'])&&($a['discoverable']??true)!==false&&Http::validUrl((string)($a['url']??''))
                &&strtolower((string)parse_url($a['url'],PHP_URL_HOST))===$domain&&(strtotime((string)($a['last_status_at']??'now'))?:time())>time()-90*86400);
            usort($accounts,static fn($a,$b)=>(int)($b['followers_count']??0)<=>(int)($a['followers_count']??0));
            $host['roster']=[];
            foreach(array_slice($accounts,0,10) as $a){
                if(strtolower((string)parse_url($a['url'],PHP_URL_HOST))!==$domain)continue;
                $host['roster'][]=['url'=>$a['url'],'name'=>mb_substr((string)(($a['display_name']??'')?:($a['username']??'')),0,80)];
                $this->watch($a['url'],$domain);
            }
            return 86400;
        }
        if($kind==='audit'){
            try{
                $list=$this->json($base.'/api/v1/instance/domain_blocks',true);
                if(!array_is_list($list))throw new RemoteError(502,'The public restriction list was unreadable.');
                $previous=$host['restriction']??null;
                $host['restriction']=self::restriction($list,$this->ownHost(),$previous);
                if(($previous['state']??'')!==$host['restriction']['state'])$this->event($domain.': published restriction status '.$host['restriction']['state'],'restriction',$domain);
            }catch(\Throwable $e){$host['restriction']=self::unavailableRestriction($host['restriction']??null,$e->getMessage());throw $e;}
            $host['restriction']['url']=$base.'/api/v1/instance/domain_blocks';
            return in_array($host['restriction']['state'],['blocked','limited'],true)?900:3600;
        }
        if($kind==='nodeinfo'){
            $data=$this->json($base.'/.well-known/nodeinfo');
            $links=$data['links']??[];if(!is_array($links))throw new RemoteError(502,'NodeInfo links were unreadable.');
            foreach($links as $link){if(!is_array($link))continue;$url=$link['href']??'';
                if(is_string($url)&&Http::validUrl($url)&&strtolower((string)parse_url($url,PHP_URL_HOST))===$domain&&str_contains((string)($link['rel']??''),'nodeinfo')){$this->queue('nodeinfo_doc',$url,$domain);break;}}
            return 7*86400;
        }
        if($kind==='nodeinfo_doc'){$doc=$this->json($job['ref']);$host['software']=mb_substr((string)($doc['software']['name']??''),0,50);return 7*86400;}
        if($kind==='actor'){
            $doc=$this->json($job['ref'],false,true);$id=$doc['id']??'';$outbox=$doc['outbox']??'';
            if(!is_string($id)||!is_string($outbox)||!Http::validUrl($id)||!Http::validUrl($outbox)||!in_array($doc['type']??'',['Person','Service','Application','Group','Organization'],true))throw new RemoteError(404,'No public ActivityPub profile or outbox.');
            if($id!==$job['ref']){
                $source=$s->get('crawl_actors',$job['ref'])['source']??$domain;
                $s->put('crawl_actors',$job['ref'],['id'=>$job['ref'],'source'=>$source,'state'=>'alias','resolved'=>$id]);
                $this->watch($id,$source);return 86400;
            }
            if(strtolower((string)parse_url($outbox,PHP_URL_HOST))!==$domain)throw new RemoteError(404,'Outbox belongs to another host.');
            $s->put('profiles',$id,['id'=>$id,'name'=>is_string($doc['name']??null)?mb_substr($doc['name'],0,80):'',
                'preferredUsername'=>is_string($doc['preferredUsername']??null)?mb_substr($doc['preferredUsername'],0,80):'',
                'summary'=>is_string($doc['summary']??null)?mb_substr(Browser::plain($doc['summary']),0,500):'',
                'icon'=>['url'=>is_string($doc['icon']['url']??null)&&Http::validUrl($doc['icon']['url'])?$doc['icon']['url']:'']]);
            $s->put('crawl_actors',$id,['id'=>$id,'outbox'=>$outbox,'source'=>$s->get('crawl_actors',$id)['source']??$domain,'state'=>'watching']);
            $s->put('crawl_watching',$id,['id'=>$id]);
            $this->queue('outbox',$id,$domain);return 86400;
        }
        if($kind==='outbox'||$kind==='outbox_page'){
            if($kind==='outbox'){$actor=$s->get('crawl_actors',$job['ref']);$url=$actor['outbox']??'';}else $url=$job['ref'];
            if(!Http::validUrl($url))throw new RemoteError(404,'The profile has no readable outbox.');
            $doc=$this->json($url,false,true);$items=$doc['orderedItems']??$doc['items']??null;
            if($items===null){$first=$doc['first']??'';
                if(is_string($first)&&Http::validUrl($first)&&strtolower((string)parse_url($first,PHP_URL_HOST))===$domain)$this->queue('outbox_page',$first,$domain,0,true);
                elseif(is_array($first))$items=$first['orderedItems']??$first['items']??[];
            }
            $added=0;
            foreach(array_slice((array)$items,0,40) as $item){
                if(!is_array($item))continue;$post=($item['type']??'')==='Create'?($item['object']??null):$item;
                if(is_array($post))$added+=(int)$this->ingest($post,$domain);
            }
            $host['outbox_ok']=$now;$host['last_poll']=$now;
            if(($host['health']??'')!=='online')$host['health']='outbox-online';
            if($added){$host['posts']=($host['posts']??0)+$added;$this->event($domain.' outbox brought '.$added.' new posts','posts',$domain);}
            return 900;
        }
        return 86400;
    }
    public function work(int $seconds=25,int $limit=20): array {
        if(!$this->settings()['enabled']||$seconds<1)return ['enabled'=>(bool)$this->settings()['enabled'],'processed'=>0];
        $lock=fopen($this->app->config['data_dir'].'/discovery.lock','c');
        if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))return ['enabled'=>true,'locked'=>true,'processed'=>0];
        $s=$this->app->store;$this->deadline=microtime(true)+min(50,$seconds);$processed=0;
        try{
            while($processed<$limit&&microtime(true)<$this->deadline-0.2&&$this->settings()['enabled']){
                $job=$s->query('SELECT * FROM discovery_queue WHERE due<=? ORDER BY due,updated,id LIMIT 1',[time()])->fetch();if(!$job)break;
                $domain=$job['domain'];$host=$s->get('crawl_servers',$domain)??['domain'=>$domain];
                if($s->get('blocks',$domain)||$domain===$this->ownHost()){$s->query('DELETE FROM discovery_queue WHERE domain=?',[$domain]);$host['health']='blocked-locally';$this->saveHost($domain,$host);continue;}
                $now=time();$cooldown=max((int)($host['cooldown']??0),(int)($host['last_request']??0)+2);
                if($job['kind']!=='expand'&&$cooldown>$now){$s->query('UPDATE discovery_queue SET due=?,updated=? WHERE id=?',[$cooldown,$now,$job['id']]);continue;}
                $pulse=['last_run'=>$now,'active'=>$domain,'task'=>$job['kind'],'processed'=>$processed];$s->put('meta','discovery_worker',$pulse);
                $start=microtime(true);$delay=300;
                try{
                    $delay=$this->task($job,$host);$host['task_errors'][$job['kind']]='';$host['task_failures'][$job['kind']]=0;
                }catch(\Throwable $e){
                    $status=$e instanceof RemoteError?$e->status:0;
                    $host['task_errors'][$job['kind']]=mb_substr($e->getMessage(),0,250);
                    $failures=min(10,($host['task_failures'][$job['kind']]??0)+1);$host['task_failures'][$job['kind']]=$failures;
                    $delay=min(86400,60*(2**min(10,$failures)));
                    $auth=$e instanceof RemoteError&&$e->authenticationRequired;
                    if($auth||in_array($status,[401,403,404,410],true))$delay=6*3600;
                    if($job['kind']==='audit')$delay=min($delay,($host['restriction']['confirmed']??0)>0?900:3600);
                    if($status===429){$delay=max(60,$e->retryAfter);$host['cooldown']=$now+$delay;}
                    if($job['kind']==='audit')$host['restriction']['url']='https://'.$domain.'/api/v1/instance/domain_blocks';
                    if($job['kind']==='timeline'){
                        $host['health']=$auth?'access-required':match($status){401,403=>'access-required',404,410=>'outbox-only',429=>'rate-limited',default=>'unreachable'};
                        if(($auth||in_array($status,[401,403,404,410],true))&&($host['outbox_ok']??0)>time()-3600)$host['health']='outbox-online';
                        $host['last_error']=mb_substr($e->getMessage(),0,250);
                    }
                    $this->event($domain.' / '.$job['kind'].': '.$e->getMessage(),'issue',$domain);
                }
                $host['last_request']=$now;$host['latency_ms']=(int)((microtime(true)-$start)*1000);
                $host['last_visit']=time();$this->saveHost($domain,$host);
                $s->query('UPDATE discovery_queue SET due=?,updated=? WHERE id=?',[time()+$delay,time(),$job['id']]);$processed++;
            }
            $s->put('meta','discovery_worker',['last_run'=>time(),'active'=>'','task'=>'','processed'=>$processed]);
            $this->prune();$this->history($processed);
        } finally{flock($lock,LOCK_UN);fclose($lock);}
        return ['enabled'=>true,'processed'=>$processed];
    }
    private function prune(): void {
        $s=$this->app->store;
        $s->query('DELETE FROM records WHERE bucket=? AND created<?',['discovery_posts',time()-7*86400]);
        $s->query('DELETE FROM records WHERE bucket=? AND updated<?',['discovery_deleted',time()-30*86400]);
        $count=$s->count('discovery_posts');
        if($count>20000){$n=$count-20000;$rows=$s->query("SELECT ref FROM records WHERE bucket=? ORDER BY created,id LIMIT $n",['discovery_posts'])->fetchAll();$s->tx(function()use($s,$rows){foreach($rows as $row)$s->remove('discovery_posts',$row['ref']);});}
    }
    private function history(int $processed): void {
        $s=$this->app->store;$r=$s->get('meta','discovery_history')??['items'=>[]];$stamp=(int)(floor(time()/60)*60);
        $point=['at'=>$stamp,'servers'=>$s->count('crawl_servers'),'posts'=>$s->count('discovery_posts'),'processed'=>$processed];
        if(($r['items'][array_key_last($r['items'])]['at']??null)===$stamp)$r['items'][array_key_last($r['items'])]=$point;else $r['items'][]=$point;
        $r['items']=array_slice($r['items'],-1440);$s->put('meta','discovery_history',$r);
    }
    public function status(): array {
        $s=$this->app->store;$queue=$s->query('SELECT COUNT(*) AS total,SUM(CASE WHEN due<=? THEN 1 ELSE 0 END) AS due,MIN(due) AS next FROM discovery_queue',[time()])->fetch();
        $hosts=$s->page('crawl_servers',0,20);$restrictions=$s->page('crawl_restrictions',0,100);$states=[];
        foreach(['queued','online','outbox-online','access-required','outbox-only','rate-limited','unreachable','blocked-locally'] as $state)$states[$state]=$s->count('health_'.$state);
        $history=$s->get('meta','discovery_history')['items']??[];$stride=max(1,(int)ceil(count($history)/240));
        $history=array_values(array_filter($history,static fn($point,$i)=>$i%$stride===0||$i===count($history)-1,ARRAY_FILTER_USE_BOTH));
        return ['enabled'=>(bool)$this->settings()['enabled'],'worker'=>$s->get('meta','discovery_worker'),'servers'=>$s->count('crawl_servers'),'posts'=>$s->count('discovery_posts'),
            'watching'=>$s->count('crawl_watching'),'queue'=>(int)$queue['total'],'due'=>(int)$queue['due'],'next'=>(int)($queue['next']??0),'states'=>$states,'restriction_count'=>$s->count('crawl_restrictions'),
            'hosts'=>array_slice($hosts,0,20),'restrictions'=>$restrictions,'events'=>$s->get('meta','discovery_events')['items']??[],
            'history'=>$history];
    }
}
