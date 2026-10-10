<?php
/* ============================================================================
   api/eventos.php — la agenda REAL de la comunidad (base de datos MySQL)
   ----------------------------------------------------------------------------
   Mismo estilo que auth.php: credencial fuera de www/, JSON por respuesta,
   errores en castellano corto. Aquí vive la agenda: las 150 quedadas que ya
   estaban en la base de datos y todo lo que la web nueva escriba a partir de
   ahora (propuestas, inscripciones, moderación, check-in).

   Acciones (POST, formulario):
     · cargar                 → catálogo (categorías/subcategorías activas) +
                                agenda + inscripciones + cola de moderación.
                                Sin token: solo lo público (Aprobado, desde hoy).
     · inscribir              → plaza o lista de espera, ATÓMICO (FOR UPDATE):
                                no hay sobreventa posible.
     · cancelar_inscripcion   → libera la plaza y sube al primero de la cola.
     · crear                  → propuesta a moderación (estado 'Pendiente').
     · moderar                → aprobar / rechazar / bloquear / pedir_cambios
                                (solo administración y moderación).
     · editar / ajustar_aforo / cerrar_inscripciones / publicar /
     · cancelar_evento / borrar / duplicar / foto_evento  → equipo (mod/admin)
     · checkin                → control de acceso por QR (solo uso).
     · asistencia             → presente / ausente / borrar (equipo).

   Dos reglas de casa:
     · el aforo NUNCA se guarda a mano: se deriva de `inscripciones`
       (la columna `plazas_ocupadas` del esquema original se ignora: era la
       causa de los eventos con más inscritos que plazas en la web anterior).
     · `plazas` NULL o 0 = aforo ABIERTO (sin límite); la web anterior los
       trataba como «cero plazas» y no entraba nadie.
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

/* --- conexión (misma credencial que auth.php, fuera de la carpeta pública) -- */
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

/* --- sesión: mismo mecanismo de token de 7 días que auth.php ---------------- */
function usuarioPorToken($pdo, $token)
{
    if (!preg_match('/^[a-f0-9]{64}$/', (string)$token)) return null;
    $st = $pdo->prepare(
        'SELECT u.id, u.nombre, u.apellidos, u.telefono, u.email, u.rol, u.activo, u.foto,
                u.membre_desde, u.estado, u.cambiar_clave_obligatoria,
                u.poblacion, u.tramo, u.perfil_corto,
                u.zona, u.participadas_manual, u.entradas_manual, u.ausencias_manual
         FROM sesiones_token s JOIN usuarios u ON u.id = s.usuario_id
         WHERE s.token = ? AND s.caduca_en > NOW()'
    );
    $st->execute([(string)$token]);
    return $st->fetch() ?: null;
}

/* FASE 10 — rastro en la base (tabla `auditoria`). Best-effort: un fallo al
   escribir el asiento no rompe la operación (queda en el log del servidor). */
function auditar_bd($pdo, $usuarioId, $accion, $detalle)
{
    try {
        $st = $pdo->prepare('INSERT INTO auditoria (usuario_id, accion, detalle) VALUES (?, ?, ?)');
        $st->execute([(int)$usuarioId, (string)$accion, json_encode($detalle, JSON_UNESCAPED_UNICODE)]);
    } catch (Throwable $e) {
        error_log('auditoria: no se pudo registrar ' . $accion . ': ' . $e->getMessage());
    }
}

function esEquipo($rol)
{
    return in_array($rol, ['administrador', 'moderador'], true);
}

/* --- mapeo BD → forma que entiende la web (lib/db.js) ------------------------ */

/* Los colores en la base son enteros ARGB desplazados 4 bits (0xFFxxYYZZ0).
   Se recuperan con >>4 y se pintan como #rrggbb; si no hay color, se usa la
   paleta de la app para esa categoría. */
$COLOR_FALLBACK = [
    5 => '#1c7a6b', 11 => '#2f6fb0', 9 => '#b5651d', 14 => '#8e3b52',
    8 => '#4d5b9c', 13 => '#127a86', 12 => '#0e86a8', 15 => '#c98a12', 10 => '#9b4fa8',
];
function colorCat($row, $id, $fallback)
{
    $v = (int)($row['color'] ?? 0);
    if ($v > 0) {
        /* El entero es ARGB (alpha en los 8 bits altos): el color es el
           trío RGB de abajo, tal cual lo pintaba la web original. */
        $hex = sprintf('#%06x', $v & 0xFFFFFF);
        if ($hex !== '#000000') return $hex;
    }
    return $fallback[$id] ?? null;
}

$ESTADO_APP = [
    'Aprobado' => 'Aprobada',
    'Pendiente' => 'Pendiente de aprobación',
    'Rechazado' => 'Rechazada',
    'Bloqueado' => 'Bloqueada',
    'Cancelada' => 'Cancelada',
];
$ESTADO_BD = [
    'aprobar' => 'Aprobado',
    'rechazar' => 'Rechazado',
    'bloquear' => 'Bloqueado',
    'pedir_cambios' => 'Pendiente',
];

function tramosDe($e)
{
    $t = [];
    if ((int)$e['disfrutines'] === 1) $t[] = 'jovenes';
    if ((int)$e['disfruteros'] === 1) $t[] = 'core';
    if ((int)$e['disfrutones'] === 1) $t[] = 'senior';
    if (!$t) $t = ['core'];
    return $t;
}

/* Inscripciones de un evento, en la forma interna de la web (socio_id 'u<n>'). */
function inscripcionesDe($pdo, $eventoId)
{
    $st = $pdo->prepare(
        'SELECT i.*, u.nombre AS u_nombre, u.foto AS u_foto
         FROM inscripciones i JOIN usuarios u ON u.id = i.usuario_id
         WHERE i.evento_id = ?
         ORDER BY (i.estado = "en_espera") ASC, i.turno ASC, i.id ASC'
    );
    $st->execute([$eventoId]);
    $out = [];
    foreach ($st as $r) {
        $out[] = [
            'id' => (int)$r['id'],
            'evento_id' => (int)$r['evento_id'],
            'socio_id' => 'u' . $r['usuario_id'],
            'socio_nombre' => $r['u_nombre'],
            /* La foto real de la persona (la sube en su perfil): aparece en la
               lista de asistentes de la quedada y en el historial. */
            'socio_foto' => $r['u_foto'] !== null ? $r['u_foto'] : null,
            'estado' => $r['estado'],
            'origen' => $r['origen'],
            'turno' => $r['turno'] !== null ? (int)$r['turno'] : null,
            'num_ninos' => (int)$r['num_ninos'],
            'num_acompanantes' => (int)$r['num_acompanantes'],
            'necesita_plaza_coche' => (bool)(int)$r['necesita_plaza_coche'],
            'ofrece_plazas_coche' => (int)$r['ofrece_plazas_coche'],
            'zona_salida' => $r['zona_salida'] !== null ? $r['zona_salida'] : null,
            'fecha_inscripcion' => $r['creado_en'] ? substr($r['creado_en'], 0, 10) : null,
            'fecha_checkin' => $r['checkin_en'],
            'asistio' => $r['checkin_en'] !== null,
            'asistencia' => $r['asistencia'],
            'caduca' => null,
        ];
    }
    return $out;
}

/* Aforo DERIVADO: nunca un número suelto. plazas NULL/0 = aforo abierto. */
function aforoDe($plazas, $personas, $espera, $version)
{
    $plazas = (int)$plazas;
    if ($plazas > 0) {
        return [
            'ocupadas' => (int)$personas,
            'limite' => $plazas,
            'libres' => max(0, $plazas - (int)$personas),
            'lleno' => (int)$personas >= $plazas,
            'en_espera' => (int)$espera,
            'pct' => min(100, (int)round((int)$personas / $plazas * 100)),
            'version' => (int)$version,
        ];
    }
    return [
        'ocupadas' => (int)$personas,
        'limite' => null,
        'libres' => null,
        'lleno' => false,
        'en_espera' => (int)$espera,
        'pct' => 0,
        'version' => (int)$version,
    ];
}

