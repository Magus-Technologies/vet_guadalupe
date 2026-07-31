<?php
$page = 'caja'; $pageTitle = 'Caja / Finanzas';

// ════════════════════════════════════════════════════════════════
// Handlers AJAX (ANTES del header → responden JSON puro)
//  - detalle_caja: todos los movimientos de una caja (cerrada o no)
//  - detalle_recibo: los productos/servicios de una venta
// ════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(($_POST['ajax'] ?? ''), ['detalle_caja','detalle_recibo'], true)) {
    require_once __DIR__ . '/../includes/config.php';
    if (function_exists('requireLogin')) requireLogin();
    $db = getDB();
    header('Content-Type: application/json');

    $metodo_icons = ['efectivo'=>'💵','yape'=>'📱','plin'=>'📱','tarjeta_debito'=>'💳','tarjeta_credito'=>'💳','transferencia'=>'🏦'];
    $cat_labels = ['servicio'=>'Servicio','producto'=>'Producto','gasto_administrativo'=>'Gasto Admin.','compra_insumos'=>'Compra Insumos','otro'=>'Otro'];

    // ── Movimientos de una caja específica ──
    if ($_POST['ajax'] === 'detalle_caja') {
        $caja_id = (int)($_POST['caja_id'] ?? 0);
        $cab = $db->prepare("SELECT ca.*, u.nombre AS cajero FROM cajas ca JOIN usuarios u ON u.id=ca.usuario_id WHERE ca.id=?");
        $cab->execute([$caja_id]); $cab = $cab->fetch();
        if (!$cab) { echo json_encode(['ok'=>false,'error'=>'Caja no encontrada']); exit; }

        $st = $db->prepare("SELECT * FROM movimientos_caja WHERE caja_id=? ORDER BY created_at ASC, id ASC");
        $st->execute([$caja_id]); $movs = $st->fetchAll();

        $ing = 0; $egr = 0; $out = [];
        foreach ($movs as $m) {
            if ($m['tipo']==='ingreso') $ing += (float)$m['monto']; else $egr += (float)$m['monto'];
            $out[] = [
                'tipo'      => $m['tipo'],
                'concepto'  => $m['concepto'],
                'hora'      => date('H:i', strtotime($m['created_at'])),
                'fecha'     => date('d/m/Y', strtotime($m['created_at'])),
                'metodo'    => ($metodo_icons[$m['metodo_pago']] ?? '') . ' ' . ucfirst(str_replace('_',' ', $m['metodo_pago'])),
                'categoria' => $cat_labels[$m['categoria']] ?? $m['categoria'],
                'monto'     => number_format((float)$m['monto'], 2),
                'venta_id'  => $m['venta_id'] ? (int)$m['venta_id'] : null,
            ];
        }
        echo json_encode([
            'ok' => true,
            'caja' => [
                'cajero'    => $cab['cajero'],
                'apertura'  => date('d/m/Y H:i', strtotime($cab['fecha_apertura'])),
                'cierre'    => $cab['fecha_cierre'] ? date('d/m/Y H:i', strtotime($cab['fecha_cierre'])) : null,
                'm_apertura'=> number_format((float)$cab['monto_apertura'], 2),
                'm_cierre'  => $cab['monto_cierre']!==null ? number_format((float)$cab['monto_cierre'], 2) : null,
                'estado'    => $cab['estado'],
                'ingresos'  => number_format($ing, 2),
                'egresos'   => number_format($egr, 2),
                'balance'   => number_format((float)$cab['monto_apertura'] + $ing - $egr, 2),
            ],
            'movimientos' => $out,
        ]); exit;
    }

    // ── Productos/servicios de un recibo (venta) ──
    if ($_POST['ajax'] === 'detalle_recibo') {
        $venta_id = (int)($_POST['venta_id'] ?? 0);
        $v = $db->prepare("SELECT v.*, c.nombre AS cliente FROM ventas v LEFT JOIN clientes c ON c.id=v.cliente_id WHERE v.id=?");
        $v->execute([$venta_id]); $v = $v->fetch();
        if (!$v) { echo json_encode(['ok'=>false,'error'=>'Recibo no encontrado']); exit; }

        $items = $db->prepare("SELECT tipo,descripcion,cantidad,precio_unitario,descuento,subtotal FROM venta_items WHERE venta_id=? ORDER BY id ASC");
        $items->execute([$venta_id]); $items = $items->fetchAll();

        // Detalle de pagos (puede ser mixto)
        $pagos = [];
        try {
            if (!empty($db->query("SHOW TABLES LIKE 'venta_pagos'")->fetchAll())) {
                $pp = $db->prepare("SELECT metodo_pago, monto FROM venta_pagos WHERE venta_id=? ORDER BY id ASC");
                $pp->execute([$venta_id]);
                foreach ($pp->fetchAll() as $p) $pagos[] = ucfirst(str_replace('_',' ',$p['metodo_pago'])).': S/ '.number_format((float)$p['monto'],2);
            }
        } catch(Exception $e){}

        $out_items = [];
        foreach ($items as $it) {
            $out_items[] = [
                'tipo'        => $it['tipo'],
                'descripcion' => $it['descripcion'],
                'cantidad'    => (int)$it['cantidad'],
                'precio'      => number_format((float)$it['precio_unitario'], 2),
                'descuento'   => number_format((float)$it['descuento'], 2),
                'subtotal'    => number_format((float)$it['subtotal'], 2),
            ];
        }
        echo json_encode([
            'ok' => true,
            'recibo' => [
                'numero'    => ($v['serie'] ?: '---').'-'.str_pad($v['numero'] ?? 0, 8, '0', STR_PAD_LEFT),
                'comprobante'=> ucfirst($v['tipo_comprobante']),
                'fecha'     => date('d/m/Y H:i', strtotime($v['fecha'])),
                'cliente'   => $v['cliente'] ?: '—',
                'subtotal'  => number_format((float)$v['subtotal'], 2),
                'igv'       => number_format((float)$v['igv'], 2),
                'descuento' => number_format((float)$v['descuento'], 2),
                'total'     => number_format((float)$v['total'], 2),
                'pagos'     => $pagos,
            ],
            'items' => $out_items,
        ]); exit;
    }
}

