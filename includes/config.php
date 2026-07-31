<?php
// ============================================================
// VetPro - Configuración del Sistema
// Auto-detecta entorno LOCAL vs PRODUCCIÓN por hostname
// ============================================================

$__host      = $_SERVER['HTTP_HOST'] ?? gethostname();
$__isWindows = DIRECTORY_SEPARATOR === '\\';
$__isLocal   = (
    $__isWindows ||
    str_contains($__host, 'localhost') ||
    str_contains($__host, '127.0.0.1') ||
    str_contains($__host, '.test')     ||
    str_contains($__host, '.local')
);

if ($__isLocal) {
    // ════════ LOCAL ════════
    define('DB_HOST', 'localhost');
    define('DB_NAME', 'vetpro');
    define('DB_USER', 'root');
    define('DB_PASS', '');
    define('BASE_URL', 'http://localhost/vetPro');
    define('APP_ENV',  'development');
    define('MIGRATIONS_TOKEN', 'dev_local_token_no_importa');
    define('MANTENIMIENTO_TOKEN', 'dev_local_token_no_importa');
} else {
    // ════════ PRODUCCIÓN ════════
    define('DB_HOST', 'localhost');
    define('DB_NAME', 'vet_guadalupe');
    define('DB_USER', 'root');
    define('DB_PASS', 'c4p1cu4$$');
    define('BASE_URL', 'https://magus-ecommerce.com/vet_guadalupe');
    define('APP_ENV',  'production');
    define('MIGRATIONS_TOKEN', 'CAMBIAR_POR_TOKEN_LARGO_Y_ALEATORIO');
    // Llave del módulo de mantenimiento SUNAT. Sin ella el módulo no existe:
    // no figura en el menú y responde 404 aunque entres con cuenta de admin.
    define('MANTENIMIENTO_TOKEN', 'b4cd29f935b093602767da483a14c2749f6a8536cca7f270');
}

define('DB_CHARSET',   'utf8mb4');
define('BASE_PATH',    dirname(__DIR__));
define('UPLOADS_PATH', BASE_PATH . '/public/uploads');
define('UPLOADS_URL',  BASE_URL  . '/public/uploads');

define('APP_NAME',    'VetPro');
define('APP_VERSION', '1.0.0');

define('SESSION_NAME',     'vetpro_session');
define('SESSION_LIFETIME', 28800); // 8 horas

date_default_timezone_set('America/Lima');

session_name(SESSION_NAME);
session_start();

// ── Conexión PDO ─────────────────────────────────────────────
function getDB() {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            if (APP_ENV === 'development') {
                die('Error de conexión: ' . $e->getMessage());
            } else {
                die('Error de conexión con la base de datos. Contacta al administrador.');
            }
        }
    }
    return $pdo;
}

// ── Autenticación ────────────────────────────────────────────
function getUser()  { return $_SESSION['user'] ?? null; }
function isLogged() { return isset($_SESSION['user']); }

function requireLogin() {
    if (!isLogged()) {
        header('Location: ' . BASE_URL . '/login.php');
        exit;
    }
}

function hasRole($roles) {
    $user = getUser();
    if (!$user) return false;
    if (is_string($roles)) $roles = [$roles];
    return in_array($user['rol'], $roles);
}

// ── Permisos granulares ───────────────────────────────────────
function loadPermisos() {
  //  if (isset($_SESSION['permisos'])) return $_SESSION['permisos'];
	
static $cache = null;
    if ($cache !== null) return $cache;


	$user = getUser();
    if (!$user) return [];
    if ($user['rol'] === 'admin') {
    
    $cache = ['__admin__' => true];
        return $cache;
    
    }
    try {
        $db = getDB();
        $st = $db->prepare("SELECT modulo,puede_ver,puede_crear,puede_editar,puede_eliminar,puede_exportar FROM permisos WHERE rol=?");
        $st->execute([$user['rol']]);
        $permisos = [];
        foreach ($st->fetchAll() as $p) {
            $permisos[$p['modulo']] = [
                'ver'      => (bool)$p['puede_ver'],
                'crear'    => (bool)$p['puede_crear'],
                'editar'   => (bool)$p['puede_editar'],
                'eliminar' => (bool)$p['puede_eliminar'],
                'exportar' => (bool)$p['puede_exportar'],
            ];
        }
    
    $cache = $permisos;
        return $cache;
    
    } catch(Exception $e) { return []; }
}

function can($modulo, $accion = 'ver') {
    $user = getUser();
    if (!$user) return false;
    if ($user['rol'] === 'admin') return true;
    $permisos = loadPermisos();
    if (isset($permisos['__admin__'])) return true;
    return $permisos[$modulo][$accion] ?? false;
}