function aforoBd($pdo, $eventoId, $plazas, $version)
{
    $st = $pdo->prepare(
        'SELECT COALESCE(SUM(1 + COALESCE(i.num_acompanantes,0) + COALESCE(i.num_ninos,0)), 0) AS personas,
                (SELECT COUNT(*) FROM inscripciones e WHERE e.evento_id = ? AND e.estado = "en_espera") AS espera
         FROM inscripciones i
         WHERE i.evento_id = ? AND i.estado = "confirmada"'
    );
    $st->execute([$eventoId, $eventoId]);
    $r = $st->fetch();
    return aforoDe($plazas, $r['personas'], $r['espera'], $version);
}

/* El evento completo, tal cual lo pinta la web. */
function appEvento($pdo, $e, $hoy)
{
    global $ESTADO_APP;
    $aforo = aforoBd($pdo, (int)$e['id'], $e['plazas'], $e['version']);
    $material = null;
    if (!empty($e['material'])) {
        $material = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string)$e['material']))));
        if (!$material) $material = null;
    }
    $plazas = (int)$e['plazas'];
    return [
        'id' => (int)$e['id'],
        'titulo' => $e['titulo'],
        'descripcion' => $e['descripcion'] !== null ? $e['descripcion'] : '',
        'fecha' => $e['fecha'],
        'hora_inicio' => $e['hora_inicio'] !== null ? substr($e['hora_inicio'], 0, 5) : null,
        'hora_fin_prevista' => $e['hora_fin'] !== null ? substr($e['hora_fin'], 0, 5) : null,
        'estado' => $ESTADO_APP[$e['estado']] ?? 'Aprobada',
        'es_publica' => (bool)(int)$e['es_publico'],
        'categoria_id' => (int)$e['categoria_id'],
        'subcategoria_id' => $e['subcategoria_id'] !== null ? (int)$e['subcategoria_id'] : null,
        'zona_id' => $e['zona_id'] !== null ? (int)$e['zona_id'] : null,
        'punto_encontrada' => $e['lugar'] !== null && $e['lugar'] !== '' ? $e['lugar']
                        : ($e['direccion_completa'] !== null && $e['direccion_completa'] !== '' ? $e['direccion_completa'] : null),
        'direccion' => $e['direccion_completa'] ?: null,
        'lugar' => ['nombre' => $e['lugar'] ?: null],
        'poblacion' => $e['poblacion'] ?: null,
        'provincia' => $e['provincia'] ?: null,
        'coordenadas' => ($e['coordenadas_lat'] !== null && $e['coordenadas_lng'] !== null)
            ? ['lat' => (float)$e['coordenadas_lat'], 'lng' => (float)$e['coordenadas_lng']] : null,
        'plazas' => $plazas > 0 ? $plazas : null,
        'precio' => round((float)$e['precio'], 2),
        'nota_precio' => $e['nota_precio'] ?: null,
        'permite_ninos' => (int)$e['disfrutines'] === 1,
        'ninos_min_edad' => $e['edad_minima'] !== null ? (int)$e['edad_minima'] : null,
        'edad_maxima' => $e['edad_maxima'] !== null ? (int)$e['edad_maxima'] : null,
        'permite_acompanantes' => (bool)(int)$e['permite_acompanantes'],
        'acompanantes_max' => $e['max_acompanantes'] !== null && (int)$e['max_acompanantes'] > 0
            ? (int)$e['max_acompanantes'] : null,
        'tramos' => tramosDe($e),
        'material' => $material,
        'dificultad' => $e['dificultad'] !== null ? (int)$e['dificultad'] : null,
        'distancia_km' => $e['distancia_km'] !== null ? (float)$e['distancia_km'] : null,
        'desnivel_m' => $e['desnivel_m'] !== null ? (int)$e['desnivel_m'] : null,
        'terreno' => $e['terreno'] ?: null,
        'ritmo' => $e['ritmo'] ?: null,
        'notas_organizador' => $e['notas_organizador'] ?: null,
        'motivo_cancelacion' => $e['motivo_cancelacion'] ?: null,
        'inscripciones_cerradas' => (bool)(int)$e['inscripciones_cerradas'],
        'grupo' => ['enlace' => $e['grupo_whatsapp'] ?: null, 'creada' => null],
        'foto_portada' => $e['imagen'] ?: null,
        'organizador_id' => $e['creador_id'] !== null ? 'u' . $e['creador_id'] : null,
        'version' => (int)$e['version'],
        'esHoy' => $e['fecha'] === $hoy,
        'esPasada' => $e['fecha'] < $hoy,
        'aforo' => $aforo,
        'inscripciones' => inscripcionesDe($pdo, (int)$e['id']),
    ];
}

function cargarEvento($pdo, $id)
{
    $st = $pdo->prepare('SELECT * FROM eventos WHERE id = ?');
    $st->execute([(int)$id]);
    $e = $st->fetch();
    if (!$e) return null;
    return appEvento($pdo, $e, date('Y-m-d'));
}

function leerBorrador()
{
    return json_decode((string)file_get_contents('php://input'), true) ?: [];
}

/* Validación de propuestas: mismas reglas que lib/db.js → validar(). */
function validarCreacion($b, $hoy, $pdo)
{
    $e = [];
    $titulo = trim((string)($b['titulo'] ?? ''));
    $desc = trim((string)($b['descripcion'] ?? ''));
    $fecha = (string)($b['fecha'] ?? '');
    if (strlen($titulo) < 4 || strlen($titulo) > 90) $e[] = ['titulo', 'El título va de 4 a 90 caracteres.'];
    if (strlen($desc) < 20) $e[] = ['descripcion', 'Explica el plan con más detalle: es lo que decide si la gente se apunta.'];
    if (strlen($desc) > 4000) $e[] = ['descripcion', 'La descripción es demasiado larga.'];
    if (!$fecha) $e[] = ['fecha', 'Indica el día.'];
    else {
        $min = date('Y-m-d', strtotime($hoy . ' +2 days'));
        if ($fecha < $min) $e[] = ['fecha', 'Entre 48 h de antelación para poder moderarla.'];
    }
    if (empty($b['hora_inicio'])) $e[] = ['hora_inicio', 'Indica la hora.'];
    if (empty($b['punto_encontrada'])) $e[] = ['punto_encontrada', 'Sin punto de encuentro la gente se pierde.'];
    $plazas = (int)($b['plazas'] ?? 0);
    if ($plazas < 1) $e[] = ['plazas', 'Las plazas son obligatorias: si son ilimitadas, escribe 999.'];
    if ($plazas > 500) $e[] = ['plazas', 'Más de 500 no lo podemos moderar: divide en grupos.'];
    $cat = (int)($b['categoria_id'] ?? 0);
    $sub = (int)($b['subcategoria_id'] ?? 0);
    $st = $pdo->prepare('SELECT id, nombre FROM categorias WHERE id = ? AND estado = 1');
    $st->execute([$cat]);
    if (!$st->fetch()) $e[] = ['categoria_id', 'Falta la categoría.'];
    $st = $pdo->prepare('SELECT id_cat FROM subcategorias WHERE id = ? AND estado = 1');
    $st->execute([$sub]);
    $subR = $st->fetch();
    if (!$subR) $e[] = ['subcategoria_id', 'Falta la subcategoría.'];
    elseif ($subR['id_cat'] != $cat) $e[] = ['subcategoria_id', 'La subcategoría no es de esa categoría.'];
    if ($cat === 5) {
        if (empty($b['distancia_km'])) $e[] = ['distancia_km', 'En montaña, los km no son ornamentales.'];
        if (empty($b['desnivel_m'])) $e[] = ['desnivel_m', 'En montaña falta el desnivel.'];
        if (empty($b['dificultad'])) $e[] = ['dificultad', 'Falta la dificultad (1-5).'];
    }
    if (empty($b['hora_fin_prevista'])) $e[] = ['hora_fin_prevista', 'Hora de vuelta prevista: quien espera a un hijo en casa lo agradece.'];
    if ((float)($b['precio'] ?? 0) > 0 && empty($b['nota_precio'])) {
        $e[] = ['nota_precio', 'Si hay dinero, explica qué incluye.'];
    }
    return $e;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    salir(['ok' => true, 'servicio' => 'eventos']);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    salir(['ok' => false, 'error' => 'metodo_no_permitido'], 405);
}

/* La entrada puede venir como formulario (como auth.php) o como JSON
   (las propuestas llevan anidados). Se unifica en $IN y el resto del
   fichero no nota la diferencia. */