require_once __DIR__ . '/../includes/header.php';
$db = getDB();

// ════════════════════════════════════════════════════════════════
// Anulación de caja — migración idempotente
//  - Agrega el estado 'anulada' al enum
//  - Registra quién, cuándo y por qué se anuló
// ════════════════════════════════════════════════════════════════
try {
    $colE = $db->query("SHOW COLUMNS FROM cajas LIKE 'estado'")->fetch();
    if ($colE && stripos($colE['Type'], 'anulada') === false) {
        $db->exec("ALTER TABLE cajas MODIFY COLUMN estado ENUM('abierta','cerrada','anulada') DEFAULT 'abierta'");
    }
    $colA = $db->query("SHOW COLUMNS FROM cajas LIKE 'anulada_por'")->fetchAll();
    if (empty($colA)) {
        $db->exec("ALTER TABLE cajas
            ADD COLUMN motivo_anulacion VARCHAR(255) NULL,
            ADD COLUMN anulada_por INT NULL,
            ADD COLUMN fecha_anulacion DATETIME NULL");
    }
} catch (Exception $e) { /* ya aplicado */ }

$action = $_GET['action'] ?? 'list';
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pa = $_POST['action'] ?? '';
    if ($pa === 'abrir') {
        $db->prepare("INSERT INTO cajas (sede_id,usuario_id,monto_apertura,estado) VALUES (?,?,?,'abierta')")->execute([$user['sede_id']??1,$user['id'],(float)$_POST['monto_apertura']]);
        $msg='abierta'; $action='list';
    }
    if ($pa === 'cerrar') {
        $db->prepare("UPDATE cajas SET estado='cerrada',fecha_cierre=NOW(),monto_cierre=? WHERE id=?")->execute([(float)$_POST['monto_cierre'],(int)$_POST['caja_id']]);
        $msg='cerrada'; $action='list';
    }
    if ($pa === 'movimiento') {
        $caja_id = (int)$_POST['caja_id'];
        $db->prepare("INSERT INTO movimientos_caja (caja_id,usuario_id,tipo,concepto,monto,metodo_pago,categoria) VALUES (?,?,?,?,?,?,?)")->execute([$caja_id,$user['id'],$_POST['tipo'],trim($_POST['concepto']),(float)$_POST['monto'],$_POST['metodo_pago'],$_POST['categoria']]);
        $msg='success'; $action='list';
    }
    if ($pa === 'anular') {
        $caja_id = (int)($_POST['caja_id'] ?? 0);
        $motivo  = trim($_POST['motivo'] ?? '');
        if (!canDelete('caja')) {                      // solo admin o rol con permiso de eliminar
            $msg = 'anular_denegado';
        } elseif ($motivo === '') {                    // motivo obligatorio
            $msg = 'anular_motivo';
        } else {
            // No permitir anular una caja con movimientos (están ligados a ventas)
            $n = (int)$db->query("SELECT COUNT(*) FROM movimientos_caja WHERE caja_id={$caja_id}")->fetchColumn();
            $estActual = $db->prepare("SELECT estado FROM cajas WHERE id=?");
            $estActual->execute([$caja_id]); $est = $estActual->fetchColumn();
            if ($est === false) {
                $msg = 'anular_inexistente';
            } elseif ($est === 'anulada') {
                $msg = 'anular_ya';
            } elseif ($n > 0) {
                $msg = 'anular_con_mov';
            } else {
                $db->prepare("UPDATE cajas SET estado='anulada', motivo_anulacion=?, anulada_por=?, fecha_anulacion=NOW() WHERE id=? AND estado IN ('abierta','cerrada')")
                   ->execute([substr($motivo,0,255), $user['id'], $caja_id]);
                auditLog('anular', 'caja', "Caja #{$caja_id} anulada. Motivo: {$motivo}");
                $msg = 'anulada'; $action = 'list';
            }
        }
    }
}

