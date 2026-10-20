<?php
declare(strict_types=1);
namespace Pup;

final class Web {
    private string $path;
    private string $method;
    public function __construct(private App $app) {
        $this->path=rawurldecode(parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH)?:'/');
        if(defined('QUACKPACK_ROUTE'))$this->path=QUACKPACK_ROUTE;
        elseif($this->path==='/index.php')$this->path=is_string($_GET['route']??null)?$_GET['route']:'/';
        $this->method=$_SERVER['REQUEST_METHOD']??'GET';
    }
    private function json(mixed $data,int $status=200,string $type='application/json'): never {
        if($type==='application/activity+json'&&is_array($data))$data=Federation::document($data);
        http_response_code($status);header('Content-Type: '.$type.'; charset=utf-8');echo App::json($data);exit;
    }
    private function owner(): bool { return ($_SESSION['owner']??null)===hash('sha256',$this->app->config['password_hash']); }
    private function requireOwner(): void { if(!$this->owner())$this->json(['error'=>'Sign in to your journal.'],401); }
    private function csrf(): void {
        if(!hash_equals($_SESSION['csrf']??'',(string)($_SERVER['HTTP_X_CSRF_TOKEN']??$_POST['csrf']??'')))$this->json(['error'=>'Refresh the page and try again.'],403);
    }
    private function input(): array {
        if(str_contains($_SERVER['CONTENT_TYPE']??'','application/json')) {
            if((int)($_SERVER['CONTENT_LENGTH']??0)>1048576)throw new \InvalidArgumentException('Request too large.');
            return json_decode(file_get_contents('php://input',false,null,0,1048577),true,32,JSON_THROW_ON_ERROR);
        }
        return $_POST;
    }
    private function rate(string $key,int $limit,int $window): bool {
        // REMOTE_ADDR comes from the web server. Untrusted forwarded headers are ignored.
        $ref=$key.':'.hash('sha256',($_SERVER['REMOTE_ADDR']??'unknown')).':'.(int)floor(time()/$window);
        return $this->app->store->tx(function()use($ref,$limit){
            $r=$this->app->store->get('attempts',$ref)??['count'=>0];$r['count']++;
            $this->app->store->put('attempts',$ref,$r);return $r['count']<=$limit;
        });
    }
    public function run(): void {
        header('X-Content-Type-Options: nosniff');header('Referrer-Policy: no-referrer');
        header('X-Frame-Options: DENY');header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
        header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' https: blob: data:; connect-src 'self'; font-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'");
        $s=$this->app->store;$path=$this->path;
        try {
            if($path==='/.well-known/webfinger' && $this->method==='GET'){
                header('Access-Control-Allow-Origin: *');
                $acct='acct:'.$this->app->config['username'].'@'.parse_url($this->app->url(),PHP_URL_HOST);
                $r=$_GET['resource']??'';
                if(!is_string($r)||!(strcasecmp($r,$acct)===0||in_array($r,[$this->app->actorId(),$this->app->url('/')],true)))$this->json(['error'=>'Not found.'],404);
                $this->json(['subject'=>$acct,'aliases'=>[$this->app->actorId(),$this->app->url('/')],'links'=>[
                    ['rel'=>'self','type'=>'application/activity+json','href'=>$this->app->actorId()],
                    ['rel'=>'http://webfinger.net/rel/profile-page','type'=>'text/html','href'=>$this->app->url('/')]]],200,'application/jrd+json');
            }
            if($path==='/.well-known/nodeinfo')$this->json(['links'=>[['rel'=>'http://nodeinfo.diaspora.software/ns/schema/2.0','href'=>$this->app->url('/nodeinfo/2.0')]]]);
            if($path==='/nodeinfo/2.0')$this->json(['version'=>'2.0','software'=>['name'=>'quackpack','version'=>'0.5.3'],'protocols'=>['activitypub'],'services'=>['inbound'=>[],'outbound'=>[]],'openRegistrations'=>false,'usage'=>['users'=>['total'=>1,'activeMonth'=>1,'activeHalfyear'=>1],'localPosts'=>$s->count('posts')],'metadata'=>(object)[]]);
            if($path==='/users/'.$this->app->config['username'] && $this->method==='GET')$this->json($this->app->actor(),200,'application/activity+json');
            // A human-facing profile URL must resolve to the same stable actor.
            if($path==='/'&&$this->method==='GET'){
                header('Vary: Accept');header('Link: <'.$this->app->actorId().'>; rel="alternate"; type="application/activity+json"');
                if(preg_match('~application/(activity\+json|ld\+json)~i',$_SERVER['HTTP_ACCEPT']??''))$this->json($this->app->actor(),200,'application/activity+json');
            }
            if($path==='/inbox' && $this->method==='POST'){
                if(!$this->rate('inbox',120,60))$this->json(['error'=>'Slow down.'],429);
                if((int)($_SERVER['CONTENT_LENGTH']??0)>Federation::MAX_INBOX_BYTES)$this->json(['error'=>'Request too large.'],413);
                $body=file_get_contents('php://input',false,null,0,Federation::MAX_INBOX_BYTES+1);
                if(strlen($body)>Federation::MAX_INBOX_BYTES)$this->json(['error'=>'Request too large.'],413);
                try{$this->app->fed->inbox(getallheaders(),$body,$_SERVER['REQUEST_URI']);}
                catch(RemoteError $e){if(in_array($e->status,[429,503],true)){header('Retry-After: '.max(1,$e->retryAfter));$this->json(['error'=>$e->getMessage()],$e->status);}throw $e;}
                $this->json(['accepted'=>true],202);
            }
            if(in_array($path,['/outbox','/followers','/following'],true)&&$this->method==='GET')$this->collection($path);
            if(preg_match('~^/a/[a-f0-9]{32}$~',$path)&&$this->method==='GET'){
                $a=$s->get('activities',$this->app->url($path));
                if(!$a||!Federation::isPublic($a))$this->json(['error'=>'Not found.'],404);
                $this->json($a,200,'application/activity+json');
            }
            if(preg_match('~^/media/([a-f0-9]{40}\.jpg)$~',$path,$m)&&$this->method==='GET'){
                $file=$this->app->config['data_dir'].'/media/'.$m[1];
                if(!is_file($file))$this->json(['error'=>'Not found.'],404);
                header('Content-Type: image/jpeg');header('Cache-Control: public, max-age=31536000, immutable');header('Content-Length: '.filesize($file));readfile($file);exit;
            }
            if($path==='/health')$this->json(['app'=>'quackpack','version'=>'0.5.3','status'=>'ok']);
            if($path==='/robots.txt'){header('Content-Type: text/plain');echo "User-agent: *\nDisallow: /api/\nDisallow: /explore\nDisallow: /saved\nDisallow: /settings\n";exit;}
            // Federation is stateless; owner sessions begin only on UI and API routes.
            session_name('pup_owner');session_save_path($this->app->config['data_dir'].'/sessions');
            ini_set('session.use_strict_mode','1');
            session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>!($this->app->config['development']??false),'httponly'=>true,'samesite'=>'Lax']);
            session_start();$_SESSION['csrf']??=bin2hex(random_bytes(32));header('Cache-Control: private, no-store');
            if($path==='/login'&&$this->method==='POST'){
                $this->csrf();
                if(!$this->rate('login',10,900))$this->json(['error'=>'Too many attempts. Try again in 15 minutes.'],429);
                $input=$this->input();
                if(!password_verify((string)($input['password']??''),$this->app->config['password_hash']))$this->json(['error'=>'That password did not match.'],401);
                session_regenerate_id(true);$_SESSION['owner']=hash('sha256',$this->app->config['password_hash']);
                $this->json(['ok'=>true]);
            }
            if($path==='/logout'&&$this->method==='POST'){$this->csrf();$_SESSION=[];session_destroy();$this->json(['ok'=>true]);}
            if(str_starts_with($path,'/api/')){
                if($path==='/api/feed'&&$this->method==='GET'&&($_GET['kind']??'journal')==='journal')$this->read('feed');
                $this->requireOwner();
                if($this->method==='POST'&&($max=ini_parse_quantity(ini_get('post_max_size')))>0&&(int)($_SERVER['CONTENT_LENGTH']??0)>$max)$this->json(['error'=>'The upload exceeded the PHP post limit. Try fewer photos or increase post_max_size.'],413);
                if($this->method==='POST'){$this->csrf();$this->mutate(substr($path,5),$this->input());}
                if($this->method==='GET')$this->read(substr($path,5));
                $this->json(['error'=>'Method not allowed.'],405);
            }
            if($this->method!=='GET')$this->json(['error'=>'Method not allowed.'],405);
            $post=null;
            if(preg_match('~^/p/[a-f0-9]{24}$~',$path)){
                $id=$this->app->url($path);$post=$s->get('posts',$id);$tomb=$s->get('tombstones',$id);
                if($tomb)$this->json($tomb,410,'application/activity+json');
                if(!$post)$this->json(['error'=>'Not found.'],404);
                header('Vary: Accept');header('Link: <'.$id.'>; rel="alternate"; type="application/activity+json"');
                if(preg_match('~application/(activity\+json|ld\+json)~',$_SERVER['HTTP_ACCEPT']??''))$this->json($post,200,'application/activity+json');
            } elseif(!in_array($path,['/','/feed','/explore','/following-feed','/saved','/settings','/login'],true))$this->json(['error'=>'Not found.'],404);
            if(in_array($path,['/feed','/explore','/following-feed','/saved','/settings'],true)&&!$this->owner()){header('Location: '.$this->app->path('/login'));exit;}
            $view=match($path){'/feed'=>'feed','/explore'=>'explore','/following-feed'=>'following','/saved'=>'saved','/settings'=>'settings','/login'=>'login',default=>'journal'};
            $owner=$this->owner();$profile=$this->app->profile();
            $posts=$post?[$this->app->normalize($post)]:array_map(fn($p)=>$this->app->normalize($p),$s->page('posts',0,24));
            if($view==='following')$posts=array_map(fn($p)=>$this->app->normalize($p),$s->page('timeline',0,24));
            if($view==='saved')$posts=array_map(fn($p)=>$this->app->normalize($p),$s->page('saved',0,24));
            if($view==='explore'||$view==='settings'||$view==='login')$posts=[];
            $master=null;
            if($view==='feed'){$this->app->discovery->boot();$master=$this->app->feed->page();$posts=$master['posts'];}
            if(!$owner)foreach($posts as &$publicPost){$publicPost['saved']=false;$publicPost['liked']=false;}unset($publicPost);
            $servers=[];if($owner)foreach($s->each('servers') as $server){unset($server['token']);$servers[]=$server;}
            $state=['view'=>$view,'owner'=>$owner,'csrf'=>$_SESSION['csrf'],'routing'=>$this->app->config['routing']??'pretty','profile'=>$profile,'handle'=>'@'.$this->app->config['username'].'@'.parse_url($this->app->url(),PHP_URL_HOST),
                'posts'=>$posts,'post_count'=>$s->count('posts'),'followers'=>$s->count('followers'),'following'=>$s->count('follows'),
                'servers'=>$servers,'single'=>(bool)$post,'links'=>$this->app->links(!($owner&&$view==='settings')),
                'uploads'=>$owner?['file_bytes'=>ini_parse_quantity(ini_get('upload_max_filesize')),'post_bytes'=>ini_parse_quantity(ini_get('post_max_size'))]:null,
                'feed_cursor'=>$master['cursor']??'','discovery'=>$view==='feed'?$this->app->discovery->status():null];
            $app=$this->app;require dirname(__DIR__).'/views/shell.php';
        } catch(RemoteError $e){$this->json(['error'=>$e->getMessage(),'remote_status'=>$e->status,'access_required'=>$e->authenticationRequired],502);}
        catch(\InvalidArgumentException|\JsonException $e){$this->json(['error'=>$e->getMessage()],400);}
        catch(\Throwable $e){error_log('Quackpack: '.$e->getMessage());$this->json(['error'=>'Something could not be completed. Check the application log.'],500);}
    }
    private function collection(string $path): never {
        $s=$this->app->store;$bucket=substr($path,1);$base=$this->app->url($path);$page=max(1,(int)($_GET['page']??1));$offset=($page-1)*24;
        if($path==='/outbox'){
            $items=array_map(function($p)use($s){$a=$s->get('creates',$p['id']);if($a)$a['object']=$p;return $a;},$s->page('posts',$offset,24));$items=array_values(array_filter($items));$total=$s->count('posts');
        } else {
            // Hide relationship identities; counts suffice for a single-owner journal.
            $total=$s->count($bucket==='following'?'follows':$bucket);$items=[];
        }
        $context=['@context'=>'https://www.w3.org/ns/activitystreams'];
        if(!isset($_GET['page']))$this->json($context+['id'=>$base,'type'=>'OrderedCollection','totalItems'=>$total,'first'=>$this->app->url($path.'?page=1')],200,'application/activity+json');
        $r=$context+['id'=>$this->app->url($path.'?page='.$page),'type'=>'OrderedCollectionPage','partOf'=>$base,'orderedItems'=>$items];
        if($path==='/outbox'&&$offset+24<$total)$r['next']=$this->app->url($path.'?page='.($page+1));
        $this->json($r,200,'application/activity+json');
    }
    private function read(string $route): never {
        $s=$this->app->store;$offset=max(0,min(100000,(int)($_GET['offset']??0)));
        if($route==='discovery')$this->json($this->app->discovery->status());
        if($route==='master-feed')$this->json($this->app->feed->page((string)($_GET['cursor']??''),(string)($_GET['mode']??'mix'),($_GET['photos']??'1')==='1'));
        if($route==='feed'){
            $bucket=match($_GET['kind']??'journal'){'following'=>'timeline','saved'=>'saved',default=>'posts'};
            $posts=array_map(fn($p)=>$this->app->normalize($p),$s->page($bucket,$offset,24));
            if(!$this->owner())foreach($posts as &$post){$post['saved']=false;$post['liked']=false;}unset($post);
            $this->json(['posts'=>$posts]);
        }
        if($route==='browse'){
            if(!$this->rate('browse',60,60))$this->json(['error'=>'Give the browser a moment before loading again.'],429);
            $mode=$_GET['mode']??'server';$cursor=(string)($_GET['cursor']??'');$photos=($_GET['photos']??'1')==='1';
            if($mode==='account')$this->json($this->app->browser->account((string)($_GET['q']??''),(string)($_GET['account']??''),(string)($_GET['family']??'mastodon'),$cursor,$photos));
            if($mode==='person')$this->json($this->app->browser->person((string)($_GET['q']??''),$cursor,$photos));
            if($mode==='post'){
                $p=$this->app->browser->object((string)($_GET['q']??''));$this->json(['posts'=>[$this->app->normalize($p)],'cursor'=>'','source'=>'Post']);
            }
            $this->json($this->app->browser->server((string)($_GET['q']??''),(string)($_GET['tag']??''),$cursor,$photos,($_GET['local']??'1')==='1'));
        }
        if($route==='settings'){
            $counts=[];foreach($s->query('SELECT state,COUNT(*) AS n FROM jobs GROUP BY state')->fetchAll() as $r)$counts[$r['state']]=(int)$r['n'];
            $follows=[];foreach($s->each('follows') as $f)$follows[]=['id'=>$f['id'],'name'=>$f['actor']['name']?:$f['actor']['preferredUsername'],'state'=>$f['state']];
            $failed=$s->query("SELECT id,error,attempts FROM jobs WHERE state='failed' ORDER BY updated DESC LIMIT 10")->fetchAll();
            $this->json(['worker'=>$s->get('meta','worker'),'jobs'=>$counts,'failed'=>$failed,'follows'=>$follows,'blocks'=>$s->page('blocks',0,200),
                'followers'=>$s->count('followers'),'last_delivery'=>$s->get('meta','last_delivery'),'federation_check'=>$s->get('meta','federation_check')]);
        }
        if($route==='comments'){
            $id=(string)($_GET['id']??'');$comments=[];
            foreach($s->each('comments') as $p)if(($p['inReplyTo']??'')===$id){$reply=$this->app->normalize($p);if($reply['photos'])$comments[]=$reply;}
            $this->json(['comments'=>$comments]);
        }
        $this->json(['error'=>'Not found.'],404);
    }
    private function mutate(string $route,array $in): never {
        $s=$this->app->store;
        if($route==='federation-check')$this->json($this->app->fed->checkPublic());
        if($route==='resend')$this->json(['queued'=>$this->app->fed->resend()]);
        if($route==='discovery')$this->json($this->app->discovery->control((string)($in['action']??''),(string)($in['value']??'')));
        if($route==='links')$this->json(['links'=>$this->app->saveLinks($in['links']??null)]);
        if($route==='post'){
            $files=[];$f=$_FILES['photos']??null;
            if($f&&is_array($f['name']))foreach($f['name'] as $i=>$name)$files[]=['name'=>$name,'tmp_name'=>$f['tmp_name'][$i],'error'=>$f['error'][$i],'size'=>$f['size'][$i]];
            $edit=!empty($in['edit'])?(string)$in['edit']:null;
            if($edit&&!$s->get('posts',$edit))throw new \InvalidArgumentException('Post not found.');
            $attachments=$this->app->media->upload($files,(array)($in['alt']??[]));
            if(!$edit&&!$attachments)throw new \InvalidArgumentException('Choose at least one photo. Replies also require a photo.');
            $replyActor=null;
            if(!empty($in['reply_to'])){
                $target=$this->app->browser->actionable((string)$in['reply_to']);
                if($target['attributedTo']!==$this->app->actorId())$replyActor=$this->app->fed->actor($target['attributedTo']);
            }
            $post=$this->app->post($in,$attachments,$edit,$replyActor);
            $this->json(['post'=>$this->app->normalize($post)]);
        }
        if($route==='delete'){$this->app->deletePost((string)($in['id']??''));$this->json(['ok'=>true]);}
        if($route==='save'){
            $id=(string)($in['id']??'');
            if($s->get('saved',$id)){$s->remove('saved',$id);$this->json(['saved'=>false]);}
            $p=$this->app->browser->actionable($id);$s->put('saved',$id,$p);$this->json(['saved'=>true]);
        }
        if($route==='like'){$p=$this->app->browser->actionable((string)($in['id']??''));$this->json(['liked'=>$this->app->fed->like($p)]);}
        if($route==='follow'){$f=$this->app->fed->follow((string)($in['q']??''));$this->json(['state'=>$f['state']]);}
        if($route==='unfollow'){$this->app->fed->unfollow((string)($in['id']??''));$this->json(['ok'=>true]);}
        if($route==='profile'){
            $profile=['name'=>mb_substr(trim((string)($in['name']??'')),0,80),'bio'=>mb_substr(trim((string)($in['bio']??'')),0,500),'avatar'=>$this->app->profile()['avatar']];
            if(!$profile['name'])throw new \InvalidArgumentException('Give your profile a name.');
            if(isset($_FILES['avatar'])&&($_FILES['avatar']['error']??4)!==4){$a=$this->app->media->upload([$_FILES['avatar']]);$profile['avatar']=$a[0]['url'];}
            $s->tx(function()use($s,$profile){$s->put('meta','profile',$profile);$this->app->fed->broadcast($this->app->fed->activity('Update',$this->app->actor()));});
            $this->json(['ok'=>true]);
        }
        if($route==='server'){
            $domain=Browser::domain((string)($in['domain']??''));
            if(!empty($in['remove'])){$s->remove('servers',$domain);$this->json(['ok'=>true]);}
            $old=$s->get('servers',$domain)??[];$token=trim((string)($in['token']??''));
            if(strlen($token)>1000||preg_match('/[\x00-\x20\x7f]/',$token))throw new \InvalidArgumentException('Invalid access token.');
            $server=['domain'=>$domain,'token'=>!empty($in['clear_token'])?'':($token?:($old['token']??''))];
            $s->put('servers',$domain,$server);$this->app->discovery->refreshAccess($domain);$this->json(['ok'=>true]);
        }
        if($route==='block'){
            $domain=Browser::domain((string)($in['domain']??''));
            if(!empty($in['remove'])){$s->remove('blocks',$domain);$this->app->discovery->seed($domain,'unblocked');$this->json(['ok'=>true]);}
            $s->put('blocks',$domain,['domain'=>$domain]);
            $this->app->discovery->localBlock($domain);
            foreach(['followers','follows','actors','timeline','remote','saved','comments','discovery_posts','crawl_actors','crawl_watching'] as $bucket){
                foreach($s->each($bucket) as $p){$id=$p['id']??'';$author=$p['attributedTo']??$id;
                    if(strtolower((string)parse_url($author,PHP_URL_HOST))===$domain||($p['_discovery']['source']??'')===$domain)$s->remove($bucket,$id);
                }
            }
            $this->json(['ok'=>true]);
        }
        if($route==='retry'){$s->query("UPDATE jobs SET state='pending',attempts=0,due=?,updated=? WHERE state='failed'",[time(),time()]);$this->json(['ok'=>true]);}
        $this->json(['error'=>'Not found.'],404);
    }
}