$IN = $_POST;
$_raw = (string)file_get_contents('php://input');
if ($_raw !== '' && (stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'json') !== false || ltrim($_raw)[0] === '{')) {
    $_j = json_decode($_raw, true);
    if (is_array($_j)) $IN = array_merge($_POST, $_j);
}

$accion = (string)($IN['accion'] ?? '');
$u = usuarioPorToken($pdo, (string)($IN['token'] ?? ''));
$hoy = date('Y-m-d');

/* ------------------------------- CARGAR ----------------------------------- */
if ($accion === 'cargar') {
    /* Catálogo: solo lo activo. El `img` usa la clave del inventario de fotos
       (data/imagenes.json) para que la tarjeta nunca se quede sin imagen. */
    $st = $pdo->query('SELECT id, nombre, color, descripcion FROM categorias WHERE estado = 1 ORDER BY nombre');
    $cats = [];
    foreach ($st as $r) {
        $cats[] = [
            'id' => (int)$r['id'],
            'nombre' => trim($r['nombre']),
            'img' => 'categorias-' . $r['id'],
            'color' => colorCat($r, (int)$r['id'], $COLOR_FALLBACK),
            'desc' => $r['descripcion'] ?: null,
        ];
    }
    $st = $pdo->query('SELECT id, id_cat, nombre FROM subcategorias WHERE estado = 1 ORDER BY nombre');
    $subs = [];
    foreach ($st as $r) {
        $subs[] = ['id' => (int)$r['id'], 'id_cat' => (int)$r['id_cat'], 'nombre' => $r['nombre']];
    }

    $eventos = [];
    if ($u && esEquipo($u['rol'])) {
        /* El equipo ve todo lo que no es historia: pendiente, aprobadas,
           canceladas… (para el panel y para moderar). */
        $st = $pdo->query(
            "SELECT * FROM eventos
             WHERE estado IN ('Aprobado','Pendiente','Rechazado','Bloqueada','Cancelada')
             ORDER BY fecha, hora_inicio, id"
        );
    } elseif ($u) {
        /* El socio ve lo público desde hoy y, además, SUS propuestas (aunque
           estén pendientes o corregidas): así sigue su propuesta en la app. */
        $st = $pdo->prepare(
            "SELECT * FROM eventos
             WHERE (estado = 'Aprobado' AND es_publico = 1 AND fecha >= ?)
                OR (creador_id = ? AND estado IN ('Aprobado','Pendiente','Rechazado','Bloqueada','Cancelada'))
             ORDER BY fecha, hora_inicio, id"
        );
        $st->execute([$hoy, (int)$u['id']]);
    } else {
        $st = $pdo->prepare(
            "SELECT * FROM eventos WHERE estado = 'Aprobado' AND es_publico = 1 AND fecha >= ?
             ORDER BY fecha, hora_inicio, id"
        );
        $st->execute([$hoy]);
    }
    while ($e = $st->fetch()) {
        $eventos[] = appEvento($pdo, $e, $hoy);
    }

    /* Inscripciones: todas las activas (avatares de la tarjeta) + las del
       usuario aunque estén canceladas (para que el cache local sea fiel). */
    $inscripciones = [];
    if ($u) {
        $st = $pdo->prepare(
            'SELECT i.*, u.nombre AS u_nombre, u.foto AS u_foto
             FROM inscripciones i JOIN usuarios u ON u.id = i.usuario_id
             WHERE i.estado IN ("confirmada","en_espera") OR i.usuario_id = ?
             ORDER BY i.evento_id, i.id'
        );
        $st->execute([(int)$u['id']]);
    } else {
        $st = $pdo->query(
            'SELECT i.*, u.nombre AS u_nombre, u.foto AS u_foto
             FROM inscripciones i JOIN usuarios u ON u.id = i.usuario_id
             WHERE i.estado IN ("confirmada","en_espera")
             ORDER BY i.evento_id, i.id'
        );
    }
    foreach ($st as $r) {
        $inscripciones[] = [
            'id' => (int)$r['id'],
            'evento_id' => (int)$r['evento_id'],
            'socio_id' => 'u' . $r['usuario_id'],
            'socio_nombre' => $r['u_nombre'],
            'socio_foto' => $r['u_foto'] !== null ? $r['u_foto'] : null,
            'estado' => $r['estado'],
            'origen' => $r['origen'],
            'turno' => $r['turno'] !== null ? (int)$r['turno'] : null,
            'num_ninos' => (int)$r['num_ninos'],
            'num_acompanantes' => (int)$r['num_acompanantes'],
            'necesita_plaza_coche' => (bool)(int)$r['necesita_plaza_coche'],
            'ofrece_plazas_coche' => (int)$r['ofrece_plazas_coche'],
            'zona_salida' => $r['zona_salida'] !== null ? $r['zona_salida'] : null,
            'fecha_inscripcion' => $r['creado_en'] ? substr($r['creado_en'], 0, 10) : null,
            'fecha_checkin' => $r['checkin_en'],
            'asistio' => $r['checkin_en'] !== null,
            'asistencia' => $r['asistencia'],
            'caduca' => null,
        ];
    }

    /* Cola de moderación: lo pendiente (al equipo; al usuario, lo suyo). */
    $moderacion = [];
    if ($u) {
        $sql = "SELECT id, creado_en FROM eventos WHERE estado = 'Pendiente'";
        if (!esEquipo($u['rol'])) $sql .= ' AND creador_id = ' . (int)$u['id'];
        $sql .= ' ORDER BY creado_en';
        foreach ($pdo->query($sql) as $r) {
            $h = $r['creado_en'] ? max(0, (int)round((time() - strtotime($r['creado_en'])) / 3600)) : 0;
            $moderacion[] = [
                'id' => (int)$r['id'],
                'evento_id' => (int)$r['id'],
                'enviado' => $r['creado_en'] ? date('c', strtotime($r['creado_en'])) : null,
                'antiguedad_h' => $h,
                'motivo' => null,
                'riesgo' => 'bajo',
            ];
        }
    }

    salir([
        'ok' => true,
        'hoy' => $hoy,
        'categorias' => $cats,
        'subcategorias' => $subs,
        'eventos' => $eventos,
        'inscripciones' => $inscripciones,
        'moderacion' => $moderacion,
        'usuario' => $u ? ['id' => (int)$u['id'], 'nombre' => $u['nombre'], 'telefono' => $u['telefono'], 'rol' => $u['rol'], 'foto' => $u['foto'] !== null ? $u['foto'] : null,
            /* FASE 9: la app alinea con esto la bandera del cambio obligatorio
               de contraseña en cada carga (auth.php ya lo devolvía; sin él,
               cargar() la dejaba en `undefined` y el modal nunca salía). */
            'cambiar_clave_obligatoria' => (bool)(int)$u['cambiar_clave_obligatoria'],
            'apellidos' => $u['apellidos'] ?? null,
            'email' => $u['email'] ?? null,
            'poblacion' => $u['poblacion'] ?? null,
            'tramo' => $u['tramo'] ?? null,
            /* FASE 11: comarca propia y contadores a mano (null = lo real). */
            'zona' => $u['zona'] !== null ? (int)$u['zona'] : null,
            'participadas_manual' => $u['participadas_manual'] !== null ? (int)$u['participadas_manual'] : null,
            'entradas_manual' => $u['entradas_manual'] !== null ? (int)$u['entradas_manual'] : null,
            'ausencias_manual' => $u['ausencias_manual'] !== null ? (int)$u['ausencias_manual'] : null,
            'perfil_corto' => $u['perfil_corto'] ?? null] : null,
    ]);
}

/* --------------------------- COPIA DE SEGURIDAD ----------------------------
   FASE 10 — «Registrar copia ahora» del panel. NO simula: lanza un mysqldump
   real con las credenciales de la base y lo guarda FUERA de la carpeta pública
   (no se puede descargar). Si el hosting no permite ejecutar binarios
   (shell_exec deshabilitado) o el dump falla, se dice la verdad y no se marca
   nada como «copiado». La retención borra los volcados de más de 7 días.     */
