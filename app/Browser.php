<?php
declare(strict_types=1);
namespace Pup;

final class Browser {
    public function __construct(private App $app) {}
    public static function plain(string $html): string {
        // Remote markup is deliberately rendered as text, never injected into the DOM.
        $html=preg_replace('~<(script|style)\b[^>]*>.*?</\1>~is','',$html)??'';
        $html=preg_replace('~</(p|div|li|blockquote)>|<br\s*/?>~i',"\n",$html)??'';
        return trim(html_entity_decode(strip_tags($html),ENT_QUOTES|ENT_HTML5,'UTF-8'));
    }
    public static function domain(string $input): string {
        $input=trim(strtolower($input));
        if (!str_contains($input,'://'))$input='https://'.$input;
        if(!Http::validUrl($input))throw new \InvalidArgumentException('Enter a server domain, such as pixelfed.social.');
        $p=parse_url($input);
        if(!preg_match('/^(?=.{1,253}$)[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?$/',$p['host'])||!str_contains($p['host'],'.')) throw new \InvalidArgumentException('Enter a public server domain.');
        return $p['host'];
    }
    private function token(string $domain): array {
        $server=$this->app->store->get('servers',$domain);
        return !empty($server['token'])?['Authorization'=>'Bearer '.$server['token']]:[];
    }
    public function remember(array $post,?array $actor=null): array {
        if(!Federation::isPublic($post)||!Http::validUrl((string)($post['id']??''))||!is_string($post['attributedTo']??null)) throw new \InvalidArgumentException('Only public posts can be opened here.');
        if($this->app->store->get('blocks',strtolower((string)parse_url($post['id'],PHP_URL_HOST)))||$this->app->store->get('blocks',strtolower((string)parse_url($post['attributedTo'],PHP_URL_HOST))))throw new \InvalidArgumentException('You have blocked this server.');
        $this->app->store->put('remote',$post['id'],$post);
        if($actor && isset($actor['id']))$this->app->store->put(isset($actor['inbox'])?'actors':'profiles',$actor['id'],$actor);
        return $this->app->normalize($post,$actor);
    }
    public function mastodonDocument(array $status): ?array {
        $status=$status['reblog']??$status;
        if(!in_array($status['visibility']??'public',['public','unlisted'],true)||!is_string($status['uri']??null))return null;
        $a=$status['account']??[];$handle=$a['acct']??'';
        // Mastodon account.url is a human-facing profile; resolve the canonical actor lazily for actions.
        $actorId=$a['url']??'';
        if(!Http::validUrl($actorId))return null;
        $actor=['id'=>$actorId,'name'=>($a['display_name']??'')?:($a['username']??$handle),
            'preferredUsername'=>$a['username']??'','icon'=>['url'=>$a['avatar_static']??''],'summary'=>$a['note']??''];
        $media=[];foreach(($status['media_attachments']??[]) as $m){
            if(($m['type']??'')==='image'&&Http::validUrl((string)($m['url']??'')))$media[]=['type'=>'Document','mediaType'=>'image/jpeg','url'=>$m['url'],'name'=>$m['description']??''];
        }
        $post=['id'=>$status['uri'],'type'=>'Note','url'=>$status['url']??$status['uri'],
            'attributedTo'=>$actorId,'content'=>$status['content']??'','published'=>$status['created_at']??'',
            'attachment'=>$media,'sensitive'=>$status['sensitive']??false,'summary'=>$status['spoiler_text']??'',
            'to'=>[Federation::PUBLIC],'cc'=>[],'inReplyTo'=>null,'_via'=>'mastodon'];
        return ['post'=>$post,'profile'=>$actor];
    }
    public function mastodon(array $status): ?array {
        $doc=$this->mastodonDocument($status);
        return $doc?$this->remember($doc['post'],$doc['profile']):null;
    }
    public function timeline(string $domain,string $tag='',string $cursor='',bool $photos=true,bool $local=true): array {
        $domain=self::domain($domain);
        $tag=ltrim(trim($tag),'#');
        if($tag!==''&&!preg_match('/^[\p{L}\p{N}_]{1,64}$/u',$tag))throw new \InvalidArgumentException('Use letters, numbers, or underscores in a hashtag.');
        if($cursor!==''&&!preg_match('/^[a-zA-Z0-9_-]{1,100}$/',$cursor))throw new \InvalidArgumentException('Invalid page cursor.');
        $path=$tag?'/api/v1/timelines/tag/'.rawurlencode($tag):'/api/v1/timelines/public';
        $query=['limit'=>24,'only_media'=>$photos?'true':'false'];
        if(!$tag)$query['local']=$local?'true':'false';
        if($cursor)$query['max_id']=$cursor;
        $url='https://'.$domain.$path.'?'.http_build_query($query);
        $headers=['Accept'=>'application/json']+$this->token($domain);
        $key='browse-v2:'.hash('sha256',$url."\0".($headers['Authorization']??''));$cached=$this->app->store->get('cache',$key);
        if($cached&&$cached['expires']>time())return $cached['result'];
        $notice='';$accessRequired=false;$people=[];
        try{$data=$this->app->remote->json($url,'GET',null,$headers);}
        catch(RemoteError $e){
            if(!$e->authenticationRequired&&!in_array($e->status,[401,403],true))throw $e;
            // Some servers close their full timeline but still publish recommendations.
            if($tag!==''||$cursor!=='')throw $e;
            try{$data=$this->app->remote->json('https://'.$domain.'/api/v1/trends/statuses?limit=40','GET',null,$headers);if(!array_is_list($data))$data=[];}catch(\Throwable){$data=[];}
            $people=$this->app->platforms->mastodonPeople($domain);
            if(!$data&&!$people)throw $e;
            $accessRequired=true;
            $notice='Public highlights and people from '.$domain.'. Highlights can include other servers. The complete live timeline is restricted by this server.';
        }
        if(!array_is_list($data))throw new RemoteError(502,'This server does not provide a compatible public timeline. Try opening a person’s @handle instead.');
        $posts=[];foreach($data as $status){if(!is_array($status))continue;$p=$this->mastodon($status);if($p&&(!$photos||$p['photos']))$posts[]=$p;}
        $last=$data?end($data):null;
        $r=['posts'=>$posts,'cursor'=>!$accessRequired&&$last?(string)($last['id']??''):'','source'=>$domain,'empty'=>count($data)===0,
            'notice'=>$notice,'access_required'=>$accessRequired,'people'=>$people,'listing'=>$accessRequired?'Public highlights':($local?'Local public timeline':'Federated public timeline')];
        $this->app->store->put('cache',$key,['expires'=>time()+30,'result'=>$r]);return $r;
    }
    public function server(string $input,string $tag='',string $cursor='',bool $photos=true,bool $local=true):array{
        $domain=self::domain($input);if($this->app->store->get('blocks',$domain))throw new RemoteError(403,'You have blocked this server.');$this->app->remote->begin();
        try{
            $software=$this->app->remote->software($domain);$family=Platforms::family($software);
            if(in_array($family,['misskey','peertube','lemmy'],true)){
                try{return $this->app->platforms->server($domain,$family,$cursor,$photos,$tag)+['software'=>$software];}
                catch(RemoteError $e){if(!in_array($e->status,[404,405,501],true))throw $e;}
            }
            if($family==='mastodon'||$software===''){
                try{return $this->timeline($domain,$tag,$cursor,$photos,$local)+['software'=>$software];}
                catch(RemoteError|\JsonException $e){if($e instanceof RemoteError&&!in_array($e->status,[404,405,501],true)&&!$e->authenticationRequired)throw $e;}
            }
            if($cursor!==''||$tag!=='')throw new \InvalidArgumentException('Open a public profile or collection on this server to browse further.');
            try{$doc=$this->app->remote->document('https://'.$domain.'/');return $this->fromDocument($doc,'https://'.$domain.'/','',$photos)+['software'=>$software];}
            catch(\Throwable $e){
                $people=[];
                foreach(['crawl_actors','profiles','actors'] as $bucket){foreach($this->app->store->page($bucket,0,200) as $p){$url=$p['id']??'';if(is_string($url)&&Http::validUrl($url)&&strtolower((string)parse_url($url,PHP_URL_HOST))===$domain)$people[$url]=['id'=>$url,'url'=>$url,'name'=>$p['name']??$p['preferredUsername']??'Public profile','handle'=>($p['preferredUsername']??'').'@'.$domain,'avatar'=>Remote::href($p['icon']??''),'mode'=>'person'];if(count($people)>=12)break 2;}}
                return ['posts'=>[],'people'=>array_values($people),'cursor'=>'','source'=>$domain,'listing'=>($software?ucfirst($software).' · ':'').'ActivityPub server','software'=>$software,
                    'notice'=>'Open a public profile, community, channel, collection or post URL from this server. Its public index is not available here.','needs_profile'=>true];
            }
        }finally{$this->app->remote->end();}
    }
    public function account(string $domain,string $id,string $family,string $cursor='',bool $photos=true):array{
        $domain=self::domain($domain);$this->app->remote->begin();
        try{return $this->app->platforms->account($domain,$id,$family,$cursor,$photos);}finally{$this->app->remote->end();}
    }
    private function actorInfo(array $a):array{
        $id=Remote::href($a['id']??'');$username=(string)($a['preferredUsername']??'');
        return ['id'=>$id,'name'=>self::plain((string)($a['name']??$username?:'Profile')),'handle'=>$username.'@'.parse_url($id,PHP_URL_HOST),
            'bio'=>self::plain((string)($a['summary']??'')),'following'=>$this->app->store->get('follows',$id)['state']??null];
    }
    public function person(string $input,string $cursor='',bool $photos=true):array{
        $this->app->remote->begin();
        try{
            if(Http::validUrl($input))return $this->fromDocument($this->app->remote->document($input),$input,$cursor,$photos);
            $actor=$this->app->fed->resolve($input);return $this->fromDocument($actor,$actor['id'],$cursor,$photos);
        }finally{$this->app->remote->end();}
    }
    private function fromDocument(array $doc,string $url,string $cursor,bool $photos):array{
        $type=$doc['type']??'';
        if(in_array($type,Remote::ACTORS,true)){
            $id=Remote::href($doc['id']??'');if(!$id)throw new \InvalidArgumentException('This profile has no canonical address.');
            if($id!==$url){$doc=$this->app->remote->document($id);if(($doc['id']??'')!==$id||!in_array($doc['type']??'',Remote::ACTORS,true))throw new \InvalidArgumentException('The canonical profile could not be verified.');}
            $outbox=Remote::href($doc['outbox']??'');if(!$outbox)throw new \InvalidArgumentException('This profile does not publish a readable outbox.');
            return $this->collection($outbox,$cursor,$photos,$doc);
        }
        if(in_array($type,['Collection','OrderedCollection','CollectionPage','OrderedCollectionPage'],true))return $this->collection($url,$cursor,$photos,null,$doc);
        if(in_array($type,array_merge(Remote::OBJECTS,['Create']),true)){
            $p=$this->objectFrom($doc,$url);$actor=$this->app->store->get('actors',$p['attributedTo']);
            $r=['posts'=>[$this->app->normalize($p)],'cursor'=>'','source'=>'Public '.strtolower($p['type']),'listing'=>'ActivityPub post','photos_applied'=>false];
            if($actor)$r['actor']=$this->actorInfo($actor);return $r;
        }
        throw new \InvalidArgumentException('Open a public ActivityPub profile, post or collection.');
    }
    private function page(string $url,?array $provided=null):array{
        $key='ap-page:'.hash('sha256',$url);$cached=$this->app->store->get('cache',$key);
        if(!$provided&&$cached&&$cached['expires']>time())return $cached['doc'];
        $doc=$provided??$this->app->remote->document($url);$this->app->store->put('cache',$key,['doc'=>$doc,'expires'=>time()+90]);return $doc;
    }
    private function collection(string $root,string $cursor,bool $photos,?array $actor=null,?array $provided=null):array{
        $url=$root;$offset=0;$host=parse_url($root,PHP_URL_HOST);
        if(str_starts_with($cursor,'ap_')){
            $encoded=substr($cursor,3);if(strlen($encoded)>8192)throw new \InvalidArgumentException('Invalid collection cursor.');
            $state=json_decode(base64_decode(strtr($encoded,'-_','+/'),true)?:'',true);
            if(!is_array($state)||($state['root']??'')!==$root||($state['photos']??null)!==$photos)throw new \InvalidArgumentException('This collection page belongs to a different view.');
            $url=$state['url']??'';$offset=$state['offset']??-1;
            if(!is_string($url)||!is_int($offset)||$offset<0||$offset>10000)throw new \InvalidArgumentException('Invalid collection cursor.');
        }elseif($cursor!=='')$url=$cursor;
        if(!Http::validUrl($url)||parse_url($url,PHP_URL_HOST)!==$host)throw new \InvalidArgumentException('Invalid collection page.');
        $doc=$this->page($url,$url===$root?$provided:null);
        if(!isset($doc['orderedItems'])&&!isset($doc['items'])){
            $first=$doc['first']??null;
            if(is_array($first)){$doc=$first;$url=Remote::href($first['id']??'')?:$root;}
            else{$next=Remote::href($first);if(!$next||parse_url($next,PHP_URL_HOST)!==$host)throw new \InvalidArgumentException('This collection has no readable public page.');$url=$next;$doc=$this->page($url);}
        }
        $items=$doc['orderedItems']??$doc['items']??[];if(!is_array($items))throw new RemoteError(502,'This collection page is not readable.');
        $all=[];$posts=[];$read=0;$i=$offset;$notice='';
        for(;$i<count($items)&&count($all)<24;$i++){
            $activity=$items[$i];if(is_array($activity)&&($activity['type']??'')==='Link')$activity=Remote::href($activity);
            try{
                if(is_string($activity)){if($read>=6)break;$activity=$this->app->remote->document($activity);$read++;}
                if(!is_array($activity))continue;$audience=$activity;$activityType=$activity['type']??'';
                $p=in_array($activityType,['Create','Announce'],true)?($activity['object']??null):$activity;
                if(is_string($p)){if($read>=6)break;$p=$this->app->remote->document($p);$read++;}
                if(!is_array($p))continue;
                $objectId=Remote::href($p['id']??'');
                if($objectId&&(($actor&&Remote::href($p['attributedTo']??'')!==($actor['id']??''))||(parse_url($objectId,PHP_URL_HOST)!==$host&&!$actor))){if($read>=6)break;$p=$this->app->remote->document($objectId);$read++;if(($p['id']??'')!==$objectId)continue;}
                $p=$this->app->remote->post($p,$audience,$actor['id']??null);if(!$p)continue;
                $profile=$actor&&$p['attributedTo']===($actor['id']??'')?$actor:($this->app->store->get('profiles',$p['attributedTo'])??['id'=>$p['attributedTo'],'name'=>parse_url($p['attributedTo'],PHP_URL_HOST)]);
                $n=$this->remember($p,$profile);$all[]=$n;if(!$photos||$n['photos'])$posts[]=$n;
            }catch(RemoteError $e){if(in_array($e->status,[429,504],true)){$notice=$e->getMessage();break;}}
            catch(\JsonException|\InvalidArgumentException){}
        }
        $next='';
        if($i<count($items))$state=['root'=>$root,'url'=>$url,'offset'=>$i,'photos'=>$photos];
        else{$nextUrl=Remote::href($doc['next']??'');$state=$nextUrl&&parse_url($nextUrl,PHP_URL_HOST)===$host?['root'=>$root,'url'=>$nextUrl,'offset'=>0,'photos'=>$photos]:null;}
        if($state)$next='ap_'.rtrim(strtr(base64_encode(App::json($state)),'+/','-_'),'=');
        $nonPhoto=$all&&!array_filter($all,static fn($p)=>!in_array($p['kind'],['Article','Page','Video','Audio','Event','Question'],true));
        $autoAll=$photos&&!$posts&&$nonPhoto;
        if($autoAll){$posts=$all;if($state){$state['photos']=false;$next='ap_'.rtrim(strtr(base64_encode(App::json($state)),'+/','-_'),'=');}}
        $r=['posts'=>$posts,'cursor'=>$next,'source'=>$actor?($actor['name']??$actor['preferredUsername']??'Profile'):'Public collection','listing'=>'ActivityPub collection','notice'=>$notice,'photos_applied'=>!$autoAll];
        if($actor)$r['actor']=$this->actorInfo($actor);return $r;
    }
    private function objectFrom(array $post,string $url):array{
        if(($post['type']??'')==='Create'){$post=$post['object']??[];if(is_string($post))$post=$this->app->remote->document($post);}
        if(!is_array($post))throw new \InvalidArgumentException('This URL does not resolve to a public post.');
        $canonical=Remote::href($post['id']??'');if(!$canonical)throw new \InvalidArgumentException('This post has no canonical address.');
        if($canonical!==$url){$post=$this->app->remote->document($canonical);if(($post['id']??'')!==$canonical)throw new \InvalidArgumentException('The canonical post could not be verified.');}
        $post=$this->app->remote->post($post);if(!$post)throw new \InvalidArgumentException('This URL does not resolve to a supported public post.');
        $actor=$this->app->fed->actor($post['attributedTo']);$this->remember($post,$actor);return $post;
    }
    public function object(string $url):array{
        $this->app->remote->begin();
        try{return $this->objectFrom($this->app->remote->document($url),$url);}finally{$this->app->remote->end();}
    }
    public function actionable(string $id): array {
        $local=$this->app->store->get('posts',$id);
        return $local??$this->object($id);
    }
}
