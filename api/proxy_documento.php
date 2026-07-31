<?php
/**
 * VetPro — Proxy público para consulta DNI/RUC
 * No requiere login. Consulta las APIs externas desde el servidor.
 */
require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$tipo = strtolower(trim($_GET['tipo'] ?? ''));
$numero = preg_replace('/\D/', '', trim($_GET['numero'] ?? ''));

if ($tipo === 'dni' && strlen($numero) !== 8) {
    echo json_encode(['ok' => false, 'error' => 'El DNI debe tener exactamente 8 digitos.']);
    exit;
}
if ($tipo === 'ruc' && strlen($numero) !== 11) {
    echo json_encode(['ok' => false, 'error' => 'El RUC debe tener exactamente 11 digitos.']);
    exit;
}
if (!in_array($tipo, ['dni', 'ruc'])) {
    echo json_encode(['ok' => false, 'error' => 'Tipo invalido. Use dni o ruc.']);
    exit;
}

function httpGet(string $url, int $timeout = 8): string|false {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT => 'VetPro/1.0',
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($body !== false && $code === 200) ? $body : false;
}

// DNI
if ($tipo === 'dni') {
    $body = httpGet("https://api.apis.net.pe/v2/reniec/dni?numero={$numero}");
    if ($body) {
        $data = json_decode($body, true);
        if (!empty($data['nombres'])) {
            $nombre = trim(ucwords(strtolower($data['nombres'] . ' ' . $data['apellidoPaterno'] . ' ' . $data['apellidoMaterno'])));
            echo json_encode(['ok' => true, 'fuente' => 'RENIEC', 'nombre' => $nombre, 'dni' => $numero]);
            exit;
        }
    }
    $body = httpGet("https://dniruc.apisperu.com/api/v1/dni/{$numero}?token=eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJlbWFpbCI6Ik1hbnVlbC5hZ3VhZG9AbWFndXN0ZWNobm9sb2dpZXMuY29tIn0.GQidyrlA0nN75zaMUstTR4a-XBUy4eH91VvX8YG2Wro");
    if ($body) {
        $data = json_decode($body, true);
        if (!empty($data['nombres'])) {
            $nombre = trim(ucwords(strtolower($data['nombres'] . ' ' . $data['apellidoPaterno'] . ' ' . $data['apellidoMaterno'])));
            echo json_encode(['ok' => true, 'fuente' => 'RENIEC', 'nombre' => $nombre, 'dni' => $numero]);
            exit;
        }
    }
    echo json_encode(['ok' => false, 'error' => 'No se encontraron datos para el DNI ' . $numero]);
    exit;
}

// RUC
if ($tipo === 'ruc') {
    $body = httpGet("https://api.apis.net.pe/v2/sunat/ruc?numero={$numero}");
    if ($body) {
        $data = json_decode($body, true);
        if (!empty($data['razonSocial'])) {
            $direccion = $data['direccion'] ?? '';
            $distrito = $data['distrito'] ?? '';
            $provincia = $data['provincia'] ?? '';
            $departamento = $data['departamento'] ?? '';
            $fullDir = trim("$direccion, $distrito, $provincia, $departamento");
            $rs = ucwords(strtolower($data['razonSocial']));
            echo json_encode([
                'ok' => true,
                'fuente' => 'SUNAT',
                'nombre' => $rs,
                'ruc' => $numero,
                'direccion' => $fullDir,
                'estado' => $data['estado'] ?? '',
                'condicion' => $data['condicion'] ?? '',
            ]);
            exit;
        }
    }
    $body = httpGet("https://dniruc.apisperu.com/api/v1/ruc/{$numero}?token=eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJlbWFpbCI6Ik1hbnVlbC5hZ3VhZG9AbWFndXN0ZWNobm9sb2dpZXMuY29tIn0.GQidyrlA0nN75zaMUstTR4a-XBUy4eH91VvX8YG2Wro");
    if ($body) {
        $data = json_decode($body, true);
        if (!empty($data['razonSocial'])) {
            $direccion = $data['direccion'] ?? '';
            $distrito = $data['distrito'] ?? '';
            $provincia = $data['provincia'] ?? '';
            $departamento = $data['departamento'] ?? '';
            $fullDir = trim("$direccion, $distrito, $provincia, $departamento");
            $rs = ucwords(strtolower($data['razonSocial']));
            echo json_encode([
                'ok' => true,
                'fuente' => 'SUNAT',
                'nombre' => $rs,
                'ruc' => $numero,
                'direccion' => $fullDir,
                'estado' => $data['estado'] ?? '',
            ]);
            exit;
        }
    }
    echo json_encode(['ok' => false, 'error' => 'No se encontraron datos para el RUC ' . $numero]);
    exit;
}