if ($accion === 'copia') {
    if (!$u) salir(['ok' => false, 'error' => 'sin_sesion'], 401);
    if ($u['rol'] !== 'administrador') salir(['ok' => false, 'error' => 'Esto solo lo puede hacer la administración.'], 403);

    $dir = '/home/adisfrutarypunto/backups_adyp';
    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0700, true)) {
            salir(['ok' => false, 'error' => 'copia_directorio: no se pudo crear la carpeta de copias.'], 500);
        }
    }
    if (!is_writable($dir)) {
        salir(['ok' => false, 'error' => 'copia_directorio: la carpeta de copias no es escribible.'], 500);
    }

    /* Solo leer: qué hay copiado ya (para el «última copia» del panel). */
    if ((string)($IN['modo'] ?? '') === 'estado') {
        $archivos = glob($dir . '/adyp-*.sql') ?: [];
        usort($archivos, fn($a, $b) => filemtime($b) <=> filemtime($a));
        $ultima = null;
        if ($archivos) {
            $ultima = ['archivo' => basename($archivos[0]), 'cuando' => date('c', filemtime($archivos[0])), 'kb' => round(filesize($archivos[0]) / 1024)];
        }
        salir(['ok' => true, 'ultima' => $ultima, 'copias' => count($archivos), 'proxima' => 'cada 24 h · retención 7 días']);
    }

    $dump = function ($dir) use ($CFG) {
        if (!function_exists('shell_exec')) {
            return ['ok' => false, 'error' => 'copia_shell: este hosting no permite ejecutar mysqldump desde la web (shell_exec deshabilitado). La copia se hará desde el panel de Alwaysdata.'];
        }
        $archivo = $dir . '/adyp-' . date('Ymd-His') . '.sql';
        $cmd = 'MYSQL_PWD=' . escapeshellarg($CFG['pass'])
            . ' mysqldump --host=' . escapeshellarg($CFG['host'])
            . ' --user=' . escapeshellarg($CFG['user'])
            . ' --single-transaction --quick --routines --triggers'
            . ' ' . escapeshellarg($CFG['bd'])
            . ' > ' . escapeshellarg($archivo) . ' 2>/dev/null';
        $err = shell_exec($cmd);
        if (!file_exists($archivo) || filesize($archivo) < 2048) {
            @unlink($archivo);
            return ['ok' => false, 'error' => 'copia_dump: el volcado no se ha generado (¿credenciales o binario mysqldump no disponible?). ' . trim((string)$err)];
        }
        /* Retención: 7 días. */
        foreach (glob($dir . '/adyp-*.sql') ?: [] as $viejo) {
            if (time() - filemtime($viejo) > 7 * 86400) @unlink($viejo);
        }
        return ['ok' => true, 'archivo' => basename($archivo), 'tamano' => filesize($archivo)];
    };

    $r = $dump($dir);
    if (!$r['ok']) {
        auditar_bd($pdo, (int)$u['id'], 'copia.fallo', ['error' => $r['error']]);
        salir(['ok' => false, 'error' => $r['error']], 500);
    }
    auditar_bd($pdo, (int)$u['id'], 'copia.manual', ['archivo' => $r['archivo'], 'kb' => round($r['tamano'] / 1024)]);
    $st = $pdo->query('SELECT COUNT(*) FROM eventos'); $ne = (int)$st->fetchColumn();
    $st = $pdo->query('SELECT COUNT(*) FROM inscripciones'); $ni = (int)$st->fetchColumn();
    $st = $pdo->query('SELECT COUNT(*) FROM usuarios'); $nu = (int)$st->fetchColumn();
    salir([
        'ok' => true,
        'archivo' => $r['archivo'],
        'kb' => round($r['tamano'] / 1024),
        'cuando' => date('c'),
        'filas' => ['eventos' => $ne, 'inscripciones' => $ni, 'usuarios' => $nu],
        'proxima' => 'cada 24 h · retención 7 días',
    ]);
}

/* A partir de aquí todo requiere sesión. */
if (!$u) {
    salir(['ok' => false, 'error' => 'sin_sesion'], 401);
}

function aplicarTramos($b)
{
    $t = is_array($b['tramos'] ?? null) ? $b['tramos'] : json_decode((string)($b['tramos'] ?? '[]'), true);
    $t = array_values(array_filter((array)$t));
    $ninos = !empty($b['permite_ninos']) || in_array('jovenes', $t, true) || in_array('mixto', $t, true);
    return [
        'disfrutines' => $ninos ? 1 : 0,
        'disfruteros' => (in_array('core', $t, true) || in_array('mixto', $t, true) || !$t) ? 1 : 0,
        'disfrutones' => (in_array('senior', $t, true) || in_array('mixto', $t, true)) ? 1 : 0,
    ];
}

/* ------------------------------ INSCRIBIR --------------------------------- */
if ($accion === 'inscribir') {
    $eid = (int)($IN['evento_id'] ?? 0);
    $st = $pdo->prepare('SELECT * FROM eventos WHERE id = ?');
    $st->execute([$eid]);
    $e = $st->fetch();
    if (!$e) salir(['ok' => false, 'error' => 'no_existe'], 404);
    if ($e['estado'] !== 'Aprobado') salir(['ok' => false, 'error' => 'no_aprobada']);
    if ($e['fecha'] < $hoy) salir(['ok' => false, 'error' => 'pasada']);
    if ((int)$e['inscripciones_cerradas'] === 1) salir(['ok' => false, 'error' => 'cerrada']);

    $versionEsperada = (string)($IN['version_esperada'] ?? '');
    if ($versionEsperada !== '' && (int)$versionEsperada !== (int)$e['version']) {
        salir(['ok' => false, 'error' => 'ha_cambiado_alguien_mientras_tanto']);
    }
    $ninos = max(0, (int)($IN['ninos'] ?? 0));
    $acomp = max(0, (int)($IN['acompanantes'] ?? 0));
    $personas = 1 + $ninos + $acomp;
    /* Mismo tope por socio que la web: organizadores + peques + acompañantes. */
    $maxPorSocio = ((int)$e['max_acompanantes'] > 0 ? (int)$e['max_acompanantes'] : 0) + 1 + (((int)$e['disfrutines'] === 1) ? 2 : 0);
    if ($personas > $maxPorSocio) salir(['ok' => false, 'error' => 'demasiados_acompanantes']);
    $acepta = (($IN['acepta_lista_espera'] ?? '') === '1');

    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT plazas FROM eventos WHERE id = ? FOR UPDATE');
        $st->execute([$eid]);
        $plazas = (int)$st->fetchColumn();
        $st = $pdo->prepare(
            'SELECT COALESCE(SUM(1 + COALESCE(num_acompanantes,0) + COALESCE(num_ninos,0)), 0)
             FROM inscripciones WHERE evento_id = ? AND estado = "confirmada"'
        );
        $st->execute([$eid]);
        $ocupadas = (int)$st->fetchColumn();
        $libres = $plazas > 0 ? $plazas - $ocupadas : null;

        $st = $pdo->prepare('SELECT * FROM inscripciones WHERE evento_id = ? AND usuario_id = ? FOR UPDATE');
        $st->execute([$eid, (int)$u['id']]);
        $mi = $st->fetch();
        if ($mi && in_array($mi['estado'], ['confirmada', 'en_espera'], true)) {
            $pdo->rollBack();
            salir(['ok' => false, 'error' => 'ya_inscrito']);
        }

        $cabe = $libres === null || $libres >= $personas;
        $turno = null;
        if (!$cabe) {
            if (!$acepta) {
                $pdo->rollBack();
                salir(['ok' => false, 'error' => $personas > 1 ? 'no_caben:' . max(0, $libres) : 'lleno']);
            }
            $estadoNueva = 'en_espera';
            $st = $pdo->prepare('SELECT COALESCE(MAX(turno), 0) + 1 FROM inscripciones WHERE evento_id = ? AND estado = "en_espera"');
            $st->execute([$eid]);
            $turno = (int)$st->fetchColumn();
        } else {
            $estadoNueva = 'confirmada';
        }
        $valores = [
            $estadoNueva, $turno, $ninos, $acomp,
            !empty($IN['coche']) ? 1 : 0,
            max(0, (int)($IN['plazas_coche'] ?? 0)),
            (string)($IN['zona_salida'] ?? '') ?: null,
        ];
        if ($mi) {
            $st = $pdo->prepare(
                'UPDATE inscripciones
                 SET estado = ?, turno = ?, num_ninos = ?, num_acompanantes = ?,
                     necesita_plaza_coche = ?, ofrece_plazas_coche = ?, zona_salida = ?,
                     checkin_en = NULL, asistencia = NULL, origen = "inscripcion"
                 WHERE id = ?'
            );
            $st->execute(array_merge($valores, [$mi['id']]));
        } else {
            $st = $pdo->prepare(
                'INSERT INTO inscripciones
                    (evento_id, usuario_id, estado, origen, turno, num_ninos, num_acompanantes,
                     necesita_plaza_coche, ofrece_plazas_coche, zona_salida)
                 VALUES (?, ?, ?, "inscripcion", ?, ?, ?, ?, ?, ?)'
            );
            $st->execute(array_merge([$eid, (int)$u['id']], $valores));
        }
        $pdo->commit();
    } catch (Throwable $t) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $t;
    }

    $evento = cargarEvento($pdo, $eid);
    $siguiente = $evento['inscripciones'][0]['socio_id']
        ?? array_values(array_filter($evento['inscripciones'], fn($x) => $x['estado'] === 'en_espera'))[0]['socio_id'] ?? null;
    auditar_bd($pdo, (int)$u['id'], $estadoNueva === 'en_espera' ? 'inscripcion.espera' : 'inscripcion.alta',
        ['evento' => $eid, 'usuario' => (int)$u['id'], 'personas' => $personas, 'turno' => $turno]);
    salir([
        'ok' => true,
        /* El vocabulario de la fila en la BD es 'en_espera'; la app dice 'espera'. */
        'tipo' => $estadoNueva === 'en_espera' ? 'espera' : 'confirmada',
        'turno' => $turno,
        'siguiente_en_cola' => $siguiente,
        'evento' => $evento,
    ]);
}

