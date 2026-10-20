<?php
declare(strict_types=1);
namespace Pup;

/** Bounded, signed ActivityPub discovery and unsigned public platform APIs. */
final class Remote {
    private ?float $deadline=null;
    private int $remaining=12;
    public const OBJECTS=['Note','Article','Image','Page','Video','Audio','Event','Question'];
    public const ACTORS=['Person','Service','Application','Group','Organization'];
    public function __construct(private App $app){}
    public function begin():void{$this->deadline=microtime(true)+18;$this->remaining=12;}
    public function end():void{$this->deadline=null;}
    private function request(string $url,string $method='GET',?array $payload=null,bool $signed=false,array $headers=[]):array{
        $seconds=$this->deadline===null?5:min(5,(int)floor($this->deadline-microtime(true)));
        if($this->deadline!==null&&(--$this->remaining<0||$seconds<1))throw new RemoteError(504,'This server is taking a while. Open a profile or try again.');
        return $this->app->http->request($url,$method,$payload===null?null:App::json($payload),$headers,$signed,2097152,1,max(1,$seconds));
    }
    public function json(string $url,string $method='GET',?array $payload=null,array $headers=[]):array{
        $headers=['Accept'=>'application/json','Content-Type'=>'application/json']+$headers;
        $r=$this->request($url,$method,$payload,false,$headers);$data=json_decode($r['body'],true,64,JSON_THROW_ON_ERROR);
        if(!is_array($data))throw new RemoteError(502,'The server returned no readable public data.');
        return $data;
    }
    public static function absolute(string $ref,string $base):string{
        $ref=html_entity_decode(trim($ref),ENT_QUOTES|ENT_HTML5,'UTF-8');if($ref==='')return '';
        if(Http::validUrl($ref))return $ref;
        $b=parse_url($base);if(!$b||str_starts_with($ref,'//')||preg_match('~^[a-z][a-z0-9+.-]*:~i',$ref))return '';
        if(str_starts_with($ref,'?')){$url='https://'.$b['host'].($b['path']??'/').$ref;return Http::validUrl($url)?$url:'';}
        $path=str_starts_with($ref,'/')?$ref:rtrim(dirname($b['path']??'/'),'/').'/'.$ref;
        $pieces=[];foreach(explode('/',$path) as $p){if($p==='..')array_pop($pieces);elseif($p!=='.'&&$p!=='')$pieces[]=$p;}
        $url='https://'.$b['host'].'/'.implode('/',$pieces);return Http::validUrl($url)?$url:'';
    }
    public function document(string $url,int $links=2):array{
        $r=$this->request($url,'GET',null,true,['Accept'=>'application/activity+json, application/ld+json; profile="https://www.w3.org/ns/activitystreams", text/html;q=0.1']);
        $data=json_decode($r['body'],true,64);
        if(is_array($data)&&isset($data['type']))return $data;
        if($links<1)throw new RemoteError(502,'No ActivityPub representation was advertised by this page.');
        $candidates=[];$header=$r['headers']['link']??'';
        preg_match_all('~<([^>]+)>\s*;([^,]+)~',$header,$matches,PREG_SET_ORDER);
        foreach($matches as $m)if(preg_match('~rel\s*=\s*"?alternate~i',$m[2])&&preg_match('~application/(?:activity|ld)\+json~i',$m[2]))$candidates[]=$m[1];
        // Only inspect link elements; remote scripts/HTML are never executed or rendered.
        preg_match_all('~<link\b[^>]{0,4096}>~i',substr($r['body'],0,262144),$tags);
        foreach($tags[0] as $tag){
            preg_match_all('~([a-zA-Z-]+)\s*=\s*(["\x27])(.*?)\2~s',$tag,$attrs,PREG_SET_ORDER);$a=[];
            foreach($attrs as $attr)$a[strtolower($attr[1])]=$attr[3];
            if(preg_match('/\balternate\b/i',$a['rel']??'')&&preg_match('~^application/(?:activity|ld)\+json~i',$a['type']??''))$candidates[]=$a['href']??'';
        }
        foreach($candidates as $candidate){$next=self::absolute($candidate,$url);if($next!==''&&$next!==$url)return $this->document($next,$links-1);}
        throw new RemoteError(404,'This page does not advertise an ActivityPub profile, post or collection.');
    }
    public function software(string $domain):string{
        $key='software:'.$domain;$cached=$this->app->store->get('cache',$key);
        if($cached&&$cached['expires']>time())return $cached['name'];
        $name='';
        try{
            $d=$this->json('https://'.$domain.'/.well-known/nodeinfo');
            foreach($d['links']??[] as $link){
                $url=$link['href']??'';
                if(is_string($url)&&Http::validUrl($url)&&strtolower((string)parse_url($url,PHP_URL_HOST))===$domain&&str_contains($link['rel']??'','nodeinfo.diaspora.software/ns/schema/')){
                    $node=$this->json($url);$name=mb_substr(strtolower((string)($node['software']['name']??'')),0,50);break;
                }
            }
        }catch(\Throwable){}
        $this->app->store->put('cache',$key,['name'=>$name,'expires'=>time()+($name!==''?21600:300)]);return $name;
    }
    public static function href(mixed $value):string{
        if(is_string($value))return Http::validUrl($value)?$value:'';
        if(!is_array($value))return '';
        if(array_is_list($value)){foreach($value as $v){$url=self::href($v);if($url!=='')return $url;}return '';}
        return self::href($value['href']??$value['id']??$value['url']??'');
    }
    public function post(array $post,?array $audience=null,?string $trustedAuthor=null):?array{
        if(!in_array($post['type']??'',self::OBJECTS,true))return null;
        $id=self::href($post['id']??'');$author=self::href($post['attributedTo']??'');
        if(!$id||!$author||($trustedAuthor!==$author&&parse_url($id,PHP_URL_HOST)!==parse_url($author,PHP_URL_HOST)))return null;
        if(!isset($post['to'])&&!isset($post['cc'])&&$audience)$post+=array_intersect_key($audience,array_flip(['to','cc']));
        if(!Federation::isPublic($post))return null;
        if(!empty($post['inReplyTo'])&&!Federation::hasImage($post))return null;
        $post=array_intersect_key($post,array_flip(['@context','id','type','attributedTo','url','published','updated','name','content','summary','sensitive','attachment','inReplyTo','to','cc','source','image','icon','startTime','location','oneOf','anyOf','mediaType']));
        $post['id']=$id;$post['attributedTo']=$author;
        $post['url']=self::href($post['url']??'')?:$id;
        $text=Browser::plain((string)($post['content']??''));$name=Browser::plain((string)($post['name']??''));
        if($name!==''&&!str_contains($text,$name))$text=$name.($text!==''?"\n\n".$text:'');
        if($post['type']==='Event'&&!empty($post['startTime']))$text.="\n\nStarts: ".Browser::plain((string)$post['startTime']);
        if($post['type']==='Question'){$options=[];foreach($post['oneOf']??$post['anyOf']??[] as $option)if(is_array($option)&&is_string($option['name']??null))$options[]=Browser::plain($option['name']);if($options)$text.="\n\n".implode(' · ',$options);}
        if($text==='')$text=$post['type']==='Question'?'A public poll':$post['type'];
        $post['content']=htmlspecialchars(mb_substr($text,0,15000),ENT_QUOTES,'UTF-8');
        $attachments=$post['attachment']??[];if(!is_array($attachments))$attachments=[];elseif(!array_is_list($attachments))$attachments=[$attachments];
        $images=in_array($post['type'],['Video','Audio'],true)?[]:($post['image']??[]);if(is_string($images)||!array_is_list((array)$images))$images=[$images];
        foreach($images as $image){$url=self::href($image);if($url!=='')$attachments[]=['type'=>'Image','mediaType'=>is_array($image)?($image['mediaType']??'image/jpeg'):'image/jpeg','url'=>$url,'name'=>is_array($image)?($image['name']??''):''];}
        foreach($attachments as &$a){if(!is_array($a))continue;$a['url']=self::href($a['url']??'');if(($a['type']??'')==='Image')$a['mediaType']??='image/jpeg';}unset($a);
        $post['attachment']=array_slice(array_values(array_filter($attachments,'is_array')),0,10);
        if($post['type']==='Image')$post['mediaType']??='image/jpeg';
        if(in_array($post['type'],['Video','Audio'],true))$post['_browser_preview']=self::href($post['icon']??$post['image']??'');
        return $post;
    }
}
