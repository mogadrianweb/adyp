<?php
/* ============================================================================
   api/auth.php — login, registro, cambio de contraseña y gestión de usuarios
   ----------------------------------------------------------------------------
   Lo que hace, en corto (mismo estilo que foto.php):

     · POST accion=login           → comprueba teléfono + contraseña contra la
                          tabla `usuarios` (hash bcrypt) y devuelve un token de
                          sesión de 7 días. El token vive en `sesiones_token`.
                          Si al usuario le toca cambiar la contraseña por
                          primera vez (clave por defecto), la respuesta lleva
                          `obligar_clave: true` y la web le enseña el
                          formulario UNA sola vez (la bandera se limpia al
                          cambiarla).
     · POST accion=registro        → pública: alta en la BD con estado
                          `pendiente`. Nadie puede entrar hasta que el equipo
                          la revise; al aprobarla se genera una contraseña
                          provisional de 6 dígitos (se enseña una vez al
                          administrador para pasársela por WhatsApp).
     · POST accion=cambiar_clave   → el propio usuario cambia su contraseña
                          comprobando la actual. Al cambiarla, TODOS sus
                          tokens caducan y se le emite uno nuevo (los demás
                          dispositivos se quedan fuera).
     · POST accion=reiniciar_clave → solo administración: da una contraseña
                          temporal (6 dígitos, se enseña una sola vez) a
                          alguien que no recuerda la suya.
     · POST accion=usuarios        → solo administración: lista a los
                          usuarios con sus contadores (quedadas que
                          organizan, inscripciones activas).
     · POST accion=usuario_nuevo   → solo administración: da de alta un
                          usuario a mano (clave opcional: si no se da, se
                          genera una de 6 dígitos y se enseña una vez).
     · POST accion=usuario_guardar → solo administración: edita nombre,
                          teléfono, rol, activo, estado (pendiente/activo/
                          rechazado), fecha de miembro y foto. Aprobar a un
                          pendiente (estado → activo) sin contraseña
                          conocida genera la provisional y la enseña una vez.
                          Opcional: `clave` para darle contraseña a mano.
     · GET                         → {ok:true} para que el navegador sepa que
                          el endpoint existe.

   Reglas de la casa:
     · la contraseña NUNCA viaja ni se guarda en claro: solo su hash bcrypt;
     · cada teléfono se normaliza antes de buscarlo (600 00 00 18, 0034600...,
       34600... y +34600... son el mismo número), con las mismas reglas que
       la web (lib/db.js, normalizarTelefono);
     · los errores no dicen SIEMPRE si el teléfono existe o la clave es mala
       por separado en el reinicio (sí lo dicen en el login: en una comunidad
       cerrada de pocas cuentas, ser claro vale más que esconderlo);
     · la credencial de la base de datos vive FUERA de la carpeta pública
       (/home/adisfrutarypunto/adyp_db.php, no se puede descargar).
   ========================================================================== */

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function salir($datos, $codigo = 200)
{
    http_response_code($codigo);
    echo json_encode($datos, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/* --- conexión a la base de datos (la credencial vive fuera de www/) ------- */
$CFG = null;
try {
    $CFG = require '/home/adisfrutarypunto/adyp_db.php';
} catch (Throwable $e) {
    $CFG = null;
}
if (!$CFG) {
    salir(['ok' => false, 'error' => 'Sin configuración de base de datos. Avísanos.'], 500);
}
try {
    $pdo = new PDO(
        'mysql:host=' . $CFG['host'] . ';dbname=' . $CFG['bd'] . ';charset=utf8mb4',
        $CFG['user'],
        $CFG['pass'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ],
    );
} catch (PDOException $e) {
    salir(['ok' => false, 'error' => 'Sin conexión con la base de datos. Inténtalo en unos segundos.'], 500);
}

/* Mismo formato que lib/db.js → normalizarTelefono(): E.164 (+34...). */
function normalizar_telefono($t)
{
    $s = preg_replace('/[^0-9+]/', '', trim((string)$t));
    if ($s === '') return '';
    if (strncmp($s, '+', 1) === 0) return $s;
    if (strncmp($s, '00', 2) === 0) return '+' . substr($s, 2);
    if (strlen($s) === 9 && preg_match('/^[6789]/', $s) === 1) return '+34' . $s;
    if (strlen($s) === 11 && strncmp($s, '34', 2) === 0) return '+' . $s;
    return $s;
}

function buscar_usuario($pdo, $telefono)
{
    $st = $pdo->prepare(
        'SELECT id, nombre, apellidos, telefono, email, clave_hash, rol, activo, estado, foto,
                membre_desde, cambiar_clave_obligatoria, creado_en, poblacion, tramo, perfil_corto,
                zona, participadas_manual, entradas_manual, ausencias_manual
         FROM usuarios WHERE telefono = ?'
    );
    $st->execute([$telefono]);
    return $st->fetch() ?: null;
}

function buscar_usuario_id($pdo, $id)
{
    $st = $pdo->prepare(
        'SELECT id, nombre, apellidos, telefono, email, clave_hash, rol, activo, estado, foto,
                membre_desde, cambiar_clave_obligatoria, creado_en, poblacion, tramo, perfil_corto,
                zona, participadas_manual, entradas_manual, ausencias_manual
         FROM usuarios WHERE id = ?'
    );
    $st->execute([(int)$id]);
    return $st->fetch() ?: null;
}

/* Ficha en el formato que entiende la web (lib/db.js). */
function appUsuario($u)
{
    return [
        'id' => (int)$u['id'],
        'nombre' => $u['nombre'],
        'apellidos' => isset($u['apellidos']) ? ($u['apellidos'] !== null ? $u['apellidos'] : null) : null,
        'telefono' => $u['telefono'],
        'email' => isset($u['email']) ? ($u['email'] !== null ? $u['email'] : null) : null,
        'rol' => $u['rol'],
        'activo' => (bool)(int)$u['activo'],
        'estado' => $u['estado'],
        'foto' => $u['foto'] !== null ? $u['foto'] : null,
        'membre_desde' => $u['membre_desde'],
        'cambiar_clave_obligatoria' => (bool)(int)$u['cambiar_clave_obligatoria'],
        'creado_en' => $u['creado_en'],
        'motivo_rechazo' => isset($u['motivo_rechazo']) ? ($u['motivo_rechazo'] !== null ? $u['motivo_rechazo'] : null) : null,
        'poblacion' => isset($u['poblacion']) ? ($u['poblacion'] !== null ? $u['poblacion'] : null) : null,
        'tramo' => isset($u['tramo']) ? ($u['tramo'] !== null ? $u['tramo'] : null) : null,
        /* FASE 11 — comarca elegida y contadores de participación a mano
           (NULL = la web cuenta lo real de las inscripciones). Ojo: un NULL de
           la base debe llegar como null, no como 0 (un 0 se leería como cifra
           puesta a mano y machacaría lo real de las inscripciones). */
        'zona' => isset($u['zona']) ? ($u['zona'] !== null ? (int)$u['zona'] : null) : null,
        'participadas_manual' => isset($u['participadas_manual']) ? ($u['participadas_manual'] !== null ? (int)$u['participadas_manual'] : null) : null,
        'entradas_manual' => isset($u['entradas_manual']) ? ($u['entradas_manual'] !== null ? (int)$u['entradas_manual'] : null) : null,
        'ausencias_manual' => isset($u['ausencias_manual']) ? ($u['ausencias_manual'] !== null ? (int)$u['ausencias_manual'] : null) : null,
        'perfil_corto' => isset($u['perfil_corto']) ? ($u['perfil_corto'] !== null ? $u['perfil_corto'] : null) : null,
    ];
}

/* FASE 10 — rastro en la base (tabla `auditoria`, inmutable para la app).
   Best-effort: si el asiento no se puede escribir, la operación NO se rompe;
   se deja constancia en el log del servidor por si acaso. */
function auditar_bd($pdo, $usuarioId, $accion, $detalle)
{
    try {
        $st = $pdo->prepare('INSERT INTO auditoria (usuario_id, accion, detalle) VALUES (?, ?, ?)');
        $st->execute([(int)$usuarioId, (string)$accion, json_encode($detalle, JSON_UNESCAPED_UNICODE)]);
    } catch (Throwable $e) {
        error_log('auditoria: no se pudo registrar ' . $accion . ': ' . $e->getMessage());
    }
}

/* Emite el token de 7 días de un usuario (luego se devolvía en la respuesta). */
function nuevo_token($pdo, $usuarioId)
{
    $token = bin2hex(random_bytes(32));
    $st = $pdo->prepare('INSERT INTO sesiones_token (token, usuario_id, caduca_en) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 7 DAY))');
    $st->execute([$token, $usuarioId]);
    return $token;
}

/* ¿Este token es de un usuario válido y sin caducar? Devuelve la fila o null. */
function usuarioPorToken($pdo, $token)
{
    if (!preg_match('/^[a-f0-9]{64}$/', (string)$token)) return null;
    $st = $pdo->prepare(
        'SELECT u.id, u.nombre, u.telefono, u.clave_hash, u.rol, u.activo,
                u.estado, u.foto, u.membre_desde, u.cambiar_clave_obligatoria
         FROM sesiones_token s JOIN usuarios u ON u.id = s.usuario_id
         WHERE s.token = ? AND s.caduca_en > NOW()'
    );
    $st->execute([(string)$token]);
    return $st->fetch() ?: null;
}

/* Contraseña provisional de 6 dígitos (misma forma que el reinicio). */
function clave_provisional()
{
    return str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    salir(['ok' => true, 'servicio' => 'auth']);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    salir(['ok' => false, 'error' => 'metodo_no_permitido'], 405);
}

$accion = $_POST['accion'] ?? '';

/* ------------------------------- LOGIN ------------------------------------ */
if ($accion === 'login') {
    $tel = normalizar_telefono($_POST['telefono'] ?? '');
    $clave = (string)($_POST['clave'] ?? '');
    if ($tel === '' || $clave === '') {
        salir(['ok' => false, 'error' => 'Faltan el teléfono o la contraseña.'], 400);
    }
    $u = buscar_usuario($pdo, $tel);
    if (!$u) {
        salir(['ok' => false, 'error' => 'No hay ninguna cuenta con ese teléfono en la comunidad.'], 401);
    }
    if ($u['estado'] === 'pendiente') {
        salir(['ok' => false, 'error' => 'Tu registro está pendiente de revisión por el equipo. En cuanto te aprueben, recibirás una contraseña provisional y podrás entrar.'], 403);
    }
    if ($u['estado'] === 'rechazado') {
        salir(['ok' => false, 'error' => 'El equipo no ha aprobado este registro. Si crees que es un error, hablad con la administración.'], 403);
    }
    if ((int)$u['activo'] !== 1) {
        salir(['ok' => false, 'error' => 'Esta cuenta está suspendida. Hablad con la administración.'], 403);
    }
    if (!password_verify($clave, $u['clave_hash'])) {
        salir(['ok' => false, 'error' => 'Esa contraseña no es la de esta cuenta. Prueba otra vez.'], 401);
    }
    $token = nuevo_token($pdo, (int)$u['id']);
    salir([
        'ok' => true,
        'token' => $token,
        'usuario' => appUsuario($u),
        /* La web enseña el cambio de contraseña solo en el primer acceso con
           clave provisional; al cambiarla la bandera se limpia en la BD. */
        'obligar_clave' => (bool)(int)$u['cambiar_clave_obligatoria'],
    ]);
}

/* ------------------------------- REGISTRO --------------------------------- */
if ($accion === 'registro') {
    $nombre = trim((string)($_POST['nombre'] ?? ''));
    $tel = normalizar_telefono($_POST['telefono'] ?? '');
    if (mb_strlen($nombre) < 2 || mb_strlen($nombre) > 60) {
        salir(['ok' => false, 'error' => 'El nombre necesita entre 2 y 60 caracteres.'], 400);
    }
    if ($tel === '' || !preg_match('/^\+\d{8,15}$/', $tel)) {
        salir(['ok' => false, 'error' => 'El teléfono no parece válido: se usa para darte de alta y para las quedadas.'], 400);
    }
    if (buscar_usuario($pdo, $tel)) {
        salir(['ok' => false, 'error' => 'Ya hay una cuenta con ese teléfono en la comunidad: entra con ella o hablad con la administración.'], 409);
    }
    /* La contraseña provisional se genera al APROBAR el registro; hasta
       entonces el hash es un valor aleatorio que nadie conoce (no se puede
       entrar con la cuenta pendiente). */
    $pdo->prepare(
        'INSERT INTO usuarios (nombre, telefono, clave_hash, rol, activo, estado, membre_desde, cambiar_clave_obligatoria)
         VALUES (?, ?, ?, "usuario", 0, "pendiente", CURDATE(), 0)'
    )->execute([$nombre, $tel, password_hash(bin2hex(random_bytes(32)), PASSWORD_BCRYPT, ['cost' => 12])]);
    auditar_bd($pdo, 0, 'auth.registro', ['telefono' => $tel, 'nombre' => $nombre]);
    salir([
        'ok' => true,
        'nota' => 'Estás en la lista. El equipo revisa las altas y, al aprobarte, te pasa por WhatsApp una contraseña provisional para entrar.',
    ]);
}

/* ------------------------ CAMBIO DE CONTRASEÑA ----------------------------- */
if ($accion === 'cambiar_clave') {
    $tel = normalizar_telefono($_POST['telefono'] ?? '');
    $actual = (string)($_POST['clave_actual'] ?? '');
    $nueva = (string)($_POST['clave_nueva'] ?? '');
    if ($tel === '' || $actual === '' || $nueva === '') {
        salir(['ok' => false, 'error' => 'Falta la contraseña actual o la nueva.'], 400);
    }
    if (strlen($nueva) < 4) {
        salir(['ok' => false, 'error' => 'La contraseña nueva necesita al menos 4 caracteres.'], 400);
    }
    if (strlen($nueva) > 72) {
        salir(['ok' => false, 'error' => 'La contraseña nueva es demasiado larga (máximo 72 caracteres).'], 400);
    }
    $u = buscar_usuario($pdo, $tel);
    if (!$u || !password_verify($actual, $u['clave_hash'])) {
        salir(['ok' => false, 'error' => 'La contraseña actual no es la correcta.'], 401);
    }
    $hash = password_hash($nueva, PASSWORD_BCRYPT, ['cost' => 12]);
    $st = $pdo->prepare('UPDATE usuarios SET clave_hash = ?, cambiar_clave_obligatoria = 0 WHERE id = ?');
    $st->execute([$hash, $u['id']]);
    // Quien cambió la contraseña manda: los otros dispositivos se quedan fuera.
    $pdo->prepare('DELETE FROM sesiones_token WHERE usuario_id = ?')->execute([$u['id']]);
    $token = nuevo_token($pdo, (int)$u['id']);
    salir(['ok' => true, 'token' => $token]);
}

/* FASE 11 — el propio usuario guarda su ciudad y su comarca en SU cuenta
   (con su token, como el resto de acciones de sesión; solo toca los dos
   campos que le tocan: nada de roles, estados ni contraseñas). */
if ($accion === 'perfil_guardar') {
    $token = (string)($_POST['token'] ?? '');
    $yo = usuarioPorToken($pdo, $token);
    if (!$yo) salir(['ok' => false, 'error' => 'Tu sesión caducó: vuelve a entrar y repítelo.'], 401);
    $sets = [];
    $val = [];
    $tocados = [];
    if (array_key_exists('poblacion', $_POST)) {
        $pob = trim((string)$_POST['poblacion']);
        if (mb_strlen($pob) > 60) salir(['ok' => false, 'error' => 'La ciudad no pasa de 60 caracteres.'], 400);
        $sets[] = 'poblacion = ?'; $val[] = $pob !== '' ? $pob : null;
        $tocados[] = 'poblacion';
    }
    if (array_key_exists('zona', $_POST)) {
        $z = trim((string)$_POST['zona']);
        if ($z === '') {
            $sets[] = 'zona = NULL';
        } else {
            /* Las mismas 11 comarcas que usa la web (ZONAS en lib/seed.js). */
            if (!in_array((int)$z, [2, 11, 4, 10, 5, 6, 7, 9, 8, 3, 13], true)) {
                salir(['ok' => false, 'error' => 'Esa comarca no existe.'], 400);
            }
            $sets[] = 'zona = ?'; $val[] = (int)$z;
        }
        $tocados[] = 'zona';
    }
    if (!$sets) salir(['ok' => false, 'error' => 'No hay nada que guardar.'], 400);
    $st = $pdo->prepare('UPDATE usuarios SET ' . implode(', ', $sets) . ' WHERE id = ?');
    $val[] = (int)$yo['id'];
    $st->execute($val);
    auditar_bd($pdo, (int)$yo['id'], 'auth.perfil_guardar', ['campos' => $tocados]);
    salir(['ok' => true, 'usuario' => appUsuario(buscar_usuario_id($pdo, (int)$yo['id']))]);
}

/* ------------------- REINICIO POR LA ADMINISTRACIÓN ------------------------ */
if ($accion === 'reiniciar_clave') {
    $token = (string)($_POST['token'] ?? '');
    $objTel = normalizar_telefono($_POST['telefono_objetivo'] ?? '');
    $admin = usuarioPorToken($pdo, $token);
    if (!$admin) {
        salir(['ok' => false, 'error' => 'Tu sesión caducó: vuelve a entrar y repítelo.'], 401);
    }
    if ($admin['rol'] !== 'administrador') {
        salir(['ok' => false, 'error' => 'Esto solo lo puede hacer la administración.'], 403);
    }
    if ($objTel === '') {
        salir(['ok' => false, 'error' => 'Falta el teléfono de la persona.'], 400);
    }
    $obj = buscar_usuario($pdo, $objTel);
    if (!$obj) {
        salir(['ok' => false, 'error' => 'No hay ninguna cuenta con ese teléfono.'], 404);
    }
    $temporal = clave_provisional();
    $st = $pdo->prepare('UPDATE usuarios SET clave_hash = ?, cambiar_clave_obligatoria = 1 WHERE id = ?');
    $st->execute([password_hash($temporal, PASSWORD_BCRYPT, ['cost' => 12]), $obj['id']]);
    auditar_bd($pdo, (int)$admin['id'], 'auth.reinicio_clave', ['usuario' => (int)$obj['id'], 'nombre' => $obj['nombre']]);
    salir([
        'ok' => true,
        'usuario' => $obj['nombre'],
        'clave_temporal' => $temporal,
        'nota' => 'Al entrar con ella se le pedirá cambiarla (solo el primer acceso).',
    ]);
}

/* --------------------- LISTA DE USUARIOS (admin) --------------------------- */
if ($accion === 'usuarios') {
    $token = (string)($_POST['token'] ?? '');
    $admin = usuarioPorToken($pdo, $token);
    if (!$admin) salir(['ok' => false, 'error' => 'Tu sesión caducó: vuelve a entrar y repítelo.'], 401);
    if ($admin['rol'] !== 'administrador') salir(['ok' => false, 'error' => 'Esto solo lo puede ver la administración.'], 403);
    $st = $pdo->query(
        'SELECT u.*,
                (SELECT COUNT(*) FROM eventos e WHERE e.creador_id = u.id) AS n_quedadas,
                (SELECT COUNT(*) FROM inscripciones i WHERE i.usuario_id = u.id AND i.estado = "confirmada") AS n_inscripciones
         FROM usuarios u ORDER BY (u.estado = "pendiente") DESC, u.id ASC'
    );
    $lista = [];
    foreach ($st as $u) {
        $fila = appUsuario($u);
        $fila['n_quedadas'] = (int)$u['n_quedadas'];
        $fila['n_inscripciones'] = (int)$u['n_inscripciones'];
        $lista[] = $fila;
    }
    salir(['ok' => true, 'usuarios' => $lista]);
}

/* ------------------- DAR DE ALTA A MANO (admin) ---------------------------- */
if ($accion === 'usuario_nuevo') {
    $token = (string)($_POST['token'] ?? '');
    $admin = usuarioPorToken($pdo, $token);
    if (!$admin) salir(['ok' => false, 'error' => 'Tu sesión caducó: vuelve a entrar y repítelo.'], 401);
    if ($admin['rol'] !== 'administrador') salir(['ok' => false, 'error' => 'Esto solo lo puede hacer la administración.'], 403);
    $nombre = trim((string)($_POST['nombre'] ?? ''));
    $tel = normalizar_telefono($_POST['telefono'] ?? '');
    $rol = (string)($_POST['rol'] ?? 'usuario');
    $clave = (string)($_POST['clave'] ?? '');
    if (mb_strlen($nombre) < 2 || mb_strlen($nombre) > 60) {
        salir(['ok' => false, 'error' => 'El nombre necesita entre 2 y 60 caracteres.'], 400);
    }
    if ($tel === '' || !preg_match('/^\+\d{8,15}$/', $tel)) {
        salir(['ok' => false, 'error' => 'El teléfono no parece válido (se guarda en formato internacional).'], 400);
    }
    if (!in_array($rol, ['usuario', 'moderador', 'administrador'], true)) {
        salir(['ok' => false, 'error' => 'El rol no existe: usuario, moderador o administrador.'], 400);
    }
    if (buscar_usuario($pdo, $tel)) {
        salir(['ok' => false, 'error' => 'Ya hay una cuenta con ese teléfono.'], 409);
    }
    $generada = null;
    if ($clave !== '') {
        if (strlen($clave) < 4 || strlen($clave) > 72) {
            salir(['ok' => false, 'error' => 'La contraseña necesita entre 4 y 72 caracteres (o déjala vacía para generar una).'], 400);
        }
        $hash = password_hash($clave, PASSWORD_BCRYPT, ['cost' => 12]);
        $obligatoria = 0;
    } else {
        $generada = clave_provisional();
        $hash = password_hash($generada, PASSWORD_BCRYPT, ['cost' => 12]);
        $obligatoria = 1; // entra con ella y se la pide cambiar
    }
    $pdo->prepare(
        'INSERT INTO usuarios (nombre, telefono, clave_hash, rol, activo, estado, membre_desde, cambiar_clave_obligatoria)
         VALUES (?, ?, ?, ?, 1, "activo", CURDATE(), ?)'
    )->execute([$nombre, $tel, $hash, $rol, $obligatoria]);
    $u = buscar_usuario($pdo, $tel);
    auditar_bd($pdo, (int)$admin['id'], 'auth.usuario_nuevo', ['usuario' => (int)$u['id'], 'nombre' => $nombre, 'rol' => $rol, 'clave_generada' => $generada !== null]);
    salir(['ok' => true, 'usuario' => appUsuario($u), 'clave_generada' => $generada]);
}

/* --------------------- EDITAR USUARIO (admin) ------------------------------ */
if ($accion === 'usuario_guardar') {
    $token = (string)($_POST['token'] ?? '');
    $admin = usuarioPorToken($pdo, $token);
    if (!$admin) salir(['ok' => false, 'error' => 'Tu sesión caducó: vuelve a entrar y repítelo.'], 401);
    if ($admin['rol'] !== 'administrador') salir(['ok' => false, 'error' => 'Esto solo lo puede hacer la administración.'], 403);
    $uid = (int)($_POST['id'] ?? 0);
    $u = buscar_usuario_id($pdo, $uid);
    if (!$u) salir(['ok' => false, 'error' => 'No existe ese usuario.'], 404);

    $sets = [];
    $val = [];
    /* Campos editables, uno por uno (el whitelist protege de escribir lo que
       no toca: no se sobreescribe la contraseña por equivocación). */
    if (isset($_POST['nombre'])) {
        $nombre = trim((string)$_POST['nombre']);
        if (mb_strlen($nombre) < 2 || mb_strlen($nombre) > 60) salir(['ok' => false, 'error' => 'El nombre necesita entre 2 y 60 caracteres.'], 400);
        $sets[] = 'nombre = ?'; $val[] = $nombre;
    }
    if (isset($_POST['telefono'])) {
        $tel = normalizar_telefono($_POST['telefono']);
        if ($tel === '' || !preg_match('/^\+\d{8,15}$/', $tel)) salir(['ok' => false, 'error' => 'El teléfono no parece válido (se guarda en formato internacional).'], 400);
        $st = $pdo->prepare('SELECT id FROM usuarios WHERE telefono = ? AND id <> ?');
        $st->execute([$tel, $uid]);
        if ($st->fetch()) salir(['ok' => false, 'error' => 'Otra cuenta ya usa ese teléfono.'], 409);
        $sets[] = 'telefono = ?'; $val[] = $tel;
    }
    if (isset($_POST['rol'])) {
        $rol = (string)$_POST['rol'];
        if (!in_array($rol, ['usuario', 'moderador', 'administrador'], true)) salir(['ok' => false, 'error' => 'El rol no existe: usuario, moderador o administrador.'], 400);
        if ($uid === (int)$admin['id'] && $rol !== 'administrador') {
            salir(['ok' => false, 'error' => 'No puedes quitarte el rol de administración: primero nombra a otro administrador.'], 400);
        }
        $sets[] = 'rol = ?'; $val[] = $rol;
    }
    if (isset($_POST['activo'])) {
        $sets[] = 'activo = ?'; $val[] = ((int)$_POST['activo'] === 1) ? 1 : 0;
    }
    if (isset($_POST['estado'])) {
        $estado = (string)$_POST['estado'];
        if (!in_array($estado, ['pendiente', 'activo', 'rechazado'], true)) salir(['ok' => false, 'error' => 'El estado no existe.'], 400);
        $sets[] = 'estado = ?'; $val[] = $estado;
    }
    if (isset($_POST['motivo_rechazo'])) {
        $mot = trim((string)$_POST['motivo_rechazo']);
        if (mb_strlen($mot) > 255) salir(['ok' => false, 'error' => 'El motivo del rechazo no pasa de 255 caracteres.'], 400);
        $sets[] = 'motivo_rechazo = ?'; $val[] = $mot !== '' ? $mot : null;
    }
    /* FASE 10 — el resto de la ficha del socio, editable desde el panel:
       antes solo existían en la maqueta y el «Guardar» del editor se perdía. */
    if (isset($_POST['apellidos'])) {
        $ap = trim((string)$_POST['apellidos']);
        if (mb_strlen($ap) > 90) salir(['ok' => false, 'error' => 'Los apellidos no pasan de 90 caracteres.'], 400);
        $sets[] = 'apellidos = ?'; $val[] = $ap !== '' ? $ap : null;
    }
    if (isset($_POST['email'])) {
        $mail = trim((string)$_POST['email']);
        if ($mail !== '' && !preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]{2,}$/', $mail)) salir(['ok' => false, 'error' => 'El correo no tiene forma de correo.'], 400);
        $st = $pdo->prepare('SELECT id FROM usuarios WHERE email = ? AND id <> ?');
        $st->execute([$mail, $uid]);
        if ($mail !== '' && $st->fetch()) salir(['ok' => false, 'error' => 'Otra cuenta ya usa ese correo.'], 409);
        $sets[] = 'email = ?'; $val[] = $mail !== '' ? substr($mail, 0, 120) : null;
    }
    if (isset($_POST['poblacion'])) {
        $pob = trim((string)$_POST['poblacion']);
        if (mb_strlen($pob) > 60) salir(['ok' => false, 'error' => 'El municipio no pasa de 60 caracteres.'], 400);
        $sets[] = 'poblacion = ?'; $val[] = $pob !== '' ? $pob : null;
    }
    if (isset($_POST['tramo'])) {
        $tramo = trim((string)$_POST['tramo']);
        if ($tramo !== '' && !in_array($tramo, ['jovenes', 'core', 'senior', 'mixto'], true)) salir(['ok' => false, 'error' => 'El tramo no existe: jovenes, core, senior o mixto.'], 400);
        $sets[] = 'tramo = ?'; $val[] = $tramo !== '' ? $tramo : null;
    }
    if (isset($_POST['perfil_corto'])) {
        $pc = trim((string)$_POST['perfil_corto']);
        if (mb_strlen($pc) > 600) salir(['ok' => false, 'error' => 'La presentación no pasa de 600 caracteres.'], 400);
        $sets[] = 'perfil_corto = ?'; $val[] = $pc !== '' ? $pc : null;
    }
    if (isset($_POST['membre_desde'])) {
        $md = trim((string)$_POST['membre_desde']);
        if ($md === '') {
            $sets[] = 'membre_desde = NULL';
        } else {
            $dt = DateTime::createFromFormat('Y-m-d', $md);
            if (!$dt || $dt->format('Y-m-d') !== $md) salir(['ok' => false, 'error' => 'La fecha de miembro no parece válida (AAAA-MM-DD).'], 400);
            $sets[] = 'membre_desde = ?'; $val[] = $md;
        }
    }
    /* FASE 11 — contadores de participación puestos a mano por el equipo
       (vacío = NULL = la web cuenta lo real de las inscripciones). */
    foreach (['participadas_manual', 'entradas_manual', 'ausencias_manual'] as $campoManual) {
        if (array_key_exists($campoManual, $_POST)) {
            $v = trim((string)$_POST[$campoManual]);
            if ($v === '') {
                $sets[] = $campoManual . ' = NULL';
            } else {
                if (!preg_match('/^\d{1,5}$/', $v)) salir(['ok' => false, 'error' => 'Esa cifra de participación no parece un número (0 o más).'], 400);
                $sets[] = $campoManual . ' = ?'; $val[] = (int)$v;
            }
        }
    }
    if (array_key_exists('foto', $_POST)) {
        $foto = trim((string)$_POST['foto']);
        if ($foto === '') {
            $sets[] = 'foto = NULL';
        } elseif (strncmp($foto, '/uploads/', 9) === 0) {
            $sets[] = 'foto = ?'; $val[] = substr($foto, 0, 255);
        } else {
            salir(['ok' => false, 'error' => 'La foto tiene que ser una URL de /uploads/.'], 400);
        }
    }

    /* Aprobación de un registro pendiente: si nunca tuvo contraseña
       conocida (estaba pendiente), se le genera la provisional y se la
       enseña UNA vez al administrador para pasársela. */
    $clave_generada = null;
    $eraPendiente = $u['estado'] === 'pendiente';
    $pasaAActivo = isset($_POST['estado']) && $_POST['estado'] === 'activo';
    if ($eraPendiente && $pasaAActivo) {
        $generada = clave_provisional();
        $sets[] = 'clave_hash = ?'; $val[] = password_hash($generada, PASSWORD_BCRYPT, ['cost' => 12]);
        $sets[] = 'cambiar_clave_obligatoria = 1';
        /* Los registros pendientes nacen con activo=0: al aprobarlos se
           reactivan (salvo que el admin lo pida explícitamente al revés). */
        if (!isset($_POST['activo']) || (int)$_POST['activo'] === 1) $sets[] = 'activo = 1';
        $clave_generada = $generada;
    }

    /* Contraseña nueva a mano (opcional): rota los tokens de la persona y le
       pide cambiarla en el primer acceso. */
    if (isset($_POST['clave']) && (string)$_POST['clave'] !== '') {
        $nueva = (string)$_POST['clave'];
        if (strlen($nueva) < 4 || strlen($nueva) > 72) salir(['ok' => false, 'error' => 'La contraseña necesita entre 4 y 72 caracteres.'], 400);
        $sets[] = 'clave_hash = ?'; $val[] = password_hash($nueva, PASSWORD_BCRYPT, ['cost' => 12]);
        $sets[] = 'cambiar_clave_obligatoria = 1';
        $pdo->prepare('DELETE FROM sesiones_token WHERE usuario_id = ?')->execute([$uid]);
    }

    if (!$sets) salir(['ok' => false, 'error' => 'Nada que guardar: no has cambiado ningún campo.'], 400);
    $val[] = $uid;
    $pdo->prepare('UPDATE usuarios SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($val);
    auditar_bd($pdo, (int)$admin['id'], 'auth.usuario_editado', [
        'usuario' => $uid,
        'campos' => array_map(fn($s) => explode(' = ', $s, 2)[0], $sets),
        'clave_generada' => $clave_generada !== null,
    ]);
    salir(['ok' => true, 'usuario' => appUsuario(buscar_usuario_id($pdo, $uid)), 'clave_generada' => $clave_generada]);
}

/* ------------- REGLAS GLOBALES (ajustes) — solo administración ------------- */
if ($accion === 'ajustes') {
    $token = (string)($_POST['token'] ?? '');
    $admin = usuarioPorToken($pdo, $token);
    if (!$admin) salir(['ok' => false, 'error' => 'Tu sesión caducó: vuelve a entrar y repítelo.'], 401);
    if ($admin['rol'] !== 'administrador') salir(['ok' => false, 'error' => 'Esto solo lo puede ver la administración.'], 403);
    $st = $pdo->query('SELECT * FROM ajustes ORDER BY clave');
    $lista = [];
    foreach ($st as $a) {
        $valor = $a['valor'];
        if ($a['tipo'] === 'numero') $valor = (int)$valor;
        elseif ($a['tipo'] === 'palanca') $valor = ((int)$valor === 1);
        $lista[] = [
            'clave' => $a['clave'],
            'valor' => $valor,
            'tipo' => $a['tipo'],
            'min' => $a['minimo'] !== null ? (int)$a['minimo'] : null,
            'max' => $a['maximo'] !== null ? (int)$a['maximo'] : null,
            'bloqueado' => (bool)(int)$a['bloqueado'],
            'cuando' => $a['cuando'],
        ];
    }
    salir(['ok' => true, 'ajustes' => $lista]);
}

if ($accion === 'ajuste_guardar') {
    $token = (string)($_POST['token'] ?? '');
    $admin = usuarioPorToken($pdo, $token);
    if (!$admin) salir(['ok' => false, 'error' => 'Tu sesión caducó: vuelve a entrar y repítelo.'], 401);
    if ($admin['rol'] !== 'administrador') salir(['ok' => false, 'error' => 'Solo la administración cambia las reglas globales.'], 403);
    $clave = trim((string)($_POST['clave'] ?? ''));
    $st = $pdo->prepare('SELECT * FROM ajustes WHERE clave = ?');
    $st->execute([$clave]);
    $a = $st->fetch();
    if (!$a) salir(['ok' => false, 'error' => 'Ese ajuste no existe.'], 404);
    if ((int)$a['bloqueado'] === 1) salir(['ok' => false, 'error' => 'ajuste_blojado'], 400);
    $raw = (string)($_POST['valor'] ?? '');
    if ($a['tipo'] === 'numero') {
        if (!preg_match('/^-?\d+$/', trim($raw))) salir(['ok' => false, 'error' => 'valor_no_valido:no_es_numero'], 400);
        $n = (int)$raw;
        if ($a['minimo'] !== null && $n < (int)$a['minimo']) salir(['ok' => false, 'error' => 'valor_no_valido:min_' . $a['minimo']], 400);
        if ($a['maximo'] !== null && $n > (int)$a['maximo']) salir(['ok' => false, 'error' => 'valor_no_valido:max_' . $a['maximo']], 400);
        $nuevo = (string)$n;
    } elseif ($a['tipo'] === 'palanca') {
        $nuevo = in_array($raw, ['1', 'true', 'on'], true) ? '1' : '0';
    } else {
        $txt = trim($raw);
        if ($txt === '') salir(['ok' => false, 'error' => 'valor_no_valido:vacio'], 400);
        if ($a['maximo'] !== null && mb_strlen($txt) > (int)$a['maximo']) salir(['ok' => false, 'error' => 'valor_no_valido:max_' . $a['maximo'] . '_caracteres'], 400);
        $nuevo = $txt;
    }
    $pdo->prepare('UPDATE ajustes SET valor = ? WHERE clave = ?')->execute([$nuevo, $clave]);
    auditar_bd($pdo, (int)$admin['id'], 'ajuste.cambio', ['clave' => $clave, 'antes' => $a['valor'], 'despues' => $nuevo]);
    salir(['ok' => true, 'clave' => $clave, 'valor' => $a['tipo'] === 'numero' ? (int)$nuevo : ($a['tipo'] === 'palanca' ? ((int)$nuevo === 1) : $nuevo)]);
}

/* ------------- FASE 12 — enlaces WhatsApp de los grupos de comunidad -------
   Cada grupo de /comunidad lleva su enlace de invitación (lo edita el equipo
   —admin o moderador— desde esa misma página). Viven en `ajustes` como filas
   de tipo «texto» con clave wa_grupo_<id>: valor vacío = borrar el enlace. */
if ($accion === 'grupos_wa') {
    $token = (string)($_POST['token'] ?? '');
    $u = usuarioPorToken($pdo, $token);
    if (!$u) salir(['ok' => false, 'error' => 'Tu sesión caducó: vuelve a entrar y repítelo.'], 401);
    $st = $pdo->query("SELECT clave, valor FROM ajustes WHERE clave LIKE 'wa\\_grupo\\_%'");
    $enlaces = [];
    foreach ($st as $a) $enlaces[$a['clave']] = $a['valor'];
    salir(['ok' => true, 'enlaces' => $enlaces]);
}

if ($accion === 'grupo_wa_guardar') {
    $token = (string)($_POST['token'] ?? '');
    $u = usuarioPorToken($pdo, $token);
    if (!$u) salir(['ok' => false, 'error' => 'Tu sesión caducó: vuelve a entrar y repítelo.'], 401);
    if (!in_array($u['rol'], ['administrador', 'moderador'], true)) salir(['ok' => false, 'error' => 'Solo el equipo cambia los enlaces de la comunidad.'], 403);
    $clave = trim((string)($_POST['clave'] ?? ''));
    if (!preg_match('/^wa_grupo_[a-z0-9]{1,32}$/i', $clave)) salir(['ok' => false, 'error' => 'Ese grupo no existe.'], 400);
    $url = trim((string)($_POST['enlace'] ?? ''));
    $antes = null;
    $st = $pdo->prepare('SELECT valor FROM ajustes WHERE clave = ?');
    $st->execute([$clave]);
    if ($a = $st->fetch()) $antes = $a['valor'];
    if ($url !== '') {
        if (!preg_match('#^https://(www\.)?(wa\.me|whatsapp\.com|chat\.whatsapp\.com|api\.whatsapp\.com)/\S+$#i', $url)) {
            salir(['ok' => false, 'error' => 'El enlace tiene que ser de WhatsApp (https://wa.me/… o https://chat.whatsapp.com/…).'], 400);
        }
        $pdo->prepare("INSERT INTO ajustes (clave, valor, tipo) VALUES (?, ?, 'texto') ON DUPLICATE KEY UPDATE valor = VALUES(valor)")
            ->execute([$clave, $url]);
    } else {
        $pdo->prepare('DELETE FROM ajustes WHERE clave = ?')->execute([$clave]);
    }
    auditar_bd($pdo, (int)$u['id'], 'comunidad.wa_guardado', ['grupo' => $clave, 'antes' => $antes, 'despues' => $url !== '' ? $url : null]);
    salir(['ok' => true, 'clave' => $clave, 'enlace' => $url !== '' ? $url : null]);
}

/* ------------- REGISTRO DE AUDITORÍA (equipo) ------------------------------- */
if ($accion === 'auditoria') {
    $token = (string)($_POST['token'] ?? '');
    $u = usuarioPorToken($pdo, $token);
    if (!$u) salir(['ok' => false, 'error' => 'Tu sesión caducó: vuelve a entrar y repítelo.'], 401);
    if (!in_array($u['rol'], ['administrador', 'moderador'], true)) salir(['ok' => false, 'error' => 'Esto solo lo puede ver el equipo.'], 403);
    $q = trim((string)($_POST['q'] ?? ''));
    $limite = min(1000, max(1, (int)($_POST['limite'] ?? 400)));
    $sql = 'SELECT a.id, a.cuando, a.usuario_id, a.accion, a.detalle, u.nombre AS u_nombre
            FROM auditoria a LEFT JOIN usuarios u ON u.id = a.usuario_id';
    $cond = [];
    $param = [];
    if ($q !== '') {
        $cond[] = '(a.accion LIKE ? OR a.detalle LIKE ?)';
        $param[] = '%' . $q . '%';
        $param[] = '%' . $q . '%';
    }
    $where = $cond ? ('WHERE ' . implode(' AND ', $cond)) : '';
    $sql .= ' ' . $where . ' ORDER BY a.cuando DESC, a.id DESC LIMIT ' . $limite;
    $st = $pdo->prepare($sql);
    $st->execute($param);
    $filas = [];
    foreach ($st as $r) {
        $detalle = null;
        if ($r['detalle'] !== null) {
            $d = json_decode($r['detalle'], true);
            $detalle = is_array($d) ? $d : $r['detalle'];
        }
        $filas[] = [
            'id' => (int)$r['id'],
            'cuando' => $r['cuando'],
            'usuario_id' => $r['usuario_id'] !== null ? (int)$r['usuario_id'] : null,
            'usuario_nombre' => $r['u_nombre'] !== null ? $r['u_nombre'] : null,
            'accion' => $r['accion'],
            'detalle' => $detalle,
        ];
    }
    $tot = $pdo->query('SELECT COUNT(*) FROM auditoria')->fetchColumn();
    salir(['ok' => true, 'registros' => $filas, 'total' => (int)$tot]);
}

/* --------------- LA PROPIA FOTO (cualquiera en sesión) --------------------- */
if ($accion === 'usuario_foto') {
    $token = (string)($_POST['token'] ?? '');
    $u = usuarioPorToken($pdo, $token);
    if (!$u) salir(['ok' => false, 'error' => 'Tu sesión caducó: vuelve a entrar y repítelo.'], 401);
    $foto = trim((string)($_POST['foto'] ?? ''));
    if ($foto !== '' && strncmp($foto, '/uploads/', 9) !== 0) {
        salir(['ok' => false, 'error' => 'La foto tiene que ser una URL de /uploads/.'], 400);
    }
    $pdo->prepare('UPDATE usuarios SET foto = ? WHERE id = ?')
        ->execute([$foto !== '' ? substr($foto, 0, 255) : null, $u['id']]);
    auditar_bd($pdo, (int)$u['id'], 'auth.foto_perfil', ['usuario' => (int)$u['id'], 'foto' => $foto !== '' ? $foto : null]);
    salir(['ok' => true, 'foto' => $foto !== '' ? $foto : null]);
}

salir(['ok' => false, 'error' => 'accion_desconocida'], 400);
