<?php
$page = 'reporte_pagos'; $pageTitle = 'Reporte de Pagos';

// ════════════════════════════════════════════════════════════════
// REPORTE DE PAGOS — exportable a Excel (XML SpreadsheetML) y PDF
// Fuente: tabla `ventas` (recibos oficiales). Filtros: rango de
// fechas + método de pago. La exportación va ANTES del header para
// que el archivo descargado salga limpio.
// ════════════════════════════════════════════════════════════════

$_metodos = [
    'efectivo'        => 'Efectivo',
    'yape'            => 'Yape',
    'plin'            => 'Plin',
    'tarjeta_debito'  => 'Tarjeta débito',
    'tarjeta_credito' => 'Tarjeta crédito',
    'transferencia'   => 'Transferencia',
];

// ── Recolectar filtros (compartido por la vista y las exportaciones) ──
$_recoger_datos = function($db) use ($_metodos) {
    $desde  = $_GET['desde']  ?? date('Y-m-01');           // 1° del mes por defecto
    $hasta  = $_GET['hasta']  ?? date('Y-m-d');            // hoy
    $metodo = $_GET['metodo'] ?? 'todos';

    $where  = "v.estado='pagado' AND DATE(v.fecha) BETWEEN ? AND ?";
    $params = [$desde, $hasta];
    // Filtro de sede si aplica
    try { if (function_exists('verTodasSedes') && !verTodasSedes()) { $where .= " AND v.sede_id=".(int)getSede(); } } catch(Exception $e){}

    // ¿Existe la tabla de pagos detallados? (pago mixto: cada método con su monto)
    $tiene_pagos = false;
    try { $tiene_pagos = !empty($db->query("SHOW TABLES LIKE 'venta_pagos'")->fetchAll()); } catch(Exception $e){}

    // 1) Traer TODAS las ventas pagadas del rango (no se pierde ninguna).
    $sqlV = "SELECT v.id, v.tipo_comprobante, v.serie, v.numero, v.fecha, v.total, v.metodo_pago,
                    COALESCE(c.nombre,'—') AS cliente
             FROM ventas v
             LEFT JOIN clientes c ON c.id=v.cliente_id
             WHERE $where
             ORDER BY v.fecha ASC, v.id ASC";
    $stV = $db->prepare($sqlV); $stV->execute($params);
    $ventas = $stV->fetchAll();

    // 2) Para cada venta: si tiene detalle en venta_pagos, lo uso (separa métodos);
    //    si NO (venta antigua), uso el método y total de la propia venta (fallback).
    $rows = [];
    $pagos_por_venta = [];
    if ($tiene_pagos && $ventas) {
        $ids = array_map(fn($v)=>(int)$v['id'], $ventas);
        $in = implode(',', array_fill(0, count($ids), '?'));
        $stP = $db->prepare("SELECT venta_id, metodo_pago, monto FROM venta_pagos WHERE venta_id IN ($in) ORDER BY id ASC");
        $stP->execute($ids);
        foreach ($stP->fetchAll() as $p) { $pagos_por_venta[(int)$p['venta_id']][] = $p; }
    }

    foreach ($ventas as $v) {
        $detalle = $pagos_por_venta[(int)$v['id']] ?? [];
        if (!empty($detalle)) {
            // Venta con desglose real: una fila por método
            $es_mixto = count($detalle) > 1;
            foreach ($detalle as $p) {
                if ($metodo !== 'todos' && $p['metodo_pago'] !== $metodo) continue;
                $rows[] = [
                    'id'=>$v['id'], 'tipo_comprobante'=>$v['tipo_comprobante'], 'serie'=>$v['serie'],
                    'numero'=>$v['numero'], 'fecha'=>$v['fecha'], 'cliente'=>$v['cliente'],
                    'metodo_pago'=>$p['metodo_pago'], 'total'=>$p['monto'], 'es_mixto'=>$es_mixto,
                ];
            }
        } else {
            // Venta sin detalle (antigua): usar el método único de la venta.
            // metodo_pago puede ser "efectivo + yape"; lo tomamos tal cual como una sola fila.
            $mp = $v['metodo_pago'];
            if ($metodo !== 'todos') {
                // Si filtran por un método, incluir solo si coincide exactamente
                if ($mp !== $metodo) continue;
            }
            $rows[] = [
                'id'=>$v['id'], 'tipo_comprobante'=>$v['tipo_comprobante'], 'serie'=>$v['serie'],
                'numero'=>$v['numero'], 'fecha'=>$v['fecha'], 'cliente'=>$v['cliente'],
                'metodo_pago'=>$mp, 'total'=>$v['total'], 'es_mixto'=>false,
            ];
        }
    }

    return ['rows'=>$rows, 'desde'=>$desde, 'hasta'=>$hasta, 'metodo'=>$metodo, 'mixto'=>$tiene_pagos];
};