// Caja activa — filtrada por sede
$_sid = getSede(); $_all = verTodasSedes();
$_caja_sw = $_all ? "" : " AND ca.sede_id=$_sid";
$caja = $db->query("SELECT ca.*,u.nombre as cajero FROM cajas ca JOIN usuarios u ON u.id=ca.usuario_id WHERE ca.estado='abierta'$_caja_sw ORDER BY ca.id DESC LIMIT 1")->fetch();

$movimientos = [];
$ingresos = $egresos = 0;
if ($caja) {
    $st = $db->prepare("SELECT * FROM movimientos_caja WHERE caja_id=? ORDER BY created_at DESC");
    $st->execute([$caja['id']]); $movimientos = $st->fetchAll();
    $ingresos = array_sum(array_column(array_filter($movimientos,fn($m)=>$m['tipo']==='ingreso'),'monto'));
    $egresos  = array_sum(array_column(array_filter($movimientos,fn($m)=>$m['tipo']==='egreso'),'monto'));
}

// Resumen por método de pago
$resumen_metodo = [];
if ($caja) {
    $st = $db->prepare("SELECT metodo_pago,SUM(monto) as total FROM movimientos_caja WHERE caja_id=? AND tipo='ingreso' GROUP BY metodo_pago");
    $st->execute([$caja['id']]); $resumen_metodo=$st->fetchAll();
}

// Historial de cajas
$historial_cajas = $db->query("SELECT ca.*,u.nombre as cajero, (SELECT COALESCE(SUM(monto),0) FROM movimientos_caja WHERE caja_id=ca.id AND tipo='ingreso') as total_ingresos, (SELECT COALESCE(SUM(monto),0) FROM movimientos_caja WHERE caja_id=ca.id AND tipo='egreso') as total_egresos, (SELECT COUNT(*) FROM movimientos_caja WHERE caja_id=ca.id) as n_mov FROM cajas ca JOIN usuarios u ON u.id=ca.usuario_id WHERE 1=1$_caja_sw ORDER BY ca.id DESC LIMIT 15")->fetchAll();
$max_ingreso = max(array_column($historial_cajas,'total_ingresos') ?: [1]);

$metodo_icons = ['efectivo'=>'💵','yape'=>'📱','plin'=>'📱','tarjeta_debito'=>'💳','tarjeta_credito'=>'💳','transferencia'=>'🏦'];
$cat_labels = ['servicio'=>'Servicio','producto'=>'Producto','gasto_administrativo'=>'Gasto Admin.','compra_insumos'=>'Compra Insumos','otro'=>'Otro'];
?>
<?php
$_alerts = [
    'anulada'           => ['ok',  '✅ Caja anulada correctamente.'],
    'anular_denegado'   => ['err', '🚫 No tienes permiso para anular cajas.'],
    'anular_motivo'     => ['err', '⚠️ Debes indicar el motivo de la anulación.'],
    'anular_con_mov'    => ['err', '⛔ No se puede anular una caja con movimientos registrados. Reviértelos primero (están ligados a ventas).'],
    'anular_ya'         => ['err', 'ℹ️ Esa caja ya estaba anulada.'],
    'anular_inexistente'=> ['err', '⚠️ La caja indicada no existe.'],
];
if ($msg):
    if (isset($_alerts[$msg])):
        [$tipo,$txt] = $_alerts[$msg]; ?>
        <div class="alert <?= $tipo==='ok'?'alert-success':'alert-danger' ?> mb-2"><?= $txt ?></div>
    <?php else: ?>
        <div class="alert alert-success mb-2">✅ Operación realizada correctamente.</div>
    <?php endif;
