<?php
/**
 * VetPro — API interna: consulta DNI / RUC
 * Endpoint: /vetpro/api/consulta_documento.php?tipo=dni&numero=12345678
 *
 * Fuentes usadas (fallback en cascada):
 *  DNI → apis.net.pe  →  dniruc.apisperu.com  →  api.apis.is
 *  RUC → apis.net.pe  →  dniruc.apisperu.com  →  sunat via factiliza
 *
 * No requiere token de pago. Usa servicios gratuitos con límite razonable.
 */

require_once __DIR__ . '/../includes/config.php';
requireLogin();

header('Content-Type: application/json; charset=utf-8');

$tipo   = strtolower(trim($_GET['tipo'] ?? ''));
$numero = preg_replace('/\D/', '', trim($_GET['numero'] ?? ''));

// Validaciones básicas
if ($tipo === 'dni' && strlen($numero) !== 8) {
    echo json_encode(['ok' => false, 'error' => 'El DNI debe tener exactamente 8 dígitos.']);
    exit;
}
if ($tipo === 'ruc' && strlen($numero) !== 11) {
    echo json_encode(['ok' => false, 'error' => 'El RUC debe tener exactamente 11 dígitos.']);
    exit;
}
if (!in_array($tipo, ['dni', 'ruc'])) {
    echo json_encode(['ok' => false, 'error' => 'Tipo inválido. Use dni o ruc.']);
    exit;
}

/**
 * Hace un GET con curl y devuelve el body, o false si falla.
 */
function httpGet(string $url, array $headers = [], int $timeout = 6): string|false {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT      => 'VetPro/1.0',
        CURLOPT_HTTPHEADER     => array_merge(['Accept: application/json'], $headers),
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($body !== false && $code === 200) ? $body : false;
}

// ─────────────────────────────────────────────
//  CONSULTA DNI
// ─────────────────────────────────────────────
if ($tipo === 'dni') {

    // Fuente 1: apis.net.pe (gratuita, sin token)
    $body = httpGet("https://api.apis.net.pe/v2/reniec/dni?numero={$numero}");
    if ($body) {
        $data = json_decode($body, true);
        if (!empty($data['nombres'])) {
            $nombre = trim(
                ucwords(strtolower(
                    $data['nombres'] . ' ' .
                    ($data['apellidoPaterno'] ?? '') . ' ' .
                    ($data['apellidoMaterno'] ?? '')
                ))
            );
            echo json_encode([
                'ok'       => true,
                'fuente'   => 'RENIEC',
                'nombre'   => $nombre,
                'dni'      => $numero,
                'raw'      => $data,
            ]);
            exit;
        }
    }

    // Fuente 2: dniruc.apisperu.com (gratuita básica)
    $body = httpGet("https://dniruc.apisperu.com/api/v1/dni/{$numero}?token=eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJlbWFpbCI6Ik1hbnVlbC5hZ3VhZG9AbWFndXN0ZWNobm9sb2dpZXMuY29tIn0.GQidyrlA0nN75zaMUstTR4a-XBUy4eH91VvX8YG2Wro");
    if ($body) {
        $data = json_decode($body, true);
        if (!empty($data['nombres'])) {
            $nombre = trim(ucwords(strtolower(
                $data['nombres'] . ' ' . ($data['apellidoPaterno'] ?? '') . ' ' . ($data['apellidoMaterno'] ?? '')
            )));
            echo json_encode(['ok' => true, 'fuente' => 'RENIEC', 'nombre' => $nombre, 'dni' => $numero]);
            exit;
        }
    }

    // Fuente 3: api.apis.is (respaldo)
    $body = httpGet("https://api.apis.is/v1/dni/{$numero}");
    if ($body) {
        $data = json_decode($body, true);
        if (!empty($data['nombre_completo'] ?? $data['nombre'] ?? '')) {
            $nombre = ucwords(strtolower($data['nombre_completo'] ?? $data['nombre']));
            echo json_encode(['ok' => true, 'fuente' => 'RENIEC', 'nombre' => trim($nombre), 'dni' => $numero]);
            exit;
        }
    }

    echo json_encode(['ok' => false, 'error' => 'No se encontraron datos para el DNI ' . $numero . '. Verifique el número o ingrese los datos manualmente.']);
    exit;
}

// ─────────────────────────────────────────────
//  CONSULTA RUC
// ─────────────────────────────────────────────
if ($tipo === 'ruc') {

    // Fuente 1: apis.net.pe
    $body = httpGet("https://api.apis.net.pe/v2/sunat/ruc?numero={$numero}");
    if ($body) {
        $data = json_decode($body, true);
        if (!empty($data['razonSocial'])) {
            $direccion = trim(implode(', ', array_filter([
                $data['direccion']  ?? '',
                $data['distrito']   ?? '',
                $data['provincia']  ?? '',
                $data['departamento'] ?? '',
            ])));
            echo json_encode([
                'ok'         => true,
                'fuente'     => 'SUNAT',
                'nombre'     => ucwords(strtolower($data['razonSocial'])),
                'ruc'        => $numero,
                'direccion'  => $direccion,
                'estado'     => $data['estado'] ?? '',
                'condicion'  => $data['condicion'] ?? '',
                'tipo'       => $data['tipoContribuyente'] ?? '',
                'raw'        => $data,
            ]);
            exit;
        }
    }

    // Fuente 2: dniruc.apisperu.com
    $body = httpGet("https://dniruc.apisperu.com/api/v1/ruc/{$numero}?token=eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJlbWFpbCI6Ik1hbnVlbC5hZ3VhZG9AbWFndXN0ZWNobm9sb2dpZXMuY29tIn0.GQidyrlA0nN75zaMUstTR4a-XBUy4eH91VvX8YG2Wro");
    if ($body) {
        $data = json_decode($body, true);
        if (!empty($data['razonSocial'])) {
            $direccion = trim(implode(', ', array_filter([
                $data['direccion'] ?? '', $data['distrito'] ?? '', $data['provincia'] ?? '', $data['departamento'] ?? ''
            ])));
            echo json_encode([
                'ok'        => true,
                'fuente'    => 'SUNAT',
                'nombre'    => ucwords(strtolower($data['razonSocial'])),
                'ruc'       => $numero,
                'direccion' => $direccion,
                'estado'    => $data['estado'] ?? '',
            ]);
            exit;
        }
    }

    // Fuente 3: factiliza.com (scraping ligero, último recurso)
    $body = httpGet("https://api.factiliza.com/pe/v1/ruc/info/{$numero}");
    if ($body) {
        $data = json_decode($body, true);
        $rs = $data['data']['nombre_o_razon_social'] ?? $data['razonSocial'] ?? '';
        if ($rs) {
            echo json_encode([
                'ok'       => true,
                'fuente'   => 'SUNAT',
                'nombre'   => ucwords(strtolower($rs)),
                'ruc'      => $numero,
                'direccion'=> $data['data']['domicilio_fiscal'] ?? '',
            ]);
            exit;
        }
    }

    echo json_encode(['ok' => false, 'error' => 'No se encontraron datos para el RUC ' . $numero . '. Verifique el número o ingrese los datos manualmente.']);
    exit;
}
