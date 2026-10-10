<?php
/* ============================================================================
   api/foto.php — guardar y borrar las fotos de la web
   ----------------------------------------------------------------------------
   Lo que hace, en corto:

     · POST accion=subir  → guarda `fichero` en uploads/<tipo>/<id>-<aleatorio>.webp
                            y devuelve su URL. El nombre lo pone el servidor: el
                            que llega del navegador no se usa nunca (así no se
                            puede escribir «../» ni un .php disfrazado de foto).
     · POST accion=borrar → borra una URL /uploads/... concreta, comprobando que
                            el archivo está DE VERDAD dentro de uploads/ (realpath).
     · GET               → {ok:true} para que el navegador sepa que el endpoint
                            existe; si no responde, la web guarda la foto en el
                            navegador y avisa de que no hay servidor.

   Límites: 8 MB por archivo, solo imágenes raster (jpeg, png, webp, gif) y el
   tipo tiene que ser `perfil` o `quedada`. El contenido se verifica leyendo el
   archivo (finfo), no el `Content-Type` que diga el navegador.
   ========================================================================== */

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

$RAIZ = realpath(__DIR__ . '/..');            // la raíz del sitio (www/)
$DEST = $RAIZ . '/uploads';
$LIMITE = 8 * 1024 * 1024;
$PERMITIDOS = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp',
    'image/gif' => 'gif',
];

function salir($datos, $codigo = 200)
{
    http_response_code($codigo);
    echo json_encode($datos, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function limpiar_id($id)
{
    $id = preg_replace('/[^0-9a-zA-Z_-]/', '', (string)$id);
    return $id === '' ? 'anonimo' : substr($id, 0, 40);
}

/* --- GET: ¿existe el endpoint? (lo usa lib/fotos.js para decidir) ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    salir(['ok' => true, 'servicio' => 'fotos', 'max_bytes' => $LIMITE]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    salir(['ok' => false, 'error' => 'metodo_no_permitido'], 405);
}

$accion = $_POST['accion'] ?? '';
$tipo = $_POST['tipo'] ?? '';
if (!in_array($tipo, ['perfil', 'quedada'], true)) {
    salir(['ok' => false, 'error' => 'tipo_no_permitido'], 400);
}
$id = limpiar_id($_POST['id'] ?? '');
$carpeta = $DEST . '/' . $tipo;
if (!is_dir($carpeta) && !mkdir($carpeta, 0755, true)) {
    salir(['ok' => false, 'error' => 'sin_carpeta'], 500);
}

if ($accion === 'subir') {
    if (!isset($_FILES['fichero']) || $_FILES['fichero']['error'] !== UPLOAD_ERR_OK) {
        salir(['ok' => false, 'error' => 'sin_fichero'], 400);
    }
    $f = $_FILES['fichero'];
    if ($f['size'] > $LIMITE) {
        salir(['ok' => false, 'error' => 'demasiado_grande'], 413);
    }
    $info = @getimagesize($f['tmp_name']);
    $mime = $info['mime'] ?? '';
    if (!isset($PERMITIDOS[$mime])) {
        salir(['ok' => false, 'error' => 'no_es_imagen'], 415);
    }
    $ext = $PERMITIDOS[$mime];
    $nombre = $id . '-' . bin2hex(random_bytes(6)) . '.' . $ext;
    $ruta = $carpeta . '/' . $nombre;
    if (!move_uploaded_file($f['tmp_name'], $ruta)) {
        salir(['ok' => false, 'error' => 'no_guardado'], 500);
    }
    @chmod($ruta, 0644);
    salir([
        'ok' => true,
        'url' => '/uploads/' . $tipo . '/' . $nombre,
        'bytes' => filesize($ruta),
        'ancho' => $info[0] ?? 0,
        'alto' => $info[1] ?? 0,
    ]);
}

if ($accion === 'borrar') {
    $url = (string)($_POST['url'] ?? '');
    if (!preg_match('#^/uploads/(perfil|quedada)/[A-Za-z0-9._-]+$#', $url)) {
        salir(['ok' => false, 'error' => 'url_no_permitida'], 400);
    }
    $objetivo = realpath($RAIZ . $url);
    $permitido = realpath($DEST);
    if ($objetivo === false || $permitido === false || strpos($objetivo, $permitido) !== 0) {
        salir(['ok' => false, 'error' => 'fuera_de_uploads'], 400);
    }
    if (!is_file($objetivo)) {
        salir(['ok' => false, 'error' => 'no_existe'], 404);
    }
    salir(['ok' => @unlink($objetivo), 'url' => $url]);
}

salir(['ok' => false, 'error' => 'accion_desconocida'], 400);
