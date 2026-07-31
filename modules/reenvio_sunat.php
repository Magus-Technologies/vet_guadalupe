<?php
/**
 * Reenvío SUNAT — recupera comprobantes que se emitieron contra el ambiente BETA.
 *
 * Cuando `sunat_modo` estuvo en 'beta', SUNAT devolvió un CDR de juguete: sin
 * firma real, con los textos literales "BetaPublicCert" / "BetaPrivateKey ...
 * not up". Esos comprobantes NO existen para SUNAT producción, así que su
 * correlativo sigue libre y pueden reenviarse con la misma serie y número.
 *
 * El módulo detecta esos comprobantes leyendo el CDR (base64 -> ZIP -> XML),
 * limpia su rastro y vuelve a correr el flujo normal: generar XML + enviar.
 * Nunca toca un comprobante cuyo CDR venga firmado de verdad.
 */
$page      = 'reenvio_sunat';
$pageTitle = 'Reenvío SUNAT';
$db        = getDB();
$user      = getUser();

// Reprocesar comprobantes fiscales es operación de administrador.
if (!hasRole(['admin']) || !canView('facturacion')) {
    $_SESSION['flash_error'] = 'Solo un administrador puede reprocesar comprobantes.';
    header('Location: ' . BASE_URL . '/index.php?p=dashboard'); exit;
}

$sunat_cfg = __DIR__ . '/../includes/config_sunat.php';
$sunat_svc = __DIR__ . '/../includes/sunat/SunatService.php';
$sunat_ok  = file_exists($sunat_cfg) && file_exists($sunat_svc);
if ($sunat_ok) { require_once $sunat_cfg; require_once $sunat_svc; }

/**
 * Abre el CDR guardado y devuelve cómo lo firmó SUNAT.
 *
 * @return array{ambiente:string, codigo:?string, detalle:string}
 *         ambiente: 'beta' | 'produccion' | 'sin_cdr' | 'ilegible'
 */
function analizarCdr(?string $cdrB64): array {
    if ($cdrB64 === null || $cdrB64 === '') {
        return ['ambiente' => 'sin_cdr', 'codigo' => null, 'detalle' => 'Sin CDR guardado.'];
    }
    $bin = base64_decode($cdrB64, true);
    if ($bin === false || substr($bin, 0, 2) !== 'PK') {
        return ['ambiente' => 'ilegible', 'codigo' => null, 'detalle' => 'El CDR no es un ZIP válido.'];
    }

    $tmp = tempnam(sys_get_temp_dir(), 'cdr');
    file_put_contents($tmp, $bin);
    $zip = new ZipArchive();
    $xml = '';
    if ($zip->open($tmp) === true) {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $n = $zip->getNameIndex($i);
            if (str_ends_with(strtolower($n), '.xml')) { $xml = (string)$zip->getFromIndex($i); break; }
        }
        $zip->close();
    }
    @unlink($tmp);

    if ($xml === '') {
        return ['ambiente' => 'ilegible', 'codigo' => null, 'detalle' => 'No se encontró XML dentro del CDR.'];
    }

    preg_match('#<cbc:ResponseCode>(\d+)</cbc:ResponseCode>#', $xml, $m);
    $codigo = $m[1] ?? null;

    // Marca inconfundible del ambiente de pruebas: SUNAT beta no firma el CDR
    // y deja estos textos en lugar del certificado.
    $esBeta = str_contains($xml, 'BetaPublicCert')
           || str_contains($xml, 'BetaPrivateKey')
           || str_contains($xml, 'not up');

    return [
        'ambiente' => $esBeta ? 'beta' : 'produccion',
        'codigo'   => $codigo,
        'detalle'  => $esBeta
            ? 'CDR del ambiente BETA — sin validez fiscal.'
            : 'CDR firmado por SUNAT producción.',
    ];
}

