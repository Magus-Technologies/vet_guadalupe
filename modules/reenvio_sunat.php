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

// Pausa entre comprobantes (ms) para no golpear la API de corrido.
define('PAUSA_MS', 400);

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

/**
 * Procesa UN comprobante y devuelve el resultado.
 *
 * Las dos etapas van separadas a propósito: 'regenerar' arma y firma el XML sin
 * tocar SUNAT, así se puede revisar antes de emitir; 'enviar' sí emite.
 */
function procesarUno(PDO $db, SunatService $svc, string $accion, int $ventaId): array {
    $st = $db->prepare("SELECT id,serie,numero,tipo_comprobante,sunat_cdr,sunat_xml FROM ventas WHERE id=?");
    $st->execute([$ventaId]);
    $v = $st->fetch();
    if (!$v) return ['ok' => false, 'ref' => "#$ventaId", 'msg' => 'Comprobante inexistente.'];

    $ref = $v['serie'] . '-' . str_pad((string)$v['numero'], 8, '0', STR_PAD_LEFT);

    // Salvaguarda: nunca tocar algo ya aceptado de verdad.
    if (analizarCdr($v['sunat_cdr'])['ambiente'] === 'produccion') {
        return ['ok' => false, 'ref' => $ref, 'omitido' => true,
                'msg' => 'OMITIDO: ya tiene CDR de producción. Solo se anula con nota de crédito.'];
    }

    if ($accion === 'regenerar') {
        limpiarRastroSunat($db, $ventaId);
        $r = $svc->generarXml($ventaId);
        return ['ok' => $r['ok'], 'ref' => $ref,
                'msg' => $r['ok'] ? 'XML regenerado y firmado.' : explicarErrorSunat($r)];
    }

    if (empty($v['sunat_xml'])) {
        return ['ok' => false, 'ref' => $ref, 'omitido' => true,
                'msg' => 'OMITIDO: no tiene XML. Regeneralo primero.'];
    }
    $r = $svc->enviarSunat($ventaId);
    return ['ok' => $r['ok'], 'ref' => $ref, 'msg' => $r['ok'] ? $r['mensaje'] : explicarErrorSunat($r)];
}

/*
 * Endpoint AJAX: un comprobante por request.
 *
 * El navegador recorre la selección de a uno y va mostrando el avance. Así no
 * existe el problema de max_execution_time por más que sean 102, y si algo
 * falla se ve exactamente en cuál.
 */
if (($_GET['ajax'] ?? '') === '1') {
    header('Content-Type: application/json; charset=utf-8');

    $responder = function(array $data) { echo json_encode($data, JSON_UNESCAPED_UNICODE); exit; };

    if (!$sunat_ok)                       $responder(['ok' => false, 'ref' => '-', 'msg' => 'Módulo SUNAT no instalado.', 'fatal' => true]);
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') $responder(['ok' => false, 'ref' => '-', 'msg' => 'Método inválido.', 'fatal' => true]);
    if (SUNAT_ENDPOINT !== 'produccion')  $responder(['ok' => false, 'ref' => '-', 'msg' => 'La configuración SUNAT sigue en BETA.', 'fatal' => true]);

    $accion = $_POST['action'] ?? '';
    $vid    = (int)($_POST['id'] ?? 0);
    if (!in_array($accion, ['regenerar', 'enviar'], true) || !$vid) {
        $responder(['ok' => false, 'ref' => '-', 'msg' => 'Parámetros inválidos.', 'fatal' => true]);
    }

    @set_time_limit(120);
    @ignore_user_abort(true);

    try {
        $responder(procesarUno($db, new SunatService($db), $accion, $vid));
    } catch (Throwable $e) {
        $responder(['ok' => false, 'ref' => "#$vid", 'msg' => 'Excepción: ' . $e->getMessage()]);
    }
}

