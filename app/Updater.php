<?php
declare(strict_types=1);
namespace Pup;

final class Updater {
    public static function locate(string $source,string $root,?string $explicit=null): string {
        if($explicit!==null){
            $file=realpath($explicit);
            if(!$file||!self::isConfig($file))throw new \RuntimeException('The selected file is not an existing Quackpack configuration.');
            return $file;
        }
        $home=getenv('HOME')?:'';
        $dirs=array_unique([$root,$source,dirname($source),$home.'/quackpack',$home.'/.quackpack',$home.'/.config/quackpack']);
        $direct=[];$fallback=[];
        foreach($dirs as $dir){
            // Read generated locators as data; never execute an installed web entrypoint.
            foreach(['/index.php','/public/index.php','/quackpack.php','/quackpack-config.php'] as $name){
                $path=$dir.$name;if(!is_file($path)||filesize($path)>262144)continue;
                $text=file_get_contents($path);
                $literal="('(?:[^'\\\\]|\\\\.)*'|\"(?:[^\"\\\\]|\\\\.)*\")";
                $pattern=$name==='/quackpack-config.php'?'~return\s+'.$literal.'\s*;~':'~define\s*\(\s*[\'\"]QUACKPACK_CONFIG_FILE[\'\"]\s*,\s*'.$literal.'\s*\)~';
                if(preg_match($pattern,$text,$m)){
                    $value=self::literal($m[1]);
                    if($value!==null&&str_starts_with($value,'/')&&self::isConfig($value))$direct[]=realpath($value);
                }
            }
            foreach(['/config.php','/var/config.php','/data/config.php','/.quackpack/config.php'] as $name){
                $file=$dir.$name;if(self::isConfig($file))$fallback[]=realpath($file);
            }
        }
        $matches=array_values(array_unique($direct?:$fallback));
        if(count($matches)===1)return $matches[0];
        if(!$matches)throw new \RuntimeException('No existing Quackpack configuration was found. No data or keys were changed. Supply --config=/absolute/path/to/your/existing/config.php if it is stored elsewhere.');
        throw new \RuntimeException('Several existing Quackpack configurations were found. Select the intended one with --config=/absolute/path/to/config.php. No data or keys were changed.');
    }
    private static function literal(string $literal): ?string {
        if($literal[0]==="'")return str_replace(["\\'","\\\\"],["'","\\"],substr($literal,1,-1));
        // Generated paths use single quotes. Accept only unescaped plain double-quoted paths.
        $value=substr($literal,1,-1);
        return str_contains($value,'$')||str_contains($value,'\\')?null:$value;
    }
    private static function isConfig(string $file): bool {
        if(!is_file($file)||!is_readable($file)||filesize($file)>262144)return false;
        $text=file_get_contents($file);
        foreach(['base_url','username','password_hash','private_key','public_key','data_dir','dsn'] as $key){
            if(!preg_match('~[\'\"]'.preg_quote($key,'~').'[\'\"]\s*=>~',$text))return false;
        }
        return (bool)preg_match('~\breturn\s+(?:\[|array\s*\()~',$text);
    }
    public static function run(string $source,array $args): array {
        $opts=[];
        foreach($args as $arg){
            if(!preg_match('~^--(config|docroot)=(.+)$~s',$arg,$m))throw new \InvalidArgumentException('Use update.php [--docroot=/absolute/app/path] [--config=/absolute/existing/config.php].');
            $opts[$m[1]]=$m[2];
        }
        $root=realpath($opts['docroot']??getcwd());
        if(!$root||!is_writable($root))throw new \RuntimeException('The application directory must already exist and be writable.');
        if(is_dir($root.'/.git'))throw new \RuntimeException('Keep the Git checkout private and select the existing application document root.');
        $config=self::locate($source,$root,$opts['config']??(getenv('PUP_CONFIG')?:null));
        $c=require $config;
        if(!is_array($c)||($c['routing']??'pretty')!=='query')throw new \RuntimeException('This updater preserves existing HOME/query identities. Legacy pretty-URL installations require their existing deployment layout. No identity was changed.');
        $configuredRoot=realpath((string)($c['document_root']??''));
        if(!$configuredRoot||$configuredRoot!==$root)throw new \RuntimeException('Run the updater from the configured application directory, or pass --docroot='.(string)($c['document_root']??'/absolute/app/path').'. No files were changed.');
        $data=realpath((string)$c['data_dir']);
        if(!$data||\pup_path_within($data,$root)||\pup_path_within($root,$data)||\pup_path_within($config,$root))throw new \RuntimeException('Existing private data and configuration must be outside the document root. No identity was changed.');
        foreach(['pdo','curl','openssl','gd','mbstring','fileinfo'] as $ext)if(!extension_loaded($ext))throw new \RuntimeException('Missing PHP extension: '.$ext);
        if(!is_dir($data.'/media'))throw new \RuntimeException('The configured media directory is missing; restore the existing data directory before updating.');
        if(str_starts_with((string)$c['dsn'],'sqlite:')&&!is_file(substr($c['dsn'],7)))throw new \RuntimeException('The existing SQLite database is missing. Restore it before updating; an empty replacement was not created.');
        if(!is_dir($data.'/sessions')&&!mkdir($data.'/sessions',0700))throw new \RuntimeException('Cannot restore the session directory.');
        $app=new App($c);$app->store->migrate();$app->fed->recover();
        $runtime=Installer::deploy($source,$root,$data,$config,true);
        return ['updated'=>true,'version'=>'0.5.3','actor'=>$app->actorId(),'document_root'=>$root,'runtime'=>$runtime,'cli'=>$root.'/quackpack.php',
            'cron'=>'* * * * * '.escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/quackpack.php').' work --seconds=45 --limit=50'];
    }
}
