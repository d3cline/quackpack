<?php
// CLI-only: the release can be extracted beside a running app before updating it.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (PHP_VERSION_ID < 80200) { fwrite(STDERR,"Run this updater with php85.\n"); exit(1); }
require __DIR__.'/app/bootstrap.php';
try {
    $result=Pup\Updater::run(__DIR__,array_slice($argv,1));
    echo Pup\App::json($result),PHP_EOL;
    echo "Updated. Existing identity, photos and database retained.\n";
    echo "Worker: ",escapeshellarg(PHP_BINARY),' ',escapeshellarg($result['cli'])," work --seconds=45 --limit=200\n";
} catch (Throwable $e) { fwrite(STDERR,'Quackpack: '.$e->getMessage().PHP_EOL); exit(1); }
