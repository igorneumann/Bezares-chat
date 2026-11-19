<?php
/** Punto de entrada único de chat.bezares.com */
require dirname(__DIR__) . '/app/bootstrap.php';
enforce_https();
security_headers();

$path = parse_url(isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/', PHP_URL_PATH);
$method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper($_SERVER['REQUEST_METHOD']) : 'GET';

if (preg_match('#^/api(/.*)?$#', $path, $m)) {
    require dirname(__DIR__) . '/app/api.php';
    Api::handle($method, isset($m[1]) ? $m[1] : '/');
}

if (preg_match('#^/admin(/.*)?$#', $path) || $path === '/' || $path === '/index.php') {
    require dirname(__DIR__) . '/app/admin.php';
    Admin::handle($method);
}

http_response_code(404);
echo 'No encontrado';