endif; ?>

<?php if(!$caja): ?>
<div class="card" style="max-width:480px;text-align:center;padding:40px">
  <div style="font-size:48px;margin-bottom:12px">🏦</div>
  <div class="sec-title mb-1">No hay caja abierta</div>
  <div class="text-muted text-sm mb-2">Para registrar movimientos, primero debes abrir la caja del día.</div>
  <form method="POST" class="flex gap-1 justify-center items-end">
    <input type="hidden" name="action" value="abrir">
    <div class="form-group text-left" style="min-width:180px">
      <label class="form-label">Monto de apertura (S/.)</label>
      <input class="form-input" type="number" step="0.01" name="monto_apertura" value="500" required>
    </div>
    <button type="submit" class="btn btn-primary" style="margin-bottom:14px">Abrir caja</button>
  </form>
</div>

<?php else: ?>
<!-- CAJA ABIERTA -->
<div class="alert alert-success mb-2"><span>✅</span><div><strong>Caja abierta</strong> desde <?= date('d/m/Y H:i',strtotime($caja['fecha_apertura'])) ?> · Cajero: <?= clean($caja['cajero']) ?> · Apertura: S/. <?= number_format($caja['monto_apertura'],2) ?></div></div>

<div class="grid g4 mb-2">
  <div class="stat-card"><div class="stat-icon si-teal">💵</div><div class="stat-value">S/. <?= number_format($ingresos,2) ?></div><div class="stat-label">Ingresos del día</div></div>
  <div class="stat-card" style="border-color:var(--red)"><div class="stat-icon si-red">📤</div><div class="stat-value">S/. <?= number_format($egresos,2) ?></div><div class="stat-label">Egresos</div></div>
  <div class="stat-card"><div class="stat-icon si-teal">📊</div><div class="stat-value">S/. <?= number_format($ingresos-$egresos,2) ?></div><div class="stat-label">Saldo neto</div></div>
  <div class="stat-card"><div class="stat-icon si-blue">🏦</div><div class="stat-value">S/. <?= number_format($caja['monto_apertura']+$ingresos-$egresos,2) ?></div><div class="stat-label">Cierre estimado</div></div>
</div>

