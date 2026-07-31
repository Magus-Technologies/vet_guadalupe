<?php
$page = 'dashboard'; $pageTitle = 'Dashboard';
require_once __DIR__ . '/../includes/header.php';
$db = getDB();

// Verificar qué tablas tienen sede_id (seguro, sin crashear)
$_has_sede = [];
foreach (['ventas','citas','clientes','mascotas','consultas','productos','petshop_productos','compras'] as $t) {
    try {
        $r = $db->query("SHOW COLUMNS FROM `$t` LIKE 'sede_id'")->fetchAll();
        $_has_sede[$t] = !empty($r);
        if (empty($r)) $db->exec("ALTER TABLE `$t` ADD COLUMN sede_id INT DEFAULT 1");
    } catch(Exception $e) { $_has_sede[$t] = false; }
}

// Filtros de sede — solo si la columna existe en cada tabla
$_sid = getSede();
$_all = verTodasSedes();
$sc   = (!$_all && $_has_sede['citas'])     ? " AND c.sede_id=$_sid"   : "";
$sv   = (!$_all && $_has_sede['ventas'])    ? " AND v.sede_id=$_sid"   : "";
$sm   = (!$_all && $_has_sede['mascotas'])  ? " AND m.sede_id=$_sid"   : "";
$scl  = (!$_all && $_has_sede['clientes'])  ? " AND cl.sede_id=$_sid"  : "";
$scon = (!$_all && $_has_sede['consultas']) ? " AND con.sede_id=$_sid" : "";
$ss   = (!$_all && $_has_sede['productos']) ? "sede_id=$_sid"          : "1=1";

// ── KPIs con filtro de sede ──
$citas_hoy=0; $citas_pend=0; $pacientes_mes=0; $pac_mes_ant=0;
$ingresos_hoy=0; $ingresos_mes=0; $nuevos_clientes=0; $cli_mes_ant=0; $alertas_count=0;

// Construir filtros de sede para cada tabla
$sc  = andSede('c');     // citas
$sv  = andSede('v');     // ventas
$sm  = andSede('m');     // mascotas
$scl = andSede('cl');    // clientes (alias cl)
$ss  = whereSedeSimple(); // sin alias

try { $citas_hoy     = (int)$db->query("SELECT COUNT(*) FROM citas c WHERE c.fecha=CURDATE()$sc")->fetchColumn(); } catch(Exception $e){}
try { $citas_pend    = (int)$db->query("SELECT COUNT(*) FROM citas c WHERE c.fecha=CURDATE() AND c.estado IN ('pendiente','confirmada')$sc")->fetchColumn(); } catch(Exception $e){}
try { $pacientes_mes = (int)$db->query("SELECT COUNT(DISTINCT con.mascota_id) FROM consultas con WHERE MONTH(con.fecha)=MONTH(CURDATE()) AND YEAR(con.fecha)=YEAR(CURDATE())$scon")->fetchColumn(); } catch(Exception $e){}
try { $pac_mes_ant   = (int)$db->query("SELECT COUNT(DISTINCT con.mascota_id) FROM consultas con WHERE MONTH(con.fecha)=MONTH(DATE_SUB(CURDATE(),INTERVAL 1 MONTH)) AND YEAR(con.fecha)=YEAR(DATE_SUB(CURDATE(),INTERVAL 1 MONTH))$scon")->fetchColumn(); } catch(Exception $e){}
try { $ingresos_hoy  = (float)$db->query("SELECT COALESCE(SUM(total),0) FROM ventas v WHERE DATE(v.fecha)=CURDATE() AND v.estado='pagado'$sv")->fetchColumn(); } catch(Exception $e){}
try { $ingresos_mes  = (float)$db->query("SELECT COALESCE(SUM(total),0) FROM ventas v WHERE MONTH(v.fecha)=MONTH(CURDATE()) AND YEAR(v.fecha)=YEAR(CURDATE()) AND v.estado='pagado'$sv")->fetchColumn(); } catch(Exception $e){}
try { $nuevos_clientes=(int)$db->query("SELECT COUNT(*) FROM clientes cl WHERE MONTH(cl.created_at)=MONTH(CURDATE()) AND cl.activo=1$scl")->fetchColumn(); } catch(Exception $e){}
try { $cli_mes_ant   = (int)$db->query("SELECT COUNT(*) FROM clientes cl WHERE MONTH(cl.created_at)=MONTH(DATE_SUB(CURDATE(),INTERVAL 1 MONTH)) AND cl.activo=1$scl")->fetchColumn(); } catch(Exception $e){}
try { $alertas_count = (int)$db->query("SELECT COUNT(*) FROM productos WHERE stock<=stock_minimo AND activo=1 AND $ss")->fetchColumn(); } catch(Exception $e){}

$meta_mes  = max($ingresos_mes * 1.2, 1);
$delta_pac = $pac_mes_ant > 0 ? round(($pacientes_mes - $pac_mes_ant)/$pac_mes_ant*100) : 0;
$delta_cli = $cli_mes_ant > 0 ? round(($nuevos_clientes - $cli_mes_ant)/$cli_mes_ant*100) : 0;

// ── Citas hoy ──
$citas = [];
try { $citas = $db->query("SELECT c.*,m.nombre as mascota,m.especie,m.foto, u.nombre as vet, cl.nombre as dueno, cl.telefono FROM citas c JOIN mascotas m ON m.id=c.mascota_id JOIN usuarios u ON u.id=c.veterinario_id JOIN clientes cl ON cl.id=m.cliente_id WHERE c.fecha=CURDATE()$sc ORDER BY c.hora ASC LIMIT 8")->fetchAll(); } catch(Exception $e){}