/* ------------------------- CANCELAR INSCRIPCIÓN ---------------------------- */
if ($accion === 'cancelar_inscripcion') {
    $eid = (int)($IN['evento_id'] ?? 0);
    $st = $pdo->prepare('SELECT * FROM eventos WHERE id = ?');
    $st->execute([$eid]);
    $e = $st->fetch();
    if (!$e) salir(['ok' => false, 'error' => 'no_existe'], 404);
    if ($e['creador_id'] !== null && (int)$e['creador_id'] === (int)$u['id']) {
        salir(['ok' => false, 'error' => 'organizador_no_cancela']);
    }
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT * FROM inscripciones WHERE evento_id = ? AND usuario_id = ? FOR UPDATE');
        $st->execute([$eid, (int)$u['id']]);
        $mi = $st->fetch();
        if (!$mi || !in_array($mi['estado'], ['confirmada', 'en_espera'], true)) {
            $pdo->rollBack();
            salir(['ok' => false, 'error' => 'no_encontrada']);
        }
        $pdo->prepare('UPDATE inscripciones SET estado = "cancelada" WHERE id = ?')->execute([$mi['id']]);

        /* ¿Sigue habiendo hueco? Entonces sube el primero de la cola. */
        $promovido = null;
        $plazas = (int)$e['plazas'];
        if ($plazas > 0) {
            $st = $pdo->prepare(
                'SELECT COALESCE(SUM(1 + COALESCE(num_acompanantes,0) + COALESCE(num_ninos,0)), 0)
                 FROM inscripciones WHERE evento_id = ? AND estado = "confirmada"'
            );
            $st->execute([$eid]);
            $ocupadas = (int)$st->fetchColumn();
            if ($ocupadas < $plazas) {
                $st = $pdo->prepare(
                    'SELECT i.id, u.nombre FROM inscripciones i
                     JOIN usuarios u ON u.id = i.usuario_id
                     WHERE i.evento_id = ? AND i.estado = "en_espera"
                     ORDER BY i.turno, i.id LIMIT 1'
                );
                $st->execute([$eid]);
                $sig = $st->fetch();
                if ($sig) {
                    $pdo->prepare('UPDATE inscripciones SET estado = "confirmada", turno = NULL WHERE id = ?')->execute([$sig['id']]);
                    $promovido = $sig['nombre'];
                }
            }
        }
        $pdo->commit();
    } catch (Throwable $t) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $t;
    }
    auditar_bd($pdo, (int)$u['id'], 'inscripcion.cancelacion',
        ['evento' => $eid, 'usuario' => (int)$u['id'], 'promovido' => $promovido]);
    salir(['ok' => true, 'promovido' => $promovido, 'evento' => cargarEvento($pdo, $eid)]);
}

/* --------------------------------- CREAR ---------------------------------- */
if ($accion === 'crear') {
    $b = $IN;
    $errores = validarCreacion($b, $hoy, $pdo);
    if ($errores) salir(['ok' => false, 'error' => 'validacion', 'errores' => $errores], 400);

    $tr = aplicarTramos($b);
    $material = is_array($b['material'] ?? null)
        ? implode("\n", array_map('trim', (array)$b['material']))
        : (string)($b['material'] ?? '');
    $st = $pdo->prepare(
        'INSERT INTO eventos
            (titulo, descripcion, fecha, hora_inicio, hora_fin, estado, es_publico,
             categoria_id, subcategoria_id, zona_id, lugar, direccion_completa, poblacion,
             coordenadas_lat, coordenadas_lng, grupo_whatsapp, imagen, precio, plazas,
             edad_minima, edad_maxima, permite_acompanantes, max_acompanantes,
             disfrutines, disfruteros, disfrutones, material, dificultad, distancia_km,
             desnivel_m, terreno, ritmo, notas_organizador, nota_precio, version, creador_id,
             creado_en, actualizado_en)
         VALUES
            (?, ?, ?, ?, ?, "Pendiente", ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
             ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, NOW(), NOW())'
    );
    $st->execute([
        trim((string)$b['titulo']),
        trim((string)$b['descripcion']),
        $b['fecha'],
        (string)$b['hora_inicio'],
        !empty($b['hora_fin_prevista']) ? (string)$b['hora_fin_prevista'] : null,
        array_key_exists('es_publica', $b) ? (!empty($b['es_publica']) ? 1 : 0) : 1,
        (int)$b['categoria_id'],
        (int)$b['subcategoria_id'],
        !empty($b['zona_id']) ? (int)$b['zona_id'] : null,
        trim((string)($b['punto_encontrada'] ?? '')),
        !empty($b['direccion']) ? trim((string)$b['direccion']) : null,
        !empty($b['lugar']['comarca']) ? trim((string)$b['lugar']['comarca'])
            : (!empty($b['poblacion']) ? trim((string)$b['poblacion']) : null),
        !empty($b['coordenadas']['lat']) ? (float)$b['coordenadas']['lat'] : null,
        !empty($b['coordenadas']['lng']) ? (float)$b['coordenadas']['lng'] : null,
        !empty($b['grupo']['enlace']) ? trim((string)$b['grupo']['enlace']) : null,
        !empty($b['foto_portada']) ? (string)$b['foto_portada'] : null,
        round((float)($b['precio'] ?? 0), 2),
        (int)$b['plazas'],
        !empty($b['ninos_min_edad']) ? (int)$b['ninos_min_edad'] : null,
        !empty($b['edad_maxima']) ? (int)$b['edad_maxima'] : null,
        !empty($b['permite_acompanantes']) ? 1 : 0,
        !empty($b['acompanantes_max']) ? (int)$b['acompanantes_max'] : 0,
        $tr['disfrutines'], $tr['disfruteros'], $tr['disfrutones'],
        $material !== '' ? $material : null,
        !empty($b['dificultad']) ? (int)$b['dificultad'] : null,
        !empty($b['distancia_km']) ? (float)$b['distancia_km'] : null,
        !empty($b['desnivel_m']) ? (int)$b['desnivel_m'] : null,
        !empty($b['terreno']) ? trim((string)$b['terreno']) : null,
        !empty($b['ritmo']) ? trim((string)$b['ritmo']) : null,
        !empty($b['notas_organizador']) ? trim((string)$b['notas_organizador']) : null,
        !empty($b['nota_precio']) ? trim((string)$b['nota_precio']) : null,
        (int)$u['id'],
    ]);
    $id = (int)$pdo->lastInsertId();
    /* Quien propone va inscrito por diseño (y no puede cancelarse a sí mismo). */
    $pdo->prepare(
        'INSERT INTO inscripciones (evento_id, usuario_id, estado, origen, num_ninos, num_acompanantes)
         VALUES (?, ?, "confirmada", "organizador", 0, 0)'
    )->execute([$id, (int)$u['id']]);
    auditar_bd($pdo, (int)$u['id'], 'quedada.creacion', ['evento' => $id, 'usuario' => (int)$u['id']]);
    salir(['ok' => true, 'id' => $id, 'evento' => cargarEvento($pdo, $id)]);
}

