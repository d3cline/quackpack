<?php
declare(strict_types=1);
namespace Pup;

final class Installer {
    private const PRIVATE_DIRS=['app','views','bin','deploy','docs','tests','public'];
    private const PRIVATE_FILES=['README.md','VALIDATION.txt','LICENSE','composer.json','.gitignore'];
    private const DEFAULT_INI="upload_max_filesize = 15M\npost_max_size = 160M\nmemory_limit = 256M\nmax_file_uploads = 10\nexpose_php = Off\ndisplay_errors = Off\n";

    public static function documentRoot(string $source,?string $requested): string {
        $root=$requested??$source;
        if(!str_starts_with($root,'/'))throw new \InvalidArgumentException('--docroot must be an absolute directory.');
        if(!is_dir($root)&&!mkdir($root,0755,true))throw new \RuntimeException('Cannot create the document root.');
        $root=realpath($root);
        if(!$root||!is_writable($root))throw new \RuntimeException('The document root must be writable.');
        if(is_dir($root.'/.git'))throw new \RuntimeException('Keep the Git checkout private and use --docroot=/absolute/app/path.');
        if($root!==$source&&file_exists($root.'/index.php'))throw new \RuntimeException('The document root already has index.php. Choose an empty app directory.');
        return $root;
    }

    public static function deploy(string $source,string $root,string $data,string $configFile,bool $repair=false): string {
        $runtime=$data.'/runtime';
        if(is_link($runtime)||(!$repair&&file_exists($runtime)))throw new \RuntimeException('The private runtime destination already exists. It was left intact.');
        $stage=$data.'/.runtime-'.bin2hex(random_bytes(8));
        if(!mkdir($stage,0700))throw new \RuntimeException('Cannot create the private runtime.');
        foreach(self::PRIVATE_DIRS as $name)if(is_dir($source.'/'.$name))self::copyTree($source.'/'.$name,$stage.'/'.$name,0700,0600);
        foreach(['quackpack','pup'] as $cli)if(is_file($stage.'/bin/'.$cli))chmod($stage.'/bin/'.$cli,0700);
        foreach(self::PRIVATE_FILES as $name)if(is_file($source.'/'.$name))self::copyFile($source.'/'.$name,$stage.'/'.$name,0600);
        // Keep a private copy of the public assets for future repairs and deployments.
        self::copyTree($source.'/assets',$stage.'/assets',0700,0600);
        self::copyFile($source.'/index.php',$stage.'/index.php',0600);
        // File-manager copies and shell globs often omit dotfiles. Generate safe
        // defaults instead of making a hidden file a prerequisite for deployment.
        $ini=is_file($source.'/.user.ini')?file_get_contents($source.'/.user.ini'):self::DEFAULT_INI;
        self::write($stage.'/.user.ini',$ini,0600);
        $locator="<?php\n// Private configuration location. No web server environment is needed.\nreturn ".var_export($configFile,true).";\n";
        self::write($stage.'/quackpack-config.php',$locator,0600);

        $backup=null;
        if(file_exists($runtime)){
            $backup=$data.'/runtime-backup-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(4));
            if(!rename($runtime,$backup))throw new \RuntimeException('Cannot preserve the previous private runtime.');
        }
        if(!rename($stage,$runtime)){
            if($backup)rename($backup,$runtime);
            throw new \RuntimeException('Cannot activate the staged runtime.');
        }

        if($source!==$root){
            self::copyTree($source.'/assets',$root.'/assets',0755,0644);
        }
        if(!is_file($root.'/.user.ini'))self::write($root.'/.user.ini',$ini,0644);
        $entry="<?php\ndeclare(strict_types=1);\n";
        $entry.="if (PHP_VERSION_ID < 80200) { http_response_code(503); header('Content-Type: text/plain; charset=utf-8'); echo 'Quackpack needs web PHP 8.2+. Select a supported PHP version for this application in your hosting panel.'; exit; }\n";
        $entry.="define('QUACKPACK_CONFIG_FILE', ".var_export($configFile,true).");\n";
        $entry.="try {\n    require ".var_export($runtime.'/app/bootstrap.php',true).";\n    (new Pup\\Web(pup()))->run();\n";
        $entry.="} catch (Throwable \$e) {\n    error_log('Quackpack startup: '.\$e->getMessage());\n    http_response_code(503);\n    header('Content-Type: text/plain; charset=utf-8');\n    echo \"Quackpack is temporarily unavailable.\\n\";\n}\n";
        self::write($root.'/index.php',$entry,0644);
        if(is_link($root.'/.well-known'))throw new \RuntimeException('Discovery directories must not be symlinks.');
        foreach(['webfinger','nodeinfo'] as $endpoint){
            $directory=$root.'/.well-known/'.$endpoint;
            if(is_link($directory))throw new \RuntimeException('Discovery directories must not be symlinks.');
            if(!is_dir($directory)&&!mkdir($directory,0755,true))throw new \RuntimeException('Cannot create discovery directories.');
            chmod($root.'/.well-known',0755);chmod($directory,0755);
            self::write($directory.'/index.php',"<?php\ndeclare(strict_types=1);\ndefine('QUACKPACK_ROUTE', '/.well-known/".$endpoint."');\nrequire dirname(__DIR__, 2).'/index.php';\n",0644);
        }
        // No deny rules are needed: the installed web tree contains no private source.
        // Only release-owned paths are removed; unrelated files are never touched.
        if($root===$source){
            foreach(self::PRIVATE_DIRS as $name)if(is_dir($source.'/'.$name))self::removeTree($source.'/'.$name);
            foreach(self::PRIVATE_FILES as $name)if(is_file($source.'/'.$name))unlink($source.'/'.$name);
            if(is_file($source.'/var/.gitkeep'))unlink($source.'/var/.gitkeep');
            if(is_dir($source.'/var')&&count(scandir($source.'/var'))===2)rmdir($source.'/var');
            if(is_file($source.'/quackpack-config.php'))unlink($source.'/quackpack-config.php');
        }
        return $runtime;
    }