<div class="grid g2">
  <!-- MOVIMIENTOS -->
  <div>
    <div class="card">
      <div class="sec-header"><div class="sec-title">Movimientos del día</div>
        <button class="btn btn-sm btn-primary" onclick="document.getElementById('mov-modal').style.display='flex'">+ Registrar</button>
      </div>
      <?php if(empty($movimientos)): ?><div class="text-center text-muted" style="padding:24px">Sin movimientos aún.</div><?php endif; ?>
      <?php foreach($movimientos as $m): ?>
      <div class="flex items-center gap-2" style="padding:9px 0;border-bottom:0.5px solid var(--border)">
        <div style="width:28px;height:28px;border-radius:8px;background:<?= $m['tipo']==='ingreso'?'var(--green-l)':'var(--red-l)' ?>;display:flex;align-items:center;justify-content:center;font-size:14px"><?= $m['tipo']==='ingreso'?'↓':'↑' ?></div>
        <div class="flex-1">
          <div class="text-sm font-med"><?= clean($m['concepto']) ?></div>
          <div class="text-xs text-muted"><?= date('H:i',strtotime($m['created_at'])) ?> · <?= $metodo_icons[$m['metodo_pago']]??'' ?> <?= ucfirst(str_replace('_',' ',$m['metodo_pago'])) ?> · <?= $cat_labels[$m['categoria']]??$m['categoria'] ?></div>
        </div>
        <?php if(!empty($m['venta_id'])): ?>
        <button onclick="verRecibo(<?= (int)$m['venta_id'] ?>)" style="border:1px solid var(--border);background:var(--bg2);border-radius:6px;padding:3px 8px;cursor:pointer;font-size:11px;white-space:nowrap" title="Ver productos del recibo">🧾</button>
        <?php endif; ?>
        <span style="font-size:14px;font-weight:700;color:<?= $m['tipo']==='ingreso'?'var(--green)':'var(--red)' ?>"><?= $m['tipo']==='ingreso'?'+':'-' ?>S/. <?= number_format($m['monto'],2) ?></span>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- RESUMEN + CIERRE -->
  <div>
    <div class="card mb-2">
      <div class="sec-header"><div class="sec-title">Ingresos por método de pago</div></div>
      <?php if(empty($resumen_metodo)): ?><div class="text-muted text-sm">Sin ingresos aún.</div><?php endif; ?>
      <?php foreach($resumen_metodo as $rm): $pct = $ingresos>0?round($rm['total']/$ingresos*100):0; ?>
      <div class="flex items-center gap-2 mb-2">
        <span style="font-size:20px"><?= $metodo_icons[$rm['metodo_pago']]??'💰' ?></span>
        <div class="flex-1">
          <div class="flex justify-between mb-1"><span class="text-sm font-med"><?= ucfirst(str_replace('_',' ',$rm['metodo_pago'])) ?></span><span class="text-sm font-bold">S/. <?= number_format($rm['total'],2) ?></span></div>
          <div class="progress-bar"><div class="progress-fill" style="width:<?= $pct ?>%;background:var(--teal)"></div></div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <div class="card">
      <div class="sec-header"><div class="sec-title">Cerrar caja</div></div>
      <form method="POST">
        <input type="hidden" name="action" value="cerrar">
        <input type="hidden" name="caja_id" value="<?= $caja['id'] ?>">
        <div class="form-group"><label class="form-label">Monto real en caja (S/.)</label><input class="form-input" type="number" step="0.01" name="monto_cierre" value="<?= number_format($caja['monto_apertura']+$ingresos-$egresos,2) ?>" required></div>
        <button type="submit" class="btn btn-red w-full" onclick="return confirm('¿Cerrar la caja del día? Esta acción no se puede deshacer.')">🔒 Cerrar caja del día</button>
      </form>
    </div>
  </div>
</div>

