<?php
declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Pup\\')) {
        $path = __DIR__ . '/' . substr($class, 4) . '.php';
        if (is_file($path)) require $path;
    }
});
date_default_timezone_set('UTC');

function pup_private_dir(): string {
    $accountHome=getenv('HOME');
    if((!$accountHome||!str_starts_with($accountHome,'/'))&&function_exists('posix_getpwuid')){
        $account=posix_getpwuid(posix_geteuid());$accountHome=$account['dir']??null;
    }
    if(!$accountHome||!str_starts_with($accountHome,'/'))throw new RuntimeException('Cannot locate your home directory. Supply --data-dir=/home/USER/quackpack.');
    return rtrim($accountHome,'/').'/quackpack';
}

function pup_config_file(?string $fallbackDirectory=null): string {
    if(defined('QUACKPACK_CONFIG_FILE'))return QUACKPACK_CONFIG_FILE;
    if ($override=getenv('PUP_CONFIG')) return $override;
    $pointer=dirname(__DIR__).'/quackpack-config.php';
    if(is_file($pointer)){
        $file=require $pointer;
        if(!is_string($file)||!str_starts_with($file,'/'))throw new RuntimeException('Invalid Quackpack config location.');
        return $file;
    }
    // Preserve installations using the original public/ document root.
    $legacy=dirname(__DIR__).'/var/config.php';
    return is_file($legacy)?$legacy:($fallbackDirectory??pup_private_dir()).'/config.php';
}

function pup_path_within(string $path,string $directory): bool {
    return $path===$directory||str_starts_with($path,rtrim($directory,'/').'/');
}

function pup(?array $config = null): Pup\App {
    if ($config === null) {
        $file = pup_config_file();
        if (!is_file($file)) throw new RuntimeException('Install Quackpack with php bin/quackpack install first.');
        $config = require $file;
    }
    return new Pup\App($config);
}
