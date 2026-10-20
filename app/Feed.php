<?php
declare(strict_types=1);
namespace Pup;

/** Ranked owner-only discovery, with stable pagination while the crawler runs. */
final class Feed {
    public function __construct(private App $app){}
    private static function photo(array $p): bool {
        if(($p['type']??'')==='Image')return true;
        foreach((array)($p['attachment']??[]) as $a)if(is_array($a)&&str_starts_with((string)($a['mediaType']??''),'image/'))return true;
        return false;
    }
    private function allowed(array $p): bool {
        $s=$this->app->store;$id=$p['id']??'';$actor=$p['attributedTo']??'';
        if(!is_string($id)||!is_string($actor)||!is_string($p['content']??'')||!Federation::isPublic($p)||$s->get('discovery_deleted',$id))return false;
        if(isset($p['_discovery'])&&strtolower((string)parse_url($id,PHP_URL_HOST))!==strtolower((string)($p['_discovery']['source']??'')))return false;
        return !$s->get('blocks',strtolower((string)parse_url($actor,PHP_URL_HOST)))&&!$s->get('blocks',$p['_discovery']['source']??'');
    }
    public function page(string $cursor='',string $mode='mix',bool $photos=true): array {
        $s=$this->app->store;
        if(!in_array($mode,['mix','newest'],true))throw new \InvalidArgumentException('Choose Mix or Newest.');
        if($cursor===''){
            $now=time();$candidates=[];$seen=[];$followed=[];$affinity=[];$served=[];
            foreach($s->query('SELECT ref FROM records WHERE bucket=? AND updated>? ORDER BY updated DESC LIMIT 5000',['feed_seen',$now-86400])->fetchAll() as $row)$served[$row['ref']]=true;
            foreach($s->each('follows') as $f)if(($f['state']??'')==='accepted'){
                $followed[$f['id']]=true;$alias=$f['actor']['url']??null;if(is_string($alias)&&Http::validUrl($alias))$followed[$alias]=true;
            }
            foreach(['saved','likes'] as $bucket)foreach($s->page($bucket,0,200) as $signal){
                $p=$bucket==='saved'?$signal:($s->get('discovery_posts',$signal['id'])??$s->get('remote',$signal['id']));
                if(isset($p['attributedTo'])&&is_string($p['attributedTo'])){
                    $actor=$p['attributedTo'];$affinity[$actor]=min(8,($affinity[$actor]??0)+2);
                    $alias=$s->get('actors',$actor)['url']??null;if(is_string($alias)&&Http::validUrl($alias))$affinity[$alias]=$affinity[$actor];
                }
            }
            $rows=$s->query('SELECT data FROM records WHERE bucket=? ORDER BY created DESC,id DESC LIMIT 3000',['discovery_posts'])->fetchAll();
            $raw=array_merge(array_map(static fn($r)=>json_decode($r['data'],true,64,JSON_THROW_ON_ERROR),$rows),$s->page('timeline',0,500));
            foreach($raw as $post){
                if(!$this->allowed($post)||($photos&&!self::photo($post)))continue;
                $id=$post['id'];if(isset($seen[$id]))continue;$seen[$id]=true;
                $published=strtotime((string)($post['published']??''))?:0;
                if($published<$now-7*86400||$published>$now+300)continue;
                $actor=$post['attributedTo'];$host=strtolower((string)parse_url($actor,PHP_URL_HOST));
                $age=max(0,($now-$published)/3600);
                $score=30*exp(-$age/18)+min(14,2*log(1+max(0,(int)($post['_discovery']['engagement']??0))))+(self::photo($post)?6:0)
                    +(isset($followed[$actor])?5:0)+($affinity[$actor]??0)+(hexdec(substr(hash('sha256',$id.':'.(int)floor($now/3600)),0,4))/65535)*5-(isset($served[$id])?12:0);
                $candidates[]=['id'=>$id,'bucket'=>isset($post['_discovery'])?'discovery_posts':'timeline','score'=>$score,'published'=>$published,'actor'=>$actor,'domain'=>$host];
            }
            usort($candidates,static fn($a,$b)=>($mode==='newest'?($b['published']<=>$a['published']):($b['score']<=>$a['score']))?:strcmp($a['id'],$b['id']));
            $order=[];$authors=[];$domains=[];
            while($candidates&&count($order)<1000){
                if(count($order)%24===0){$authors=[];$domains=[];}
                $pick=0;
                if($mode==='mix'){
                    $best=-INF;
                    // A bounded window mixes sources while retaining the quality ordering.
                    foreach(array_slice($candidates,0,240,true) as $index=>$c){
                        $rank=$c['score']-8*($authors[$c['actor']]??0)-3*($domains[$c['domain']]??0);
                        if($rank>$best){$best=$rank;$pick=$index;}
                    }
                }
                $chosen=$candidates[$pick];array_splice($candidates,$pick,1);
                $authors[$chosen['actor']]=($authors[$chosen['actor']]??0)+1;$domains[$chosen['domain']]=($domains[$chosen['domain']]??0)+1;
                $order[]=['id'=>$chosen['id'],'bucket'=>$chosen['bucket']];
            }
            $token=bin2hex(random_bytes(16));$snapshot=['expires'=>$now+3600,'items'=>$order,'mode'=>$mode,'photos'=>$photos];
            $s->put('cache','feed:'.$token,$snapshot);$offset=0;
        }else{
            if(!preg_match('/^([a-f0-9]{32}):([0-9]{1,5})$/',$cursor,$m))throw new \InvalidArgumentException('Refresh your feed to continue.');
            $token=$m[1];$offset=(int)$m[2];$snapshot=$s->get('cache','feed:'.$token);
            if(!$snapshot||$snapshot['expires']<time())throw new \InvalidArgumentException('This feed view expired. Refresh for a fresh mix.');
            if($snapshot['mode']!==$mode||$snapshot['photos']!==$photos)throw new \InvalidArgumentException('Refresh after changing feed filters.');
        }
        $posts=[];$items=$snapshot['items'];
        while($offset<count($items)&&count($posts)<24){
            $ref=$items[$offset++];$p=$s->get($ref['bucket'],$ref['id']);if(!$p||!$this->allowed($p))continue;
            $n=$this->app->normalize($p);$n['discovery_source']=$p['_discovery']['source']??parse_url($p['attributedTo'],PHP_URL_HOST);$posts[]=$n;
            $s->put('feed_seen',$p['id'],['id'=>$p['id']]);
        }
        return ['posts'=>$posts,'cursor'=>$offset<count($items)?$token.':'.$offset:'','mode'=>$mode,'photos'=>$photos,'snapshot'=>$token];
    }
}
