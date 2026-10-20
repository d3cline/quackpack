<?php
declare(strict_types=1);
// Compatibility entry point for existing installations served from public/.
$path=parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH)?:'/';
if(preg_match('~^/assets/([a-zA-Z0-9_-]+\.(css|js|png|svg))$~',$path,$m)){
    $file=dirname(__DIR__).'/assets/'.$m[1];
    if(!is_file($file)){http_response_code(404);exit;}
    $types=['css'=>'text/css','js'=>'application/javascript','png'=>'image/png','svg'=>'image/svg+xml'];
    header('Content-Type: '.$types[$m[2]]);header('X-Content-Type-Options: nosniff');
    header('Cache-Control: public, max-age=3600');readfile($file);exit;
}
require dirname(__DIR__).'/index.php';
