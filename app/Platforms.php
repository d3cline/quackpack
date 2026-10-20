<?php
declare(strict_types=1);
namespace Pup;

/** Optional public indexes. ActivityPub remains the common profile/post format. */
final class Platforms {
    public function __construct(private App $app){}
    public static function family(string $name):string{
        if(preg_match('/misskey|sharkey|firefish|iceshrimp|cherrypick|foundkey|calckey/',$name))return 'misskey';
        if(str_contains($name,'peertube'))return 'peertube';
        if($name==='lemmy')return 'lemmy';
        if(preg_match('/mastodon|pixelfed|pleroma|akkoma|gotosocial|friendica/',$name))return 'mastodon';
        return 'activitypub';
    }
    private function api(string $domain,string $path,?array $payload=null):array{
        $headers=[];$saved=$this->app->store->get('servers',$domain);
        // Mastodon user tokens are only attached to that server's compatible REST API.
        if(str_starts_with($path,'/api/v1/')&&!empty($saved['token']))$headers['Authorization']='Bearer '.$saved['token'];
        return $this->app->remote->json('https://'.$domain.$path,$payload===null?'GET':'POST',$payload,$headers);
    }
    private function people(array $rows,string $domain,string $family):array{
        $people=[];
        foreach(array_slice($rows,0,12) as $row){
            if(!is_array($row))continue;$a=$row['community']??$row;
            if(($family==='misskey'&&!empty($a['host']))||!empty($a['suspended'])||!empty($a['isSuspended']))continue;
            $url=Remote::href($a['url']??$a['actor_id']??$a['ap_id']??$a['uri']??'');
            if(!$url&&$family==='misskey'&&isset($a['id']))$url='https://'.$domain.'/users/'.rawurlencode((string)$a['id']);
            if(!$url||strtolower((string)parse_url($url,PHP_URL_HOST))!==$domain)continue;
            $id=(string)($a['id']??'');if(!preg_match('/^[a-zA-Z0-9_-]{1,100}$/',$id))continue;
            $people[]=['id'=>$id,'url'=>$url,'name'=>Browser::plain((string)($a['display_name']??$a['displayName']??$a['title']??$a['name']??$a['username']??'Profile')),
                'handle'=>(string)($a['acct']??$a['username']??$a['name']??'').'@'.$domain,
                'avatar'=>Remote::href($a['avatar_static']??$a['avatarUrl']??$a['avatar']??$a['icon']??''),'mode'=>'account','family'=>$family];
        }
        return $people;
    }
    public function mastodonPeople(string $domain):array{
        try{$rows=$this->api($domain,'/api/v1/directory?local=true&order=active&limit=12');return array_is_list($rows)?$this->people($rows,$domain,'mastodon'):[];}
        catch(\Throwable){return [];}
    }
    private function misskeyPost(array $n,string $domain):?array{
        $n=$n['renote']??$n;if(!is_array($n)||!in_array($n['visibility']??'public',['public','home'],true))return null;
        $u=$n['user']??[];$author=Remote::href($u['uri']??'')?:'https://'.$domain.'/users/'.rawurlencode((string)($u['id']??''));
        $id=Remote::href($n['uri']??'')?:'https://'.$domain.'/notes/'.rawurlencode((string)($n['id']??''));
        if(!isset($n['id'],$u['id']))return null;
        $files=[];$sensitive=false;$preview='';
        foreach($n['files']??[] as $f){if(!is_array($f))continue;$sensitive=$sensitive||!empty($f['isSensitive']);
            if(str_starts_with($f['type']??'','image/'))$files[]=['type'=>'Document','mediaType'=>$f['type'],'url'=>$f['url']??'','name'=>$f['comment']??$f['name']??''];
            elseif($preview==='')$preview=Remote::href($f['thumbnailUrl']??'');
        }
        return ['post'=>['id'=>$id,'type'=>'Note','attributedTo'=>$author,'url'=>$id,'published'=>$n['createdAt']??'','content'=>htmlspecialchars((string)($n['text']??''),ENT_QUOTES,'UTF-8'),
            'attachment'=>$files,'to'=>($n['visibility']??'public')==='public'?[Federation::PUBLIC]:[],'cc'=>($n['visibility']??'')==='home'?[Federation::PUBLIC]:[],
            'sensitive'=>$sensitive||!empty($n['cw']),'summary'=>$n['cw']??'','_browser_preview'=>$preview],
            'profile'=>['id'=>$author,'name'=>$u['name']??$u['username']??'Someone','preferredUsername'=>$u['username']??'','icon'=>['url'=>Remote::href($u['avatarUrl']??'')]]];
    }
    private function videoPost(array $v,string $domain):?array{
        $id=Remote::href($v['url']??'')?:'https://'.$domain.'/videos/watch/'.rawurlencode((string)($v['uuid']??''));
        $c=$v['channel']??[];$author=Remote::href($c['url']??'')?:'https://'.$domain.'/video-channels/'.rawurlencode((string)($c['name']??''));
        if(!isset($v['uuid'])||($v['privacy']['id']??1)!==1)return null;
        return ['post'=>['id'=>$id,'type'=>'Video','attributedTo'=>$author,'url'=>$id,'published'=>$v['publishedAt']??$v['createdAt']??'',
            'content'=>htmlspecialchars((string)($v['name']??'Video')."\n\n".(string)($v['description']??''),ENT_QUOTES,'UTF-8'),
            'attachment'=>[],'to'=>[Federation::PUBLIC],'sensitive'=>!empty($v['nsfw']),'_browser_preview'=>Remote::absolute((string)($v['thumbnailPath']??''),'https://'.$domain.'/')],
            'profile'=>['id'=>$author,'name'=>$c['displayName']??$c['name']??'Channel','preferredUsername'=>$c['name']??'']];
    }
    private function lemmyPost(array $v,string $domain):?array{
        $p=$v['post']??[];$a=$v['creator']??[];
        if(!empty($p['deleted'])||!empty($p['removed'])||!empty($v['community']['deleted'])||!empty($v['community']['removed']))return null;
        $id=Remote::href($p['ap_id']??'');$author=Remote::href($a['actor_id']??$a['ap_id']??'');if(!$id||!$author)return null;
        $photos=[];$url=Remote::href($p['url']??'');
        if($url&&(str_starts_with($p['url_content_type']??'','image/')||preg_match('/\.(?:jpe?g|png|webp|gif)(?:\?|$)/i',$url)))$photos[]=['type'=>'Image','mediaType'=>'image/jpeg','url'=>$url,'name'=>$p['alt_text']??$p['name']??''];
        return ['post'=>['id'=>$id,'type'=>'Page','attributedTo'=>$author,'url'=>$id,'published'=>$p['published']??$p['published_at']??'',
            'content'=>htmlspecialchars((string)($p['name']??'Post')."\n\n".(string)($p['body']??'').($url?"\n\n".$url:''),ENT_QUOTES,'UTF-8'),
            'attachment'=>$photos,'to'=>[Federation::PUBLIC],'sensitive'=>!empty($p['nsfw']),'_browser_preview'=>Remote::href($p['thumbnail_url']??'')],
            'profile'=>['id'=>$author,'name'=>$a['display_name']??$a['name']??'Someone','preferredUsername'=>$a['name']??'','icon'=>['url'=>Remote::href($a['avatar']??'')]]];
    }
    private function result(array $rows,string $domain,string $family,array $people,string $cursor,bool $photos,?array $actor=null):array{
        $posts=[];$all=[];
        foreach(array_slice($rows,0,40) as $row){
            if(!is_array($row))continue;
            $doc=match($family){'misskey'=>$this->misskeyPost($row,$domain),'peertube'=>$this->videoPost($row,$domain),'lemmy'=>$this->lemmyPost($row,$domain),default=>$this->app->browser->mastodonDocument($row)};
            if(!$doc)continue;$p=$this->app->browser->remember($doc['post'],$doc['profile']);$all[]=$p;
            if(!$photos||$p['photos'])$posts[]=$p;
        }
        $autoAll=$family==='peertube'||($family==='lemmy'&&$photos&&!$posts&&$all);if($autoAll)$posts=$all;
        $r=['posts'=>$posts,'cursor'=>$cursor,'source'=>$domain,'listing'=>match($family){'misskey'=>'Public notes','peertube'=>'Public videos','lemmy'=>'Public discussions',default=>'Public posts'},'people'=>$people,'family'=>$family,'photos_applied'=>!$autoAll,'photos_supported'=>$family!=='peertube'];
        if($actor)$r['actor']=$actor;return $r;
    }
    public function server(string $domain,string $family,string $cursor='',bool $photos=true,string $tag=''):array{
        if($tag!=='')throw new \InvalidArgumentException('Open a profile or clear the hashtag to browse this platform.');
        if($cursor!==''&&!preg_match('/^[a-zA-Z0-9_-]{1,100}$/',$cursor))throw new \InvalidArgumentException('Invalid page cursor.');
        $people=[];$next='';$notice='';
        if($family==='misskey'){
            $q=['limit'=>24,'withFiles'=>$photos];if($cursor)$q['untilId']=$cursor;
            try{$rows=$this->api($domain,'/api/notes/local-timeline',$q);}
            catch(RemoteError $e){if($cursor!==''||!in_array($e->status,[401,403,422],true))throw $e;try{$rows=$this->api($domain,'/api/notes/featured',['limit'=>24]);}catch(\Throwable){$rows=[];}$notice='Public highlights and people. This server restricts its complete live timeline.';}
            if($cursor==='')try{$people=$this->people($this->api($domain,'/api/users',['limit'=>12,'origin'=>'local','sort'=>'+updatedAt']),$domain,$family);}catch(\Throwable){}
            if(count($rows)===24)$next=(string)(end($rows)['id']??'');
        }elseif($family==='peertube'){
            $offset=$cursor===''?0:(int)$cursor;
            $d=$this->api($domain,'/api/v1/videos?'.http_build_query(['count'=>24,'start'=>$offset,'sort'=>'-publishedAt','isLocal'=>'true']));$rows=$d['data']??[];
            if($cursor==='')try{$d2=$this->api($domain,'/api/v1/video-channels?count=12&start=0');$people=$this->people($d2['data']??[],$domain,$family);}catch(\Throwable){}
            if($offset+count($rows)<($d['total']??0))$next=(string)($offset+count($rows));
        }elseif($family==='lemmy'){
            $page=max(1,(int)$cursor);$d=$this->api($domain,'/api/v3/post/list?'.http_build_query(['type_'=>'Local','sort'=>'Active','limit'=>24,'page'=>$page]));$rows=$d['posts']??[];
            if($cursor==='')try{$d2=$this->api($domain,'/api/v3/community/list?type_=Local&sort=Active&limit=12');$people=$this->people($d2['communities']??[],$domain,$family);}catch(\Throwable){}
            if(count($rows)===24)$next=(string)($page+1);
        }else throw new \InvalidArgumentException('Open a public profile or collection on this server.');
        $result=$this->result($rows,$domain,$family,$people,$notice!==''?'':$next,$photos);
        if($notice!==''){$result['notice']=$notice;$result['listing']='Public highlights';}return $result;
    }
    public function account(string $domain,string $id,string $family='mastodon',string $cursor='',bool $photos=true):array{
        if(!preg_match('/^[a-zA-Z0-9_-]{1,100}$/',$id)||($cursor!==''&&!preg_match('/^[a-zA-Z0-9_-]{1,100}$/',$cursor)))throw new \InvalidArgumentException('Invalid public profile or page.');
        $next='';
        if($family==='misskey'){
            $a=$this->api($domain,'/api/users/show',['userId'=>$id]);$q=['userId'=>$id,'limit'=>24,'withFiles'=>$photos];if($cursor)$q['untilId']=$cursor;
            $rows=$this->api($domain,'/api/users/notes',$q);$actorId=Remote::href($a['uri']??'')?:'https://'.$domain.'/users/'.$id;
        }elseif($family==='peertube'){
            $a=$this->api($domain,'/api/v1/video-channels/'.rawurlencode($id));$offset=$cursor===''?0:(int)$cursor;
            $d=$this->api($domain,'/api/v1/video-channels/'.rawurlencode($id).'/videos?count=24&start='.$offset);$rows=$d['data']??[];$actorId=Remote::href($a['url']??'');
            if($offset+count($rows)<($d['total']??0))$next=(string)($offset+count($rows));
        }elseif($family==='lemmy'){
            $d=$this->api($domain,'/api/v3/community?id='.$id);$a=$d['community_view']['community']??[];$actorId=Remote::href($a['actor_id']??$a['ap_id']??'');
            $page=max(1,(int)$cursor);$d=$this->api($domain,'/api/v3/post/list?'.http_build_query(['community_id'=>$id,'sort'=>'Active','limit'=>24,'page'=>$page]));$rows=$d['posts']??[];
            if(count($rows)===24)$next=(string)($page+1);
        }else{
            $family='mastodon';$a=$this->api($domain,'/api/v1/accounts/'.rawurlencode($id));$actorId=Remote::href($a['uri']??$a['url']??'');
            $q=['limit'=>24,'only_media'=>$photos?'true':'false','exclude_reblogs'=>'true'];if($cursor)$q['max_id']=$cursor;
            $rows=$this->api($domain,'/api/v1/accounts/'.rawurlencode($id).'/statuses?'.http_build_query($q));
        }
        if(!$actorId||!is_array($rows)||!array_is_list($rows))throw new RemoteError(502,'This public profile could not be read.');
        if($next===''&&in_array($family,['mastodon','misskey'],true)&&count($rows)===24)$next=(string)(end($rows)['id']??'');
        $actor=['id'=>$actorId,'name'=>Browser::plain((string)($a['display_name']??$a['displayName']??$a['title']??$a['name']??$a['username']??'Profile')),
            'handle'=>(string)($a['acct']??$a['username']??$a['name']??'').'@'.$domain,'bio'=>Browser::plain((string)($a['note']??$a['description']??'')),
            'following'=>$this->app->store->get('follows',$actorId)['state']??null];
        return $this->result($rows,$domain,$family,[],$next,$photos,$actor);
    }
}