    private static function copyTree(string $source,string $target,int $dirMode,int $fileMode): void {
        if(is_link($source)||is_link($target))throw new \RuntimeException('Release directories must not be symlinks.');
        if(!is_dir($target)&&!mkdir($target,$dirMode,true))throw new \RuntimeException('Cannot create deployment directory.');
        chmod($target,$dirMode);
        foreach(new \FilesystemIterator($source,\FilesystemIterator::SKIP_DOTS) as $file){
            if($file->isLink())throw new \RuntimeException('Release files must not be symlinks.');
            $next=$target.'/'.$file->getFilename();
            if($file->isDir())self::copyTree($file->getPathname(),$next,$dirMode,$fileMode);
            else self::copyFile($file->getPathname(),$next,$fileMode);
        }
    }
    private static function copyFile(string $source,string $target,int $mode): void {
        if(is_link($source)||is_link($target)||!copy($source,$target))throw new \RuntimeException('Cannot copy deployment file.');
        chmod($target,$mode);
    }
    private static function write(string $path,string $contents,int $mode): void {
        if(is_link($path))throw new \RuntimeException('Deployment files must not be symlinks.');
        $temporary=$path.'.'.bin2hex(random_bytes(6));
        if(file_put_contents($temporary,$contents,LOCK_EX)===false)throw new \RuntimeException('Cannot write deployment file.');
        chmod($temporary,$mode);
        if(!rename($temporary,$path))throw new \RuntimeException('Cannot activate deployment file.');
    }
    private static function removeTree(string $directory): void {
        foreach(new \FilesystemIterator($directory,\FilesystemIterator::SKIP_DOTS) as $file){
            if($file->isDir()&&!$file->isLink())self::removeTree($file->getPathname());
            else unlink($file->getPathname());
        }
        rmdir($directory);
    }
}
