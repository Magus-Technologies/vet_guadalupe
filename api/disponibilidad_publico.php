<?php
/**
 * VetPro — API PÚBLICA: disponibilidad de horario (para reservar.php)
 * Endpoint: /api/disponibilidad_publico.php?sede_id=1&fecha=2026-07-10&hora=10:30&dur=30
 *
 * Devuelve si el horario está disponible según la CAPACIDAD de la sede
 * (número de veterinarios activos) y las citas que se solapan a esa hora.
 */
require_once __DIR__ . '/../includes/config.php';
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$sede = (int)($_GET['sede_id'] ?? 1) ?: 1;
$fecha = trim($_GET['fecha'] ?? '');
$hora  = trim($_GET['hora'] ?? '');
$dur   = max(10, min(240, (int)($_GET['dur'] ?? 30)));

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) || !preg_match('/^\d{1,2}:\d{2}/', $hora)) {
    echo json_encode(['ok' => false, 'error' => 'Parámetros inválidos.']); exit;
}
$ini = substr($hora, 0, 5) . ':00';

$db = getDB();
try {
    // Capacidad = veterinarios activos de la sede (fallback: global, mínimo 1)
    $cap = (int)$db->query("SELECT COUNT(*) FROM usuarios WHERE rol='veterinario' AND activo=1 AND sede_id={$sede}")->fetchColumn();
    if ($cap === 0) $cap = (int)$db->query("SELECT COUNT(*) FROM usuarios WHERE rol='veterinario' AND activo=1")->fetchColumn();
    if ($cap === 0) $cap = 1;

    // Citas que se solapan con [ini, ini+dur) en esa sede y fecha
    $st = $db->prepare("
        SELECT COUNT(*) FROM citas
        WHERE sede_id=? AND fecha=? AND estado IN ('pendiente','confirmada')
          AND ? < ADDTIME(hora, SEC_TO_TIME(duracion_minutos*60))
          AND hora < ADDTIME(?, SEC_TO_TIME(?*60))
    ");
    $st->execute([$sede, $fecha, $ini, $ini, $dur]);
    $ocupadas = (int)$st->fetchColumn();

    $disponible = $ocupadas < $cap;
    echo json_encode([
        'ok'         => true,
        'disponible' => $disponible,
        'capacidad'  => $cap,
        'ocupadas'   => $ocupadas,
        'mensaje'    => $disponible
            ? 'Horario disponible'
            : 'Ese horario ya está reservado. Por favor elige otra hora.'
    ]);
} catch (Exception $e) {
    // Si algo falla, no bloqueamos la reserva (el admin valida al aceptar)
    echo json_encode(['ok' => false, 'error' => 'No se pudo verificar la disponibilidad.']);
}