function canView($modulo)   { return can($modulo, 'ver'); }
function canCreate($modulo) { return can($modulo, 'crear'); }
function canEdit($modulo)   { return can($modulo, 'editar'); }
function canDelete($modulo) { return can($modulo, 'eliminar'); }
function canExport($modulo) { return can($modulo, 'exportar'); }

function clearPermisosCache() { unset($_SESSION['permisos']); }

function auditLog($accion, $modulo, $descripcion = '') {
    try {
        $user = getUser();
        if (!$user) return;
        $db = getDB();
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $db->prepare("INSERT INTO auditoria (usuario_id,usuario_nombre,rol,accion,modulo,descripcion,ip) VALUES (?,?,?,?,?,?,?)")
           ->execute([$user['id'],$user['nombre'],$user['rol'],$accion,$modulo,$descripcion,substr($ip,0,45)]);
    } catch(Exception $e) {}
}

// ── Sistema de Sedes ──────────────────────────────────────────

// Sede activa del usuario en esta sesión
function getSede() {
    return (int)($_SESSION['sede_id'] ?? getUser()['sede_id'] ?? 1);
}

// Admin eligió "ver todas las sedes"
function verTodasSedes() {
    $user = getUser();
    return $user && $user['rol'] === 'admin'
        && ($_SESSION['ver_todas_sedes'] ?? false) === true;
}

// WHERE SQL para filtrar: "m.sede_id=2" o "1=1" si ver todas
function sedeWhere($alias = '', $col = 'sede_id') {
    $user = getUser();
    if (!$user) return "1=0";
    if (verTodasSedes()) return "1=1";
    $prefix = $alias ? "$alias." : "";
    return "{$prefix}{$col}=" . getSede();
}

// " AND m.sede_id=2" listo para concatenar (vacío si ver todas)
function andSede($alias = '', $col = 'sede_id') {
    if (verTodasSedes()) return '';
    $prefix = $alias ? "$alias." : "";
    return " AND {$prefix}{$col}=" . getSede();
}

// "sede_id=2" sin alias (vacío si ver todas)
function whereSedeSimple($col = 'sede_id') {
    if (verTodasSedes()) return "1=1";
    return "{$col}=" . getSede();
}

// Agrega columna sede_id a tabla si no existe (MariaDB 10.5 compatible)
function ensureSedeCol($db, $tabla) {
    try {
        $r = $db->query("SHOW COLUMNS FROM `$tabla` LIKE 'sede_id'")->fetchAll();
        if (empty($r)) $db->exec("ALTER TABLE `$tabla` ADD COLUMN sede_id INT DEFAULT 1");
    } catch(Exception $e) {}
}

// ── Helpers generales ─────────────────────────────────────────