<!-- MODAL MOVIMIENTO -->
<div id="mov-modal" class="modal-overlay" style="display:none">
  <div class="modal">
    <div class="modal-header"><div class="modal-title">Registrar Movimiento</div><button class="modal-close" onclick="document.getElementById('mov-modal').style.display='none'">✕</button></div>
    <div class="modal-body">
      <form method="POST">
        <input type="hidden" name="action" value="movimiento">
        <input type="hidden" name="caja_id" value="<?= $caja['id'] ?>">
        <div class="form-row">
          <div class="form-group"><label class="form-label">Tipo *</label><select class="form-input" name="tipo"><option value="ingreso">Ingreso</option><option value="egreso">Egreso</option></select></div>
          <div class="form-group"><label class="form-label">Monto (S/.) *</label><input class="form-input" type="number" step="0.01" name="monto" required></div>
        </div>
        <div class="form-group"><label class="form-label">Concepto *</label><input class="form-input" name="concepto" required></div>
        <div class="form-row">
          <div class="form-group"><label class="form-label">Método de pago</label><select class="form-input" name="metodo_pago"><option value="efectivo">Efectivo</option><option value="yape">Yape</option><option value="plin">Plin</option><option value="tarjeta_debito">Tarjeta</option><option value="transferencia">Transferencia</option></select></div>
          <div class="form-group"><label class="form-label">Categoría</label><select class="form-input" name="categoria"><?php foreach($cat_labels as $k=>$v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?></select></div>
        </div>
        <div class="modal-footer" style="padding:0;border:0"><button type="submit" class="btn btn-primary">💾 Guardar</button></div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- HISTORIAL DE CAJAS -->
<?php if(!empty($historial_cajas)): ?>
<div class="card mt-2">
  <div class="sec-header"><div class="sec-title">Historial de cajas</div><div class="text-xs text-muted">Haz clic en una caja para ver sus movimientos</div></div>
  <div class="table-wrap">
    <table class="vtable">
      <thead><tr><th>Apertura</th><th>Cierre</th><th>Cajero</th><th>Apertura (S/.)</th><th>Ingresos</th><th>Egresos</th><th>Balance</th><th>Estado</th><th></th></tr></thead>
      <tbody>
        <?php foreach($historial_cajas as $h): ?>
        <tr onclick="verCaja(<?= (int)$h['id'] ?>)" style="cursor:pointer<?= $h['estado']==='anulada'?';opacity:.6':'' ?>" class="caja-row">
          <td class="text-muted"><?= date('d/m/Y H:i',strtotime($h['fecha_apertura'])) ?></td>
          <td class="text-muted"><?= $h['fecha_cierre'] ? date('d/m/Y H:i',strtotime($h['fecha_cierre'])) : '—' ?></td>
          <td><?= clean($h['cajero']) ?></td>
          <td>S/. <?= number_format($h['monto_apertura'],2) ?></td>
          <td class="font-med" style="color:var(--green)">S/. <?= number_format($h['total_ingresos'],2) ?></td>
          <td class="font-med" style="color:var(--red)">S/. <?= number_format($h['total_egresos'],2) ?></td>
          <td class="font-bold">S/. <?= number_format($h['monto_apertura']+$h['total_ingresos']-$h['total_egresos'],2) ?></td>
          <td><?php if($h['estado']==='anulada'): ?><span class="badge" style="background:#fee2e2;color:#b91c1c">Anulada</span><?php else: ?><span class="badge <?= $h['estado']==='abierta'?'b-teal':'b-gray' ?>"><?= ucfirst($h['estado']) ?></span><?php endif; ?></td>
          <td style="text-align:right;color:var(--text3);white-space:nowrap">
            <?php if(canDelete('caja') && $h['estado']!=='anulada'): ?>
              <button onclick="event.stopPropagation();anularCaja(<?= (int)$h['id'] ?>,<?= (int)$h['n_mov'] ?>)" title="Anular caja" style="border:1px solid #fecaca;background:#fff5f5;color:#b91c1c;border-radius:6px;padding:3px 8px;cursor:pointer;font-size:11px;margin-right:6px">🚫 anular</button>
            <?php endif; ?>
            👁️ ver
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<!-- MODAL: Detalle de caja (movimientos del día) -->
<div id="caja-modal" class="modal-overlay" style="display:none">
  <div class="modal" style="max-width:680px">
    <div class="modal-header">
      <div class="modal-title">📋 Movimientos de la caja</div>
      <button class="modal-close" onclick="document.getElementById('caja-modal').style.display='none'">✕</button>
    </div>
    <div class="modal-body" id="caja-modal-body" style="max-height:70vh;overflow-y:auto">
      <div class="text-center text-muted" style="padding:30px">Cargando…</div>
    </div>
  </div>
</div>

<!-- MODAL: Detalle de recibo (productos) -->
<div id="recibo-modal" class="modal-overlay" style="display:none;z-index:1100">
  <div class="modal" style="max-width:600px">
    <div class="modal-header">
      <div class="modal-title">🧾 Detalle del recibo</div>
      <button class="modal-close" onclick="document.getElementById('recibo-modal').style.display='none'">✕</button>
    </div>
    <div class="modal-body" id="recibo-modal-body" style="max-height:70vh;overflow-y:auto">
      <div class="text-center text-muted" style="padding:30px">Cargando…</div>
    </div>
  </div>
</div>

<!-- MODAL: Anular caja -->
<div id="anular-modal" class="modal-overlay" style="display:none;z-index:1200">
  <div class="modal" style="max-width:440px">
    <div class="modal-header">
      <div class="modal-title">🚫 Anular caja</div>
      <button class="modal-close" onclick="document.getElementById('anular-modal').style.display='none'">✕</button>
    </div>
    <form method="post">
      <div class="modal-body">
        <input type="hidden" name="action" value="anular">
        <input type="hidden" name="caja_id" id="anular-caja-id">
        <div class="alert alert-danger mb-2" style="font-size:13px">Esta acción marca la caja #<span id="anular-caja-num"></span> como <strong>anulada</strong> y queda registrada en auditoría. No se puede deshacer.</div>
        <label class="form-label">Motivo de la anulación <span style="color:var(--red)">*</span></label>
        <textarea class="form-input" name="motivo" id="anular-motivo" rows="3" required placeholder="Ej: caja abierta por error"></textarea>
      </div>
      <div class="modal-footer flex gap-1" style="padding:0 16px 16px;justify-content:flex-end">
        <button type="button" class="btn" onclick="document.getElementById('anular-modal').style.display='none'">Cancelar</button>
        <button type="submit" class="btn" style="background:#b91c1c;color:#fff">Anular caja</button>
      </div>
    </form>
  </div>
</div>

<script>
function _waMoney(s){ return 'S/ ' + s; }

// ── Anular caja ──
function anularCaja(id, nMov){
  if (nMov > 0){
    alert('No se puede anular esta caja: tiene ' + nMov + ' movimiento(s) registrado(s).\n\nLos movimientos están ligados a ventas. Reviértelos primero si necesitas anularla.');
    return;
  }
  document.getElementById('anular-caja-id').value = id;
  document.getElementById('anular-caja-num').textContent = id;
  document.getElementById('anular-motivo').value = '';
  document.getElementById('anular-modal').style.display = 'flex';
}

// ── Ver movimientos de una caja ──
function verCaja(id){
  var modal = document.getElementById('caja-modal');
  var body  = document.getElementById('caja-modal-body');
  body.innerHTML = '<div class="text-center text-muted" style="padding:30px">Cargando…</div>';
  modal.style.display = 'flex';
  fetch('<?= BASE_URL ?>/index.php?p=caja', {
    method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body:'ajax=detalle_caja&caja_id='+id
  }).then(r=>r.json()).then(function(d){
    if(!d.ok){ body.innerHTML = '<div class="text-center text-muted" style="padding:30px">'+(d.error||'Error')+'</div>'; return; }
    var c = d.caja;
    var h = '<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:14px">'
      + _box('Cajero', c.cajero) + _box('Estado', c.estado==='abierta'?'🟢 Abierta':'🔒 Cerrada')
      + _box('Apertura', c.apertura+' · S/ '+c.m_apertura)
      + _box('Cierre', c.cierre ? (c.cierre+(c.m_cierre?' · S/ '+c.m_cierre:'')) : '—')
      + '</div>';
    h += '<div style="display:flex;gap:8px;margin-bottom:14px">'
      + '<div style="flex:1;background:var(--green-l,#ecfdf5);border-radius:8px;padding:9px 12px"><div style="font-size:11px;color:var(--text3)">Ingresos</div><div style="font-weight:800;color:var(--green,#10b981)">S/ '+c.ingresos+'</div></div>'
      + '<div style="flex:1;background:var(--red-l,#fef2f2);border-radius:8px;padding:9px 12px"><div style="font-size:11px;color:var(--text3)">Egresos</div><div style="font-weight:800;color:var(--red,#ef4444)">S/ '+c.egresos+'</div></div>'
      + '<div style="flex:1;background:var(--bg3,#f1f5f9);border-radius:8px;padding:9px 12px"><div style="font-size:11px;color:var(--text3)">Balance</div><div style="font-weight:800">S/ '+c.balance+'</div></div>'
      + '</div>';
    if(d.movimientos.length===0){
      h += '<div class="text-center text-muted" style="padding:24px">Sin movimientos en esta caja.</div>';
    } else {
      h += '<table style="width:100%;border-collapse:collapse;font-size:13px;border:1px solid var(--border);border-radius:8px;overflow:hidden">';
      h += '<thead><tr style="background:var(--bg3,#f1f5f9)"><th style="text-align:left;padding:8px 10px">Hora</th><th style="text-align:left;padding:8px 10px">Concepto</th><th style="text-align:left;padding:8px 10px">Método</th><th style="text-align:right;padding:8px 10px">Monto</th><th></th></tr></thead><tbody>';
      d.movimientos.forEach(function(m){
        var color = m.tipo==='ingreso' ? 'var(--green,#10b981)' : 'var(--red,#ef4444)';
        var signo = m.tipo==='ingreso' ? '+' : '−';
        var verBtn = m.venta_id ? '<button onclick="verRecibo('+m.venta_id+')" style="border:1px solid var(--border);background:var(--bg2);border-radius:6px;padding:3px 8px;cursor:pointer;font-size:11px;white-space:nowrap">🧾 recibo</button>' : '';
        h += '<tr style="border-top:1px solid var(--border)">'
          + '<td style="padding:7px 10px;color:var(--text3)">'+m.hora+'</td>'
          + '<td style="padding:7px 10px">'+_esc(m.concepto)+'<div style="font-size:10px;color:var(--text3)">'+m.categoria+'</div></td>'
          + '<td style="padding:7px 10px">'+_esc(m.metodo)+'</td>'
          + '<td style="padding:7px 10px;text-align:right;font-weight:700;color:'+color+'">'+signo+'S/ '+m.monto+'</td>'
          + '<td style="padding:7px 10px;text-align:right">'+verBtn+'</td>'
          + '</tr>';
      });
      h += '</tbody></table>';
    }
    body.innerHTML = h;
  }).catch(function(){ body.innerHTML='<div class="text-center text-muted" style="padding:30px">Error de conexión</div>'; });
}

// ── Ver productos de un recibo ──
function verRecibo(id){
  var modal = document.getElementById('recibo-modal');
  var body  = document.getElementById('recibo-modal-body');
  body.innerHTML = '<div class="text-center text-muted" style="padding:30px">Cargando…</div>';
  modal.style.display = 'flex';
  fetch('<?= BASE_URL ?>/index.php?p=caja', {
    method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body:'ajax=detalle_recibo&venta_id='+id
  }).then(r=>r.json()).then(function(d){
    if(!d.ok){ body.innerHTML = '<div class="text-center text-muted" style="padding:30px">'+(d.error||'Error')+'</div>'; return; }
    var r = d.recibo;
    var h = '<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:14px">'
      + _box('N° Recibo', r.numero) + _box('Comprobante', r.comprobante)
      + _box('Fecha', r.fecha) + _box('Cliente', r.cliente) + '</div>';
    h += '<table style="width:100%;border-collapse:collapse;font-size:13px;border:1px solid var(--border);border-radius:8px;overflow:hidden">';
    h += '<thead><tr style="background:var(--bg3,#f1f5f9)"><th style="text-align:left;padding:8px 10px">Producto / Servicio</th><th style="text-align:center;padding:8px 10px">Cant.</th><th style="text-align:right;padding:8px 10px">P. Unit</th><th style="text-align:right;padding:8px 10px">Subtotal</th></tr></thead><tbody>';
    if(d.items.length===0){
      h += '<tr><td colspan="4" style="text-align:center;color:var(--text3);padding:20px">Sin items registrados.</td></tr>';
    } else {
      d.items.forEach(function(it){
        var icon = it.tipo==='servicio' ? '🩺' : '📦';
        h += '<tr style="border-top:1px solid var(--border)">'
          + '<td style="padding:7px 10px">'+icon+' '+_esc(it.descripcion)+'</td>'
          + '<td style="padding:7px 10px;text-align:center">'+it.cantidad+'</td>'
          + '<td style="padding:7px 10px;text-align:right">S/ '+it.precio+'</td>'
          + '<td style="padding:7px 10px;text-align:right;font-weight:600">S/ '+it.subtotal+'</td>'
          + '</tr>';
      });
    }
    h += '</tbody></table>';
    // Totales
    h += '<div style="margin-top:12px;text-align:right;font-size:13px">';
    if(parseFloat(r.descuento)>0) h += '<div style="color:var(--text3)">Descuento: −S/ '+r.descuento+'</div>';
    if(parseFloat(r.igv)>0) h += '<div style="color:var(--text3)">IGV: S/ '+r.igv+'</div>';
    h += '<div style="font-size:17px;font-weight:800;color:var(--primary,#0d9488);margin-top:4px">Total: S/ '+r.total+'</div>';
    h += '</div>';
    // Pagos (si mixto)
    if(r.pagos && r.pagos.length){
      h += '<div style="margin-top:12px;background:var(--bg3,#f1f5f9);border-radius:8px;padding:10px 12px"><div style="font-size:11px;color:var(--text3);margin-bottom:4px">Forma de pago</div>';
      r.pagos.forEach(function(p){ h += '<div style="font-size:13px">💳 '+_esc(p)+'</div>'; });
      h += '</div>';
    }
    body.innerHTML = h;
  }).catch(function(){ body.innerHTML='<div class="text-center text-muted" style="padding:30px">Error de conexión</div>'; });
}

function _box(label,val){ return '<div style="background:var(--bg3,#f1f5f9);border-radius:8px;padding:8px 11px"><div style="font-size:10px;color:var(--text3);text-transform:uppercase">'+label+'</div><div style="font-size:13px;font-weight:600">'+_esc(val)+'</div></div>'; }
function _esc(s){ var d=document.createElement('div'); d.textContent=(s==null?'':s); return d.innerHTML; }
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
