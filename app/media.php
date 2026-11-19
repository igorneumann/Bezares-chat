<?php
/** Adjuntos (imágenes, audio, vídeo, documentos) en public/uploads/ */
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

    /**
     * Comprueba que el contenido corresponde de verdad a la extensión (firma del archivo),
     * para que nadie suba, por ejemplo, una página HTML o un ejecutable renombrado como .pdf.
     */
    private static function content_matches($bytes, $ext)
    {
        $head = substr($bytes, 0, 8);
        $zip = "PK\x03\x04";
        $ole = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1";
        switch ($ext) {
            case 'pdf':
                return strpos(substr($bytes, 0, 1024), '%PDF-') !== false;
            case 'docx':
            case 'xlsx':
            case 'pptx':
            case 'odt':
            case 'ods':
            case 'odp':
                return strncmp($head, $zip, 4) === 0;
            case 'doc':
            case 'xls':
            case 'ppt':
                return $head === $ole;
            case 'rtf':
                return strncmp($bytes, '{\\rtf', 5) === 0;
            case 'txt':
            case 'csv':
                return strpos(substr($bytes, 0, 65536), "\0") === false; // texto, sin binarios
            default:
                return true; // imagen/audio/vídeo: se validan aparte o son inocuos al servirse con nosniff
        }
    }

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
            throw new ApiError('Archivo demasiado grande (máximo ' . (int) Settings::get('max_upload_mb') . ' MB)');
        }
        if (!self::content_matches($bytes, $ext)) {
            throw new ApiError('El contenido del archivo no corresponde a un .' . $ext . ' válido');
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

    /** Descarga una URL remota (de Meta) y la guarda. Devuelve null si falla. */
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
        // Si el tipo MIME no lo aclara, se usa la extensión del nombre original o de la URL.
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
