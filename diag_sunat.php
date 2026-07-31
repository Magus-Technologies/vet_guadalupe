<?php
/**
 * Diagnóstico del envío a SUNAT — script temporal, borrar después de usar.
 *
 * Llama a la API igual que SunatService pero mostrando TODO: la URL, el payload
 * (sin la clave), el código HTTP y el cuerpo crudo de la respuesta. Es lo único
 * que permite distinguir un error de la API de un rechazo real de SUNAT.
 *
 * USO (solo CLI, desde la raíz del proyecto):
 *   php diag_sunat.php                 -> lista candidatos y no envía nada
 *   php diag_sunat.php <id> --enviar   -> envía ese comprobante y muestra todo
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Solo por CLI.\n"); }

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/config_sunat.php';

$db = getDB();

echo "══ CONFIGURACIÓN ══\n";
echo "  API URL   : " . SUNAT_API_URL . "\n";
echo "  Endpoint  : " . SUNAT_ENDPOINT . "\n";
echo "  RUC       : " . SUNAT_RUC . "\n";
echo "  Usuario   : " . SUNAT_USUARIO_SOL . "\n";
echo "  Clave     : " . (SUNAT_CLAVE_SOL !== '' ? '(definida, ' . strlen(SUNAT_CLAVE_SOL) . ' chars)' : '*** VACÍA ***') . "\n\n";

$id = (int)($argv[1] ?? 0);

if (!$id) {
    echo "══ COMPROBANTES CON XML LISTO ══\n";
    $q = $db->query("SELECT id,serie,numero,fecha,total,sunat_estado,LENGTH(sunat_xml) len
                     FROM ventas
                     WHERE sunat_xml IS NOT NULL AND sunat_xml<>'' AND tipo_comprobante IN('boleta','factura')
                     ORDER BY numero DESC LIMIT 10");
    foreach ($q as $r) {
        printf("  id=%-5d %s-%s  %s  S/%-8s estado=%-10s xml=%d bytes\n",
            $r['id'], $r['serie'], str_pad($r['numero'],8,'0',STR_PAD_LEFT),
            substr($r['fecha'],0,10), $r['total'], $r['sunat_estado'] ?? 'NULL', $r['len']);
    }
    echo "\nPara diagnosticar:  php diag_sunat.php <id> --enviar\n";
    exit;
}

$st = $db->prepare("SELECT * FROM ventas WHERE id=?");
$st->execute([$id]);
$v = $st->fetch();
if (!$v)                 exit("No existe la venta #$id\n");
if (empty($v['sunat_xml'])) exit("La venta #$id no tiene XML. Regeneralo primero.\n");

$tipo   = $v['tipo_comprobante'] === 'factura' ? '01' : '03';
$nombre = SUNAT_RUC . '-' . $tipo . '-' . $v['serie'] . '-' . str_pad((string)$v['numero'], 8, '0', STR_PAD_LEFT);

echo "══ COMPROBANTE #$id ══\n";
echo "  Ref            : {$v['serie']}-{$v['numero']}\n";
echo "  Fecha emisión  : {$v['fecha']}\n";
echo "  nombre_documento: $nombre\n";
echo "  XML            : " . strlen($v['sunat_xml']) . " bytes\n";

// El cbc:ID del XML tiene que coincidir con el nombre del archivo: si difieren,
// SUNAT rechaza. Es de los motivos más comunes de un 400 en producción.
if (preg_match('#<cbc:ID>([^<]+)</cbc:ID>#', $v['sunat_xml'], $m)) {
    echo "  cbc:ID del XML : {$m[1]}\n";
    $esperado = $v['serie'] . '-' . str_pad((string)$v['numero'], 8, '0', STR_PAD_LEFT);
    $crudo    = $v['serie'] . '-' . $v['numero'];
    echo "  ¿coincide?     : ";
    if ($m[1] === $esperado)   echo "sí, con padding\n";
    elseif ($m[1] === $crudo)  echo "*** el XML usa '{$m[1]}' pero el archivo se llama '...{$esperado}' ***\n";
    else                       echo "*** NO coincide con ninguna forma esperada ***\n";
}
if (preg_match('#<cbc:IssueDate>([^<]+)</cbc:IssueDate>#', $v['sunat_xml'], $m)) {
    echo "  IssueDate XML  : {$m[1]}\n";
}

if (($argv[2] ?? '') !== '--enviar') {
    echo "\n(no se envió nada — agregá --enviar para hacer la llamada real)\n";
    exit;
}

$payload = [
    'ruc'                 => SUNAT_RUC,
    'usuario'             => SUNAT_USUARIO_SOL,
    'clave'               => SUNAT_CLAVE_SOL,
    'endpoint'            => SUNAT_ENDPOINT,
    'nombre_documento'    => $nombre,
    'contenido_documento' => $v['sunat_xml'],
];

$url = rtrim(SUNAT_API_URL, '/') . '/enviar/documento/electronico';
echo "\n══ PETICIÓN ══\n  POST $url\n";
$muestra = $payload;
$muestra['clave'] = '***';
$muestra['contenido_documento'] = '(' . strlen($payload['contenido_documento']) . ' bytes)';
echo "  " . json_encode($muestra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
    CURLOPT_TIMEOUT        => 90,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
]);
$body = curl_exec($ch);
$http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err  = curl_error($ch);
curl_close($ch);

echo "\n══ RESPUESTA ══\n";
echo "  HTTP  : $http\n";
if ($err) echo "  cURL  : $err\n";
echo "  Cuerpo (crudo, hasta 4000 chars):\n";
echo "  ────────────────────────────────────────\n";
echo substr((string)$body, 0, 4000) . "\n";
echo "  ────────────────────────────────────────\n";

$j = json_decode((string)$body, true);
if (is_array($j)) {
    echo "\n══ CLAVES DE LA RESPUESTA ══\n";
    foreach ($j as $k => $val) {
        echo "  $k = " . (is_scalar($val) ? mb_substr((string)$val, 0, 200) : json_encode($val, JSON_UNESCAPED_UNICODE)) . "\n";
    }
}
echo "\nNOTA: este script no guardó nada en la base.\n";
