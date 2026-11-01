<?php
declare(strict_types=1);

// Only for php -S 127.0.0.1:8765 -t public public/router.php.
$path=parse_url($_SERVER['REQUEST_URI'] ?? '/',PHP_URL_PATH);
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header("Content-Security-Policy: default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self' data:; font-src 'self'; connect-src 'self'; form-action 'self'; base-uri 'none'; object-src 'none'; frame-ancestors 'none'");
if ($path==='/api/index.php') { require __DIR__.'/api/index.php'; return true; }
if ($path==='/') $path='/index.html';
$decoded=is_string($path)?rawurldecode($path):'';
$file=$decoded!=='' && !str_contains($decoded,"\0") ? realpath(__DIR__.$decoded) : false;
$root=realpath(__DIR__);
if ($file!==false && is_file($file) && str_starts_with($file,$root.DIRECTORY_SEPARATOR) && preg_match('/\.(?:html|css|js|svg|png|jpg|ico|woff2)$/i',$file) && !preg_match('~(?:^|[/\\\\])\.~',$decoded)) {
    $types=['html'=>'text/html; charset=utf-8','css'=>'text/css; charset=utf-8','js'=>'text/javascript; charset=utf-8','svg'=>'image/svg+xml','png'=>'image/png','jpg'=>'image/jpeg','ico'=>'image/x-icon','woff2'=>'font/woff2'];
    header('Content-Type: '.$types[strtolower(pathinfo($file,PATHINFO_EXTENSION))]);
    header('Cache-Control: no-cache'); readfile($file); return true;
}
http_response_code(404); header('Content-Type: text/plain; charset=utf-8'); echo 'Not found'; return true;
