<?php
/**
 * Adjuntos (imágenes, audio, vídeo, documentos).
 *
 * - Se guardan FUERA de la carpeta pública (storage/uploads/) y solo se sirven con enlaces firmados que caducan
 *   (/media/AAAA/MM/archivo.ext?e=caducidad&s=firma).
 * - En la base de datos se guarda la ruta relativa ("AAAA/MM/archivo.ext"), nunca un enlace permanente.
 * - Las subidas no se cargan en memoria: se comprueba el tamaño antes de leer y se mueve el archivo temporal.
 * - Las descargas de Meta se hacen sin seguir redirecciones automáticamente: cada salto se valida.
 */
class Media
{
    const EXT = array(
        'jpg' => 'image', 'jpeg' => 'image', 'png' => 'image', 'gif' => 'image', 'webp' => 'image',
        'mp3' => 'audio', 'ogg' => 'audio', 'oga' => 'audio', 'opus' => 'audio', 'm4a' => 'audio', 'aac' => 'audio', 'wav' => 'audio', 'weba' => 'audio', 'amr' => 'audio',
        'mp4' => 'video', '3gp' => 'video', 'mov' => 'video', 'webm' => 'video',
        // Documentos: PDF, Word, Excel, PowerPoint, OpenDocument, RTF, texto y CSV
        'pdf' => 'file', 'doc' => 'file', 'docx' => 'file', 'xls' => 'file', 'xlsx' => 'file', 'ppt' => 'file', 'pptx' => 'file',
        'odt' => 'file', 'ods' => 'file', 'odp' => 'file', 'rtf' => 'file', 'txt' => 'file', 'csv' => 'file',
    );

    const MIME_EXT = array(
        'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp',
        'audio/mpeg' => 'mp3', 'audio/ogg' => 'ogg', 'audio/opus' => 'opus', 'audio/mp4' => 'm4a', 'audio/aac' => 'aac',
        'audio/wav' => 'wav', 'audio/x-wav' => 'wav', 'audio/webm' => 'weba', 'audio/amr' => 'amr',
        'video/mp4' => 'mp4', 'video/3gpp' => '3gp', 'video/quicktime' => 'mov', 'video/webm' => 'webm',
        'application/pdf' => 'pdf', 'text/plain' => 'txt', 'text/csv' => 'csv', 'application/rtf' => 'rtf', 'text/rtf' => 'rtf',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.ms-excel' => 'xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'application/vnd.ms-powerpoint' => 'ppt',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
        'application/vnd.oasis.opendocument.text' => 'odt',
        'application/vnd.oasis.opendocument.spreadsheet' => 'ods',
        'application/vnd.oasis.opendocument.presentation' => 'odp',
    );

    /** Tipo MIME con el que se sirve cada extensión. */
    const SERVE_MIME = array(
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp',
        'mp3' => 'audio/mpeg', 'ogg' => 'audio/ogg', 'oga' => 'audio/ogg', 'opus' => 'audio/ogg', 'm4a' => 'audio/mp4', 'aac' => 'audio/aac',
        'wav' => 'audio/wav', 'weba' => 'audio/webm', 'amr' => 'audio/amr',
        'mp4' => 'video/mp4', '3gp' => 'video/3gpp', 'mov' => 'video/quicktime', 'webm' => 'video/webm',
        'pdf' => 'application/pdf', 'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ppt' => 'application/vnd.ms-powerpoint', 'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'odt' => 'application/vnd.oasis.opendocument.text', 'ods' => 'application/vnd.oasis.opendocument.spreadsheet',
        'odp' => 'application/vnd.oasis.opendocument.presentation', 'rtf' => 'application/rtf', 'txt' => 'text/plain; charset=utf-8', 'csv' => 'text/csv; charset=utf-8',
    );

    /** Solo se descargan adjuntos de servidores de Meta (evita SSRF y no envía el token a terceros). */
    const REMOTE_HOSTS = array('fbcdn.net', 'fbsbx.com', 'facebook.com', 'whatsapp.net', 'whatsapp.com', 'cdninstagram.com');

