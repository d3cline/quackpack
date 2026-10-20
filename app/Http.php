<?php
declare(strict_types=1);
namespace Pup;

final class RemoteError extends \RuntimeException {
    public function __construct(public int $status, string $message, public int $retryAfter=0, public bool $authenticationRequired=false) { parent::__construct($message); }
}

final class Http {
    private ?\Closure $transport;
    public function __construct(private App $app,?callable $transport=null) {$this->transport=$transport?\Closure::fromCallable($transport):null;}
    public static function responseError(int $status,string $url,string $body,array $headers=[]): RemoteError {
        $data=json_decode($body,true);$detail=is_array($data)&&is_string($data['error']??null)?$data['error']:'';
        $detail=mb_substr(trim(preg_replace('/[\x00-\x1f\x7f]/u',' ',strip_tags($detail))??''),0,250);
        $auth=$status===401||($status===422&&preg_match('/requires? an? authenticated user|authentication (?:is )?required/i',$detail));
        $host=(string)parse_url($url,PHP_URL_HOST);$path=(string)parse_url($url,PHP_URL_PATH);
        $message=$host.' returned HTTP '.$status.' for '.$path.'.';
        if($auth)$message.=' This server requires a read access token from an account on '.$host.'. Add it in the saved place, or open a public @person@'.$host.' instead.';
        elseif($detail!=='')$message.=' '.$detail;
        elseif($status===403)$message.=' This server has restricted access.';
        elseif($status===429)$message.=' This server asked us to slow down.';
        $retry=$headers['retry-after']??'0';
        $seconds=preg_match('/^[0-9]+$/',$retry)?(int)$retry:max(0,(int)strtotime($retry)-time());
        if($status===429&&isset($headers['x-ratelimit-reset'])){
            $reset=$headers['x-ratelimit-reset'];$resetAt=preg_match('/^[0-9]+$/',$reset)?(int)$reset:(int)strtotime($reset);
            $seconds=max($seconds,max(0,$resetAt-time()));
        }
        return new RemoteError($status,$message,min(86400,$seconds),(bool)$auth);
    }
    public static function validUrl(string $url): bool {
        $p=parse_url($url);
        return strlen($url)<=4096 && $p && ($p['scheme']??'')==='https' && !empty($p['host']) && empty($p['user']) && empty($p['pass'])
            && (!isset($p['port']) || $p['port']===443) && !preg_match('/[\x00-\x20\x7f]/',$url);
    }
    public static function publicIp(string $ip): bool {
        if (!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)) return false;
        if (str_contains($ip,':')) {
            // Reject transition/mapped addresses, multicast, documentation, and local translation ranges.
            $packed=inet_pton($ip);
            return $packed!==false && (ord($packed[0])&0xe0)===0x20 && !str_starts_with(strtolower($ip),'2001:db8:') && !str_starts_with(strtolower($ip),'2002:');
        }
        $n=ip2long($ip);
        foreach([['100.64.0.0',10],['198.18.0.0',15],['192.0.0.0',24],['192.0.2.0',24],['198.51.100.0',24],['203.0.113.0',24]] as [$network,$bits])
            if(($n & (-1 << (32-$bits)))===(ip2long($network)&(-1 << (32-$bits))))return false;
        return !preg_match('/^(0|127|22[4-9]|23[0-9]|24[0-9]|25[0-5])\./',$ip);
    }
    private function addresses(string $host): array {
        $host=trim($host,'[]');
        if (filter_var($host,FILTER_VALIDATE_IP)) $ips=[$host];
        else {
            if (!preg_match('/^(?=.{1,253}$)[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?$/i',$host) || !str_contains($host,'.')) throw new \InvalidArgumentException('Use a public internet domain.');
            $rr=@dns_get_record($host,DNS_A|DNS_AAAA);
            $ips=[]; foreach ($rr?:[] as $r) if(isset($r['ip'])||isset($r['ipv6'])) $ips[]=$r['ip']??$r['ipv6'];
        }
        if (!$ips) throw new RemoteError(0,'The server could not be found.');
        foreach ($ips as $ip) if (!self::publicIp($ip)) throw new \InvalidArgumentException('Local and reserved network addresses are not allowed.');
        return array_values(array_unique($ips));
    }
    public function request(string $url,string $method='GET',?string $body=null,array $extra=[],bool $signed=true,int $maxBytes=2097152,int $redirects=3,int $timeout=15): array {
        if (!self::validUrl($url)) throw new \InvalidArgumentException('Only public HTTPS URLs on port 443 are supported.');
        $p=parse_url($url); $host=$p['host'];
        if ($this->app->store->get('blocks',strtolower($host))) throw new RemoteError(403,'You have blocked this server.');
        $headers=['Accept'=>'application/activity+json, application/ld+json; profile="https://www.w3.org/ns/activitystreams", application/json',
            'User-Agent'=>'Quackpack/0.5.3 (+'.$this->app->url('/').')'];
        if ($signed) $headers += $this->signatureHeaders($method,$url,$body);
        $headers=array_merge($headers,$extra);
        if ($body!==null&&!isset($headers['Content-Type'])) $headers['Content-Type']='application/activity+json';
        $lines=[]; foreach ($headers as $k=>$v) {
            if (preg_match('/[\r\n]/',$k.$v)) throw new \InvalidArgumentException('Invalid HTTP header.');
            $lines[]=$k.': '.$v;
        }
        $responseHeaders=[]; $response=''; $tooLarge=false;
        if($this->transport){
            $r=($this->transport)($url,$method,$body,$headers);
            $response=(string)$r['body'];$status=(int)$r['status'];$responseHeaders=array_change_key_case($r['headers']??[],CASE_LOWER);
            if(strlen($response)>$maxBytes)throw new RemoteError(413,'The remote response is too large.');
        }else{
        $ips=$this->addresses($host);
        $c=curl_init($url);
        curl_setopt_array($c,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$lines,
            CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>min(5,max(1,$timeout)),CURLOPT_TIMEOUT=>max(1,$timeout),
            CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_PROXY=>'',CURLOPT_RESOLVE=>[$host.':443:'.(str_contains($ips[0],':')?'['.$ips[0].']':$ips[0])],
            CURLOPT_HEADERFUNCTION=>static function($c,$line)use(&$responseHeaders){
                if(str_contains($line,':')) {[$k,$v]=explode(':',$line,2);$responseHeaders[strtolower(trim($k))]=trim($v);} return strlen($line);
            },CURLOPT_WRITEFUNCTION=>static function($c,$chunk)use(&$response,&$tooLarge,$maxBytes){
                if (strlen($response)+strlen($chunk)>$maxBytes) {$tooLarge=true;return 0;} $response.=$chunk;return strlen($chunk);
            }]);
        if ($body!==null) curl_setopt($c,CURLOPT_POSTFIELDS,$body);
        $ok=curl_exec($c);$status=(int)curl_getinfo($c,CURLINFO_RESPONSE_CODE);$error=curl_error($c);curl_close($c);
        if ($tooLarge) throw new RemoteError(413,'The remote response is too large.');
        if ($ok===false) throw new RemoteError(0,'Could not reach the remote server: '.$error);
        }
        if ($status>=300 && $status<400 && isset($responseHeaders['location'])) {
            if ($method!=='GET'||$redirects<=0) throw new RemoteError($status,'The server redirected this request.');
            $next=$responseHeaders['location'];
            if (str_starts_with($next,'/')&&!str_starts_with($next,'//')) $next='https://'.$host.$next;
            // Never forward a server token across a redirect, including same-host redirects.
            $nextHeaders=isset($extra['Accept'])?['Accept'=>$extra['Accept']]:[];
            return $this->request($next,'GET',null,$nextHeaders,$signed,$maxBytes,$redirects-1,$timeout);
        }
        if ($status<200||$status>=300) {
            throw self::responseError($status,$url,$response,$responseHeaders);
        }
        return ['body'=>$response,'headers'=>$responseHeaders,'status'=>$status];
    }
    public function getJson(string $url,bool $signed=true,array $headers=[]): array {
        $r=$this->request($url,'GET',null,$headers,$signed);
        $data=json_decode($r['body'],true,64,JSON_THROW_ON_ERROR);
        if (!is_array($data)) throw new RemoteError(502,'The server did not return a JSON object.');
        return $data;
    }
    public function signatureHeaders(string $method,string $url,?string $body=null): array {
        $p=parse_url($url);$path=($p['path']??'/').(isset($p['query'])?'?'.$p['query']:'');
        $h=['Host'=>$p['host'],'Date'=>gmdate('D, d M Y H:i:s').' GMT'];
        $parts=['(request-target): '.strtolower($method).' '.$path,'host: '.$h['Host'],'date: '.$h['Date']];
        $names='(request-target) host date';
        if ($body!==null) {$h['Digest']='SHA-256='.base64_encode(hash('sha256',$body,true));$parts[]='digest: '.$h['Digest'];$names.=' digest';}
        if (!openssl_sign(implode("\n",$parts),$sig,$this->app->config['private_key'],OPENSSL_ALGO_SHA256)) throw new \RuntimeException('Signing failed.');
        $h['Signature']='keyId="'.$this->app->actorId().'#main-key",algorithm="rsa-sha256",headers="'.$names.'",signature="'.base64_encode($sig).'"';
        return $h;
    }
}