// Formatea el N° de recibo: SERIE-00000001
$_num_recibo = function($r) {
    return ($r['serie'] ?: '---') . '-' . str_pad($r['numero'] ?? 0, 8, '0', STR_PAD_LEFT);
};

// ── EXPORTAR ──
if (in_array(($_GET['action'] ?? ''), ['excel','pdf'], true)) {
    require_once __DIR__ . '/../includes/config.php';
    $db = getDB();
    if (function_exists('requireLogin')) requireLogin();

    $d = $_recoger_datos($db);
    $rows = $d['rows'];
    $total_general = array_sum(array_column($rows, 'total'));
    $metodo_lbl = ($d['metodo']==='todos') ? 'Todos los métodos' : ($_metodos[$d['metodo']] ?? $d['metodo']);

    // ----- EXCEL (XML SpreadsheetML: abre en columnas en cualquier Excel) -----
    if ($_GET['action'] === 'excel') {
        $fname = 'reporte_pagos_'.$d['desde'].'_a_'.$d['hasta'].'.xls';
        header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
        header('Content-Disposition: attachment; filename="'.$fname.'"');
        header('Cache-Control: max-age=0');
        $esc = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES|ENT_XML1, 'UTF-8');

        echo '<?xml version="1.0" encoding="UTF-8"?>'."\n";
        echo '<?mso-application progid="Excel.Sheet"?>'."\n";
        echo '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">'."\n";
        echo '<Worksheet ss:Name="Pagos"><Table>'."\n";
        // Encabezado informativo
        echo '<Row><Cell><Data ss:Type="String">Reporte de Pagos</Data></Cell></Row>'."\n";
        echo '<Row><Cell><Data ss:Type="String">Periodo: '.$esc($d['desde']).' a '.$esc($d['hasta']).'  |  Método: '.$esc($metodo_lbl).'</Data></Cell></Row>'."\n";
        echo '<Row></Row>'."\n";
        // Cabecera de columnas
        $cols = ['N° Recibo','Comprobante','Fecha','Cliente','Método de Pago','Monto'];
        echo '<Row>'; foreach ($cols as $c) echo '<Cell><Data ss:Type="String">'.$esc($c).'</Data></Cell>'; echo '</Row>'."\n";
        // Filas
        foreach ($rows as $r) {
            echo '<Row>';
            echo '<Cell><Data ss:Type="String">'.$esc($_num_recibo($r)).'</Data></Cell>';
            echo '<Cell><Data ss:Type="String">'.$esc(ucfirst($r['tipo_comprobante'])).'</Data></Cell>';
            echo '<Cell><Data ss:Type="String">'.$esc(date('d/m/Y H:i', strtotime($r['fecha']))).'</Data></Cell>';
            echo '<Cell><Data ss:Type="String">'.$esc($r['cliente']).'</Data></Cell>';
            echo '<Cell><Data ss:Type="String">'.$esc($_metodos[$r['metodo_pago']] ?? $r['metodo_pago']).'</Data></Cell>';
            echo '<Cell><Data ss:Type="Number">'.$esc(number_format((float)$r['total'],2,'.','')).'</Data></Cell>';
            echo '</Row>'."\n";
        }
        // Total
        echo '<Row></Row>'."\n";
        echo '<Row><Cell></Cell><Cell></Cell><Cell></Cell><Cell></Cell><Cell><Data ss:Type="String">TOTAL</Data></Cell><Cell><Data ss:Type="Number">'.$esc(number_format($total_general,2,'.','')).'</Data></Cell></Row>'."\n";
        echo '</Table></Worksheet></Workbook>';
        exit;
    }

    // ----- PDF (HTML imprimible que el navegador convierte a PDF con Ctrl+P) -----
    if ($_GET['action'] === 'pdf') {
        // Datos de la clínica para el encabezado
        $cfg = [];
        try { $cfg = $db->query("SELECT clave,valor FROM configuracion")->fetchAll(PDO::FETCH_KEY_PAIR); } catch(Exception $e){}
        $clinica = $cfg['nombre_clinica'] ?? 'VetPro';
        ?><!DOCTYPE html><html lang="es"><head><meta charset="UTF-8">
        <title>Reporte de Pagos</title>
        <style>
          *{margin:0;padding:0;box-sizing:border-box;font-family:'Segoe UI',Arial,sans-serif}
          body{padding:24px;color:#1e293b;font-size:12px}
          .toolbar{position:fixed;top:14px;right:14px;display:flex;gap:8px}
          .toolbar button{font-size:13px;font-weight:600;border:none;border-radius:8px;padding:9px 16px;cursor:pointer;box-shadow:0 2px 8px rgba(0,0,0,.15)}
          .b-pr{background:#0d9488;color:#fff}.b-cl{background:#fff;color:#374151;border:1px solid #d1d5db}
          h1{font-size:18px;color:#0d9488;margin-bottom:2px}
          .sub{color:#64748b;font-size:12px;margin-bottom:14px}
          .meta{background:#f8fafc;border:1px solid #cbd5e1;border-radius:8px;padding:10px 14px;margin-bottom:16px;font-size:12px}
          table{width:100%;border-collapse:collapse;margin-bottom:14px;border:1.5px solid #0d9488;border-radius:8px;overflow:hidden}
          th{background:#0d9488;color:#fff;text-align:left;padding:9px 11px;font-size:11px;border-right:1px solid rgba(255,255,255,.2)}
          th:last-child{border-right:none}
          td{padding:8px 11px;border-bottom:1px solid #e2e8f0;border-right:1px solid #eef2f6}
          td:last-child{border-right:none}
          tr:nth-child(even) td{background:#f8fafc}
          .r{text-align:right}
          .mix{display:inline-block;background:#fef3c7;color:#92400e;font-size:9px;font-weight:700;padding:1px 6px;border-radius:6px;margin-left:5px;vertical-align:middle}
          .total-row td{border-top:2px solid #0d9488;font-weight:800;font-size:14px;color:#0d9488;background:#f0fdfa!important}
          .foot{margin-top:18px;text-align:center;color:#94a3b8;font-size:10px;border-top:1px solid #e2e8f0;padding-top:8px}
          @media print{.toolbar{display:none}body{padding:0}@page{margin:16mm;size:A4}}
        </style></head><body>
        <div class="toolbar">
          <button class="b-pr" onclick="window.print()">🖨️ Imprimir / Guardar PDF</button>
          <button class="b-cl" onclick="window.close()">Cerrar</button>
        </div>
        <h1>📊 Reporte de Pagos</h1>
        <div class="sub"><?= htmlspecialchars($clinica) ?></div>
        <div class="meta">
          <strong>Periodo:</strong> <?= date('d/m/Y', strtotime($d['desde'])) ?> al <?= date('d/m/Y', strtotime($d['hasta'])) ?>
          &nbsp;·&nbsp; <strong>Método:</strong> <?= htmlspecialchars($metodo_lbl) ?>
          &nbsp;·&nbsp; <strong>Recibos:</strong> <?= count($rows) ?>
        </div>
        <table>
          <thead><tr><th>N° Recibo</th><th>Comprobante</th><th>Fecha</th><th>Cliente</th><th>Método</th><th class="r">Monto</th></tr></thead>
          <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td><?= htmlspecialchars($_num_recibo($r)) ?></td>
              <td><?= ucfirst($r['tipo_comprobante']) ?></td>
              <td><?= date('d/m/Y H:i', strtotime($r['fecha'])) ?></td>
              <td><?= htmlspecialchars($r['cliente']) ?></td>
              <td><?= htmlspecialchars($_metodos[$r['metodo_pago']] ?? $r['metodo_pago']) ?><?= !empty($r['es_mixto']) ? '<span class="mix">mixto</span>' : '' ?></td>
              <td class="r">S/ <?= number_format((float)$r['total'],2) ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if (empty($rows)): ?>
            <tr><td colspan="6" style="text-align:center;color:#94a3b8;padding:24px">Sin pagos en el periodo seleccionado.</td></tr>
          <?php endif; ?>
            <tr class="total-row"><td colspan="5" class="r">TOTAL</td><td class="r">S/ <?= number_format($total_general,2) ?></td></tr>
          </tbody>
        </table>
        <div class="foot"><?= htmlspecialchars($clinica) ?> · Generado el <?= date('d/m/Y H:i') ?> · VetPro</div>
        <script>window.addEventListener('load',function(){setTimeout(function(){window.print();},400);});</script>
        </body></html><?php
        exit;
    }
}

require_once __DIR__ . '/../includes/header.php';
$db = getDB();

// Datos para la vista en pantalla
$d = $_recoger_datos($db);
$rows = $d['rows'];
$total_general = array_sum(array_column($rows, 'total'));
// Subtotales por método (para el resumen)
$por_metodo = [];
foreach ($rows as $r) {
    $m = $r['metodo_pago'];
    $por_metodo[$m] = ($por_metodo[$m] ?? 0) + (float)$r['total'];
}
$qs_export = 'desde='.urlencode($d['desde']).'&hasta='.urlencode($d['hasta']).'&metodo='.urlencode($d['metodo']);
?>

<div class="page">
  <div class="sec-header">
    <div>
      <div class="page-title">📊 Reporte de Pagos</div>
      <div class="page-desc"><?= count($rows) ?> recibo(s) · Total S/ <?= number_format($total_general,2) ?></div>
    </div>
    <div class="flex gap-2">
      <a href="?p=reporte_pagos&action=excel&<?= $qs_export ?>" class="btn btn-ghost">📥 Excel</a>
      <a href="?p=reporte_pagos&action=pdf&<?= $qs_export ?>" target="_blank" class="btn btn-ghost">📄 PDF</a>
    </div>
  </div>

  <!-- Filtros -->
  <div class="card mb-3" style="padding:16px">
    <form method="GET" class="flex gap-2 items-end" style="flex-wrap:wrap">
      <input type="hidden" name="p" value="reporte_pagos">
      <div class="form-group" style="margin:0">
        <label class="form-label">Desde</label>
        <input type="date" name="desde" class="form-input" value="<?= clean($d['desde']) ?>">
      </div>
      <div class="form-group" style="margin:0">
        <label class="form-label">Hasta</label>
        <input type="date" name="hasta" class="form-input" value="<?= clean($d['hasta']) ?>">
      </div>
      <div class="form-group" style="margin:0">
        <label class="form-label">Método de pago</label>
        <select name="metodo" class="form-input">
          <option value="todos" <?= $d['metodo']==='todos'?'selected':'' ?>>Todos los métodos</option>
          <?php foreach ($_metodos as $k=>$lbl): ?>
          <option value="<?= $k ?>" <?= $d['metodo']===$k?'selected':'' ?>><?= $lbl ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <button type="submit" class="btn btn-primary">🔍 Filtrar</button>
    </form>
  </div>

  <!-- Resumen por método -->
  <?php if (!empty($por_metodo)): ?>
  <div class="flex gap-2 mb-3" style="flex-wrap:wrap">
    <?php
      $mcolors=['efectivo'=>'#10b981','yape'=>'#7c3aed','plin'=>'#06b6d4','tarjeta_debito'=>'#3b82f6','tarjeta_credito'=>'#6366f1','transferencia'=>'#f59e0b'];
      foreach ($por_metodo as $m=>$tot): $col=$mcolors[$m]??'#64748b'; ?>
    <div style="flex:1;min-width:130px;background:var(--bg2);border:1px solid var(--border);border-left:3px solid <?= $col ?>;border-radius:10px;padding:11px 14px">
      <div style="font-size:11px;color:var(--text3)"><?= $_metodos[$m]??$m ?></div>
      <div style="font-size:18px;font-weight:800;color:<?= $col ?>">S/ <?= number_format($tot,2) ?></div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- Tabla -->
  <div class="card" style="padding:0;overflow:hidden;border:1.5px solid var(--primary)">
    <div class="table-wrap">
      <table class="table" style="border-collapse:collapse;width:100%">
        <thead><tr style="background:var(--primary)">
          <th style="color:#fff;padding:11px 12px">N° Recibo</th>
          <th style="color:#fff;padding:11px 12px">Comprobante</th>
          <th style="color:#fff;padding:11px 12px">Fecha</th>
          <th style="color:#fff;padding:11px 12px">Cliente</th>
          <th style="color:#fff;padding:11px 12px">Método</th>
          <th style="color:#fff;padding:11px 12px;text-align:right">Monto</th>
        </tr></thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
          <tr style="border-bottom:1px solid var(--border)">
            <td style="font-family:monospace;font-weight:600;padding:9px 12px;border-right:1px solid var(--border)"><?= clean($_num_recibo($r)) ?></td>
            <td style="padding:9px 12px;border-right:1px solid var(--border)"><?= ucfirst($r['tipo_comprobante']) ?></td>
            <td style="padding:9px 12px;border-right:1px solid var(--border)"><?= date('d/m/Y H:i', strtotime($r['fecha'])) ?></td>
            <td style="padding:9px 12px;border-right:1px solid var(--border)"><?= clean($r['cliente']) ?></td>
            <td style="padding:9px 12px;border-right:1px solid var(--border)">
              <?= clean($_metodos[$r['metodo_pago']] ?? $r['metodo_pago']) ?>
              <?php if (!empty($r['es_mixto'])): ?><span style="display:inline-block;background:#fef3c7;color:#92400e;font-size:9px;font-weight:700;padding:1px 6px;border-radius:6px;margin-left:5px">mixto</span><?php endif; ?>
            </td>
            <td style="text-align:right;font-weight:700;padding:9px 12px">S/ <?= number_format((float)$r['total'],2) ?></td>
          </tr>
          <?php endforeach; ?>
          <?php if (empty($rows)): ?>
          <tr><td colspan="6" style="text-align:center;color:var(--text3);padding:30px">Sin pagos en el periodo seleccionado.</td></tr>
          <?php endif; ?>
        </tbody>
        <?php if (!empty($rows)): ?>
        <tfoot>
          <tr style="border-top:2px solid var(--primary);background:#f0fdfa">
            <td colspan="5" style="text-align:right;font-weight:800;padding:11px 12px">TOTAL</td>
            <td style="text-align:right;font-weight:800;color:var(--primary);font-size:15px;padding:11px 12px">S/ <?= number_format($total_general,2) ?></td>
          </tr>
        </tfoot>
        <?php endif; ?>
      </table>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