/* -------------------------------- MODERAR --------------------------------- */
if ($accion === 'moderar') {
    if (!esEquipo($u['rol'])) salir(['ok' => false, 'error' => 'sin_permiso:moderacion'], 403);
    $eid = (int)($IN['evento_id'] ?? 0);
    $accionM = (string)($IN['accion_moderacion'] ?? '');
    if (!isset($ESTADO_BD[$accionM])) salir(['ok' => false, 'error' => 'accion_desconocida'], 400);
    $pdo->prepare(
        'UPDATE eventos SET estado = ?, version = version + 1, actualizado_en = NOW() WHERE id = ?'
    )->execute([$ESTADO_BD[$accionM], $eid]);
    auditar_bd($pdo, (int)$u['id'], 'moderacion.' . $accionM, ['evento' => $eid, 'usuario' => (int)$u['id']]);
    salir(['ok' => true, 'evento' => cargarEvento($pdo, $eid)]);
}

/* ------------------------------- EDITAR ----------------------------------- */
if ($accion === 'editar') {
    if (!esEquipo($u['rol'])) salir(['ok' => false, 'error' => 'sin_permiso:editar_quedada'], 403);
    $eid = (int)($IN['evento_id'] ?? 0);
    $cambios = json_decode((string)($IN['cambios'] ?? '[]'), true);
    if (!is_array($cambios)) {
        $cambios = $IN['cambios'] ?? [];
    }
    $st = $pdo->prepare('SELECT * FROM eventos WHERE id = ?');
    $st->execute([$eid]);
    $e = $st->fetch();
    if (!$e) salir(['ok' => false, 'error' => 'no_existe'], 404);
    $versionEsperada = (string)($IN['version_esperada'] ?? '');
    if ($versionEsperada !== '' && (int)$versionEsperada !== (int)$e['version']) {
        salir(['ok' => false, 'error' => 'ha_cambiado_alguien_mientras_tanto']);
    }
    /* Fila = valores actuales + cambios, y se valida la fila completa
       (mismas reglas que lib/db.js → validarQuedadaAdmin). */
    $f = $e;
    $mapa = [
        'titulo' => 'titulo', 'descripcion' => 'descripcion', 'punto_encontrada' => 'lugar',
        'fecha' => 'fecha', 'hora_inicio' => 'hora_inicio', 'hora_fin_prevista' => 'hora_fin',
        'plazas' => 'plazas', 'precio' => 'precio', 'nota_precio' => 'nota_precio',
        'categoria_id' => 'categoria_id', 'subcategoria_id' => 'subcategoria_id',
        'dificultad' => 'dificultad', 'distancia_km' => 'distancia_km',
        'desnivel_m' => 'desnivel_m', 'terreno' => 'terreno', 'ritmo' => 'ritmo',
        'permite_ninos' => null,
    ];
    $tocaTramos = false;
    foreach ($mapa as $app => $col) {
        if (!array_key_exists($app, $cambios)) continue;
        $v = $cambios[$app];
        if ($col === null) { $tocaTramos = true; continue; }
        $f[$col] = $v;
    }
    $err = [];
    $titulo = trim((string)$f['titulo']);
    $desc = trim((string)$f['descripcion']);
    if (strlen($titulo) < 4 || strlen($titulo) > 90) $err[] = ['titulo', 'El título tiene que tener entre 4 y 90 caracteres.'];
    if (strlen($desc) < 20 || strlen($desc) > 4000) $err[] = ['descripcion', 'La descripción tiene que tener entre 20 y 4000 caracteres.'];
    if (empty($f['lugar'])) $err[] = ['punto_encontrada', 'Sin punto de encuentro la gente no sabe dónde ir.'];
    if (empty($f['fecha'])) $err[] = ['fecha', 'Falta el día.'];
    if (empty($f['hora_inicio'])) $err[] = ['hora_inicio', 'Falta la hora de salida.'];
    if (empty($f['hora_fin'])) $err[] = ['hora_fin_prevista', 'Falta la hora de vuelta.'];
    if (!empty($f['hora_inicio']) && $f['hora_inicio'] == $f['hora_fin']) {
        $err[] = ['hora_fin_prevista', 'La vuelta no puede ser a la misma hora que la salida.'];
    }
    $plazas = $f['plazas'] === null || $f['plazas'] === '' ? null : (int)$f['plazas'];
    /* null = aforo abierto (como nació la mitad de las quedadas de la BD). */
    if ($plazas !== null && ($plazas < 1 || $plazas > 400)) $err[] = ['plazas', 'El aforo va de 1 a 400 plazas.'];
    $precio = (float)$f['precio'];
    if ($precio < 0 || $precio > 500) $err[] = ['precio', 'El precio va de 0 a 500 €.'];
    $st = $pdo->prepare('SELECT id FROM categorias WHERE id = ?');
    $st->execute([(int)$f['categoria_id']]);
    if (!$st->fetch()) $err[] = ['categoria_id', 'Esa categoría no existe.'];
    $st = $pdo->prepare('SELECT id_cat FROM subcategorias WHERE id = ?');
    $st->execute([(int)$f['subcategoria_id']]);
    $subR = $st->fetch();
    if (!$subR) $err[] = ['subcategoria_id', 'Esa subcategoría no existe.'];
    elseif ($subR['id_cat'] != (int)$f['categoria_id']) $err[] = ['subcategoria_id', 'La subcategoría no es de esa categoría.'];
    if ((int)$f['categoria_id'] === 5 && (empty($f['distancia_km']) || empty($f['desnivel_m']) || empty($f['dificultad']))) {
        $err[] = ['dificultad', 'En montaña hacen falta km, desnivel y dificultad.'];
    }
    if ($err) salir(['ok' => false, 'error' => 'validacion', 'errores' => $err], 400);

    /* El aforo nunca puede quedar por debajo de la ocupación real. */
    $af = aforoBd($pdo, $eid, $f['plazas'], $f['version']);
    if ($plazas !== null && $plazas < (int)$af['ocupadas']) {
        salir(['ok' => false, 'error' => 'aforo_bajo_minimo:' . (int)$af['ocupadas']]);
    }

    $set = [];
    $val = [];
    $campos = [];
    foreach ($mapa as $app => $col) {
        if (!array_key_exists($app, $cambios) || $col === null) continue;
        $nuevo = $cambios[$app];
        if (is_numeric($nuevo) && !is_bool($nuevo)) $nuevo = (float)$nuevo;
        if ($app === 'plazas') {
            if ($nuevo !== null) $nuevo = (int)$nuevo;   // null deja el aforo abierto
        } elseif (in_array($app, ['categoria_id', 'subcategoria_id', 'dificultad', 'desnivel_m'], true)) {
            $nuevo = (int)$nuevo;
        }
        if ((string)$nuevo === (string)($e[$col] ?? '')) continue;
        $set[] = "$col = ?";
        $val[] = $nuevo;
        $campos[] = $app;
    }
    if ($tocaTramos) {
        $tr = aplicarTramos(['permite_ninos' => $cambios['permite_ninos'] ?? $e['disfrutines'], 'tramos' => $cambios['tramos'] ?? tramosDe($e)]);
        foreach (['disfrutines', 'disfruteros', 'disfrutones'] as $k) {
            if ((int)$e[$k] !== (int)$tr[$k]) {
                $set[] = "$k = ?";
                $val[] = $tr[$k];
                $campos[] = 'tramos';
                break;
            }
        }
    }
    if (!$set) salir(['ok' => false, 'error' => 'sin_cambios']);
    $val[] = (int)$eid;
    $pdo->prepare('UPDATE eventos SET ' . implode(', ', $set) . ', version = version + 1, actualizado_en = NOW() WHERE id = ?')
        ->execute($val);
    auditar_bd($pdo, (int)$u['id'], 'quedada.edicion',
        ['evento' => $eid, 'usuario' => (int)$u['id'], 'campos' => array_values(array_unique($campos))]);
    salir(['ok' => true, 'campos' => array_values(array_unique($campos)), 'evento' => cargarEvento($pdo, $eid)]);
}

