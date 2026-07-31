<?php
/**
 * VetPro — API PÚBLICA: consulta DNI (solo lectura, para la reserva pública)
 * Endpoint: /api/consulta_dni_publico.php?numero=12345678
 *
 * No requiere login (lo usa reservar.php). Reutiliza las MISMAS fuentes
 * gratuitas que api/consulta_documento.php, pero limitado a DNI y con un
 * límite por IP para evitar abuso del servicio gratuito.
 */
require_once __DIR__ . '/../includes/config.php';
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$numero = preg_replace('/\D/', '', trim($_GET['numero'] ?? ''));
if (strlen($numero) !== 8) {
    echo json_encode(['ok' => false, 'error' => 'El DNI debe tener 8 dígitos.']); exit;
}

$db = getDB();

// ── Límite anti-abuso: máx. 25 consultas por IP por hora ──
try {
    $db->exec("CREATE TABLE IF NOT EXISTS dni_publico_hits (ip VARCHAR(45), ts INT, KEY idx_ip_ts (ip,ts)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $ip = substr($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0', 0, 45);
    $ahora = time();
    // limpiar registros viejos ocasionalmente
    if (mt_rand(1,20) === 1) { $db->exec("DELETE FROM dni_publico_hits WHERE ts < " . ($ahora - 7200)); }
    $st = $db->prepare("SELECT COUNT(*) FROM dni_publico_hits WHERE ip=? AND ts > ?");
    $st->execute([$ip, $ahora - 3600]);
    if ((int)$st->fetchColumn() >= 25) {
        http_response_code(429);
        echo json_encode(['ok' => false, 'error' => 'Demasiadas consultas. Intenta más tarde o escribe tu nombre manualmente.']); exit;
    }
    $db->prepare("INSERT INTO dni_publico_hits (ip,ts) VALUES (?,?)")->execute([$ip, $ahora]);
} catch (Exception $e) { /* si falla el limitador, seguimos */ }

/** GET simple con cURL. */
function _dniGet(string $url, int $timeout = 6): string|false {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT      => 'VetPro/1.0',
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($body !== false && $code === 200) ? $body : false;
}

function _dniNombre(array $d): string {
    if (!empty($d['nombres'])) {
        return trim(ucwords(strtolower(
            $d['nombres'] . ' ' . ($d['apellidoPaterno'] ?? '') . ' ' . ($d['apellidoMaterno'] ?? '')
        )));
    }
    if (!empty($d['nombre_completo'] ?? $d['nombre'] ?? '')) {
        return trim(ucwords(strtolower($d['nombre_completo'] ?? $d['nombre'])));
    }
    return '';
}

// Fuente 1: apis.net.pe
$body = _dniGet("https://api.apis.net.pe/v2/reniec/dni?numero={$numero}");
if ($body && ($d = json_decode($body, true)) && ($n = _dniNombre($d)) !== '') {
    echo json_encode(['ok' => true, 'nombre' => $n, 'dni' => $numero]); exit;
}
// Fuente 2: dniruc.apisperu.com
$body = _dniGet("https://dniruc.apisperu.com/api/v1/dni/{$numero}?token=eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJlbWFpbCI6Ik1hbnVlbC5hZ3VhZG9AbWFndXN0ZWNobm9sb2dpZXMuY29tIn0.GQidyrlA0nN75zaMUstTR4a-XBUy4eH91VvX8YG2Wro");
if ($body && ($d = json_decode($body, true)) && ($n = _dniNombre($d)) !== '') {
    echo json_encode(['ok' => true, 'nombre' => $n, 'dni' => $numero]); exit;
}
// Fuente 3: api.apis.is
$body = _dniGet("https://api.apis.is/v1/dni/{$numero}");
if ($body && ($d = json_decode($body, true)) && ($n = _dniNombre($d)) !== '') {
    echo json_encode(['ok' => true, 'nombre' => $n, 'dni' => $numero]); exit;
}

echo json_encode(['ok' => false, 'error' => 'No se encontraron datos para ese DNI. Escribe tu nombre manualmente.']);