// ── Stock crítico ──
$stock_alertas = [];
try { $stock_alertas = $db->query("SELECT nombre,stock,stock_minimo FROM productos WHERE stock<=stock_minimo AND activo=1 AND $ss ORDER BY stock ASC LIMIT 4")->fetchAll(); } catch(Exception $e){}

// ── Vacunas por vencer ──
$vac_vencer = [];
try { $vac_vencer = $db->query("SELECT v.*,m.nombre as mascota,m.foto FROM vacunas v JOIN mascotas m ON m.id=v.mascota_id WHERE v.proxima_dosis <= DATE_ADD(CURDATE(),INTERVAL 14 DAY)$sm ORDER BY v.proxima_dosis ASC LIMIT 5")->fetchAll(); } catch(Exception $e){}

// ── Pacientes recientes ──
$ult_mascotas = [];
try { $ult_mascotas = $db->query("SELECT m.*,c.nombre as dueno,c.telefono FROM mascotas m JOIN clientes c ON c.id=m.cliente_id WHERE m.estado='activo'$sm ORDER BY m.id DESC LIMIT 6")->fetchAll(); } catch(Exception $e){}

// ── Ingresos últimos 7 días ──
$ingresos_semana = []; $dias_semana = [];
try { $ingresos_semana = $db->query("SELECT DATE(v.fecha) as dia, COALESCE(SUM(v.total),0) as total FROM ventas v WHERE v.estado='pagado' AND v.fecha >= DATE_SUB(CURDATE(),INTERVAL 6 DAY)$sv GROUP BY DATE(v.fecha) ORDER BY dia ASC")->fetchAll(PDO::FETCH_KEY_PAIR); } catch(Exception $e){}
for ($i=6;$i>=0;$i--) { $d=date('Y-m-d',strtotime("-$i days")); $dias_semana[$d]=$ingresos_semana[$d]??0; }
$max_ing = max(array_values($dias_semana)?:[1]);

// ── Ingresos por método de pago ──
$ing_metodo = [];
try { $ing_metodo = $db->query("SELECT v.metodo_pago, COALESCE(SUM(v.total),0) as total FROM ventas v WHERE MONTH(v.fecha)=MONTH(CURDATE()) AND v.estado='pagado'$sv GROUP BY v.metodo_pago ORDER BY total DESC LIMIT 3")->fetchAll(); } catch(Exception $e){}
// ── Totales por método de pago (para el resumen) ──
$efectivo=0; $tarjeta=0; $yape=0; $transf=0;
$metodo_map = ['efectivo'=>'efectivo','tarjeta_debito'=>'tarjeta','tarjeta_credito'=>'tarjeta','yape'=>'yape','plin'=>'yape','transferencia'=>'transf'];
foreach ($ing_metodo as $row) {
    $key = $metodo_map[$row['metodo_pago']] ?? null;
    if ($key) { $$key += (float)$row['total']; }
}

// ── Hospitalizados ──
$hosp = [];
try { $hosp = $db->query("SELECT h.*,m.nombre as mascota,m.especie FROM hospitalizacion h JOIN mascotas m ON m.id=h.mascota_id WHERE h.activo=1$sm ORDER BY FIELD(h.estado,'emergencia','critico','observacion','estable') LIMIT 3")->fetchAll(); } catch(Exception $e) {}

// ── Datos para mini-tendencias (sparklines) de los últimos 7 días ──
$spark_ingresos = array_values($dias_semana); // ya calculado arriba
$spark_pacientes = []; $spark_citas = [];
for ($i=6;$i>=0;$i--) {
    $d = date('Y-m-d',strtotime("-$i days"));
    try { $spark_pacientes[] = (int)$db->query("SELECT COUNT(DISTINCT mascota_id) FROM consultas WHERE DATE(fecha)='$d'")->fetchColumn(); } catch(Exception $e){ $spark_pacientes[]=0; }
    try { $spark_citas[] = (int)$db->query("SELECT COUNT(*) FROM citas WHERE fecha='$d'$sc")->fetchColumn(); } catch(Exception $e){ $spark_citas[]=0; }
}
// Helper: genera un mini-gráfico SVG de línea (sparkline) a partir de un array de números
if (!function_exists('sparkline_svg')) {
  function sparkline_svg(array $vals, string $color='#10b981', int $w=52, int $h=22): string {
    if (count($vals) < 2) $vals = array_pad($vals, 2, 0);
    $max = max($vals); $min = min($vals); $range = ($max-$min) ?: 1;
    $n = count($vals); $step = $w/($n-1); $pts = [];
    foreach ($vals as $i=>$v) {
      $x = round($i*$step,1);
      $y = round($h - 3 - (($v-$min)/$range)*($h-6),1);
      $pts[] = "$x,$y";
    }
    $poly = implode(' ',$pts);
    return '<svg width="'.$w.'" height="'.$h.'" viewBox="0 0 '.$w.' '.$h.'" style="overflow:visible"><polyline points="'.$poly.'" fill="none" stroke="'.$color.'" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
  }
}

$ei = ['perro'=>'🐕','gato'=>'🐈','conejo'=>'🐰','ave'=>'🐦','reptil'=>'🦎','roedor'=>'🐭','otro'=>'🐾'];
$estado_cfg = [
    'pendiente'  =>['color'=>'#f59e0b','bg'=>'#fef3c7','label'=>'Pendiente'],
    'confirmada' =>['color'=>'#3b82f6','bg'=>'#dbeafe','label'=>'Confirmada'],
    'atendida'   =>['color'=>'#10b981','bg'=>'#d1fae5','label'=>'Atendida'],
    'cancelada'  =>['color'=>'#ef4444','bg'=>'#fee2e2','label'=>'Cancelada'],
];
$tipo_icons = ['consulta'=>'🩺','vacuna'=>'💉','control'=>'🔄','cirugia'=>'✂️','bano'=>'🛁','grooming'=>'✨','emergencia'=>'🚨'];
?>

