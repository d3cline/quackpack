<?php
declare(strict_types=1);
namespace Pup;

final class Federation {
    public const PUBLIC='https://www.w3.org/ns/activitystreams#Public';
    public const MAX_INBOX_BYTES=60000;
    public function __construct(private App $app) {}
    private function cacheActor(string $id,array $actor): void {
        $s=$this->app->store;
        $actor=array_intersect_key($actor,array_flip(['id','type','inbox','outbox','url','preferredUsername','name','summary','icon','publicKey','_fetched']));
        if(strlen(App::json($actor))>65536)throw new \InvalidArgumentException('The actor document is too large.');
        if(!$s->get('actors',$id)&&$s->count('actors')>=5000){
            $old=$s->query('SELECT ref FROM records WHERE bucket=? ORDER BY updated,id LIMIT 1',['actors'])->fetchColumn();
            if($old!==false)$s->remove('actors',$old);
        }
        $s->put('actors',$id,$actor);
    }
    private function keyActor(string $id,bool $refresh=false): array {
        $s=$this->app->store;$cached=$s->get('actors',$id);
        if(!$refresh&&$cached&&($cached['_fetched']??0)>time()-21600)return $cached;
        $ref='inbox-keys:'.(int)floor(time()/60);$host=strtolower((string)parse_url($id,PHP_URL_HOST));
        $s->tx(function()use($s,$ref,$host){
            $budget=$s->get('attempts',$ref)??['count'=>0,'hosts'=>[]];
            if($budget['count']>=40||($budget['hosts'][$host]??0)>=8)throw new RemoteError(429,'Actor verification is busy. Please retry later.',60);
            $budget['count']++;$budget['hosts'][$host]=($budget['hosts'][$host]??0)+1;$s->put('attempts',$ref,$budget);
        });
        // Canonical actor IDs must return JSON directly; no HTML/alternate-link crawl in the public inbox.
        $r=$this->app->http->request($id,'GET',null,[],true,65536,1,4);
        $actor=json_decode($r['body'],true,32,JSON_THROW_ON_ERROR);
        if(!is_array($actor)||($actor['id']??'')!==$id||!in_array($actor['type']??'',Remote::ACTORS,true)||!Http::validUrl((string)($actor['inbox']??'')))throw new \InvalidArgumentException('This address is not an ActivityPub profile.');
        $actor['_fetched']=time();return $actor;
    }
    public function actor(string $id,bool $refresh=false): array {
        $cached=$this->app->store->get('actors',$id);
        if (!$refresh && $cached && ($cached['_fetched']??0)>time()-21600) return $cached;
        $a=$this->app->remote->document($id);
        if (($a['id']??'')!==$id || !in_array($a['type']??'', ['Person','Service','Application','Group','Organization'],true) || !Http::validUrl((string)($a['inbox']??''))) throw new \InvalidArgumentException('This address is not an ActivityPub profile.');
        $a['_fetched']=time();$this->cacheActor($id,$a);return $a;
    }
    public function resolve(string $input): array {
        $input=trim($input);
        if (Http::validUrl($input)) {
            $doc=$this->app->remote->document($input);
            if (isset($doc['inbox'],$doc['id']) && is_string($doc['id'])) return $this->actor($doc['id']);
            if (isset($doc['attributedTo']) && is_string($doc['attributedTo'])) return $this->actor($doc['attributedTo']);
            throw new \InvalidArgumentException('Use a profile address or @name@server.');
        }
        $handle=ltrim($input,'@');
        if (!preg_match('/^([a-zA-Z0-9_.-]{1,100})@([a-zA-Z0-9.-]+)$/',$handle,$m)) throw new \InvalidArgumentException('Use @name@server or a full profile URL.');
        $wf=$this->app->http->getJson('https://'.$m[2].'/.well-known/webfinger?resource='.rawurlencode('acct:'.$handle),false,['Accept'=>'application/jrd+json, application/json']);
        foreach (($wf['links']??[]) as $link) {
            if (($link['rel']??'')==='self' && in_array($link['type']??'', ['application/activity+json','application/ld+json; profile="https://www.w3.org/ns/activitystreams"'],true) && is_string($link['href']??null)) return $this->actor($link['href']);
        }
        throw new \InvalidArgumentException('This server did not provide an ActivityPub profile.');
    }
    public function activity(string $type,mixed $object,?string $to=null): array {
        $activity=['@context'=>App::context(),'id'=>$this->app->url('/a/'.bin2hex(random_bytes(16))),
            'type'=>$type,'actor'=>$this->app->actorId(),'published'=>gmdate('Y-m-d\TH:i:s\Z'),'object'=>$object,
            'to'=>[$to??self::PUBLIC],'cc'=>$to?[]:[$this->app->url('/followers')]];
        $this->app->store->put('activities',$activity['id'],$activity);
        return $activity;
    }
    public static function priority(array $payload): int {
        if(in_array($payload['activity']['type']??'',['Follow','Accept','Reject','Undo'],true))return 0;
        return match($payload['kind']??''){'deliver'=>5,'fanout'=>10,default=>20};
    }
    public function enqueue(array $payload,int $due=0): void {
        $now=time();$this->app->store->query('INSERT INTO jobs(id,payload,attempts,due,state,error,created,updated,priority) VALUES(?,?,0,?,\'pending\',\'\',?,?,?)',
            [bin2hex(random_bytes(16)),App::json($payload),$due?:$now,$now,$now,self::priority($payload)]);
    }
    public function deliver(array $activity,array $actor): void {
        // Per-actor inboxes keep delivery independent from untrusted sharedInbox hints.
        $this->enqueue(['kind'=>'deliver','inbox'=>$actor['inbox'],'activity'=>$activity]);
    }
    public function broadcast(array $activity): void { $this->enqueue(['kind'=>'fanout','activity'=>$activity]); }
    public static function document(array $doc): array {
        $doc['@context']=App::context();
        if(is_array($doc['object']??null))$doc['object']=self::document($doc['object']);
        if(isset($doc['orderedItems']))$doc['orderedItems']=array_map(static fn($item)=>is_array($item)?self::document($item):$item,$doc['orderedItems']);
        return $doc;
    }
    public function resend(): int {
        $s=$this->app->store;
        if(!$s->count('followers'))throw new \InvalidArgumentException('No followers yet. Follow your Quackpack address from Mastodon first, then send your recent posts.');
        return $s->tx(function()use($s){
            $count=0;
            foreach(array_reverse($s->page('posts',0,24)) as $post){
                $create=$s->get('creates',$post['id']);if(!$create)continue;
                $create['object']=$post;$this->broadcast($create);$count++;
            }
            $this->broadcast($this->activity('Update',$this->app->actor()));
            return $count;
        });
    }
    public function recover(): void {
        $s=$this->app->store;
        $s->tx(function()use($s){
            if($s->get('meta','federation_recovery_0_5_2'))return;
            foreach($s->each('followers') as $f){
                if(empty($f['follow_id']))continue;
                $follow=['id'=>$f['follow_id'],'type'=>'Follow','actor'=>$f['id'],'object'=>$this->app->actorId()];
                $this->deliver($this->activity('Accept',$follow,$f['id']),$f['actor']);
            }
            foreach($s->each('follows') as $f)if(($f['state']??'')==='pending')$this->deliver($f['activity'],$f['actor']);
            if($s->count('followers'))$this->resend();
            $s->put('meta','federation_recovery_0_5_2',['at'=>time()]);
        });
    }
    public function checkPublic(): array {
        $app=$this->app;$acct='acct:'.$app->config['username'].'@'.parse_url($app->url(),PHP_URL_HOST);
        $checks=[];$deadline=microtime(true)+15;
        $targets=[
            ['WebFinger',$app->url('/.well-known/webfinger').'?resource='.rawurlencode($acct),'application/jrd+json',static fn($d)=>($d['subject']??'')===$acct&&count(array_filter($d['links']??[],static fn($l)=>($l['rel']??'')==='self'&&($l['href']??'')===$app->actorId()))>0],
            ['Profile',$app->actorId(),'application/activity+json',static fn($d)=>($d['id']??'')===$app->actorId()&&($d['inbox']??'')===$app->url('/inbox')&&($d['publicKey']['owner']??'')===$app->actorId()],
            ['Homepage discovery',$app->url('/'),'application/activity+json',static fn($d)=>($d['id']??'')===$app->actorId()],
            ['Outbox',$app->url('/outbox?page=1'),'application/activity+json',static fn($d)=>($d['type']??'')==='OrderedCollectionPage'&&isset($d['orderedItems'])]
        ];
        if($post=$app->store->page('posts',0,1)[0]??null)$targets[]=['Latest post',$post['id'],'application/activity+json',static fn($d)=>($d['id']??'')===$post['id']&&($d['attributedTo']??'')===$app->actorId()&&self::isPublic($d)&&count($d['attachment']??[])===count($post['attachment']??[])];
        foreach($targets as [$name,$url,$accept,$valid]){
            try{
                $seconds=min(4,(int)floor($deadline-microtime(true)));
                if($seconds<1)throw new \RuntimeException('Check timed out. Try again when the server is reachable.');
                $r=$app->http->request($url,'GET',null,['Accept'=>$accept],false,2097152,1,$seconds);
                $doc=json_decode($r['body'],true,64,JSON_THROW_ON_ERROR);
                $mime=strtolower($r['headers']['content-type']??'');
                if(!is_array($doc)||!$valid($doc)||!str_contains($mime,'json'))throw new \RuntimeException('The public URL did not return the expected federation JSON.');
                $checks[]=['name'=>$name,'url'=>$url,'ok'=>true,'detail'=>'Public JSON is reachable and identifies this journal.'];
            }catch(\Throwable $e){$checks[]=['name'=>$name,'url'=>$url,'ok'=>false,'detail'=>mb_substr($e->getMessage(),0,400)];}
        }
        $result=['checked'=>time(),'ok'=>!in_array(false,array_column($checks,'ok'),true),'checks'=>$checks];
        $app->store->put('meta','federation_check',$result);return $result;
    }
    public function follow(string $input): array {
        $a=$this->resolve($input);
        if ($a['id']===$this->app->actorId()) throw new \InvalidArgumentException('That is your own profile.');
        return $this->app->store->tx(function()use($a){
            $existing=$this->app->store->get('follows',$a['id']);
            if ($existing&&($existing['state']??'')!=='rejected') {
                if(($existing['state']??'')==='pending'){$existing['actor']=$a;$this->app->store->put('follows',$a['id'],$existing);$this->deliver($existing['activity'],$a);}
                return $existing;
            }
            $activity=$this->activity('Follow',$a['id'],$a['id']);
            $follow=['id'=>$a['id'],'actor'=>$a,'activity'=>$activity,'state'=>'pending'];
            $this->app->store->put('follows',$a['id'],$follow);$this->deliver($activity,$a);return $follow;
        });
    }
    public function unfollow(string $id): void {
        $f=$this->app->store->get('follows',$id);
        if (!$f) return;
        $this->app->store->tx(function()use($f,$id){
            $a=$this->activity('Undo',$f['activity'],$id);$this->deliver($a,$f['actor']);
            $this->app->store->remove('follows',$id);
            foreach ($this->app->store->each('timeline') as $p) if (($p['attributedTo']??'')===$id) $this->app->store->remove('timeline',$p['id']);
        });
    }
    public function like(array $post): bool {
        $id=$post['id'];$actor=$this->actor($post['attributedTo']);
        return $this->app->store->tx(function()use($id,$actor){
            $existing=$this->app->store->get('likes',$id);
            $a=$this->activity($existing?'Undo':'Like',$existing['activity']??$id,$actor['id']);
            $this->deliver($a,$actor);
            if($existing) $this->app->store->remove('likes',$id);
            else $this->app->store->put('likes',$id,['id'=>$id,'activity'=>$a]);
            return !$existing;
        });
    }
    public function verify(array $headers,string $body,string $target,string $method='POST'): array {
        if(strlen($body)>self::MAX_INBOX_BYTES)throw new \InvalidArgumentException('The activity is too large.');
        $headers=array_change_key_case($headers,CASE_LOWER);
        $date=strtotime($headers['date']??'');
        if (!$date || abs(time()-$date)>43200) throw new \InvalidArgumentException('The signature date is missing or expired.');
        $matched=preg_match('/^SHA-256=([A-Za-z0-9+\/=]+)$/i',$headers['digest']??'',$digestParts);
        $digestBytes=$matched?base64_decode($digestParts[1],true):false;
        if ($digestBytes===false || !hash_equals(hash('sha256',$body,true),$digestBytes)) throw new \InvalidArgumentException('The request digest does not match.');
        preg_match_all('/(keyId|algorithm|headers|signature)="([^"\r\n]+)"/',$headers['signature']??'',$m,PREG_SET_ORDER);
        $s=[];foreach($m as $v)$s[$v[1]]=$v[2];
        if (!isset($s['keyId'],$s['headers'],$s['signature']) || !in_array($s['algorithm']??'rsa-sha256',['rsa-sha256','hs2019'],true)) throw new \InvalidArgumentException('A supported HTTP signature is required.');
        $names=explode(' ',strtolower($s['headers']));
        foreach(['(request-target)','host','date','digest'] as $required) if(!in_array($required,$names,true)) throw new \InvalidArgumentException('The signature omits a required header.');
        $activity=json_decode($body,true,64,JSON_THROW_ON_ERROR);
        if (!is_array($activity)||!is_string($activity['actor']??null)||!Http::validUrl($activity['actor'])||!is_string($activity['id']??null)||!Http::validUrl($activity['id'])) throw new \InvalidArgumentException('An activity ID and actor are required.');
        if (parse_url($activity['id'],PHP_URL_HOST)!==parse_url($activity['actor'],PHP_URL_HOST)) throw new \InvalidArgumentException('The activity ID must belong to the sender.');
        $lines=[];foreach($names as $name){
            if($name==='(request-target)')$value=strtolower($method).' '.$target;
            else {$value=$headers[$name]??null;if($value===null)throw new \InvalidArgumentException('Missing signed header.');}
            $lines[]=$name.': '.$value;
        }
        $lock=null;
        $fetch=function(bool $refresh=false)use(&$lock,$activity):array{
            if($lock===null){
                $lock=fopen($this->app->config['data_dir'].'/inbox-verify.lock','c');
                if(!$lock){$lock=null;throw new RemoteError(503,'Actor verification is unavailable. Retry later.',60);}
                if(!flock($lock,LOCK_EX|LOCK_NB)){fclose($lock);$lock=null;throw new RemoteError(429,'Actor verification is busy. Retry later.',5);}
            }
            return $this->keyActor($activity['actor'],$refresh);
        };
        try {
        $cached=$this->app->store->get('actors',$activity['actor']);
        $actor=$cached&&($cached['_fetched']??0)>time()-21600?$cached:$fetch();
        $verify=function(array $actor)use($s,$lines):bool{
            $keys=$actor['publicKey']??[];$key=[];
            foreach(isset($keys[0])?$keys:[$keys] as $candidate)if(is_array($candidate)&&($candidate['id']??'')===$s['keyId']){$key=$candidate;break;}
            if(!is_array($key)||!is_string($key['publicKeyPem']??null)||strlen($key['publicKeyPem'])>16384)return false;
            if (($key['id']??'')!==$s['keyId']||($key['owner']??'')!==$actor['id']) return false;
            $bytes=base64_decode($s['signature'],true);
            return $bytes!==false && @openssl_verify(implode("\n",$lines),$bytes,$key['publicKeyPem']??'',OPENSSL_ALGO_SHA256)===1;
        };
        $valid=$verify($actor);
        // A recently refreshed key must not trigger repeated fetches on forged requests.
        if (!$valid && ($actor['_fetched']??0)<time()-60) {$actor=$fetch(true);$valid=$verify($actor);}
        if (!$valid) throw new \InvalidArgumentException('The signature could not be verified.');
        $this->cacheActor($activity['actor'],$actor);
        return $activity;
        } finally {if($lock!==null){flock($lock,LOCK_UN);fclose($lock);}}
    }
    public function inbox(array $headers,string $body,string $target): void {
        if((int)$this->app->store->query("SELECT COUNT(*) FROM jobs WHERE state='pending' AND payload LIKE ?",['%"kind":"receive"%'])->fetchColumn()>=2000)throw new RemoteError(429,'The inbox queue is full. Retry later.',60);
        $activity=$this->verify($headers,$body,$target);
        $this->app->store->tx(function()use($activity){
            if (!$this->app->store->add('receipts',$activity['actor']."\n".$activity['id'],['id'=>$activity['id'],'actor'=>$activity['actor']])) return;
            $this->enqueue(['kind'=>'receive','activity'=>$activity]);
        });
    }
    public static function isPublic(array $post): bool {
        return in_array(self::PUBLIC,array_merge((array)($post['to']??[]),(array)($post['cc']??[])),true);
    }
    public static function hasImage(array $post): bool {
        $attachments=$post['attachment']??[];
        if(!is_array($attachments))return false;
        if(isset($attachments['type']))$attachments=[$attachments];
        if(($post['type']??'')==='Image')$attachments[]=$post;
        foreach($attachments as $attachment){
            if(!is_array($attachment))continue;
            $url=Remote::href($attachment['url']??'');
            if(Http::validUrl($url)&&(str_starts_with(strtolower((string)($attachment['mediaType']??'')),'image/')||(($attachment['type']??'')==='Image'&&!isset($attachment['mediaType']))))return true;
        }
        return false;
    }
    public function receive(array $a): void {
        $s=$this->app->store;$who=$a['actor'];$type=$a['type']??'';$obj=$a['object']??null;
        $id=is_string($obj)?$obj:(is_array($obj)?($obj['id']??''):'');
        $actor=$this->actor($who);
        if ($s->get('blocks',strtolower((string)parse_url($who,PHP_URL_HOST)))) return;
        if($type==='Follow' && $id===$this->app->actorId()) {
            $s->tx(function()use($s,$who,$actor,$a){
                if(!$s->get('followers',$who)&&$s->count('followers')>=10000)return;
                $existing=$s->get('followers',$who);
                $s->put('followers',$who,['id'=>$who,'actor'=>$actor,'follow_id'=>$a['id']]);
                $this->deliver($this->activity('Accept',$a,$who),$actor);
                if(!$existing){
                    foreach(array_reverse($s->page('posts',0,24)) as $post){
                        if(!self::hasImage($post))continue;
                        $create=$s->get('creates',$post['id']);if(!$create)continue;
                        $create['object']=$post;$this->deliver($create,$actor);
                    }
                }
            });
        } elseif(in_array($type,['Accept','Reject'],true)) {
            $f=$s->get('follows',$who);
            if($f && $id===$f['activity']['id']) {
                $f['state']=$type==='Accept'?'accepted':'rejected';$s->put('follows',$who,$f);
            }
        } elseif(in_array($type,['Create','Update'],true)&&is_array($obj)) {
            unset($obj['_discovery']);
            if (($obj['attributedTo']??'')!==$who || !Http::validUrl((string)$id) || parse_url($id,PHP_URL_HOST)!==parse_url($who,PHP_URL_HOST)) return;
            if (!in_array($obj['type']??'', ['Note','Article','Image'],true)) return;
            foreach(['posts','timeline','remote','saved','comments','discovery_posts'] as $bucket){
                $existing=$s->get($bucket,$id);
                if($existing&&($existing['attributedTo']??'')!==$who)return;
            }
            $following=$s->get('follows',$who);
            $reply=is_string($obj['inReplyTo']??null)?$s->get('posts',$obj['inReplyTo']):null;
            if (!self::isPublic($obj)) {
                // A public post becoming private must disappear from all local caches.
                $this->forget($id,$who);return;
            }
            if(!empty($obj['inReplyTo'])&&!self::hasImage($obj)){$this->forget($id,$who);return;}
            $s->remove('discovery_deleted',$id);
            if($discovered=$s->get('discovery_posts',$id)){
                if(!in_array(self::PUBLIC,(array)($obj['to']??[]),true))$s->remove('discovery_posts',$id);
                else $this->app->discovery->ingest($obj,$discovered['_discovery']['source'],['engagement'=>$discovered['_discovery']['engagement']??0]);
            }
            if (($following['state']??'')==='accepted') $s->put('timeline',$id,$obj,strtotime($obj['published']??'now')?:time());
            if($s->get('remote',$id)) $s->put('remote',$id,$obj);
            if($s->get('saved',$id)) $s->put('saved',$id,$obj);
            if($reply&&($s->get('comments',$id)||$s->count('comments')<20000)) $s->put('comments',$id,$obj,strtotime($obj['published']??'now')?:time());
        } elseif($type==='Delete') {
            $this->forget($id,$who);
        } elseif($type==='Like' && $s->get('posts',$id)) {
            $existing=$s->get('reactions',$a['id']);if($existing&&($existing['actor']??'')!==$who)return;
            if($s->get('reactions',$a['id'])||$s->count('reactions')<20000)$s->put('reactions',$a['id'],['id'=>$a['id'],'actor'=>$who,'post'=>$id,'type'=>'Like']);
        } elseif($type==='Undo' && is_string($obj)) {
            $f=$s->get('followers',$who);if(($f['follow_id']??null)===$obj)$s->remove('followers',$who);
            $r=$s->get('reactions',$obj);if(($r['actor']??null)===$who)$s->remove('reactions',$obj);
        } elseif($type==='Undo' && is_array($obj) && ($obj['actor']??null)===$who) {
            if(($obj['type']??'')==='Follow') {
                $f=$s->get('followers',$who);
                if($f && $f['follow_id']===($obj['id']??'')) $s->remove('followers',$who);
            } elseif(($obj['type']??'')==='Like') {
                $r=$s->get('reactions',$obj['id']??'');
                if(($r['actor']??'')===$who) $s->remove('reactions',$r['id']);
            }
        }
    }
    private function forget(string $id,string $actor): void {
        foreach(['timeline','remote','saved','comments','discovery_posts'] as $bucket) {
            $post=$this->app->store->get($bucket,$id);
            if(($post['attributedTo']??'')===$actor){
                $this->app->store->remove($bucket,$id);
                if($bucket==='discovery_posts')$this->app->store->put('discovery_deleted',$id,['id'=>$id,'actor'=>$actor]);
            }
        }
    }
    public function work(int $limit=50,int $seconds=45): array {
        $lock=fopen($this->app->config['data_dir'].'/worker.lock','c');
        if(!$lock||!flock($lock,LOCK_EX|LOCK_NB)) return ['processed'=>0,'locked'=>true];
        $start=time();$processed=0;$failed=0;
        try {
            $s=$this->app->store;
            while($processed<$limit && time()-$start<$seconds) {
                $job=$s->query("SELECT * FROM jobs WHERE state='pending' AND due<=? ORDER BY priority,due,created,id LIMIT 1",[time()])->fetch();
                if(!$job)break;
                try {
                    $p=json_decode($job['payload'],true,64,JSON_THROW_ON_ERROR);
                    if($p['kind']==='deliver') {
                        $this->app->http->request($p['inbox'],'POST',App::json(self::document($p['activity'])));
                        $s->put('meta','last_delivery',['at'=>time(),'server'=>parse_url($p['inbox'],PHP_URL_HOST),'type'=>$p['activity']['type']??'activity']);
                    } elseif($p['kind']==='fanout') {
                        $s->tx(function()use($s,$p){
                            foreach($s->each('followers') as $f) $this->deliver($p['activity'],$f['actor']);
                        });
                    } elseif($p['kind']==='receive') { $this->receive($p['activity']); }
                    $s->query("UPDATE jobs SET state='done',updated=?,error='' WHERE id=?",[time(),$job['id']]);
                } catch(\Throwable $e) {
                    $n=(int)$job['attempts']+1;$permanent=$e instanceof RemoteError && in_array($e->status,[400,404,410,422],true);
                    $dead=$n>=8||$permanent;
                    $delay=max(min(86400,60*(2**($n-1)))+random_int(0,20),$e instanceof RemoteError?$e->retryAfter:0);
                    $s->query('UPDATE jobs SET attempts=?,due=?,state=?,error=?,updated=? WHERE id=?',[$n,time()+$delay,$dead?'failed':'pending',mb_substr($e->getMessage(),0,500),time(),$job['id']]);$failed++;
                }
                $processed++;
            }
            $s->put('meta','worker',['last_run'=>time()]);
            $s->query("DELETE FROM jobs WHERE state IN ('done','failed') AND updated<?",[time()-7*86400]);
            foreach(['remote'=>7,'actors'=>7,'profiles'=>7,'receipts'=>14,'cache'=>1,'attempts'=>1,'feed_seen'=>7] as $bucket=>$days)
                $s->query('DELETE FROM records WHERE bucket=? AND updated<?',[$bucket,time()-$days*86400]);
            foreach(['actors'=>5000,'profiles'=>10000] as $bucket=>$maximum){
                $excess=$s->count($bucket)-$maximum;if($excess<=0)continue;
                $batch=min(1000,$excess);$rows=$s->query("SELECT ref FROM records WHERE bucket=? ORDER BY updated,id LIMIT $batch",[$bucket])->fetchAll();
                foreach($rows as $row)$s->remove($bucket,$row['ref']);
            }
            // Keep received history bounded; saved posts have their own durable bucket.
            $s->query('DELETE FROM records WHERE bucket=? AND created<?',['timeline',time()-90*86400]);
        } finally { flock($lock,LOCK_UN);fclose($lock); }
        return ['processed'=>$processed,'rescheduled_or_failed'=>$failed];
    }
}