/** Deja el comprobante como si nunca se hubiera enviado. */
function limpiarRastroSunat(PDO $db, int $ventaId): void {
    $db->prepare("
        UPDATE ventas SET
            sunat_estado  = NULL,
            sunat_hash    = NULL,
            sunat_qr      = NULL,
            sunat_xml     = NULL,
            sunat_cdr     = NULL,
            sunat_mensaje = NULL,
            sunat_fecha   = NULL
        WHERE id=?
    ")->execute([$ventaId]);
}

// ─── POST ─────────────────────────────────────────────────────────
$reporte = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $sunat_ok) {
    $pa  = $_POST['action'] ?? '';
    $ids = array_map('intval', (array)($_POST['ids'] ?? []));

    if ($pa === 'reprocesar' && $ids) {
        if (SUNAT_ENDPOINT !== 'produccion') {
            $_SESSION['flash_error'] = 'La configuración SUNAT sigue en BETA. Cambiala a producción antes de reprocesar.';
            header('Location: ' . BASE_URL . '/index.php?p=reenvio_sunat'); exit;
        }

        $svc = new SunatService($db);
        foreach ($ids as $vid) {
            $st = $db->prepare("SELECT id,serie,numero,tipo_comprobante,sunat_cdr FROM ventas WHERE id=?");
            $st->execute([$vid]);
            $v = $st->fetch();
            if (!$v) continue;

            $ref = $v['serie'] . '-' . str_pad((string)$v['numero'], 8, '0', STR_PAD_LEFT);

            // Salvaguarda: nunca reprocesar algo ya aceptado de verdad.
            $a = analizarCdr($v['sunat_cdr']);
            if ($a['ambiente'] === 'produccion') {
                $reporte[] = ['ref' => $ref, 'ok' => false, 'msg' => 'OMITIDO: ya tiene CDR de producción. Para anularlo se necesita nota de crédito.'];
                continue;
            }

            limpiarRastroSunat($db, $vid);

            $g = $svc->generarXml($vid);
            if (!$g['ok']) {
                $reporte[] = ['ref' => $ref, 'ok' => false, 'msg' => 'XML: ' . $g['mensaje']];
                continue;
            }
            $e = $svc->enviarSunat($vid);
            $reporte[] = ['ref' => $ref, 'ok' => $e['ok'], 'msg' => $e['mensaje']];
        }
    }
}