<style>
/* ── DASHBOARD VARS ── */
.db-wrap { display:flex; flex-direction:column; gap:18px; }

/* KPI cards */
.kpi-grid { display:grid; grid-template-columns:repeat(5,1fr); gap:14px; }
.kpi-card {
  background:var(--bg2); border:1px solid var(--border);
  border-radius:14px; padding:18px 20px;
  display:flex; align-items:center; gap:14px;
  transition:box-shadow .15s, transform .15s; cursor:default;
}
.kpi-card:hover { box-shadow:0 4px 20px rgba(0,0,0,.07); transform:translateY(-1px); }
.kpi-icon {
  width:46px; height:46px; border-radius:12px; flex-shrink:0;
  display:flex; align-items:center; justify-content:center; font-size:20px;
}
.kpi-icon.blue   { background:#dbeafe; }
.kpi-icon.green  { background:#d1fae5; }
.kpi-icon.purple { background:#ede9fe; }
.kpi-icon.orange { background:#ffedd5; }
.kpi-icon.red    { background:#fee2e2; }
.kpi-val { font-size:22px; font-weight:800; color:var(--text); line-height:1; font-family:var(--font-display); letter-spacing:-.5px; }
.kpi-label { font-size:11px; color:var(--text3); margin-top:3px; font-weight:500; }
.kpi-sub { font-size:11px; font-weight:600; margin-top:6px; display:flex; align-items:center; gap:3px; }
.kpi-up   { color:#10b981; } .kpi-dn { color:#ef4444; } .kpi-neutral { color:var(--text3); }

/* Agenda */
.agenda-wrap { background:var(--bg2); border:1px solid var(--border); border-radius:14px; overflow:hidden; }
.agenda-head { padding:16px 20px; border-bottom:1px solid var(--border); display:flex; align-items:center; justify-content:space-between; }
.agenda-item {
  display:grid; grid-template-columns:52px 1fr auto;
  gap:12px; align-items:center; padding:11px 20px;
  border-bottom:1px solid var(--border); transition:background .12s;
}
.agenda-item:last-child { border-bottom:none; }
.agenda-item:hover { background:var(--bg3); }
.agenda-hora { font-size:13px; font-weight:700; color:var(--primary); font-variant-numeric:tabular-nums; }
.agenda-pet-img { width:38px; height:38px; border-radius:10px; object-fit:cover; border:2px solid var(--border); flex-shrink:0; }
.agenda-pet-emoji { width:38px; height:38px; border-radius:10px; background:var(--primary-l); display:flex; align-items:center; justify-content:center; font-size:19px; flex-shrink:0; }
.estado-pill { display:inline-flex; align-items:center; gap:4px; padding:3px 10px; border-radius:999px; font-size:11px; font-weight:600; }

/* Panel derecho */
.right-col { display:flex; flex-direction:column; gap:14px; width:288px; flex-shrink:0; }
.panel-card { background:var(--bg2); border:1px solid var(--border); border-radius:14px; overflow:hidden; }
.panel-head { padding:13px 16px; border-bottom:1px solid var(--border); display:flex; align-items:center; justify-content:space-between; }
.panel-head-title { font-size:13px; font-weight:700; color:var(--text); display:flex; align-items:center; gap:6px; }
.panel-link { font-size:11px; color:var(--primary); font-weight:600; text-decoration:none; }
.panel-link:hover { text-decoration:underline; }

/* Stock items */
.stock-item { padding:10px 16px; border-bottom:1px solid var(--border); }
.stock-item:last-child { border-bottom:none; }
.stock-bar { height:5px; background:var(--border); border-radius:999px; overflow:hidden; margin-top:5px; }
.stock-fill { height:100%; border-radius:999px; }

/* Vacunas items */
.vac-item { padding:9px 16px; border-bottom:1px solid var(--border); display:flex; align-items:center; gap:10px; }
.vac-item:last-child { border-bottom:none; }
.vac-pet-img { width:30px; height:30px; border-radius:8px; object-fit:cover; flex-shrink:0; border:1px solid var(--border); }
.vac-pet-emoji { width:30px; height:30px; border-radius:8px; background:var(--bg3); display:flex; align-items:center; justify-content:center; font-size:14px; flex-shrink:0; }

/* Ingreso chart mini */
.chart-wrap { padding:16px 20px; }
.chart-bars { display:flex; align-items:flex-end; gap:5px; height:56px; }
.chart-bar-col { flex:1; display:flex; flex-direction:column; align-items:center; gap:3px; }
.chart-bar { width:100%; border-radius:4px 4px 0 0; transition:opacity .15s; min-height:4px; }
.chart-bar:hover { opacity:.8; }
.chart-label { font-size:9px; color:var(--text3); white-space:nowrap; }

/* Acciones rápidas */
.quick-grid { display:grid; grid-template-columns:1fr 1fr 1fr; gap:8px; padding:14px; }
.quick-btn {
  display:flex; flex-direction:column; align-items:center; justify-content:center;
  gap:5px; padding:12px 8px; border-radius:10px; border:1px solid var(--border);
  background:var(--bg3); text-decoration:none; transition:all .15s; cursor:pointer;
}
.quick-btn:hover { background:var(--primary-l); border-color:var(--primary); }
.quick-icon { font-size:20px; }
.quick-label { font-size:10px; font-weight:600; color:var(--text2); text-align:center; line-height:1.2; }

/* Pacientes recientes */
.pac-grid { display:grid; grid-template-columns:repeat(6,1fr); gap:10px; padding:12px; }
.pac-item {
  display:flex; flex-direction:column; align-items:center; gap:7px;
  padding:14px 8px; border:1.5px solid var(--border); border-radius:14px;
  text-decoration:none; transition:all .15s; position:relative;
}
.pac-item:hover { box-shadow:0 3px 14px rgba(0,0,0,.08); transform:translateY(-1px); }
.pac-photo { width:56px; height:56px; border-radius:12px; object-fit:cover; }
.pac-emoji { width:56px; height:56px; border-radius:12px; background:var(--primary-l); display:flex; align-items:center; justify-content:center; font-size:26px; }
.pac-actions { display:flex; gap:4px; margin-top:2px; }
.pac-act-btn { width:22px; height:22px; border-radius:6px; border:1px solid var(--border); background:var(--bg2); display:flex; align-items:center; justify-content:center; font-size:11px; text-decoration:none; transition:all .12s; }
.pac-act-btn:hover { background:var(--primary-l); border-color:var(--primary); }

/* Resumen ingresos */
.ing-wrap { background:var(--bg2); border:1px solid var(--border); border-radius:14px; padding:0; overflow:hidden; }
.ing-head { padding:16px 20px; border-bottom:1px solid var(--border); display:flex; align-items:center; justify-content:space-between; }
.ing-body { display:grid; grid-template-columns:1fr 1fr; gap:0; }
.ing-stat { padding:14px 20px; border-right:1px solid var(--border); }
.ing-stat:last-child { border-right:none; }
.ing-stat-val { font-size:16px; font-weight:800; font-family:var(--font-display); color:var(--text); }
.ing-stat-label { font-size:11px; color:var(--text3); margin-top:2px; }

@media(max-width:1200px) { .kpi-grid { grid-template-columns:repeat(3,1fr); } }
@media(max-width:900px)  { .kpi-grid { grid-template-columns:1fr 1fr; } .right-col { width:100%; } }
</style>

<div class="page db-wrap">

<!-- ══ KPI ROW ══ -->
<style>
.kpi-link{text-decoration:none;color:inherit;cursor:pointer;transition:transform .15s ease,box-shadow .15s ease,border-color .15s ease}
.kpi-link:hover{transform:translateY(-3px);box-shadow:var(--shadow-lg,0 10px 30px rgba(0,0,0,.10));border-color:var(--primary)}
</style>
<div class="kpi-grid">
  <!-- Citas hoy -->
  <a href="?p=citas" class="kpi-card kpi-link" title="Ir a Citas / Agenda">
    <div class="kpi-icon blue">📅</div>
    <div class="flex-1">
      <div style="display:flex;align-items:center;justify-content:space-between;gap:6px">
        <div class="kpi-val"><?= $citas_hoy ?></div>
        <?= sparkline_svg($spark_citas, '#6366f1') ?>
      </div>
      <div class="kpi-label">Citas de hoy</div>
      <div class="kpi-sub kpi-neutral">⏳ <?= $citas_pend ?> pendientes</div>
    </div>
  </a>
  <!-- Pacientes mes -->
  <a href="?p=historial" class="kpi-card kpi-link" title="Ir a Historia Clínica / atenciones">
    <div class="kpi-icon green">🐾</div>
    <div class="flex-1">
      <div style="display:flex;align-items:center;justify-content:space-between;gap:6px">
        <div class="kpi-val"><?= $pacientes_mes ?></div>
        <?= sparkline_svg($spark_pacientes, $delta_pac>=0?'#10b981':'#ef4444') ?>
      </div>
      <div class="kpi-label">Pacientes atendidos</div>
      <div class="kpi-sub <?= $delta_pac>=0?'kpi-up':'kpi-dn' ?>">
        <?= $delta_pac>=0?'↑':'↓' ?> <?= abs($delta_pac) ?>% este mes
      </div>
    </div>
  </a>
  <!-- Ingresos -->
  <a href="?p=caja" class="kpi-card kpi-link" title="Ir a Caja">
    <div class="kpi-icon orange">💰</div>
    <div class="flex-1">
      <div style="display:flex;align-items:center;justify-content:space-between;gap:6px">
        <div class="kpi-val">S/ <?= number_format($ingresos_hoy,0) ?></div>
        <?= sparkline_svg($spark_ingresos, '#3b82f6') ?>
      </div>
      <div class="kpi-label">Ingresos del día</div>
      <div class="kpi-sub kpi-neutral">Meta mes: S/ <?= number_format($ingresos_mes,0) ?></div>
    </div>
  </a>
  <!-- Nuevos clientes -->
  <a href="?p=clientes" class="kpi-card kpi-link" title="Ir a Clientes">
    <div class="kpi-icon purple">👥</div>
    <div class="flex-1">
      <div class="kpi-val"><?= $nuevos_clientes ?></div>
      <div class="kpi-label">Nuevos clientes</div>
      <div class="kpi-sub <?= $delta_cli>=0?'kpi-up':'kpi-dn' ?>">
        <?= $delta_cli>=0?'↑':'↓' ?> <?= abs($delta_cli) ?>% vs mes anterior
      </div>
    </div>
  </a>
  <!-- Alertas -->
  <a href="?p=inventario" class="kpi-card kpi-link" title="Ir a Inventario (stock crítico)" style="<?= $alertas_count>0?'border-color:#fca5a5':'' ?>">
    <div class="kpi-icon red">⚠️</div>
    <div class="flex-1">
      <div class="kpi-val" style="<?= $alertas_count>0?'color:var(--danger)':'' ?>"><?= $alertas_count ?></div>
      <div class="kpi-label">Alertas</div>
      <div class="kpi-sub <?= $alertas_count>0?'kpi-dn':'kpi-neutral' ?>">
        <?= $alertas_count>0?'Requieren atención':'Todo en orden ✅' ?>
      </div>
    </div>
  </a>
</div>

<!-- ══ FILA PRINCIPAL ══ -->
<div style="display:flex;gap:16px;align-items:flex-start">

  <!-- Agenda + Resumen Ingresos + Pacientes -->
  <div style="flex:1;display:flex;flex-direction:column;gap:14px;min-width:0">

    <!-- AGENDA -->
    <div class="agenda-wrap">
      <div class="agenda-head">
        <div>
          <div style="font-size:14px;font-weight:700;color:var(--text)">📅 Agenda de hoy</div>
          <div style="font-size:11px;color:var(--text3);margin-top:2px"><?= date('l d \d\e F Y') ?></div>
        </div>
        <div class="flex gap-2">
          <a href="<?= BASE_URL ?>/index.php?p=citas&action=nueva" class="btn btn-xs btn-primary">+ Nueva cita</a>
          <a href="<?= BASE_URL ?>/index.php?p=citas" class="btn btn-xs btn-ghost">Ver calendario →</a>
        </div>
      </div>

      <?php if(empty($citas)): ?>
      <div style="padding:18px 20px;display:flex;align-items:center;justify-content:center;gap:10px;color:var(--text3)">
        <span style="font-size:18px;opacity:.5">📭</span>
        <span style="font-size:13px">Sin citas programadas para hoy</span>
        <a href="<?= BASE_URL ?>/index.php?p=citas&action=nueva" class="btn btn-xs btn-primary" style="margin-left:4px">+ Agendar</a>
      </div>
      <?php else: ?>
      <?php foreach($citas as $c):
        $ec = $estado_cfg[$c['estado']] ?? ['color'=>'#94a3b8','bg'=>'#f1f5f9','label'=>ucfirst($c['estado'])];
        $foto = !empty($c['foto']) ? BASE_URL.'/public/uploads/'.$c['foto'] : null;
        $tel = preg_replace('/[^0-9]/','',ltrim($c['telefono']??'','+'));
        if(strlen($tel)<11) $tel='51'.$tel;
      ?>
      <div class="agenda-item">
        <!-- Hora -->
        <div>
          <div class="agenda-hora"><?= substr($c['hora'],0,5) ?></div>
          <div style="font-size:10px;color:var(--text3);margin-top:2px"><?= $c['duracion_minutos']??30 ?> min</div>
        </div>
        <!-- Paciente -->
        <div class="flex items-center gap-10" style="gap:10px;min-width:0">
          <?php if($foto): ?>
          <img src="<?= $foto ?>" class="agenda-pet-img" alt="<?= clean($c['mascota']) ?>">
          <?php else: ?>
          <div class="agenda-pet-emoji"><?= $ei[$c['especie']]??'🐾' ?></div>
          <?php endif; ?>
          <div style="min-width:0">
            <div style="font-size:13px;font-weight:600;color:var(--text)"><?= clean($c['mascota']) ?></div>
            <div style="font-size:11px;color:var(--text3)"><?= clean($c['dueno']) ?> · <span style="color:var(--text2)"><?= ($tipo_icons[$c['tipo']]??'🩺') ?> <?= ucfirst($c['tipo']) ?></span></div>
            <div style="font-size:11px;color:var(--text3);margin-top:1px">Dr/a. <?= clean(explode(' ',$c['vet'])[0]) ?></div>
          </div>
        </div>
        <!-- Estado + acción -->
        <div class="flex items-center gap-2" style="flex-shrink:0">
          <span class="estado-pill" style="background:<?= $ec['bg'] ?>;color:<?= $ec['color'] ?>"><?= $ec['label'] ?></span>
          <?php if($c['estado']!=='atendida' && $c['estado']!=='cancelada'): ?>
          <a href="<?= BASE_URL ?>/index.php?p=historial&action=nueva&cita_id=<?= $c['id'] ?>&mascota_id=<?= $c['mascota_id'] ?>"
             class="btn btn-xs btn-primary" style="white-space:nowrap">Atender</a>
          <?php endif; ?>
          <a href="https://wa.me/<?= $tel ?>" target="_blank" class="btn btn-xs btn-ghost" style="font-size:13px" title="WhatsApp">💬</a>
        </div>
      </div>
      <?php endforeach; ?>
      <div style="padding:12px 20px;border-top:1px solid var(--border);text-align:center">
        <a href="<?= BASE_URL ?>/index.php?p=citas" style="font-size:12px;color:var(--primary);text-decoration:none;font-weight:600">
          Ver todas las citas →
        </a>
      </div>
      <?php endif; ?>
    </div>

    <!-- RESUMEN INGRESOS + MINI CHART -->
    <div class="ing-wrap">
      <div class="ing-head">
        <div>
          <div style="font-size:14px;font-weight:700;color:var(--text)">📊 Resumen de ingresos</div>
          <div style="font-size:20px;font-weight:800;color:var(--text);margin-top:4px;font-family:var(--font-display)">
            S/ <?= number_format($ingresos_mes,2) ?>
          </div>
          <div style="font-size:11px;color:var(--text3)">Meta: S/ <?= number_format($meta_mes,0) ?></div>
        </div>
        <!-- Mini chart -->
        <div class="chart-wrap" style="padding:0">
          <div class="chart-bars">
            <?php foreach($dias_semana as $dia=>$val):
              $pct = $max_ing>0 ? max(4, round($val/$max_ing*56)) : 4;
              $isHoy = $dia===date('Y-m-d');
            ?>
            <div class="chart-bar-col">
              <div class="chart-bar" title="<?= date('d/m',strtotime($dia)) ?>: S/. <?= number_format($val,0) ?>"
                   style="height:<?= $pct ?>px;background:<?= $isHoy?'var(--primary)':'rgba(30,168,161,.25)' ?>"></div>
              <div class="chart-label"><?= date('d',strtotime($dia)) ?></div>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
      <div class="ing-body">
        <div class="ing-stat"><div class="ing-stat-val">💵 S/ <?= number_format($efectivo,2) ?></div><div class="ing-stat-label">Efectivo</div></div>
        <div class="ing-stat"><div class="ing-stat-val">💳 S/ <?= number_format($tarjeta,2) ?></div><div class="ing-stat-label">Tarjeta</div></div>
        <div class="ing-stat" style="border-top:1px solid var(--border)"><div class="ing-stat-val">📱 S/ <?= number_format($yape,2) ?></div><div class="ing-stat-label">Yape / Plin</div></div>
        <div class="ing-stat" style="border-top:1px solid var(--border);border-right:none"><div class="ing-stat-val">🏦 S/ <?= number_format($transf,2) ?></div><div class="ing-stat-label">Transferencia</div></div>
      </div>
    </div>

    <!-- PACIENTES RECIENTES -->
    <div class="agenda-wrap">
      <div class="agenda-head">
        <div style="font-size:14px;font-weight:700;color:var(--text)">🐾 Pacientes recientes</div>
        <a href="<?= BASE_URL ?>/index.php?p=mascotas" class="panel-link">Ver todos →</a>
      </div>
      <div class="pac-grid">
        <?php foreach($ult_mascotas as $m):
          $foto_url = !empty($m['foto']) && file_exists(UPLOADS_PATH.'/'.$m['foto']) ? BASE_URL.'/public/uploads/'.$m['foto'] : null;
          $tel2 = preg_replace('/[^0-9]/','',ltrim($m['telefono']??'','+'));
          if(strlen($tel2)<11) $tel2='51'.$tel2;
          $especie_color=['perro'=>'#10b981','gato'=>'#6366f1','conejo'=>'#f59e0b','ave'=>'#3b82f6','reptil'=>'#84cc16','roedor'=>'#f97316','otro'=>'#8b5cf6'];
          $ec=$especie_color[$m['especie']]??'#10b981';
        ?>
        <div class="pac-item" style="border-color:<?= $ec ?>">
          <?php if($foto_url): ?>
          <img src="<?= $foto_url ?>" class="pac-photo" alt="<?= clean($m['nombre']) ?>">
          <?php else: ?>
          <div class="pac-emoji" style="background:<?= $ec ?>18"><?= $ei[$m['especie']]??'🐾' ?></div>
          <?php endif; ?>
          <div>
            <div style="font-size:12px;font-weight:700;color:var(--text);text-align:center"><?= clean($m['nombre']) ?></div>
            <div style="font-size:10px;color:var(--text3);text-align:center"><?= clean($m['dueno']) ?></div>
          </div>
          <div class="pac-actions">
            <a href="<?= BASE_URL ?>/index.php?p=mascotas&action=editar&id=<?= $m['id'] ?>" class="pac-act-btn" title="Ver ficha">👁️</a>
            <a href="https://wa.me/<?= $tel2 ?>" target="_blank" class="pac-act-btn" title="WhatsApp">💬</a>
            <a href="<?= BASE_URL ?>/index.php?p=citas&action=nueva" class="pac-act-btn" title="Agendar cita">📅</a>
          </div>
        </div>
        <?php endforeach; ?>
        <?php if(empty($ult_mascotas)): ?>
        <div style="grid-column:1/-1;text-align:center;padding:32px;color:var(--text3)">Sin pacientes registrados.</div>
        <?php endif; ?>
      </div>
    </div>

  </div><!-- fin col izq -->

  <!-- ══ COLUMNA DERECHA ══ -->
  <div class="right-col">

    <!-- Stock crítico -->
    <?php if(!empty($stock_alertas)): ?>
    <div class="panel-card">
      <div class="panel-head">
        <div class="panel-head-title">⚠️ Stock crítico</div>
        <a href="<?= BASE_URL ?>/index.php?p=farmacia" class="panel-link">Ver todos</a>
      </div>
      <?php foreach($stock_alertas as $p):
        $pct = $p['stock_minimo']>0 ? min(100, round($p['stock']/$p['stock_minimo']*100)) : 0;
        $color = $pct<=20 ? '#ef4444' : ($pct<=60 ? '#f59e0b' : '#10b981');
      ?>
      <div class="stock-item">
        <div class="flex justify-between">
          <span style="font-size:12px;font-weight:600;color:var(--text)"><?= clean($p['nombre']) ?></span>
          <span style="font-size:11px;font-weight:700;color:<?= $color ?>"><?= $p['stock'] ?> uds</span>
        </div>
        <div class="stock-bar">
          <div class="stock-fill" style="width:<?= $pct ?>%;background:<?= $color ?>"></div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Vacunas por vencer -->
    <?php if(!empty($vac_vencer)): ?>
    <div class="panel-card">
      <div class="panel-head">
        <div class="panel-head-title">💉 Vacunas por vencer</div>
        <a href="<?= BASE_URL ?>/index.php?p=vacunas" class="panel-link">Ver todos</a>
      </div>
      <?php foreach($vac_vencer as $v):
        $dias = (int)ceil((strtotime($v['proxima_dosis'])-time())/86400);
        $dc = $dias<0?'#ef4444':($dias<=3?'#f59e0b':($dias<=7?'#f97316':'#3b82f6'));
        $foto_v = !empty($v['foto']) && file_exists(UPLOADS_PATH.'/'.$v['foto']) ? BASE_URL.'/public/uploads/'.$v['foto'] : null;
      ?>
      <div class="vac-item">
        <?php if($foto_v): ?>
        <img src="<?= $foto_v ?>" class="vac-pet-img" alt="">
        <?php else: ?>
        <div class="vac-pet-emoji">🐾</div>
        <?php endif; ?>
        <div class="flex-1" style="min-width:0">
          <div style="font-size:12px;font-weight:600;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= clean($v['mascota']) ?></div>
          <div style="font-size:10px;color:var(--text3)"><?= clean($v['tipo_vacuna']) ?></div>
        </div>
        <span style="font-size:11px;font-weight:700;color:<?= $dc ?>;white-space:nowrap">
          <?= $dias<0?'Vencida':date('d/m/Y',strtotime($v['proxima_dosis'])) ?>
        </span>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Hospitalizados -->
    <?php if(!empty($hosp)): ?>
    <div class="panel-card" style="border-color:#fca5a5">
      <div class="panel-head" style="background:#fff5f5">
        <div class="panel-head-title" style="color:#dc2626">🚑 Internados</div>
        <a href="<?= BASE_URL ?>/index.php?p=hospital" class="panel-link" style="color:#dc2626">UCI →</a>
      </div>
      <?php foreach($hosp as $h): $badge=['estable'=>['#065f46','#d1fae5'],'observacion'=>['#78350f','#fef3c7'],'emergencia'=>['#7f1d1d','#fee2e2'],'critico'=>['#f9fafb','#1f2937']][$h['estado']]??['#475569','#f1f5f9']; ?>
      <div style="padding:9px 14px;border-bottom:1px solid #ffe4e6;display:flex;align-items:center;gap:8px">
        <span style="font-size:18px"><?= $ei[$h['especie']]??'🐾' ?></span>
        <div class="flex-1"><div style="font-size:12px;font-weight:600"><?= clean($h['mascota']) ?></div></div>
        <span style="padding:2px 8px;border-radius:999px;font-size:10px;font-weight:700;background:<?= $badge[1] ?>;color:<?= $badge[0] ?>"><?= ucfirst($h['estado']) ?></span>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Acciones rápidas -->
    <div class="panel-card">
      <div class="panel-head">
        <div class="panel-head-title">⚡ Acciones rápidas</div>
      </div>
      <div class="quick-grid">
        <a href="<?= BASE_URL ?>/index.php?p=citas&action=nueva" class="quick-btn">
          <span class="quick-icon">📅</span><span class="quick-label">Nueva cita</span>
        </a>
        <a href="<?= BASE_URL ?>/index.php?p=mascotas&action=nueva" class="quick-btn">
          <span class="quick-icon">🐾</span><span class="quick-label">Nueva paciente</span>
        </a>
        <a href="<?= BASE_URL ?>/index.php?p=historial&action=nueva" class="quick-btn">
          <span class="quick-icon">🩺</span><span class="quick-label">Nueva atención</span>
        </a>
        <a href="<?= BASE_URL ?>/index.php?p=facturacion&action=nueva" class="quick-btn">
          <span class="quick-icon">🧾</span><span class="quick-label">Venta rápida</span>
        </a>
        <a href="<?= BASE_URL ?>/index.php?p=clientes&action=nuevo" class="quick-btn">
          <span class="quick-icon">👤</span><span class="quick-label">Nuevo examen</span>
        </a>
        <a href="<?= BASE_URL ?>/index.php?p=recetas" class="quick-btn">
          <span class="quick-icon">💊</span><span class="quick-label">Receta médica</span>
        </a>
      </div>
    </div>

  </div><!-- fin right-col -->
</div><!-- fin fila principal -->

<!-- ══ GRÁFICAS REALES ══ -->
<?php
$ingresos_12 = [];
for($i=5;$i>=0;$i--){
    $mes = date('Y-m', strtotime("-$i months"));
    $tot = 0;
    try { $tot=(float)$db->query("SELECT COALESCE(SUM(total),0) FROM ventas v WHERE DATE_FORMAT(v.fecha,'%Y-%m')='$mes' AND v.estado='pagado'$sv")->fetchColumn(); }catch(Exception $e){}
    $ingresos_12[$mes] = $tot;
}
$especies = [];
try { $especies = $db->query("SELECT especie, COUNT(*) as n FROM mascotas m WHERE m.estado='activo'$sm GROUP BY especie ORDER BY n DESC")->fetchAll(); } catch(Exception $e) {}
$citas_estados = [];
try { $citas_estados = $db->query("SELECT estado, COUNT(*) as n FROM citas c WHERE MONTH(c.fecha)=MONTH(CURDATE())$sc GROUP BY estado")->fetchAll(); } catch(Exception $e){}
$citas_dow = [];
try {
    $rows = $db->query("SELECT DAYOFWEEK(c.fecha) as dow, COUNT(*) as n FROM citas c WHERE c.fecha >= DATE_SUB(CURDATE(), INTERVAL 28 DAY)$sc GROUP BY DAYOFWEEK(c.fecha)")->fetchAll();
    $map = [2=>'Lun',3=>'Mar',4=>'Mié',5=>'Jue',6=>'Vie',7=>'Sáb',1=>'Dom'];
    foreach($rows as $r) $citas_dow[$map[$r['dow']]??'?'] = (int)$r['n'];
} catch(Exception $e) {}
?>

<div style="margin-top:20px">
  <div style="font-size:16px;font-weight:800;color:var(--text);margin-bottom:14px;display:flex;align-items:center;gap:8px">
    📊 <span>Análisis del negocio</span>
  </div>

  <div style="display:grid;grid-template-columns:2fr 1fr;gap:16px;margin-bottom:16px">
    <!-- Gráfica ingresos 12 meses -->
    <div class="card" style="padding:18px">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px">
        <div>
          <div style="font-size:13px;font-weight:700;color:var(--text)">💰 Ingresos mensuales</div>
          <div style="font-size:11px;color:var(--text3)">Últimos 6 meses</div>
        </div>
        <div style="text-align:right">
          <div style="font-size:16px;font-weight:800;color:var(--success)">S/ <?= number_format(array_sum($ingresos_12),0) ?></div>
          <div style="font-size:10px;color:var(--text3)">Total año</div>
        </div>
      </div>
      <div style="height:120px;position:relative"><canvas id="chartIngresos"></canvas></div>
    </div>
    <!-- Gráfica especies -->
    <div class="card" style="padding:18px">
      <div style="font-size:13px;font-weight:700;color:var(--text);margin-bottom:2px">🐾 Especies</div>
      <div style="font-size:11px;color:var(--text3);margin-bottom:12px">Total de pacientes</div>
      <div style="height:140px;position:relative"><canvas id="chartEspecies"></canvas></div>
    </div>
  </div>

  <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
    <!-- Citas por día de semana -->
    <div class="card" style="padding:18px">
      <div style="font-size:13px;font-weight:700;color:var(--text);margin-bottom:2px">📅 Citas por día</div>
      <div style="font-size:11px;color:var(--text3);margin-bottom:12px">Últimas 4 semanas</div>
      <div style="height:140px;position:relative"><canvas id="chartDow"></canvas></div>
    </div>
    <!-- Estado de citas del mes -->
    <div class="card" style="padding:18px">
      <div style="font-size:13px;font-weight:700;color:var(--text);margin-bottom:2px">🎯 Estado de citas</div>
      <div style="font-size:11px;color:var(--text3);margin-bottom:12px">Mes actual</div>
      <div style="height:140px;position:relative"><canvas id="chartEstados"></canvas></div>
    </div>
  </div>
</div>

</div><!-- fin db-wrap -->

<!-- Chart.js desde CDN -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js"></script>
<script>
Chart.defaults.font.family = "'Inter', sans-serif";
Chart.defaults.color = getComputedStyle(document.documentElement).getPropertyValue('--text3') || '#64748b';

var primary = '#1ea8a1';
var accent  = '#6366f1';

// ── 1. Ingresos 12 meses (bar + line) ──
var ing12Labels = <?= json_encode(array_map(fn($m)=>date('M y',strtotime($m.'-01')), array_keys($ingresos_12))) ?>;
var ing12Data   = <?= json_encode(array_values($ingresos_12)) ?>;
new Chart(document.getElementById('chartIngresos'), {
  type:'bar',
  data:{
    labels:ing12Labels,
    datasets:[{
      label:'Ingresos S/',
      data:ing12Data,
      backgroundColor:ing12Data.map(function(v,i){ return i===ing12Data.length-1?primary:'rgba(30,168,161,.25)'; }),
      borderColor:primary,
      borderWidth:0,
      borderRadius:6,
    }]
  },
  options:{
    responsive:true,
    maintainAspectRatio:false,
    plugins:{
      legend:{display:false},
      tooltip:{callbacks:{label:function(c){return ' S/ '+c.parsed.y.toLocaleString('es-PE',{minimumFractionDigits:0});}}}
    },
    scales:{
      x:{grid:{display:false}, ticks:{font:{size:10}}},
      y:{grid:{color:'rgba(0,0,0,.04)'}, ticks:{callback:function(v){return 'S/'+v.toLocaleString();}, font:{size:10}}}
    }
  }
});

// ── 2. Especies (doughnut) ──
var espLabels = <?= json_encode(array_map(fn($e)=>ucfirst($e['especie']), $especies)) ?>;
var espData   = <?= json_encode(array_map(fn($e)=>(int)$e['n'], $especies)) ?>;
var espColors = ['#10b981','#6366f1','#f59e0b','#3b82f6','#ef4444','#8b5cf6','#ec4899'];
new Chart(document.getElementById('chartEspecies'), {
  type:'doughnut',
  data:{
    labels:espLabels,
    datasets:[{data:espData, backgroundColor:espColors, borderWidth:2, hoverOffset:4}]
  },
  options:{
    responsive:true,
    maintainAspectRatio:false,
    cutout:'60%',
    plugins:{legend:{position:'bottom', labels:{font:{size:10}, padding:8, boxWidth:10}}}
  }
});

// ── 3. Citas por día de semana (bar horizontal) ──
var dowLabels = <?= json_encode(array_keys($citas_dow)) ?>;
var dowData   = <?= json_encode(array_values($citas_dow)) ?>;
new Chart(document.getElementById('chartDow'), {
  type:'bar',
  data:{
    labels:dowLabels,
    datasets:[{
      label:'Citas',
      data:dowData,
      backgroundColor:'rgba(99,102,241,.2)',
      borderColor:accent,
      borderWidth:2,
      borderRadius:5,
    }]
  },
  options:{
    responsive:true,
    maintainAspectRatio:false,
    indexAxis:'y',
    plugins:{legend:{display:false}},
    scales:{
      x:{grid:{color:'rgba(0,0,0,.04)'}, ticks:{stepSize:1, font:{size:10}}},
      y:{grid:{display:false}, ticks:{font:{size:11}}}
    }
  }
});

// ── 4. Estado de citas (pie) ──
var estLabels = <?= json_encode(array_map(fn($e)=>ucfirst($e['estado']), $citas_estados)) ?>;
var estData   = <?= json_encode(array_map(fn($e)=>(int)$e['n'], $citas_estados)) ?>;
var estColors = {'pendiente':'#f59e0b','confirmada':'#3b82f6','atendida':'#10b981','cancelada':'#ef4444','no_asistio':'#94a3b8'};
var estBg = <?= json_encode(array_map(fn($e)=>$e['estado'], $citas_estados)) ?>.map(function(s){ return estColors[s]||'#94a3b8'; });
new Chart(document.getElementById('chartEstados'), {
  type:'pie',
  data:{
    labels:estLabels,
    datasets:[{data:estData, backgroundColor:estBg, borderWidth:2, hoverOffset:4}]
  },
  options:{
    responsive:true,
    maintainAspectRatio:false,
    plugins:{legend:{position:'bottom', labels:{font:{size:10}, padding:8, boxWidth:10}}}
  }
});
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>