/* --------------------------- AJUSTAR AFORO --------------------------------- */
if ($accion === 'ajustar_aforo') {
    if (!esEquipo($u['rol'])) salir(['ok' => false, 'error' => 'sin_permiso:ajustar_aforo'], 403);
    $eid = (int)($IN['evento_id'] ?? 0);
    $n = (int)($IN['plazas'] ?? 0);
    $st = $pdo->prepare('SELECT * FROM eventos WHERE id = ?');
    $st->execute([$eid]);
    $e = $st->fetch();
    if (!$e) salir(['ok' => false, 'error' => 'no_existe'], 404);
    if ($n < 1) salir(['ok' => false, 'error' => 'aforo_no_valido']);
    if ($n > 500) salir(['ok' => false, 'error' => 'aforo_demasiado_grande']);
    $af = aforoBd($pdo, $eid, $e['plazas'], $e['version']);
    if ($n < (int)$af['ocupadas']) salir(['ok' => false, 'error' => 'aforo_bajo_minimo:' . (int)$af['ocupadas']]);
    $pdo->prepare('UPDATE eventos SET plazas = ?, version = version + 1, actualizado_en = NOW() WHERE id = ?')
        ->execute([$n, $eid]);
    auditar_bd($pdo, (int)$u['id'], 'quedada.aforo',
        ['evento' => $eid, 'usuario' => (int)$u['id'], 'antes' => $e['plazas'], 'despues' => $n]);
    salir(['ok' => true, 'evento' => cargarEvento($pdo, $eid)]);
}

/* ------------------------ CERRAR INSCRIPCIONES ----------------------------- */
if ($accion === 'cerrar_inscripciones') {
    if (!esEquipo($u['rol'])) salir(['ok' => false, 'error' => 'sin_permiso:cerrar_inscripciones'], 403);
    $eid = (int)($IN['evento_id'] ?? 0);
    $cerrar = (($IN['cerrar'] ?? '0') === '1') ? 1 : 0;
    $pdo->prepare('UPDATE eventos SET inscripciones_cerradas = ?, version = version + 1, actualizado_en = NOW() WHERE id = ?')
        ->execute([$cerrar, $eid]);
    auditar_bd($pdo, (int)$u['id'], 'quedada.cierre_inscripciones',
        ['evento' => $eid, 'usuario' => (int)$u['id'], 'cerrado' => (bool)$cerrar]);
    salir(['ok' => true, 'evento' => cargarEvento($pdo, $eid)]);
}

/* ------------------------------- PUBLICAR ---------------------------------- */
if ($accion === 'publicar') {
    if (!esEquipo($u['rol'])) salir(['ok' => false, 'error' => 'sin_permiso:publicar_quedada'], 403);
    $eid = (int)($IN['evento_id'] ?? 0);
    $st = $pdo->prepare('SELECT estado FROM eventos WHERE id = ?');
    $st->execute([$eid]);
    $e = $st->fetch();
    if (!$e) salir(['ok' => false, 'error' => 'no_existe'], 404);
    if ($e['estado'] === 'Cancelada') salir(['ok' => false, 'error' => 'cancelada_no_publica']);
    $pdo->prepare('UPDATE eventos SET estado = "Aprobado", version = version + 1, actualizado_en = NOW() WHERE id = ?')
        ->execute([$eid]);
    auditar_bd($pdo, (int)$u['id'], 'quedada.publicacion', ['evento' => $eid, 'usuario' => (int)$u['id']]);
    salir(['ok' => true, 'evento' => cargarEvento($pdo, $eid)]);
}

/* --------------------------- CANCELAR EVENTO -------------------------------- */
if ($accion === 'cancelar_evento') {
    if (!esEquipo($u['rol'])) salir(['ok' => false, 'error' => 'sin_permiso:cancelar_quedada'], 403);
    $eid = (int)($IN['evento_id'] ?? 0);
    $motivo = trim((string)($IN['motivo'] ?? ''));
    if (strlen($motivo) < 12) salir(['ok' => false, 'error' => 'motivo_obligatorio:12']);
    $st = $pdo->prepare('SELECT * FROM eventos WHERE id = ?');
    $st->execute([$eid]);
    $e = $st->fetch();
    if (!$e) salir(['ok' => false, 'error' => 'no_existe'], 404);
    $st = $pdo->prepare('SELECT COUNT(*) FROM inscripciones WHERE evento_id = ? AND estado = "confirmada"');
    $st->execute([$eid]);
    $avisados = (int)$st->fetchColumn();
    $pdo->prepare(
        'UPDATE eventos SET estado = "Cancelada", motivo_cancelacion = ?, version = version + 1, actualizado_en = NOW() WHERE id = ?'
    )->execute([mb_substr($motivo, 0, 255), $eid]);
    auditar_bd($pdo, (int)$u['id'], 'quedada.cancelacion_admin',
        ['evento' => $eid, 'usuario' => (int)$u['id'], 'avisados' => $avisados, 'motivo' => mb_substr($motivo, 0, 80)]);
    salir(['ok' => true, 'avisados' => $avisados, 'evento' => cargarEvento($pdo, $eid)]);
}

/* --------------------------------- BORRAR ---------------------------------- */
if ($accion === 'borrar') {
    if (!esEquipo($u['rol'])) salir(['ok' => false, 'error' => 'sin_permiso:eliminar_quedada'], 403);
    $eid = (int)($IN['evento_id'] ?? 0);
    $st = $pdo->prepare('SELECT COUNT(*) FROM inscripciones WHERE evento_id = ?');
    $st->execute([$eid]);
    $inscripciones = (int)$st->fetchColumn();
    $pdo->prepare('DELETE FROM eventos WHERE id = ?')->execute([$eid]);
    auditar_bd($pdo, (int)$u['id'], 'quedada.eliminacion',
        ['evento' => $eid, 'usuario' => (int)$u['id'], 'inscripciones' => $inscripciones]);
    salir(['ok' => true, 'id' => $eid, 'inscripciones' => $inscripciones]);
}

/* -------------------------------- DUPLICAR --------------------------------- */
if ($accion === 'duplicar') {
    if (!esEquipo($u['rol'])) salir(['ok' => false, 'error' => 'sin_permiso:duplicar_quedada'], 403);
    $eid = (int)($IN['evento_id'] ?? 0);
    $fecha = (string)($IN['fecha'] ?? '');
    $st = $pdo->prepare('SELECT * FROM eventos WHERE id = ?');
    $st->execute([$eid]);
    $e = $st->fetch();
    if (!$e) salir(['ok' => false, 'error' => 'no_existe'], 404);
    if (!$fecha) salir(['ok' => false, 'error' => 'falta_fecha']);
    if ($fecha <= $e['fecha']) salir(['ok' => false, 'error' => 'fecha_anterior']);
    $pdo->prepare(
        'INSERT INTO eventos
            (titulo, descripcion, fecha, hora_inicio, hora_fin, estado, es_publico,
             categoria_id, subcategoria_id, zona_id, lugar, direccion_completa, poblacion, provincia,
             coordenadas_lat, coordenadas_lng, imagen, precio, plazas,
             edad_minima, edad_maxima, permite_acompanantes, max_acompanantes,
             disfrutines, disfruteros, disfrutones, material, dificultad, distancia_km,
             desnivel_m, terreno, ritmo, notas_organizador, nota_precio, version, creador_id,
             creado_en, actualizado_en)
         VALUES
            (?, ?, ?, ?, ?, "Pendiente", 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
             ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, NOW(), NOW())'
    )->execute([
        $e['titulo'], $e['descripcion'], $fecha, $e['hora_inicio'], $e['hora_fin'],
        $e['categoria_id'], $e['subcategoria_id'], $e['zona_id'], $e['lugar'], $e['direccion_completa'],
        $e['poblacion'], $e['provincia'], $e['coordenadas_lat'], $e['coordenadas_lng'], $e['imagen'],
        $e['precio'], $e['plazas'], $e['edad_minima'], $e['edad_maxima'], $e['permite_acompanantes'],
        $e['max_acompanantes'], $e['disfrutines'], $e['disfruteros'], $e['disfrutones'],
        $e['material'], $e['dificultad'], $e['distancia_km'], $e['desnivel_m'], $e['terreno'],
        $e['ritmo'], $e['notas_organizador'], $e['nota_precio'], (int)$u['id'],
    ]);
    $nuevo = (int)$pdo->lastInsertId();
    $pdo->prepare(
        'INSERT INTO inscripciones (evento_id, usuario_id, estado, origen, num_ninos, num_acompanantes)
         VALUES (?, ?, "confirmada", "organizador", 0, 0)'
    )->execute([$nuevo, (int)$u['id']]);
    auditar_bd($pdo, (int)$u['id'], 'quedada.duplicada',
        ['evento' => $nuevo, 'origen' => $eid, 'usuario' => (int)$u['id']]);
    salir(['ok' => true, 'evento' => cargarEvento($pdo, $nuevo)]);
}

