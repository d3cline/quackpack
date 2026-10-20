<?php
declare(strict_types=1);
if(PHP_VERSION_ID<80200){http_response_code(503);header('Content-Type: text/plain; charset=utf-8');echo 'Quackpack needs web PHP 8.2+. Select a supported PHP version for this application in your hosting panel.';exit;}
require __DIR__.'/app/bootstrap.php';
try { (new Pup\Web(pup()))->run(); }
catch(Throwable $e) {error_log('Quackpack startup: '.$e->getMessage());http_response_code(503);header('Content-Type: text/plain; charset=utf-8');echo "Quackpack is not configured. Run php bin/quackpack install from the application directory.\n";}
