<?php
declare(strict_types=1);
namespace Pup;

final class App {
    public Store $store;
    public Http $http;
    public Remote $remote;
    public Platforms $platforms;
    public Federation $fed;
    public Browser $browser;
    public Media $media;
    public Discovery $discovery;
    public Feed $feed;
    public function __construct(public array $config) {
        $this->store=new Store($config);
        $this->http=new Http($this);
        $this->remote=new Remote($this);
        $this->platforms=new Platforms($this);
        $this->fed=new Federation($this);
        $this->browser=new Browser($this);
        $this->media=new Media($this);
        $this->discovery=new Discovery($this);
        $this->feed=new Feed($this);
    }
    public static function json(mixed $data): string {
        return json_encode($data,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    }
    public function path(string $path=''): string {
        // Existing identities keep their original URLs. New installs use ordinary PHP URLs.
        if(($this->config['routing']??'pretty')!=='query'||$path==='')return $path;
        $parts=parse_url($path);$route=$parts['path']??'/';
        if($route==='/'||str_starts_with($route,'/assets/')||str_starts_with($route,'/.well-known/'))return $path;
        return '/index.php?route='.rawurlencode($route).(isset($parts['query'])?'&'.$parts['query']:'').(isset($parts['fragment'])?'#'.$parts['fragment']:'');
    }
    public function url(string $path=''): string { return $this->config['base_url'].$this->path($path); }
    public function actorId(): string { return $this->url('/users/'.$this->config['username']); }
    public static function context(): array {
        return ['https://www.w3.org/ns/activitystreams','https://w3id.org/security/v1',[
            'manuallyApprovesFollowers'=>'as:manuallyApprovesFollowers','sensitive'=>'as:sensitive',
            'Hashtag'=>'as:Hashtag','toot'=>'http://joinmastodon.org/ns#','discoverable'=>'toot:discoverable']];
    }
    public function profile(): array {
        return $this->store->get('meta','profile') ?? ['name'=>$this->config['display_name'],'bio'=>'A little corner of the internet.','avatar'=>''];
    }
    public function links(bool $visibleOnly=false): array {
        $links=$this->store->get('meta','links')['items']??[];
        return $visibleOnly?array_values(array_filter($links,static fn($link)=>$link['enabled'])):$links;
    }
    public function saveLinks(mixed $items): array {
        if(!is_array($items)||!array_is_list($items)||count($items)>30)throw new \InvalidArgumentException('Use up to 30 links.');
        $links=[];
        foreach($items as $item){
            if(!is_array($item)||!is_string($item['label']??null)||!is_string($item['url']??null))throw new \InvalidArgumentException('Each link needs a label and an address.');
            $label=trim($item['label']);$url=trim($item['url']);$enabled=$item['enabled']??true;
            if($label===''||mb_strlen($label)>80)throw new \InvalidArgumentException('Link labels must contain 1–80 characters.');
            if(!is_bool($enabled))throw new \InvalidArgumentException('Link visibility must be true or false.');
            if(!self::validLink($url))throw new \InvalidArgumentException('Use a full http://, https://, mailto:, or tel: address.');
            $links[]=['label'=>$label,'url'=>$url,'enabled'=>$enabled];
        }
        $this->store->put('meta','links',['items'=>$links]);return $links;
    }
    private static function validLink(string $url): bool {
        if($url===''||strlen($url)>2048||preg_match('/[\x00-\x20\x7f\\\\]/',$url))return false;
        $scheme=strtolower((string)parse_url($url,PHP_URL_SCHEME));
        if(in_array($scheme,['http','https'],true)){
            $parts=parse_url($url);
            return filter_var($url,FILTER_VALIDATE_URL)!==false&&!empty($parts['host'])&&!isset($parts['user'])&&!isset($parts['pass']);
        }
        if($scheme==='mailto')return filter_var(substr($url,7),FILTER_VALIDATE_EMAIL)!==false;
        if($scheme==='tel')return (bool)preg_match('/^\+?[0-9][0-9().-]{1,30}$/',substr($url,4));
        return false;
    }
    public function actor(): array {
        $p=$this->profile();
        return ['@context'=>self::context(),
            'id'=>$this->actorId(),'type'=>'Person','preferredUsername'=>$this->config['username'],
            'name'=>$p['name'],'summary'=>nl2br(htmlspecialchars($p['bio'],ENT_QUOTES,'UTF-8')),
            'url'=>$this->url('/'),'inbox'=>$this->url('/inbox'),'outbox'=>$this->url('/outbox'),
            'followers'=>$this->url('/followers'),'following'=>$this->url('/following'),
            'manuallyApprovesFollowers'=>false,'discoverable'=>true,
            'icon'=>['type'=>'Image','mediaType'=>$p['avatar'] ? 'image/jpeg':'image/png','url'=>$p['avatar'] ?: $this->url('/assets/quackpack-logo.png')],
            'publicKey'=>['id'=>$this->actorId().'#main-key','owner'=>$this->actorId(),'publicKeyPem'=>$this->config['public_key']]];
    }
    public function post(array $input, array $attachments=[], ?string $edit=null, ?array $replyActor=null): array {
        $caption=trim((string)($input['caption']??''));
        if (mb_strlen($caption)>5000) throw new \InvalidArgumentException('Keep captions under 5,000 characters.');
        $old=$edit ? $this->store->get('posts',$edit) : null;
        if ($edit && !$old) throw new \InvalidArgumentException('That post was not found.');
        if (!$old && !$attachments && empty($input['reply_to'])) throw new \InvalidArgumentException('Choose at least one photo.');
        $id=$old['id'] ?? $this->url('/p/'.bin2hex(random_bytes(12)));
        $post=['@context'=>self::context(),'id'=>$id,'type'=>'Note',
            'attributedTo'=>$this->actorId(),'url'=>$id,'published'=>$old['published']??gmdate('Y-m-d\TH:i:s\Z'),
            'to'=>[Federation::PUBLIC],'cc'=>[$this->url('/followers')],
            'content'=>'<p>'.nl2br(htmlspecialchars($caption,ENT_QUOTES,'UTF-8')).'</p>',
            'source'=>['content'=>$caption,'mediaType'=>'text/plain'],
            'sensitive'=>!empty($input['sensitive']),'summary'=>mb_substr(trim((string)($input['summary']??'')),0,300),
            'attachment'=>$old['attachment']??$attachments,
            'inReplyTo'=>$old['inReplyTo']??($input['reply_to']??null)];
        $tags=[];
        preg_match_all('/(?<![\p{L}\p{N}_])#([\p{L}\p{N}_]{1,64})/u',$caption,$matches);
        foreach (array_unique($matches[1]) as $tag) $tags[]=['type'=>'Hashtag','name'=>'#'.$tag,'href'=>$this->url('/?tag='.rawurlencode($tag))];
        $post['tag']=$tags;
        if ($replyActor && !in_array($replyActor['id'],$post['cc'],true)) $post['cc'][]=$replyActor['id'];
        if ($edit) $post['updated']=gmdate('Y-m-d\TH:i:s\Z');
        $this->store->tx(function () use($post,$edit,$replyActor) {
            $this->store->put('posts',$post['id'],$post,strtotime($post['published']));
            $activity=$this->fed->activity($edit?'Update':'Create',$post);
            if (!$edit) $this->store->put('creates',$post['id'],$activity);
            $this->fed->broadcast($activity);
            if ($replyActor) $this->fed->deliver($activity,$replyActor);
        });
        return $post;
    }
    public function deletePost(string $id): void {
        $post=$this->store->get('posts',$id);
        if (!$post) throw new \InvalidArgumentException('Post not found.');
        $this->store->tx(function () use($id) {
            $tomb=['id'=>$id,'type'=>'Tombstone','formerType'=>'Note','deleted'=>gmdate('Y-m-d\TH:i:s\Z')];
            $this->store->put('tombstones',$id,$tomb);
            $this->fed->broadcast($this->fed->activity('Delete',$tomb));
            $this->store->remove('posts',$id);
        });
        foreach ($post['attachment']??[] as $attachment) {
            $prefix=preg_quote($this->url('/media/'),'~');
            if(preg_match('~^'.$prefix.'([a-f0-9]{40}\.jpg)$~',$attachment['url']??'',$m))@unlink($this->config['data_dir'].'/media/'.$m[1]);
        }
    }
    public function normalize(array $post, ?array $actor=null): array {
        $id=$post['id']??'';
        $actorId=is_string($post['attributedTo']??null)?$post['attributedTo']:'';
        $actor=$actor??$this->store->get('actors',$actorId)??$this->store->get('profiles',$actorId)??[];
        if ($actorId===$this->actorId()) $actor=$this->actor();
        $photos=[];
        $attachments=(array)($post['attachment']??[]);
        if (($post['type']??'')==='Image' && isset($post['url'])) $attachments[]=$post;
        foreach ($attachments as $a) {
            if (!is_array($a)) continue;
            $u=$a['url']??'';
            if (is_array($u)) $u=$u['href']??($u[0]['href']??'');
            if (is_string($u) && Http::validUrl($u) && str_starts_with((string)($a['mediaType']??''),'image/'))
                $photos[]=['url'=>$u,'alt'=>$a['name']??'','width'=>$a['width']??null,'height'=>$a['height']??null];
        }
        return ['id'=>$id,'url'=>is_string($post['url']??null)?$post['url']:$id,'kind'=>$post['type']??'Note','preview'=>Remote::href($post['_browser_preview']??''),
            'actor'=>$actorId,'name'=>$actor['name']??$actor['preferredUsername']??parse_url($actorId,PHP_URL_HOST)??'Someone',
            'handle'=>($actor['preferredUsername']??'').'@'.(parse_url($actorId,PHP_URL_HOST)??''),
            'avatar'=>$actor['icon']['url']??'','content'=>Browser::plain($post['content']??''),
            'caption'=>$post['source']['content']??Browser::plain($post['content']??''),
            'photos'=>$photos,'published'=>$post['published']??'','sensitive'=>!empty($post['sensitive']),
            'summary'=>$post['summary']??'','local'=>$actorId===$this->actorId(),
            'saved'=>$this->store->get('saved',$id)!==null,'liked'=>$this->store->get('likes',$id)!==null,
            'reply_to'=>$post['inReplyTo']??null];
    }
}