/* ---------------------------- PROMOVER DE ESPERA --------------------------- */
if ($accion === 'promover') {
    if (!esEquipo($u['rol'])) salir(['ok' => false, 'error' => 'sin_permiso:promover_espera'], 403);
    $eid = (int)($IN['evento_id'] ?? 0);
    /* La app manda socio_ids del tipo 'u4'; a la BD va el número. 0 = el primero. */
    $sid = (int)str_replace('u', '', (string)($IN['socio_id'] ?? ''));
    $st = $pdo->prepare('SELECT * FROM eventos WHERE id = ?');
    $st->execute([$eid]);
    $e = $st->fetch();
    if (!$e) salir(['ok' => false, 'error' => 'no_existe'], 404);
    $pdo->beginTransaction();
    try {
        /* ¿Quién sube? El que se pide, o el primero de la cola si no se pide. */
        $st = $pdo->prepare(
            'SELECT i.id, (1 + COALESCE(i.num_acompanantes, 0) + COALESCE(i.num_ninos, 0)) AS gente
             FROM inscripciones i
             WHERE i.evento_id = ? AND i.estado = "en_espera" AND (? = 0 OR i.usuario_id = ?)
             ORDER BY i.turno, i.id LIMIT 1'
        );
        $st->execute([$eid, $sid, $sid]);
        $sig = $st->fetch();
        if (!$sig) {
            $pdo->rollBack();
            salir(['ok' => false, 'error' => 'cola_vacia']);
        }
        /* El hueco se comprueba con la ocupación REAL (derivada, nunca a mano). */
        $plazas = (int)$e['plazas'];
        if ($plazas > 0) {
            $st = $pdo->prepare(
                'SELECT COALESCE(SUM(1 + COALESCE(num_acompanantes,0) + COALESCE(num_ninos,0)), 0)
                 FROM inscripciones WHERE evento_id = ? AND estado = "confirmada"'
            );
            $st->execute([$eid]);
            $ocupadas = (int)$st->fetchColumn();
            $libres = $plazas - $ocupadas;
            if ($libres < (int)$sig['gente']) {
                $pdo->rollBack();
                salir(['ok' => false, 'error' => $libres < 1 ? 'sin_hueco' : 'no_caben:' . $libres]);
            }
        }
        $pdo->prepare('UPDATE inscripciones SET estado = "confirmada", turno = NULL WHERE id = ?')
            ->execute([$sig['id']]);
        /* El resto de la cola se renumera: el turno es una posición viva, no un
           número histórico que se queda huérfano al subir alguien. */
        $st = $pdo->prepare('SELECT id FROM inscripciones WHERE evento_id = ? AND estado = "en_espera" ORDER BY turno, id');
        $st->execute([$eid]);
        $n = 0;
        foreach ($st as $fila) {
            $n++;
            $pdo->prepare('UPDATE inscripciones SET turno = ? WHERE id = ?')->execute([$n, $fila['id']]);
        }
        $pdo->commit();
    } catch (Throwable $t) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $t;
    }
    auditar_bd($pdo, (int)$u['id'], 'lista_espera.promocion',
        ['evento' => $eid, 'usuario' => (int)$u['id']]);
    salir(['ok' => true, 'evento' => cargarEvento($pdo, $eid)]);
}

/* ----------------------------- FOTO DE EVENTO ------------------------------- */
if ($accion === 'foto_evento') {
    if (!esEquipo($u['rol'])) {
        $st = $pdo->prepare('SELECT creador_id FROM eventos WHERE id = ?');
        $st->execute([(int)($IN['evento_id'] ?? 0)]);
        $creador = $st->fetchColumn();
        if ($creador === null || (int)$creador !== (int)$u['id']) {
            salir(['ok' => false, 'error' => 'sin_permiso:foto_evento'], 403);
        }
    }
    $eid = (int)($IN['evento_id'] ?? 0);
    $url = trim((string)($IN['url'] ?? ''));
    $pdo->prepare('UPDATE eventos SET imagen = ?, version = version + 1, actualizado_en = NOW() WHERE id = ?')
        ->execute([$url !== '' ? $url : null, $eid]);
    auditar_bd($pdo, (int)$u['id'], 'quedada.foto', ['evento' => $eid, 'usuario' => (int)$u['id'], 'foto' => $url !== '' ? $url : null]);
    salir(['ok' => true, 'evento' => cargarEvento($pdo, $eid)]);
}

/* --------------------------------- CHECKIN ---------------------------------- */
if ($accion === 'checkin') {
    $eid = (int)($IN['evento_id'] ?? 0);
    $qr = (string)($IN['qr'] ?? '');
    if (!preg_match('/^adyp-(\d+)-u(\d+)$/', $qr, $m)) {
        salir(['ok' => false, 'error' => 'QR no válido para esta quedada']);
    }
    if ((int)$m[1] !== $eid) salir(['ok' => false, 'error' => 'QR no válido para esta quedada']);
    $st = $pdo->prepare('SELECT * FROM eventos WHERE id = ?');
    $st->execute([$eid]);
    $e = $st->fetch();
    if (!$e) salir(['ok' => false, 'error' => 'QR no válido para esta quedada']);
    if (!esEquipo($u['rol']) && ($e['creador_id'] === null || (int)$e['creador_id'] !== (int)$u['id'])) {
        salir(['ok' => false, 'error' => 'Esto lo puede hacer quien organiza o el equipo.'], 403);
    }
    $st = $pdo->prepare('SELECT i.*, u.nombre FROM inscripciones i JOIN usuarios u ON u.id = i.usuario_id WHERE i.evento_id = ? AND i.usuario_id = ?');
    $st->execute([$eid, (int)$m[2]]);
    $ins = $st->fetch();
    if (!$ins || $ins['estado'] !== 'confirmada') salir(['ok' => false, 'error' => 'QR no válido para esta quedada']);
    if ($ins['checkin_en'] !== null) {
        salir(['ok' => false, 'error' => 'Ya ha entrado: ' . $ins['nombre']]);
    }
    $pdo->prepare('UPDATE inscripciones SET checkin_en = NOW() WHERE id = ?')->execute([$ins['id']]);
    auditar_bd($pdo, (int)$u['id'], 'checkin', ['evento' => $eid, 'usuario' => (int)$u['id'], 'entrante' => (int)$m[2]]);
    salir([
        'ok' => true,
        'socio' => ['id' => 'u' . $m[2], 'nombre' => $ins['nombre']],
        'entrada' => date('c'),
        'token_usado' => true,
        'evento' => cargarEvento($pdo, $eid),
    ]);
}

/* -------------------------------- ASISTENCIA -------------------------------- */
if ($accion === 'asistencia') {
    if (!esEquipo($u['rol'])) salir(['ok' => false, 'error' => 'sin_permiso:marcar_asistencia'], 403);
    $eid = (int)($IN['evento_id'] ?? 0);
    $sid = (int)($IN['socio_id'] ?? 0);
    $valor = (string)($IN['valor'] ?? '');
    if (!in_array($valor, ['presente', 'ausente', ''], true)) salir(['ok' => false, 'error' => 'valor_no_valido']);
    $pdo->prepare('UPDATE inscripciones SET asistencia = ? WHERE evento_id = ? AND usuario_id = ? AND estado = "confirmada"')
        ->execute([$valor !== '' ? $valor : null, $eid, $sid]);
    auditar_bd($pdo, (int)$u['id'], 'asistencia.cambio',
        ['evento' => $eid, 'usuario' => (int)$u['id'], 'socio' => $sid, 'valor' => $valor !== '' ? $valor : null]);
    salir(['ok' => true, 'evento' => cargarEvento($pdo, $eid)]);
}

salir(['ok' => false, 'error' => 'accion_desconocida'], 400);
