<?php
/**
 * VetPro — API: Registro rápido de cliente + mascota "en la marcha"
 * POST /api/registro_rapido.php  (JSON o form-data)
 * Body: { dni?, dueno_nombre?, dueno_telefono?, mascota_nombre, mascota_especie? }
 * - Si el DNI coincide con un cliente existente, lo reutiliza; si no, lo crea.
 * - Crea la mascota, le asigna su HC correlativo y devuelve id + label.
 * Reutilizable por Citas, Grooming/Baño e Historia Clínica.
 */
require_once __DIR__ . '/../includes/config.php';
requireLogin();
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método no permitido.']);
    exit;
}

$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) $in = $_POST;

$dni   = preg_replace('/\D/', '', trim($in['dni'] ?? ''));
$dueno = trim($in['dueno_nombre'] ?? '');
$tel   = trim($in['dueno_telefono'] ?? '');
$mnom  = trim($in['mascota_nombre'] ?? '');
$mesp  = strtolower(trim($in['mascota_especie'] ?? 'perro')) ?: 'perro';

if ($mnom === '') { echo json_encode(['ok' => false, 'error' => 'El nombre de la mascota es obligatorio.']); exit; }

$db      = getDB();
$user    = function_exists('getUser') ? getUser() : null;
$sede_id = $user['sede_id'] ?? 1;

// ── Cliente: reutilizar por DNI o crear uno nuevo ──
$cliente_id = 0;
if ($dni !== '' && strlen($dni) === 8) {
    try { $q = $db->prepare("SELECT id,nombre FROM clientes WHERE dni=? AND activo=1 LIMIT 1"); $q->execute([$dni]);
          if ($r = $q->fetch()) { $cliente_id = (int)$r['id']; if ($dueno === '') $dueno = $r['nombre']; } }
    catch (Throwable $e) {}
}
if (!$cliente_id) {
    if ($dni !== '' && strlen($dni) !== 8) { echo json_encode(['ok' => false, 'error' => 'El DNI debe tener 8 dígitos.']); exit; }
    if ($dueno === '') { echo json_encode(['ok' => false, 'error' => 'Falta el nombre del dueño.']); exit; }
    try {
        $st = $db->prepare("INSERT INTO clientes (nombre,dni,telefono,sede_id,activo) VALUES (?,?,?,?,1)");
        $st->execute([$dueno, ($dni ?: null), ($tel ?: ''), $sede_id]);
        $cliente_id = (int)$db->lastInsertId();
    } catch (Throwable $e) { echo json_encode(['ok' => false, 'error' => 'No se pudo crear el cliente: '.$e->getMessage()]); exit; }
} elseif ($tel !== '') {
    // Completar el teléfono si el cliente existente no tenía uno
    try { $db->prepare("UPDATE clientes SET telefono=? WHERE id=? AND (telefono IS NULL OR telefono='' OR telefono='-')")->execute([$tel, $cliente_id]); } catch (Throwable $e) {}
}

// ── Mascota ──
try {
    $st = $db->prepare("INSERT INTO mascotas (cliente_id,nombre,especie,sede_id,estado) VALUES (?,?,?,?, 'activo')");
    $st->execute([$cliente_id, $mnom, $mesp, $sede_id]);
    $mid = (int)$db->lastInsertId();
    // Asignar HC correlativo (HC-0001, HC-0002, …) igual que el alta normal
    try {
        $col = $db->query("SHOW COLUMNS FROM mascotas LIKE 'hc_numero'")->fetchAll();
        if (empty($col)) { $db->exec("ALTER TABLE mascotas ADD COLUMN hc_numero VARCHAR(15) NULL"); }
        $r = $db->query("SELECT MAX(CAST(SUBSTRING(hc_numero,4) AS UNSIGNED)) AS m FROM mascotas WHERE hc_numero LIKE 'HC-%'")->fetch();
        $next = ((int)($r['m'] ?? 0)) + 1;
        $db->prepare("UPDATE mascotas SET hc_numero=? WHERE id=?")->execute([sprintf('HC-%04d', $next), $mid]);
    } catch (Throwable $e) {}
    echo json_encode(['ok' => true, 'mascota_id' => $mid, 'cliente_id' => $cliente_id, 'label' => $mnom.' ('.$dueno.')']);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => 'No se pudo crear la mascota: '.$e->getMessage()]);
    exit;
}