function jsonResponse($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function clean($str) {
    return htmlspecialchars(trim($str ?? ''), ENT_QUOTES, 'UTF-8');
}

function formatMoney($amount) {
    return 'S/. ' . number_format($amount, 2);
}

function formatDate($date) {
    if (!$date) return '—';
    $ts    = strtotime($date);
    $dias  = ['Domingo','Lunes','Martes','Miércoles','Jueves','Viernes','Sábado'];
    $meses = ['','Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
    return $dias[date('w',$ts)] . ', ' . date('d',$ts) . ' de ' . $meses[(int)date('n',$ts)] . ' ' . date('Y',$ts);
}

/**
 * Devuelve el próximo correlativo a emitir para una serie.
 * Respeta el "correlativo inicial" configurado en `configuracion.correlativo_inicio_{SERIE}`
 * (usado por empresas que migran desde otro sistema).
 *
 * Fórmula:  proximo = max(MAX(ventas.numero) + 1, correlativo_inicio_configurado)
 */
function siguienteNumeroSerie(PDO $db, string $serie): int {
    try {
        $st = $db->prepare("SELECT COALESCE(MAX(numero),0)+1 FROM ventas WHERE serie=?");
        $st->execute([$serie]);
        $siguienteReal = (int)$st->fetchColumn();

        $st = $db->prepare("SELECT valor FROM configuracion WHERE clave=?");
        $st->execute(['correlativo_inicio_' . $serie]);
        $inicio = (int)$st->fetchColumn();

        return $inicio > 0 ? max($siguienteReal, $inicio) : $siguienteReal;
    } catch (Exception $e) {
        return 1;
    }
}

/**
 * Arma un mensaje de error útil a partir de una respuesta de SunatService.
 *
 * SunatService cae en un texto genérico ("Error al generar XML.", "HTTP: Bad
 * Request") cuando la respuesta no trae la clave `mensaje`. El motivo real
 * queda en `detalle` —el cuerpo completo que devolvió la API— y sin esto se
 * perdía, dejando al usuario con un error que no dice nada.
 */
function explicarErrorSunat(array $r): string {
    $base = trim((string)($r['mensaje'] ?? '')) ?: 'Error desconocido.';
    $d    = $r['detalle'] ?? null;
    if (!is_array($d)) return $base;

    $partes = [];

    // Validación estilo Laravel: {"message": "...", "errors": {"campo": ["..."]}}
    if (!empty($d['errors']) && is_array($d['errors'])) {
        foreach ($d['errors'] as $campo => $msgs) {
            $partes[] = $campo . ': ' . (is_array($msgs) ? implode(' / ', $msgs) : (string)$msgs);
        }
    }
    foreach (['message', 'error', 'detalle', 'descripcion', 'mensaje_sunat', 'codigo'] as $k) {
        if (!empty($d[$k]) && is_scalar($d[$k])) { $partes[] = $k . ': ' . $d[$k]; }
    }

    // Último recurso: el cuerpo crudo, recortado.
    if (!$partes) {
        $crudo = $d['raw'] ?? json_encode($d, JSON_UNESCAPED_UNICODE);
        $partes[] = mb_substr((string)$crudo, 0, 400);
    }

    $http = isset($d['http']) ? ' [HTTP ' . $d['http'] . ']' : '';
    return $base . $http . ' → ' . implode(' | ', $partes);
}

/**
 * Registra el egreso que compensa la anulación de una venta.
 *
 * `movimientos_caja` no guarda estado y caja.php suma sus filas en crudo, así
 * que anular una venta no descuenta nada por sí solo. En vez de borrar el
 * ingreso original —lo que reescribiría el arqueo de una caja ya cerrada—
 * se asienta un egreso por el mismo importe en la caja ABIERTA: la devolución
 * impacta el día en que ocurre, que es como se lleva un libro de caja.
 *
 * Se apoya en los ingresos realmente asentados (no en `ventas.total`) para que
 * un pago mixto o un cobro parcial se devuelvan por su monto y método reales.
 *
 * @return int Cantidad de egresos asentados (0 si no hay caja abierta o ya se compensó).
 */
function registrarEgresoAnulacion(PDO $db, int $ventaId, int $usuarioId, string $motivo = 'Anulación'): int {
    try {
        $caja = $db->query("SELECT id FROM cajas WHERE estado='abierta' ORDER BY id DESC LIMIT 1")->fetchColumn();
        if (!$caja) return 0;

        // Ingresos ya asentados por esta venta.
        $st = $db->prepare("
            SELECT metodo_pago, SUM(monto) AS monto
            FROM movimientos_caja
            WHERE venta_id=? AND tipo='ingreso'
            GROUP BY metodo_pago
        ");
        $st->execute([$ventaId]);
        $ingresos = $st->fetchAll();
        if (!$ingresos) return 0;

        // Egresos ya compensados antes (evita duplicar si se reintenta).
        $st = $db->prepare("
            SELECT metodo_pago, SUM(monto) AS monto
            FROM movimientos_caja
            WHERE venta_id=? AND tipo='egreso'
            GROUP BY metodo_pago
        ");
        $st->execute([$ventaId]);
        $yaDevuelto = [];
        foreach ($st->fetchAll() as $r) $yaDevuelto[$r['metodo_pago']] = (float)$r['monto'];

        $st = $db->prepare("SELECT serie, numero FROM ventas WHERE id=?");
        $st->execute([$ventaId]);
        $v = $st->fetch();
        $ref = $v ? $v['serie'] . '-' . str_pad((string)$v['numero'], 8, '0', STR_PAD_LEFT) : "venta #$ventaId";

        $ins = $db->prepare("
            INSERT INTO movimientos_caja (caja_id,usuario_id,tipo,concepto,monto,metodo_pago,categoria,venta_id)
            VALUES (?,?,'egreso',?,?,?,'otro',?)
        ");

        $n = 0;
        foreach ($ingresos as $g) {
            $pendiente = (float)$g['monto'] - ($yaDevuelto[$g['metodo_pago']] ?? 0);
            if ($pendiente <= 0) continue;
            $ins->execute([$caja, $usuarioId, "$motivo $ref", $pendiente, $g['metodo_pago'], $ventaId]);
            $n++;
        }
        return $n;
    } catch (Exception $e) {
        return 0;
    }
}