// ─── DATOS ────────────────────────────────────────────────────────
$comprobantes = $db->query("
    SELECT v.id, v.serie, v.numero, v.tipo_comprobante, v.fecha, v.total,
           v.estado, v.sunat_estado, v.sunat_cdr, v.sunat_xml,
           COALESCE(c.nombre,'—') AS cliente
    FROM ventas v
    LEFT JOIN clientes c ON c.id = v.cliente_id
    WHERE v.tipo_comprobante IN('boleta','factura')
    ORDER BY v.serie, v.numero
")->fetchAll();

$grupos = ['beta' => [], 'produccion' => [], 'sin_cdr' => [], 'ilegible' => []];
foreach ($comprobantes as &$v) {
    $v['_cdr'] = analizarCdr($v['sunat_cdr']);
    $grupos[$v['_cdr']['ambiente']][] = $v;
}
unset($v);

require_once __DIR__ . '/../includes/header.php';

if (isset($_SESSION['flash_error'])) {
    echo '<div class="alert alert-danger mb-3" style="padding:12px 16px;border-radius:8px">❌ ' . htmlspecialchars($_SESSION['flash_error']) . '</div>';
    unset($_SESSION['flash_error']);
}
?>

<?php if (!$sunat_ok): ?>
  <div class="alert alert-danger mb-3">El módulo SUNAT no está instalado en este proyecto.</div>
<?php else: ?>

<div class="card mb-2" style="padding:16px 18px">
  <div class="sec-title mb-2">Estado de la configuración SUNAT</div>
  <div class="flex gap-2 flex-wrap" style="font-size:13px">
    <span class="badge <?= SUNAT_ENDPOINT === 'produccion' ? 'b-teal' : 'b-red' ?>">
      Modo: <?= strtoupper(SUNAT_ENDPOINT) ?>
    </span>
    <span class="badge b-gray">RUC: <?= clean(SUNAT_RUC) ?></span>
    <span class="badge b-gray">Usuario SOL: <?= clean(SUNAT_USUARIO_SOL) ?></span>
  </div>
  <?php if (SUNAT_ENDPOINT !== 'produccion'): ?>
    <div class="alert alert-warn mt-2" style="font-size:13px">
      ⚠️ La configuración sigue en <strong>beta</strong>. Cambiala a producción en Configuración antes de reprocesar,
      o volverás a emitir contra el ambiente de pruebas.
    </div>
  <?php endif; ?>
</div>

<?php if ($reporte): ?>
<div class="card mb-2" style="padding:16px 18px">
  <div class="sec-title mb-2">Resultado del reproceso</div>
  <?php
    $ok = count(array_filter($reporte, fn($r) => $r['ok']));
    $ko = count($reporte) - $ok;
  ?>
  <div class="flex gap-2 mb-2">
    <span class="badge b-teal"><?= $ok ?> aceptados</span>
    <?php if ($ko): ?><span class="badge b-red"><?= $ko ?> con problema</span><?php endif; ?>
  </div>
  <div class="table-wrap" style="max-height:320px;overflow:auto">
    <table class="vtable">
      <thead><tr><th>Comprobante</th><th>Resultado</th></tr></thead>
      <tbody>
        <?php foreach ($reporte as $r): ?>
        <tr>
          <td class="font-bold" style="font-family:monospace"><?= clean($r['ref']) ?></td>
          <td><span class="badge <?= $r['ok'] ? 'b-teal' : 'b-red' ?>"><?= $r['ok'] ? 'OK' : 'ERROR' ?></span>
              <span class="text-xs text-muted" style="margin-left:6px"><?= clean($r['msg']) ?></span></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<div class="card mb-2" style="padding:16px 18px">
  <div class="sec-title mb-2">Diagnóstico de los <?= count($comprobantes) ?> comprobantes</div>
  <div class="flex gap-2 flex-wrap">
    <span class="badge b-red"><?= count($grupos['beta']) ?> emitidos en BETA (reprocesables)</span>
    <span class="badge b-teal"><?= count($grupos['produccion']) ?> en producción (intocables)</span>
    <span class="badge b-amber"><?= count($grupos['sin_cdr']) ?> sin CDR</span>
    <?php if ($grupos['ilegible']): ?><span class="badge b-gray"><?= count($grupos['ilegible']) ?> CDR ilegible</span><?php endif; ?>
  </div>

  <?php if ($grupos['beta']):
    // Reparto por antigüedad. El plazo exacto lo define la normativa vigente y
    // difiere entre boleta y factura: acá solo se expone el dato en crudo.
    $ant = ['0-3' => 0, '4-7' => 0, '8+' => 0];
    foreach ($grupos['beta'] as $b) {
        $d = (int)floor((time() - strtotime($b['fecha'])) / 86400);
        if ($d <= 3)      $ant['0-3']++;
        elseif ($d <= 7)  $ant['4-7']++;
        else              $ant['8+']++;
    }
  ?>
  <div class="mt-3" style="border-top:1px solid var(--border);padding-top:12px">
    <div class="text-xs text-muted mb-2">ANTIGÜEDAD DE LOS COMPROBANTES EN BETA</div>
    <div class="flex gap-2 flex-wrap">
      <span class="badge b-teal"><?= $ant['0-3'] ?> con 0–3 días</span>
      <span class="badge b-amber"><?= $ant['4-7'] ?> con 4–7 días</span>
      <span class="badge b-red"><?= $ant['8+'] ?> con 8 días o más</span>
    </div>
    <div class="text-xs text-muted mt-2">
      El plazo aplicable lo determina la normativa vigente y no es el mismo para boleta que para factura.
      Este corte es informativo: confirmalo con tu contador antes de enviar.
    </div>
  </div>
  <?php endif; ?>
</div>

<?php if ($grupos['beta']): ?>
<form method="POST">
  <input type="hidden" name="action" value="reprocesar">
  <div class="card" style="padding:0">
    <div style="padding:14px 18px;border-bottom:1px solid var(--border)" class="flex items-center justify-between">
      <div>
        <div class="font-bold">Comprobantes emitidos contra BETA</div>
        <div class="text-xs text-muted">Su correlativo sigue libre en producción: se reenvían con la misma serie y número.</div>
      </div>
      <div class="flex gap-1">
        <button type="button" class="btn btn-sm" onclick="marcarTodos(true)">Seleccionar todos</button>
        <button type="button" class="btn btn-sm" onclick="marcarTodos(false)">Ninguno</button>
        <button type="submit" class="btn btn-sm btn-primary"
          <?= SUNAT_ENDPOINT !== 'produccion' ? 'disabled title="Cambiá la configuración a producción primero"' : '' ?>
          onclick="return confirmarEnvio()">📤 Regenerar y enviar</button>
      </div>
    </div>
    <div class="table-wrap">
      <table class="vtable">
        <thead>
          <tr>
            <th style="width:36px"><input type="checkbox" onclick="marcarTodos(this.checked)"></th>
            <th>Comprobante</th><th>Cliente</th><th>Emitido</th><th>Antigüedad</th>
            <th style="text-align:right">Total</th><th>CDR actual</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($grupos['beta'] as $v): ?>
          <tr>
            <td><input type="checkbox" class="chk" name="ids[]" value="<?= $v['id'] ?>" checked></td>
            <td class="td-main">
              <span class="badge b-gray"><?= strtoupper($v['tipo_comprobante']) ?></span>
              <div style="font-size:12px;margin-top:2px;font-family:monospace"><?= clean($v['serie']) ?>-<?= str_pad((string)$v['numero'],8,'0',STR_PAD_LEFT) ?></div>
            </td>
            <td><?= clean($v['cliente']) ?></td>
            <td class="text-xs"><?= date('d/m/Y H:i', strtotime($v['fecha'])) ?></td>
            <?php
              // Días transcurridos desde la emisión: el dato con el que el
              // contador decide si el envío entra en plazo o es extemporáneo.
              $dias = (int)floor((time() - strtotime($v['fecha'])) / 86400);
              $dCl  = $dias <= 3 ? 'b-teal' : ($dias <= 7 ? 'b-amber' : 'b-red');
            ?>
            <td><span class="badge <?= $dCl ?>"><?= $dias ?> día<?= $dias === 1 ? '' : 's' ?></span></td>
            <td style="text-align:right" class="font-bold">S/. <?= number_format($v['total'],2) ?></td>
            <td><span class="badge b-red"><span class="dot"></span>BETA</span></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</form>

<script>
function marcarTodos(v){ document.querySelectorAll('.chk').forEach(c => c.checked = v); }
function confirmarEnvio(){
  var n = document.querySelectorAll('.chk:checked').length;
  if (!n) { alert('No seleccionaste ningún comprobante.'); return false; }
  return confirm('Se van a REGENERAR y ENVIAR ' + n + ' comprobante(s) a SUNAT PRODUCCIÓN.\n\n' +
                 'Esta operación es real y no se puede deshacer: una vez aceptados, solo se anulan con nota de crédito.\n\n' +
                 '¿Confirmás?');
}
</script>
<?php else: ?>
  <div class="card" style="padding:24px;text-align:center" class="text-muted">
    No hay comprobantes emitidos contra beta.
  </div>
<?php endif; ?>

<?php endif; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
