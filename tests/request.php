<?php
// Subprocess HTTP contract harness. It invokes the actual request router without a preview server.
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
$r=json_decode(stream_get_contents(STDIN),true,32,JSON_THROW_ON_ERROR);
$_SERVER=array_merge($_SERVER,['REQUEST_METHOD'=>$r['method']??'GET','REQUEST_URI'=>$r['path']??'/',
    'REMOTE_ADDR'=>'127.0.0.1','HTTP_HOST'=>'photos.example','CONTENT_TYPE'=>'application/x-www-form-urlencoded',
    'HTTP_ACCEPT'=>$r['accept']??'text/html']);
$_GET=$r['get']??[];$_POST=$r['post']??[];$_COOKIE=$r['cookies']??[];
if(isset($r['csrf']))$_SERVER['HTTP_X_CSRF_TOKEN']=$r['csrf'];
register_shutdown_function(function(){fwrite(STDERR,'STATUS:'.(http_response_code()?:200)."\n");});
(new Pup\Web(pup()))->run();