    /** Duración de los enlaces firmados. */
    const TTL_VIEW = 86400;  // 24 h para agentes y visitantes (se renuevan cada vez que se cargan los mensajes)
    const TTL_META = 7200;   // 2 h para que WhatsApp/Messenger descarguen lo que envía un agente

    const REL_RE = '#^\d{4}/\d{2}/[A-Za-z0-9]{24}\.[a-z0-9]{2,5}$#';

    /* ---------------------------------------------------------- rutas */

    public static function dir()
    {
        return BZC_ROOT . '/storage/uploads';
    }

    private static function tmp_dir()
    {
        return BZC_ROOT . '/storage/tmp';
    }

    /** Carpeta y URL antiguas (versiones anteriores guardaban los adjuntos en public/uploads). */
    private static function legacy_dir()
    {
        return BZC_ROOT . '/public/uploads';
    }

    private static function legacy_base()
    {
        return rtrim((string) cfg('base_url'), '/') . '/uploads/';
    }

    private static function ensure_dirs()
    {
        foreach (array(self::dir(), self::tmp_dir()) as $d) {
            if (!is_dir($d) && !mkdir($d, 0750, true)) {
                throw new ApiError('No se pudo crear la carpeta de adjuntos', 500);
            }
        }
        $ht = BZC_ROOT . '/storage/.htaccess';
        if (!file_exists($ht)) {
            @file_put_contents($ht, "Require all denied\n"); // por si el hosting no permite sacar storage/ de la web
        }
    }

    public static function max_bytes()
    {
        return max(1, (int) Settings::get('max_upload_mb')) * 1048576;
    }

    public static function type_for_ext($ext)
    {
        $ext = strtolower((string) $ext);
        return isset(self::EXT[$ext]) ? self::EXT[$ext] : null;
    }

    public static function ext_for_mime($mime)
    {
        $mime = strtolower(trim(explode(';', (string) $mime)[0]));
        return isset(self::MIME_EXT[$mime]) ? self::MIME_EXT[$mime] : null;
    }

    /** Convierte lo guardado en BD (ruta relativa o URL antigua) en ruta relativa válida, o null. */
    public static function rel($stored)
    {
        $stored = (string) $stored;
        $legacy = self::legacy_base();
        if (strpos($stored, $legacy) === 0) {
            $stored = substr($stored, strlen($legacy));
        }
        return preg_match(self::REL_RE, $stored) ? $stored : null;
    }

    private static function file_of($rel)
    {
        foreach (array(self::dir(), self::legacy_dir()) as $d) {
            $p = $d . '/' . $rel;
            if (is_file($p)) {
                return $p;
            }
        }
        return null;
    }

    /* ---------------------------------------------------------- enlaces firmados */

    /** Enlace firmado y con caducidad para ver/descargar un adjunto. */
    public static function url($stored, $ttl = self::TTL_VIEW)
    {
        $rel = self::rel($stored);
        if (!$rel) {
            return '';
        }
        // Caducidad redondeada a la hora: el mismo enlace sirve durante un rato (caché del navegador).
        $exp = (intdiv(time() + $ttl, 3600) + 1) * 3600;
        return rtrim((string) cfg('base_url'), '/') . '/media/' . $rel . '?e=' . $exp . '&s=' . substr(sign('media|' . $rel . '|' . $exp), 0, 40);
    }