// ─── DATOS ────────────────────────────────────────────────────────
$comprobantes = $db->query("
    SELECT v.id, v.serie, v.numero, v.tipo_comprobante, v.fecha, v.total,
           v.estado, v.sunat_estado, v.sunat_cdr, v.sunat_xml, v.sunat_mensaje,
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
      <div class="flex gap-1 flex-wrap" id="barraAcciones">
        <button type="button" class="btn btn-sm" onclick="marcarTodos(true)">Todos</button>
        <button type="button" class="btn btn-sm" onclick="marcarTodos(false)">Ninguno</button>
        <button type="button" class="btn btn-sm" onclick="marcarPor('fallo')" title="Los que quedaron rechazados en el último intento">Solo fallados</button>
        <button type="button" class="btn btn-sm" onclick="marcarPor('listo')" title="Los que ya tienen XML y solo falta enviar">Solo con XML listo</button>
        <?php $bloqueado = SUNAT_ENDPOINT !== 'produccion' ? 'disabled title="Cambiá la configuración a producción primero"' : ''; ?>
        <button type="button" class="btn btn-sm btnAccion" <?= $bloqueado ?>
          onclick="procesar('regenerar')">⚡ Solo regenerar XML</button>
        <button type="button" class="btn btn-sm btn-primary btnAccion" <?= $bloqueado ?>
          onclick="procesar('enviar')">📤 Enviar a SUNAT</button>
      </div>
    </div>
    <div style="padding:10px 18px;background:var(--bg3);border-bottom:1px solid var(--border)" class="text-xs text-muted">
      <strong>Son dos pasos.</strong>
      <span style="color:var(--text)">⚡ Regenerar</span> arma y firma el XML de nuevo — <u>no toca SUNAT</u>, podés revisarlo antes.
      <span style="color:var(--text)">📤 Enviar</span> sí emite de verdad, y es irreversible.<br>
      Se procesan <strong>de a uno</strong>, con barra de avance. Podés seleccionar los <?= count($reprocesables) ?> sin problema:
      no hay riesgo de timeout. Si cortás a mitad, los que falten siguen listados al recargar.
    </div>

    <!-- Panel de progreso -->
    <div id="panelProgreso" style="display:none;padding:14px 18px;border-bottom:1px solid var(--border)">
      <div class="flex items-center justify-between mb-2">
        <div class="font-bold text-sm" id="progTitulo">Procesando…</div>
        <button type="button" class="btn btn-xs" onclick="detener()" id="btnDetener">✕ Detener</button>
      </div>
      <div style="background:var(--bg2);border-radius:999px;height:10px;overflow:hidden;margin-bottom:8px">
        <div id="progBarra" style="height:100%;width:0%;background:var(--teal-d);transition:width .2s"></div>
      </div>
      <div class="flex gap-2 mb-2" style="font-size:12px">
        <span id="progContador" class="text-muted">0 / 0</span>
        <span class="badge b-teal" id="progOk">0 OK</span>
        <span class="badge b-red" id="progErr">0 error</span>
        <span class="badge b-gray" id="progOmit">0 omitido</span>
      </div>
      <div id="progLog" style="max-height:240px;overflow:auto;background:var(--bg3);border-radius:8px;padding:8px;font-family:monospace;font-size:11.5px;line-height:1.7"></div>
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
          <?php foreach ($reprocesables as $v): ?>
          <tr id="fila-<?= $v['id'] ?>">
            <td><input type="checkbox" class="chk" value="<?= $v['id'] ?>"
                       data-ref="<?= clean($v['serie']) ?>-<?= str_pad((string)$v['numero'],8,'0',STR_PAD_LEFT) ?>"
                       data-fallo="<?= $v['sunat_estado'] === 'rechazado' ? '1' : '0' ?>"
                       data-listo="<?= !empty($v['sunat_xml']) ? '1' : '0' ?>"></td>
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
              <?php if ($v['sunat_estado'] === 'rechazado' && !empty($v['sunat_mensaje'])): ?>
                <div class="text-xs" style="margin-top:3px;color:var(--red);max-width:280px">
                  ⚠ <?= clean(mb_substr($v['sunat_mensaje'], 0, 160)) ?>
                </div>
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
(function(){
  var URL_AJAX = '<?= BASE_URL ?>/index.php?p=reenvio_sunat&ajax=1';
  var PAUSA    = <?= PAUSA_MS ?>;
  var cancelar = false;
  var corriendo = false;

  var $ = function(id){ return document.getElementById(id); };
  var dormir = function(ms){ return new Promise(function(r){ setTimeout(r, ms); }); };

  window.marcarTodos = function(v){
    if (corriendo) return;
    document.querySelectorAll('.chk').forEach(function(c){ c.checked = v; });
  };

  // Selecciona por atributo: 'fallo' (rechazados) o 'listo' (ya tienen XML).
  window.marcarPor = function(attr){
    if (corriendo) return;
    var n = 0;
    document.querySelectorAll('.chk').forEach(function(c){
      c.checked = c.dataset[attr] === '1';
      if (c.checked) n++;
    });
    if (!n) alert('No hay comprobantes en ese estado.');
  };

  window.detener = function(){
    cancelar = true;
    $('btnDetener').textContent = 'Deteniendo…';
    $('btnDetener').disabled = true;
  };

  function log(clase, ref, msg){
    var color = clase === 'ok' ? 'var(--teal-d)' : (clase === 'omit' ? 'var(--text3)' : 'var(--red)');
    var icono = clase === 'ok' ? '✓' : (clase === 'omit' ? '–' : '✕');
    var linea = document.createElement('div');
    linea.style.color = color;
    linea.textContent = icono + ' ' + ref + '  ' + msg;
    $('progLog').appendChild(linea);
    $('progLog').scrollTop = $('progLog').scrollHeight;
  }

  window.procesar = async function(accion){
    if (corriendo) return;

    var chks = Array.prototype.slice.call(document.querySelectorAll('.chk:checked'));
    if (!chks.length) { alert('No seleccionaste ningún comprobante.'); return; }

    var n = chks.length, ok;
    if (accion === 'regenerar') {
      ok = confirm('Se va a REGENERAR el XML de ' + n + ' comprobante(s).\n\n' +
                   'Esto NO envía nada a SUNAT: solo arma y firma el XML de nuevo.\n' +
                   'Podés revisarlo antes de emitir.\n\n¿Continuar?');
    } else {
      ok = confirm('Se van a ENVIAR ' + n + ' comprobante(s) a SUNAT PRODUCCIÓN.\n\n' +
                   'Esta operación es REAL y no se puede deshacer:\n' +
                   'una vez aceptados, solo se anulan con nota de crédito.\n\n' +
                   'NO cierres la pestaña mientras avanza.\n\n¿Confirmás?');
    }
    if (!ok) return;

    corriendo = true; cancelar = false;
    document.querySelectorAll('.btnAccion').forEach(function(b){ b.disabled = true; });
    $('btnDetener').disabled = false;
    $('btnDetener').textContent = '✕ Detener';
    $('panelProgreso').style.display = 'block';
    $('progLog').innerHTML = '';
    $('progTitulo').textContent = (accion === 'regenerar' ? 'Regenerando XML' : 'Enviando a SUNAT') + '…';

    var nOk = 0, nErr = 0, nOmit = 0, i = 0;

    for (i = 0; i < chks.length; i++) {
      if (cancelar) { log('omit', '—', 'Detenido por el usuario. ' + (chks.length - i) + ' sin procesar.'); break; }

      var chk = chks[i];
      var ref = chk.dataset.ref || ('#' + chk.value);
      var fila = $('fila-' + chk.value);
      if (fila) fila.style.background = 'rgba(30,168,161,.10)';

      try {
        var fd = new FormData();
        fd.append('action', accion);
        fd.append('id', chk.value);
        var resp = await fetch(URL_AJAX, { method:'POST', body: fd, credentials:'same-origin' });
        var txt  = await resp.text();
        var r;
        try { r = JSON.parse(txt); }
        catch (e) { r = { ok:false, ref:ref, msg:'Respuesta no-JSON (HTTP ' + resp.status + '): ' + txt.slice(0,120) }; }

        if (r.fatal) { log('err', r.ref || ref, r.msg); alert('Se detuvo: ' + r.msg); break; }

        if (r.ok)            { nOk++;   log('ok',   r.ref || ref, r.msg); chk.checked = false; }
        else if (r.omitido)  { nOmit++; log('omit', r.ref || ref, r.msg); chk.checked = false; }
        else                 { nErr++;  log('err',  r.ref || ref, r.msg); }
      } catch (e) {
        nErr++; log('err', ref, 'Error de red: ' + e.message);
      }

      if (fila) fila.style.background = '';
      var hechos = i + 1;
      $('progBarra').style.width = (hechos / chks.length * 100) + '%';
      $('progContador').textContent = hechos + ' / ' + chks.length;
      $('progOk').textContent   = nOk + ' OK';
      $('progErr').textContent  = nErr + ' error';
      $('progOmit').textContent = nOmit + ' omitido';

      if (hechos < chks.length) await dormir(PAUSA);
    }

    corriendo = false;
    $('btnDetener').disabled = true;
    $('progTitulo').textContent = 'Terminado — ' + nOk + ' OK, ' + nErr + ' error, ' + nOmit + ' omitido';
    document.querySelectorAll('.btnAccion').forEach(function(b){ b.disabled = false; });

    var aviso = document.createElement('div');
    aviso.style.marginTop = '10px';
    aviso.innerHTML = '<button type="button" class="btn btn-sm btn-primary" onclick="location.reload()">🔄 Recargar para ver el estado actualizado</button>';
    $('panelProgreso').appendChild(aviso);
  };

  // Evita perder el proceso a mitad de camino por un clic distraído.
  window.addEventListener('beforeunload', function(e){
    if (corriendo) { e.preventDefault(); e.returnValue = ''; }
  });
})();
</script>
<?php else: ?>
  <div class="card" style="padding:24px;text-align:center" class="text-muted">
    No hay comprobantes emitidos contra beta.
  </div>
<?php endif; ?>

<?php endif; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
