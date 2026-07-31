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

// Tamaño máximo de lote. Cada comprobante son 2 llamadas HTTP a SUNAT: un lote
// grande agota max_execution_time y corta a mitad de camino.
define('LOTE_MAX', 15);

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

    // Las dos etapas van por separado a propósito: regenerar no toca SUNAT
    // producción, así se puede revisar el XML antes de emitirlo de verdad.
    if (in_array($pa, ['regenerar', 'enviar'], true) && $ids) {
        if (SUNAT_ENDPOINT !== 'produccion') {
            $_SESSION['flash_error'] = 'La configuración SUNAT sigue en BETA. Cambiala a producción primero.';
            header('Location: ' . BASE_URL . '/index.php?p=reenvio_sunat'); exit;
        }

        if (count($ids) > LOTE_MAX) {
            $_SESSION['flash_error'] = 'Seleccionaste ' . count($ids) . ' comprobantes. El máximo por lote es ' . LOTE_MAX . ' para no cortar por timeout.';
            header('Location: ' . BASE_URL . '/index.php?p=reenvio_sunat'); exit;
        }

        // Cada llamada a la API puede tardar varios segundos.
        @set_time_limit(0);
        @ignore_user_abort(true);

        $accion = $pa === 'regenerar' ? 'Regenerar' : 'Enviar';
        $svc    = new SunatService($db);

        foreach ($ids as $vid) {
            $st = $db->prepare("SELECT id,serie,numero,tipo_comprobante,sunat_cdr,sunat_xml FROM ventas WHERE id=?");
            $st->execute([$vid]);
            $v = $st->fetch();
            if (!$v) continue;

            $ref = $v['serie'] . '-' . str_pad((string)$v['numero'], 8, '0', STR_PAD_LEFT);

            // Salvaguarda común: nunca tocar algo ya aceptado de verdad.
            if (analizarCdr($v['sunat_cdr'])['ambiente'] === 'produccion') {
                $reporte[] = ['ref' => $ref, 'accion' => $accion, 'ok' => false,
                              'msg' => 'OMITIDO: ya tiene CDR de producción. Para anularlo se necesita nota de crédito.'];
                continue;
            }

            if ($pa === 'regenerar') {
                limpiarRastroSunat($db, $vid);
                $r = $svc->generarXml($vid);
                $reporte[] = ['ref' => $ref, 'accion' => $accion, 'ok' => $r['ok'],
                              'msg' => $r['ok'] ? 'XML regenerado y firmado. Listo para enviar.' : $r['mensaje']];
                continue;
            }

            // Enviar: exige XML previo, si no no hay nada que mandar.
            if (empty($v['sunat_xml'])) {
                $reporte[] = ['ref' => $ref, 'accion' => $accion, 'ok' => false,
                              'msg' => 'OMITIDO: no tiene XML. Regeneralo primero.'];
                continue;
            }
            $r = $svc->enviarSunat($vid);
            $reporte[] = ['ref' => $ref, 'accion' => $accion, 'ok' => $r['ok'], 'msg' => $r['mensaje']];
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

/*
 * Reprocesables = los de beta + los que quedaron sin CDR.
 *
 * "Sin CDR" cubre dos casos que se reintentan igual: el que nunca se envió, y
 * el que quedó a medio camino porque PHP cortó por timeout entre el limpiado y
 * el envío. Sin incluirlos acá, un corte a mitad de lote los dejaba invisibles.
 * Lo que jamás entra es un comprobante con CDR de producción.
 */
$reprocesables = array_merge($grupos['beta'], $grupos['sin_cdr']);
usort($reprocesables, fn($a, $b) => [$a['serie'], (int)$a['numero']] <=> [$b['serie'], (int)$b['numero']]);

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
      <thead><tr><th>Comprobante</th><th>Acción</th><th>Resultado</th></tr></thead>
      <tbody>
        <?php foreach ($reporte as $r): ?>
        <tr>
          <td class="font-bold" style="font-family:monospace"><?= clean($r['ref']) ?></td>
          <td><span class="badge b-gray"><?= clean($r['accion'] ?? '—') ?></span></td>
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
    <span class="badge b-red"><?= count($grupos['beta']) ?> emitidos en BETA</span>
    <span class="badge b-amber"><?= count($grupos['sin_cdr']) ?> sin CDR (nunca enviados o cortados a medio envío)</span>
    <span class="badge b-teal"><?= count($grupos['produccion']) ?> en producción (intocables)</span>
    <?php if ($grupos['ilegible']): ?><span class="badge b-gray"><?= count($grupos['ilegible']) ?> CDR ilegible</span><?php endif; ?>
  </div>

  <?php if ($reprocesables):
    // Reparto por antigüedad. El plazo exacto lo define la normativa vigente y
    // difiere entre boleta y factura: acá solo se expone el dato en crudo.
    $ant = ['0-3' => 0, '4-7' => 0, '8+' => 0];
    foreach ($reprocesables as $b) {
        $d = (int)floor((time() - strtotime($b['fecha'])) / 86400);
        if ($d <= 3)      $ant['0-3']++;
        elseif ($d <= 7)  $ant['4-7']++;
        else              $ant['8+']++;
    }
  ?>
  <div class="mt-3" style="border-top:1px solid var(--border);padding-top:12px">
    <div class="text-xs text-muted mb-2">ANTIGÜEDAD DE LOS <?= count($reprocesables) ?> REPROCESABLES</div>
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

<?php if ($reprocesables): ?>
<form method="POST">
  <div class="card" style="padding:0">
    <div style="padding:14px 18px;border-bottom:1px solid var(--border)" class="flex items-center justify-between">
      <div>
        <div class="font-bold">Comprobantes reprocesables (<?= count($reprocesables) ?>)</div>
        <div class="text-xs text-muted">Su correlativo sigue libre en producción: se reenvían con la misma serie y número.</div>
      </div>
      <div class="flex gap-1 flex-wrap">
        <button type="button" class="btn btn-sm" onclick="marcarPrimeros(<?= LOTE_MAX ?>)">Primeros <?= LOTE_MAX ?></button>
        <button type="button" class="btn btn-sm" onclick="marcarTodos(false)">Ninguno</button>
        <?php $bloqueado = SUNAT_ENDPOINT !== 'produccion' ? 'disabled title="Cambiá la configuración a producción primero"' : ''; ?>
        <button type="submit" name="action" value="regenerar" class="btn btn-sm" <?= $bloqueado ?>
          onclick="return confirmarAccion('regenerar')">⚡ Solo regenerar XML</button>
        <button type="submit" name="action" value="enviar" class="btn btn-sm btn-primary" <?= $bloqueado ?>
          onclick="return confirmarAccion('enviar')">📤 Enviar a SUNAT</button>
      </div>
    </div>
    <div style="padding:10px 18px;background:var(--bg3);border-bottom:1px solid var(--border)" class="text-xs text-muted">
      <strong>Son dos pasos.</strong>
      <span style="color:var(--text)">⚡ Regenerar</span> arma y firma el XML de nuevo — <u>no toca SUNAT</u>, podés revisarlo antes.
      <span style="color:var(--text)">📤 Enviar</span> sí emite de verdad, y es irreversible.<br>
      ⏱ Máximo <strong><?= LOTE_MAX ?></strong> por lote: uno más grande corta por <code>max_execution_time</code>.
      Si se corta, volvés a entrar y los pendientes siguen listados.
    </div>
    <div class="table-wrap">
      <table class="vtable">
        <thead>
          <tr>
            <th style="width:36px"><input type="checkbox" onclick="marcarTodos(this.checked)"></th>
            <th>Comprobante</th><th>Cliente</th><th>Emitido</th><th>Antigüedad</th>
            <th style="text-align:right">Total</th><th>Estado</th><th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($reprocesables as $i => $v): ?>
          <tr>
            <td><input type="checkbox" class="chk" name="ids[]" value="<?= $v['id'] ?>" <?= $i < LOTE_MAX ? 'checked' : '' ?>></td>
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
            <td>
              <?php if ($v['_cdr']['ambiente'] === 'beta'): ?>
                <span class="badge b-red"><span class="dot"></span>BETA</span>
                <div class="text-xs text-muted" style="margin-top:2px">Regenerar primero</div>
              <?php elseif (!empty($v['sunat_xml'])): ?>
                <span class="badge b-teal"><span class="dot"></span>XML LISTO</span>
                <div class="text-xs text-muted" style="margin-top:2px">Falta enviar</div>
              <?php else: ?>
                <span class="badge b-amber"><span class="dot"></span>SIN XML</span>
                <div class="text-xs text-muted" style="margin-top:2px">Regenerar primero</div>
              <?php endif; ?>
            </td>
            <td>
              <?php if (!empty($v['sunat_xml'])): ?>
                <a href="?p=facturacion&action=ver&id=<?= $v['id'] ?>" target="_blank" class="btn btn-xs" title="Ver el comprobante">🔍</a>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</form>

<script>
var LOTE_MAX = <?= LOTE_MAX ?>;

function marcarTodos(v){ document.querySelectorAll('.chk').forEach(c => c.checked = v); }
function marcarPrimeros(n){
  document.querySelectorAll('.chk').forEach((c, i) => c.checked = i < n);
}
function confirmarAccion(accion){
  var n = document.querySelectorAll('.chk:checked').length;
  if (!n) { alert('No seleccionaste ningún comprobante.'); return false; }
  if (n > LOTE_MAX) {
    alert('Seleccionaste ' + n + '. El máximo por lote es ' + LOTE_MAX + ':\n' +
          'un lote más grande corta por timeout a mitad de camino.');
    return false;
  }

  if (accion === 'regenerar') {
    return confirm('Se va a REGENERAR el XML de ' + n + ' comprobante(s).\n\n' +
                   'Esto NO envía nada a SUNAT: solo arma y firma el XML de nuevo.\n' +
                   'Podés revisarlo antes de emitir.\n\n' +
                   '¿Continuar?');
  }

  return confirm('Se van a ENVIAR ' + n + ' comprobante(s) a SUNAT PRODUCCIÓN.\n\n' +
                 'Esta operación es REAL y no se puede deshacer:\n' +
                 'una vez aceptados, solo se anulan con nota de crédito.\n\n' +
                 'Puede tardar hasta un minuto. NO cierres la pestaña.\n\n' +
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
