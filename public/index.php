<?php
/** Punto de entrada único de chat.bezares.com */
require dirname(__DIR__) . '/app/bootstrap.php';
enforce_https();
try {
    DB::ensure_schema();
} catch (PDOException $e) {
    log_error('BD: ' . $e->getMessage()); // el panel muestra el aviso de conexión
}
security_headers();

$path = parse_url(isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/', PHP_URL_PATH);
$method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper($_SERVER['REQUEST_METHOD']) : 'GET';

// Adjuntos: solo con enlace firmado y vigente (los archivos están fuera de la carpeta pública).
if (preg_match('#^/media/(\d{4}/\d{2}/[A-Za-z0-9]{24}\.[a-z0-9]{2,5})$#', $path, $m)) {
    Media::serve($m[1]);
}

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