    /** Sirve un adjunto si la firma es válida y no ha caducado (admite Range para audio/vídeo). */
    public static function serve($rel)
    {
        $exp = isset($_GET['e']) ? (int) $_GET['e'] : 0;
        $sig = isset($_GET['s']) ? (string) $_GET['s'] : '';
        if (!preg_match(self::REL_RE, $rel) || $exp < time()
            || !hash_equals(substr(sign('media|' . $rel . '|' . $exp), 0, 40), $sig)) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            exit('Enlace no válido o caducado');
        }
        $path = self::file_of($rel);
        if (!$path) {
            http_response_code(404);
            exit('No encontrado');
        }
        $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
        $size = filesize($path);
        header('Content-Type: ' . (self::SERVE_MIME[$ext] ?? 'application/octet-stream'));
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'none'; sandbox");
        header('Cross-Origin-Resource-Policy: cross-origin'); // el widget muestra las imágenes en otras webs
        header('Cache-Control: private, max-age=3600');
        header('Accept-Ranges: bytes');
        if (self::type_for_ext($ext) === 'file' && $ext !== 'pdf') {
            header('Content-Disposition: attachment; filename="documento.' . $ext . '"');
        }
        $start = 0;
        $end = $size - 1;
        if (isset($_SERVER['HTTP_RANGE']) && preg_match('/^bytes=(\d*)-(\d*)$/', $_SERVER['HTTP_RANGE'], $m)) {
            if ($m[1] === '' && $m[2] !== '') {
                $start = max(0, $size - (int) $m[2]);
            } else {
                $start = (int) $m[1];
                if ($m[2] !== '') {
                    $end = min($end, (int) $m[2]);
                }
            }
            if ($start > $end || $start >= $size) {
                http_response_code(416);
                header('Content-Range: bytes */' . $size);
                exit;
            }
            http_response_code(206);
            header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
        }
        header('Content-Length: ' . ($end - $start + 1));
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') {
            exit;
        }
        $fp = fopen($path, 'rb');
        fseek($fp, $start);
        $left = $end - $start + 1;
        while ($left > 0 && !feof($fp)) {
            $chunk = fread($fp, min(65536, $left));
            echo $chunk;
            $left -= strlen($chunk);
            flush();
        }
        fclose($fp);
        exit;
    }

    /* ---------------------------------------------------------- validación del contenido */

    /**
     * Comprueba que el contenido corresponde de verdad a la extensión, leyendo solo la cabecera del archivo
     * (y la estructura interna en los documentos ZIP).
     */
    private static function content_matches($path, $ext)
    {
        $fp = fopen($path, 'rb');
        if (!$fp) {
            return false;
        }
        $head = (string) fread($fp, 65536);
        fclose($fp);
        switch ($ext) {
            case 'pdf':
                return strpos(substr($head, 0, 1024), '%PDF-') !== false;
            case 'docx':
            case 'xlsx':
            case 'pptx':
            case 'odt':
            case 'ods':
            case 'odp':
                return strncmp($head, "PK\x03\x04", 4) === 0 && self::valid_office_zip($path, $ext);
            case 'doc':
            case 'xls':
            case 'ppt':
                return strncmp($head, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1", 8) === 0;
            case 'rtf':
                return strncmp($head, '{\\rtf', 5) === 0;
            case 'txt':
            case 'csv':
                return strpos($head, "\0") === false; // texto, sin binarios
            case 'jpg':
            case 'jpeg':
            case 'png':
            case 'gif':
            case 'webp':
                return @getimagesize($path) !== false;
            default:
                return true; // audio/vídeo: inocuos al servirse con nosniff y CSP sandbox
        }
    }

    /**
     * Documento Office/OpenDocument: debe ser un ZIP con los archivos obligatorios del formato
     * y sin señales de bomba ZIP (demasiadas entradas, tamaño descomprimido o ratio de compresión excesivos).
     */
    private static function valid_office_zip($path, $ext)
    {
        if (!class_exists('ZipArchive')) {
            log_error('Falta la extensión zip de PHP: no se pueden validar documentos ' . $ext);
            return false;
        }
        $required = array(
            'docx' => array('[Content_Types].xml', 'word/document.xml'),
            'xlsx' => array('[Content_Types].xml', 'xl/workbook.xml'),
            'pptx' => array('[Content_Types].xml', 'ppt/presentation.xml'),
            'odt'  => array('mimetype', 'content.xml'),
            'ods'  => array('mimetype', 'content.xml'),
            'odp'  => array('mimetype', 'content.xml'),
        );
        $odfMime = array('odt' => 'application/vnd.oasis.opendocument.text', 'ods' => 'application/vnd.oasis.opendocument.spreadsheet', 'odp' => 'application/vnd.oasis.opendocument.presentation');
        $z = new ZipArchive();
        if ($z->open($path) !== true) {
            return false;
        }
        $ok = true;
        if ($z->numFiles < 1 || $z->numFiles > 5000) {
            $ok = false;
        }
        $total = 0;
        for ($i = 0; $ok && $i < $z->numFiles; $i++) {
            $st = $z->statIndex($i);
            if (!$st) {
                $ok = false;
                break;
            }
            $total += (int) $st['size'];
            $ratio = $st['comp_size'] > 0 ? $st['size'] / $st['comp_size'] : 0;
            if ($total > 300 * 1048576 || ($st['size'] > 1048576 && $ratio > 200)) {
                $ok = false; // posible bomba ZIP
            }
        }
        foreach ($required[$ext] as $name) {
            if ($ok && $z->locateName($name) === false) {
                $ok = false;
            }
        }
        if ($ok && isset($odfMime[$ext]) && trim((string) $z->getFromName('mimetype', 200)) !== $odfMime[$ext]) {
            $ok = false;
        }
        $z->close();
        return $ok;
    }

    /* ---------------------------------------------------------- guardar */

    /** Valida y mueve un archivo ya escrito en disco a su ubicación definitiva. */
    private static function store_file($src, $ext, $name, $uploaded)
    {
        $ext = strtolower($ext);
        $type = self::type_for_ext($ext);
        if (!$type) {
            throw new ApiError('Tipo de archivo no permitido');
        }
        $size = (int) @filesize($src);
        if ($size <= 0) {
            throw new ApiError('Archivo vacío');
        }
        if ($size > self::max_bytes()) {
            throw new ApiError('Archivo demasiado grande (máximo ' . (int) Settings::get('max_upload_mb') . ' MB)');
        }
        if (!self::content_matches($src, $ext)) {
            throw new ApiError('El contenido del archivo no corresponde a un .' . $ext . ' válido');
        }
        self::ensure_dirs();
        $sub = date('Y/m');
        if (!is_dir(self::dir() . '/' . $sub) && !mkdir(self::dir() . '/' . $sub, 0750, true)) {
            throw new ApiError('No se pudo crear la carpeta de adjuntos', 500);
        }
        $rel = $sub . '/' . substr(random_token(12), 0, 24) . '.' . $ext;
        $dest = self::dir() . '/' . $rel;
        $moved = $uploaded ? move_uploaded_file($src, $dest) : rename($src, $dest);
        if (!$moved) {
            throw new ApiError('No se pudo guardar el archivo', 500);
        }
        @chmod($dest, 0640);
        $name = $name !== '' ? preg_replace('/[^\w.\- ]+/u', '_', basename($name)) : basename($rel);
        return array('url' => $rel, 'type' => $type, 'name' => mb_substr($name, 0, 150));
    }

    /** Archivo subido por formulario: el tamaño se comprueba ANTES de leerlo y nunca se carga entero en memoria. */
    public static function store_upload($f)
    {
        if (!$f || !isset($f['tmp_name'], $f['error'])) {
            throw new ApiError('No se recibió ningún archivo');
        }
        if ((int) $f['error'] === UPLOAD_ERR_INI_SIZE || (int) $f['error'] === UPLOAD_ERR_FORM_SIZE) {
            throw new ApiError('Archivo demasiado grande');
        }
        if ((int) $f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
            throw new ApiError('Error al subir el archivo');
        }
        if ((int) $f['size'] > self::max_bytes() || (int) filesize($f['tmp_name']) > self::max_bytes()) {
            throw new ApiError('Archivo demasiado grande (máximo ' . (int) Settings::get('max_upload_mb') . ' MB)');
        }
        $ext = strtolower(pathinfo((string) $f['name'], PATHINFO_EXTENSION));
        if (!self::type_for_ext($ext)) {
            $ext = self::ext_for_mime($f['type'] ?? '');
        }
        if (!$ext) {
            throw new ApiError('Tipo de archivo no permitido');
        }
        return self::store_file($f['tmp_name'], $ext, (string) $f['name'], true);
    }

    /* ---------------------------------------------------------- descargas de Meta (anti-SSRF) */

    /** URL https de un dominio de Meta, sin usuario ni puerto. */
    public static function allowed_remote($url)
    {
        $p = parse_url((string) $url);
        if (!$p || strtolower($p['scheme'] ?? '') !== 'https' || empty($p['host']) || isset($p['user']) || isset($p['pass']) || isset($p['port'])) {
            return false;
        }
        $host = strtolower($p['host']);
        foreach (self::REMOTE_HOSTS as $h) {
            if ($host === $h || substr($host, -strlen('.' . $h)) === '.' . $h) {
                return true;
            }
        }
        return false;
    }

    /** Resuelve el dominio y devuelve una IP pública; null si alguna IP es privada/reservada o no resuelve. */
    private static function public_ip($host)
    {
        $ips = array();
        foreach ((array) @dns_get_record($host, DNS_A | DNS_AAAA) as $r) {
            if (!empty($r['ip'])) {
                $ips[] = $r['ip'];
            }
            if (!empty($r['ipv6'])) {
                $ips[] = $r['ipv6'];
            }
        }
        if (!$ips) {
            $ips = (array) @gethostbynamel($host);
        }
        if (!$ips) {
            return null;
        }
        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return null; // cualquier destino interno invalida el dominio
            }
        }
        return $ips[0];
    }

    /**
     * Descarga un adjunto de Meta. Cada redirección se valida (dominio de Meta + HTTPS + IP pública),
     * la conexión se fija a la IP validada y la cabecera Authorization solo se envía al dominio original.
     * Devuelve null si falla.
     */
    public static function store_remote($url, array $headers = array(), $mime = '', $name = '')
    {
        try {
            self::ensure_dirs();
        } catch (ApiError $e) {
            log_error($e->getMessage());
            return null;
        }
        $origHost = strtolower((string) parse_url($url, PHP_URL_HOST));
        $tmp = tempnam(self::tmp_dir(), 'dl');
        if (!$tmp) {
            log_error('No se pudo crear un archivo temporal para la descarga');
            return null;
        }
        try {
            for ($hop = 0; $hop < 4; $hop++) {
                if (!self::allowed_remote($url)) {
                    log_error('Descarga bloqueada (destino no permitido): ' . $url);
                    return null;
                }
                $host = strtolower((string) parse_url($url, PHP_URL_HOST));
                $ip = self::public_ip($host);
                if (!$ip) {
                    log_error('Descarga bloqueada (IP no pública o sin resolver): ' . $host);
                    return null;
                }
                $hopHeaders = $host === $origHost ? $headers : array_diff_key($headers, array('Authorization' => 1));
                $r = Http::download($url, $hopHeaders, $tmp, self::max_bytes(), $ip);
                if ($r['status'] >= 300 && $r['status'] < 400 && !empty($r['headers']['location'])) {
                    $loc = $r['headers']['location'];
                    if (strpos($loc, '//') === 0) {
                        $loc = 'https:' . $loc;
                    } elseif (!preg_match('#^https?://#i', $loc)) {
                        $loc = 'https://' . $host . '/' . ltrim($loc, '/');
                    }
                    $url = $loc;
                    continue;
                }
                if ($r['status'] !== 200 || $r['error']) {
                    log_error('Descarga de medio fallida ' . $r['status'] . ' ' . $r['error']);
                    return null;
                }
                $ext = self::ext_for_mime($mime ?: ($r['headers']['content-type'] ?? ''));
                foreach (array($name, (string) parse_url($url, PHP_URL_PATH)) as $candidate) {
                    if ($ext) {
                        break;
                    }
                    $pe = strtolower(pathinfo($candidate, PATHINFO_EXTENSION));
                    $ext = self::type_for_ext($pe) ? $pe : null;
                }
                if (!$ext) {
                    return null;
                }
                return self::store_file($tmp, $ext, $name, false);
            }
            log_error('Descarga bloqueada: demasiadas redirecciones');
            return null;
        } catch (ApiError $e) {
            log_error('Adjunto rechazado: ' . $e->getMessage());
            return null;
        } finally {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }

    /* ---------------------------------------------------------- borrar y migrar */

    public static function delete_stored($stored)
    {
        $rel = self::rel($stored);
        if ($rel && ($p = self::file_of($rel))) {
            @unlink($p);
        }
    }

    /**
     * Mueve los adjuntos de versiones anteriores (public/uploads, accesibles sin firma) a storage/uploads
     * y guarda en la BD solo la ruta relativa. Se ejecuta por lotes desde las tareas periódicas.
     */
    /**
     * Procesa un lote de mensajes con id > $afterId. La BD solo se actualiza si el archivo está de verdad en
     * storage/uploads (movido o ya presente); si no se puede mover, se registra y se deja como estaba para reintentar.
     * Mientras tanto el archivo antiguo NO es accesible directamente (public/uploads está bloqueada) y se sigue
     * sirviendo solo por /media/ con enlace firmado.
     * @return array{0:int,1:int} [último id procesado, nº de filas del lote]
     */
    public static function migrate_legacy($afterId = 0)
    {
        $base = self::legacy_base();
        $rows = DB::rows('SELECT id, media_url FROM messages WHERE id > ? AND media_url LIKE ? ORDER BY id LIMIT 200', array((int) $afterId, $base . '%'));
        if (!$rows) {
            return array((int) $afterId, 0);
        }
        self::ensure_dirs();
        $last = (int) $afterId;
        foreach ($rows as $r) {
            $last = (int) $r['id'];
            $rel = self::rel($r['media_url']);
            if (!$rel) {
                log_error('Migración de adjunto: ruta no reconocida en el mensaje ' . $r['id']);
                continue;
            }
            $old = self::legacy_dir() . '/' . $rel;
            $new = self::dir() . '/' . $rel;
            if (is_file($old)) {
                if (!self::move_file($old, $new)) {
                    log_error('Migración de adjunto: no se pudo mover ' . $rel . ' (se reintentará)');
                    continue; // la BD sigue apuntando al archivo antiguo
                }
            } elseif (!is_file($new)) {
                log_error('Migración de adjunto: el archivo ' . $rel . ' ya no existe');
                // No queda ningún archivo que exponer: se normaliza la ruta para no reintentar cada día.
            }
            DB::update('messages', array('media_url' => $rel), array('id' => $r['id']));
        }
        return array($last, count($rows));
    }

    /** Mueve un archivo comprobando el resultado (rename y, si falla, copia + verificación + borrado). */
    private static function move_file($old, $new)
    {
        $dir = dirname($new);
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            return false;
        }
        $size = filesize($old);
        if (is_file($new)) {
            // Ya estaba copiado en un intento anterior: solo se borra el antiguo si es idéntico en tamaño.
            return filesize($new) === $size && @unlink($old);
        }
        if (@rename($old, $new)) {
            clearstatcache(true, $new);
            return is_file($new) && filesize($new) === $size;
        }
        if (@copy($old, $new)) {
            clearstatcache(true, $new);
            if (filesize($new) === $size) {
                @chmod($new, 0640);
                if (!@unlink($old)) {
                    log_error('Migración de adjunto: copiado pero no se pudo borrar el original ' . $old);
                }
                return true;
            }
            @unlink($new); // copia incompleta
        }
        return false;
    }
}
