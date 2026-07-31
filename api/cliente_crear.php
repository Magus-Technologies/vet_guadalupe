<?php
/**
 * VetPro — API: crear cliente desde datos de RENIEC/SUNAT
 * POST /api/cliente_crear.php
 * Body: { nombre, dni?, ruc?, telefono?, email?, direccion? }
 * Requiere sesión iniciada.
 */

require_once __DIR__ . '/../includes/config.php';
requireLogin();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método no permitido.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    echo json_encode(['ok' => false, 'error' => 'JSON inválido.']);
    exit;
}

$nombre    = trim($input['nombre'] ?? '');
$dni       = preg_replace('/\D/', '', trim($input['dni'] ?? ''));
$ruc       = preg_replace('/\D/', '', trim($input['ruc'] ?? ''));
$ce        = preg_replace('/\D/', '', trim($input['ce'] ?? ''));
$pasaporte = trim($input['pasaporte'] ?? '');
$telefono  = trim($input['telefono'] ?? '');
$email     = trim($input['email'] ?? '');
$direccion = trim($input['direccion'] ?? '');

if (!$nombre) {
    echo json_encode(['ok' => false, 'error' => 'El nombre es obligatorio.']);
    exit;
}

if ($dni && strlen($dni) !== 8) {
    echo json_encode(['ok' => false, 'error' => 'El DNI debe tener 8 dígitos.']);
    exit;
}

if ($ruc && strlen($ruc) !== 11) {
    echo json_encode(['ok' => false, 'error' => 'El RUC debe tener 11 dígitos.']);
    exit;
}

if ($dni && $ruc) {
    echo json_encode(['ok' => false, 'error' => 'Un cliente no puede tener DNI y RUC a la vez.']);
    exit;
}

if ($ce && strlen($ce) < 9) {
    echo json_encode(['ok' => false, 'error' => 'El Carné de Extranjería debe tener al menos 9 dígitos.']);
    exit;
}

$db = getDB();
$user = getUser();
$sede_id = $user['sede_id'] ?? 1;

// Verificar duplicados
if ($dni) {
    $st = $db->prepare("SELECT id FROM clientes WHERE dni=? AND activo=1 LIMIT 1");
    $st->execute([$dni]);
    if ($st->fetchColumn()) {
        echo json_encode(['ok' => false, 'error' => "Ya existe un cliente con DNI $dni."]);
        exit;
    }
}
if ($ruc) {
    $st = $db->prepare("SELECT id FROM clientes WHERE ruc=? AND activo=1 LIMIT 1");
    $st->execute([$ruc]);
    if ($st->fetchColumn()) {
        echo json_encode(['ok' => false, 'error' => "Ya existe un cliente con RUC $ruc."]);
        exit;
    }
}
if ($ce) {
    $st = $db->prepare("SELECT id FROM clientes WHERE ce=? AND activo=1 LIMIT 1");
    $st->execute([$ce]);
    if ($st->fetchColumn()) {
        echo json_encode(['ok' => false, 'error' => "Ya existe un cliente con CE $ce."]);
        exit;
    }
}

try {
    $st = $db->prepare("INSERT INTO clientes (nombre,dni,ruc,ce,pasaporte,telefono,email,direccion,sede_id,activo) VALUES (?,?,?,?,?,?,?,?,?,1)");
    $st->execute([
        $nombre,
        $dni       ?: '',
        $ruc       ?: '',
        $ce        ?: '',
        $pasaporte ?: '',
        $telefono  ?: '',
        $email     ?: '',
        $direccion ?: '',
        $sede_id,
    ]);
    $id = (int)$db->lastInsertId();

    echo json_encode([
        'ok'   => true,
        'id'   => $id,
        'nombre' => $nombre,
        'dni'  => $dni  ?: null,
        'ruc'  => $ruc  ?: null,
        'ce'   => $ce   ?: null,
        'pasaporte' => $pasaporte ?: null,
    ]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => 'No se pudo crear el cliente: ' . $e->getMessage()]);
    exit;
}