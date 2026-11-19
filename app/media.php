<?php
/** Adjuntos (imágenes, audio, vídeo, documentos) en public/uploads/ */
class Media
{
    const EXT = array(
        'jpg' => 'image', 'jpeg' => 'image', 'png' => 'image', 'gif' => 'image', 'webp' => 'image',
        'mp3' => 'audio', 'ogg' => 'audio', 'oga' => 'audio', 'opus' => 'audio', 'm4a' => 'audio', 'aac' => 'audio', 'wav' => 'audio', 'weba' => 'audio', 'amr' => 'audio',
        'mp4' => 'video', '3gp' => 'video', 'mov' => 'video', 'webm' => 'video',
        'pdf' => 'file', 'doc' => 'file', 'docx' => 'file', 'xls' => 'file', 'xlsx' => 'file', 'txt' => 'file',
    );

    const MIME_EXT = array(
        'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp',
        'audio/mpeg' => 'mp3', 'audio/ogg' => 'ogg', 'audio/opus' => 'opus', 'audio/mp4' => 'm4a', 'audio/aac' => 'aac',
        'audio/wav' => 'wav', 'audio/x-wav' => 'wav', 'audio/webm' => 'weba', 'audio/amr' => 'amr',
        'video/mp4' => 'mp4', 'video/3gpp' => '3gp', 'video/quicktime' => 'mov', 'video/webm' => 'webm',
        'application/pdf' => 'pdf', 'text/plain' => 'txt',
    );

    public static function dir()
    {
        return BZC_ROOT . '/public/uploads';
    }

    public static function base_url()
    {
        return rtrim(cfg('base_url'), '/') . '/uploads';
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

    public static function store_bytes($bytes, $ext, $name = '')
    {
        $ext = strtolower($ext);
        $type = self::type_for_ext($ext);
        if (!$type) {
            throw new ApiError('Tipo de archivo no permitido');
        }
        if (strlen($bytes) > max(1, (int) Settings::get('max_upload_mb')) * 1048576) {
            throw new ApiError('Archivo demasiado grande');
        }
        $sub = date('Y/m');
        $dir = self::dir() . '/' . $sub;
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            throw new ApiError('No se pudo crear la carpeta de adjuntos', 500);
        }
        $file = $sub . '/' . random_token(12) . '.' . $ext;
        if (file_put_contents(self::dir() . '/' . $file, $bytes) === false) {
            throw new ApiError('No se pudo guardar el archivo', 500);
        }
        $name = $name !== '' ? preg_replace('/[^\w.\- ]+/u', '_', basename($name)) : basename($file);
        return array('url' => self::base_url() . '/' . $file, 'type' => $type, 'name' => mb_substr($name, 0, 150));
    }

    public static function store_upload($f)
    {
        if (!$f || !isset($f['tmp_name']) || !is_uploaded_file($f['tmp_name'])) {
            throw new ApiError('No se recibió ningún archivo');
        }
        if (!empty($f['error'])) {
            throw new ApiError('Error al subir el archivo (' . (int) $f['error'] . ')');
        }
        $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        if (!self::type_for_ext($ext)) {
            $ext = self::ext_for_mime(isset($f['type']) ? $f['type'] : '');
        }
        if (!$ext) {
            throw new ApiError('Tipo de archivo no permitido');
        }
        if (self::type_for_ext($ext) === 'image' && !@getimagesize($f['tmp_name'])) {
            throw new ApiError('La imagen no es válida');
        }
        return self::store_bytes(file_get_contents($f['tmp_name']), $ext, $f['name']);
    }

    /** Descarga una URL remota y la guarda. Devuelve null si falla. */
    /** Solo se descargan adjuntos de servidores de Meta (evita SSRF y no envía el token a terceros). */
    const REMOTE_HOSTS = array('fbcdn.net', 'fbsbx.com', 'facebook.com', 'whatsapp.net', 'whatsapp.com', 'cdninstagram.com');

    public static function allowed_remote($url)
    {
        $p = parse_url((string) $url);
        if (!$p || strtolower($p['scheme'] ?? '') !== 'https' || empty($p['host']) || isset($p['user']) || isset($p['port'])) {
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

    public static function store_remote($url, array $headers = array(), $mime = '', $name = '')
    {
        if (!self::allowed_remote($url)) {
            log_error('Descarga bloqueada (host no permitido): ' . $url);
            return null;
        }
        $r = Http::request('GET', $url, $headers, null, 40);
        if ($r['status'] !== 200) {
            log_error('Descarga de medio fallida ' . $r['status'] . ' ' . $url);
            return null;
        }
        $ext = self::ext_for_mime($mime ?: (isset($r['headers']['content-type']) ? $r['headers']['content-type'] : ''));
        if (!$ext) {
            $pe = strtolower(pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
            $ext = self::type_for_ext($pe) ? $pe : null;
        }
        if (!$ext) {
            return null;
        }
        try {
            return self::store_bytes($r['body'], $ext, $name);
        } catch (ApiError $e) {
            log_error($e->getMessage());
            return null;
        }
    }

    public static function delete_by_url($url)
    {
        $base = self::base_url() . '/';
        if ($url && strpos($url, $base) === 0) {
            $rel = substr($url, strlen($base));
            if (strpos($rel, '..') === false) {
                @unlink(self::dir() . '/' . $rel);
            }
        }
    }
}
