<?php
$page = 'grooming'; $pageTitle = 'Grooming / Peluquería';
require_once __DIR__ . '/../includes/config.php';
if (function_exists('requireLogin')) requireLogin();
$db = getDB();

$db->exec("CREATE TABLE IF NOT EXISTS grooming (
  id INT AUTO_INCREMENT PRIMARY KEY,
  mascota_id INT NOT NULL,
  groomer_id INT NOT NULL,
  fecha DATETIME NOT NULL,
  duracion_minutos INT DEFAULT 60,
  tipo_servicio ENUM('bano_basico','bano_completo','corte','bano_corte','spa','deslanado','outro') DEFAULT 'bano_completo',
  tipo_corte VARCHAR(150),
  observaciones TEXT,
  condicion_pelo ENUM('normal','enredado','muy_enredado','cortado_previo') DEFAULT 'normal',
  alergias_reportadas TEXT,
  productos_usados TEXT,
  foto_antes VARCHAR(500),
  foto_despues VARCHAR(500),
  precio DECIMAL(10,2) DEFAULT 0,
  estado ENUM('programado','en_proceso','completado','cancelado') DEFAULT 'programado',
  notas_internas TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (mascota_id) REFERENCES mascotas(id),
  FOREIGN KEY (groomer_id) REFERENCES usuarios(id)
)");

// Self-heal: columna de método de pago (para el reporte)
try { $db->exec("ALTER TABLE grooming ADD COLUMN IF NOT EXISTS metodo_pago VARCHAR(20) DEFAULT NULL"); } catch (Exception $e) {}

// ─────────────────────────────────────────────────────────────
// POST ANTES del header: guardar/eliminar responden con REDIRECCIÓN
// (Post/Redirect/Get) para que al recargar NO se reenvíe el formulario
// y NO se dupliquen registros. cambiar_estado responde JSON puro.
// ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST') {
  $pa=$_POST['action']??'';
  if ($pa==='save') {
    $id=(int)($_POST['id']??0);
    $fields=['mascota_id','groomer_id','fecha','duracion_minutos','tipo_servicio','tipo_corte',
             'observaciones','condicion_pelo','alergias_reportadas','productos_usados','precio','metodo_pago','estado','notas_internas'];
    $data=[]; foreach($fields as $f) $data[$f]=trim($_POST[$f]??'')?:null;
    if ($id) {
      $sets=implode(',',array_map(fn($f)=>"$f=:$f",$fields));
      $st=$db->prepare("UPDATE grooming SET $sets WHERE id=:id"); $data['id']=$id;
    } else {
      $cols=implode(',',$fields); $pls=implode(',',array_map(fn($f)=>":$f",$fields));
      $st=$db->prepare("INSERT INTO grooming ($cols) VALUES ($pls)");
    }
    $st->execute($data);
    $gid = $id ?: (int)$db->lastInsertId();
    header('Location: '.BASE_URL.'/index.php?p=grooming&gid='.$gid.'&ok=1'); exit;
  }
  if ($pa==='delete') {
    $db->prepare("DELETE FROM grooming WHERE id=?")->execute([(int)$_POST['id']]);
    header('Location: '.BASE_URL.'/index.php?p=grooming'); exit;
  }
  if ($pa==='cambiar_estado') {
    $estados_ok=['programado','en_proceso','completado','cancelado'];
    $nuevo = $_POST['estado']??'';
    if(in_array($nuevo,$estados_ok)) {
      $db->prepare("UPDATE grooming SET estado=? WHERE id=?")->execute([$nuevo,(int)$_POST['id']]);
    }
    header('Content-Type: application/json'); echo json_encode(['ok'=>true]); exit;
  }
}

require_once __DIR__ . '/../includes/header.php';

$action = $_GET['action'] ?? 'list';
$msg = isset($_GET['ok']) ? 'success' : '';

$editing=null;
if (in_array($action,['editar']) && isset($_GET['id'])) {
  $st=$db->prepare("SELECT * FROM grooming WHERE id=?"); $st->execute([(int)$_GET['id']]); $editing=$st->fetch();
}

$fecha_f = $_GET['fecha'] ?? date('Y-m-d');
$mascotas_sel=$db->query("SELECT m.id,CONCAT(m.nombre,' (',c.nombre,')') as label FROM mascotas m JOIN clientes c ON c.id=m.cliente_id WHERE m.estado='activo' ORDER BY m.nombre")->fetchAll();
$groomers=$db->query("SELECT id,nombre FROM usuarios WHERE activo=1 ORDER BY nombre")->fetchAll();

$groomings=$db->prepare("SELECT g.*,m.nombre as mascota,m.especie,m.foto as foto_mascota,u.nombre as groomer,c.nombre as dueno,c.telefono
  FROM grooming g JOIN mascotas m ON m.id=g.mascota_id JOIN usuarios u ON u.id=g.groomer_id
  JOIN clientes c ON c.id=m.cliente_id
  WHERE DATE(g.fecha)=? ORDER BY g.fecha ASC");
$groomings->execute([$fecha_f]); $groomings=$groomings->fetchAll();

$servicio_labels=['bano_basico'=>'Baño Básico','bano_completo'=>'Baño Completo','corte'=>'Corte','bano_corte'=>'Baño + Corte','spa'=>'Spa Premium','deslanado'=>'Deslanado/Antipulgas','outro'=>'Otro'];
$servicio_icons=['bano_basico'=>'🚿','bano_completo'=>'🛁','corte'=>'✂️','bano_corte'=>'✨','spa'=>'💆','deslanado'=>'🌿','outro'=>'🐾'];

// Servicios del catálogo (Servicios) con tipo Baño/Grooming — para jalar precio y nombre
$serv_catalogo = [];
try {
    $serv_catalogo = $db->query("SELECT id,nombre,precio,tipo FROM servicios WHERE activo=1 AND LOWER(tipo) IN ('baño','bano','grooming') ORDER BY tipo,nombre")->fetchAll();
} catch (Exception $e) {}
$estado_badge=['programado'=>'b-info','en_proceso'=>'b-warning','completado'=>'b-success','cancelado'=>'b-danger'];
$ei=['perro'=>'🐕','gato'=>'🐈','conejo'=>'🐰','ave'=>'🐦','reptil'=>'🦎','roedor'=>'🐭','otro'=>'🐾'];
$estado_color=['programado'=>'var(--info)','en_proceso'=>'var(--warning)','completado'=>'var(--success)','cancelado'=>'var(--danger)'];
?>
<div class="page">
<?php if($msg==='success'): ?><div class="alert alert-success"><span class="alert-icon">✅</span>Servicio de grooming guardado.</div><?php endif; ?>

<?php if(in_array($action,['nuevo','editar'])): ?>
<div class="card" style="max-width:720px">
  <div class="sec-header">
    <div><div class="sec-title">✨ <?= $action==='editar'?'Editar':'Nuevo'?> Servicio de Grooming</div></div>
    <a href="?p=grooming" class="btn btn-ghost btn-sm">← Volver</a>
  </div>
  <form method="POST">
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?= $editing['id']??'' ?>">
    <div class="form-row">
      <div class="form-group" style="position:relative">
        <label class="form-label required" style="display:flex;align-items:center;justify-content:space-between">
          <span>Mascota</span>
          <a href="javascript:void(0)" onclick="rrAbrir()" style="font-size:11px;font-weight:600;color:var(--primary);text-decoration:none">➕ Registrar nuevo</a>
        </label>
        <input type="text" id="inp-mas-grm" class="form-input" placeholder="🐾 Buscar mascota..." autocomplete="off">
        <input type="hidden" name="mascota_id" id="hid-mas-grm" value="" required>
        <div id="drop-mas-grm" style="display:none;position:absolute;top:100%;left:0;right:0;background:var(--bg2);border:1px solid var(--border);border-radius:8px;box-shadow:0 8px 24px rgba(0,0,0,.12);z-index:300;max-height:220px;overflow-y:auto"></div>
      </div>
      <div class="form-group" style="position:relative"><label class="form-label required">Groomer / Peluquero</label>
        <input type="text" id="inp-grm-grm" class="form-input" placeholder="✂️ Buscar groomer..." autocomplete="off">
        <input type="hidden" name="groomer_id" id="hid-grm-grm" value="" required>
        <div id="drop-grm-grm" style="display:none;position:absolute;top:100%;left:0;right:0;background:var(--bg2);border:1px solid var(--border);border-radius:8px;box-shadow:0 8px 24px rgba(0,0,0,.12);z-index:300;max-height:200px;overflow-y:auto"></div>
      </div>
    </div>
    <div class="form-row">
      <div class="form-group"><label class="form-label required">Fecha y hora</label>
        <input class="form-input" type="datetime-local" name="fecha" value="<?= clean(str_replace(' ','T',substr($editing['fecha']??date('Y-m-d\TH:i'),0,16))) ?>" required>
      </div>
      <div class="form-group"><label class="form-label">Duración estimada</label>
        <select class="form-input" name="duracion_minutos">
          <?php foreach([30,45,60,90,120,150,180] as $d): ?>
          <option value="<?= $d ?>" <?= ($editing['duracion_minutos']??60)==$d?'selected':'' ?>><?= $d ?> min</option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <!-- Tipo de servicio -->
    <div class="form-group">
      <label class="form-label required">Tipo de servicio</label>
      <div class="grid g4" style="gap:8px;margin-top:8px">
        <?php foreach($servicio_labels as $k=>$v): ?>
        <label style="cursor:pointer">
          <input type="radio" name="tipo_servicio" value="<?= $k ?>" <?= ($editing['tipo_servicio']??'bano_completo')===$k?'checked':'' ?> style="display:none" onchange="updateServUI()">
          <div class="serv-opt" data-val="<?= $k ?>" style="padding:10px 8px;border:1.5px solid var(--border);border-radius:var(--r-sm);text-align:center;transition:all .15s;background:var(--bg3)">
            <div style="font-size:22px;margin-bottom:4px"><?= $servicio_icons[$k] ?></div>
            <div style="font-size:10px;font-weight:600;color:var(--text2)"><?= $v ?></div>
          </div>
        </label>
        <?php endforeach; ?>
      </div>
    </div>

    <?php if (!empty($serv_catalogo)): ?>
    <div class="form-group" style="position:relative">
      <label class="form-label">Servicio del catálogo (opcional)</label>
      <input class="form-input" id="serv-cat-inp" autocomplete="off" placeholder="🔍 Escribe el nombre del servicio (Baño / Grooming)..."
             oninput="scFilter()" onfocus="scFilter()" onblur="scBlur()">
      <div id="serv-cat-drop" style="display:none;position:absolute;z-index:60;left:0;right:0;top:100%;margin-top:2px;max-height:230px;overflow:auto;background:var(--bg2);border:1px solid var(--border);border-radius:8px;box-shadow:var(--shadow-lg,0 10px 30px rgba(0,0,0,.12))"></div>
      <div class="text-xs text-muted" style="margin-top:4px">Busca entre los servicios que agregaste en <strong>Servicios</strong> con tipo Baño o Grooming. Al elegir uno, se completa el precio.</div>
    </div>
    <script>var SERV_CAT = <?= json_encode(array_map(fn($s)=>['id'=>(int)$s['id'],'nombre'=>$s['nombre'],'precio'=>(float)$s['precio'],'tipo'=>$s['tipo']], $serv_catalogo), JSON_UNESCAPED_UNICODE) ?>;</script>
    <?php endif; ?>

    <div class="form-group"><label class="form-label">Tipo de corte (si aplica)</label>
      <input class="form-input" name="tipo_corte" value="<?= clean($editing['tipo_corte']??'') ?>" placeholder="Ej: Corte teddy, corte higiénico, corte raza...">
    </div>

    <div class="form-row">
      <div class="form-group"><label class="form-label">Condición del pelaje</label>
        <select class="form-input" name="condicion_pelo">
          <option value="normal" <?= ($editing['condicion_pelo']??'')==='normal'?'selected':'' ?>>Normal</option>
          <option value="enredado" <?= ($editing['condicion_pelo']??'')==='enredado'?'selected':'' ?>>Enredado</option>
          <option value="muy_enredado" <?= ($editing['condicion_pelo']??'')==='muy_enredado'?'selected':'' ?>>Muy enredado</option>
          <option value="cortado_previo" <?= ($editing['condicion_pelo']??'')==='cortado_previo'?'selected':'' ?>>Cortado previo</option>
        </select>
      </div>
      <div class="form-group"><label class="form-label">Precio (S/.)</label>
        <input class="form-input" type="number" step="0.50" name="precio" value="<?= clean($editing['precio']??'') ?>">
      </div>
    </div>
    <div class="form-row">
      <div class="form-group"><label class="form-label">Método de pago</label>
        <select class="form-input" name="metodo_pago">
          <option value="">— Sin registrar —</option>
          <?php foreach(['Efectivo','Yape','Plin','Tarjeta','Transferencia'] as $mp): ?>
          <option value="<?= $mp ?>" <?= ($editing['metodo_pago']??'')===$mp?'selected':'' ?>><?= $mp ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group"></div>
    </div>
    <div class="form-group"><label class="form-label">Alergias reportadas / Piel sensible</label>
      <input class="form-input" name="alergias_reportadas" value="<?= clean($editing['alergias_reportadas']??'') ?>" placeholder="Ej: Alergia a champús con fragancia, piel seca...">
    </div>
    <div class="form-group"><label class="form-label">Productos utilizados</label>
      <input class="form-input" name="productos_usados" value="<?= clean($editing['productos_usados']??'') ?>" placeholder="Champú, acondicionador, colonia...">
    </div>
    <div class="form-group"><label class="form-label">Observaciones del servicio</label>
      <textarea class="form-input" name="observaciones"><?= clean($editing['observaciones']??'') ?></textarea>
    </div>
    <div class="form-group"><label class="form-label">Notas internas</label>
      <input class="form-input" name="notas_internas" value="<?= clean($editing['notas_internas']??'') ?>">
    </div>
    <div class="form-group"><label class="form-label">Estado</label>
      <select class="form-input" name="estado">
        <option value="programado" <?= ($editing['estado']??'')==='programado'?'selected':'' ?>>Programado</option>
        <option value="en_proceso" <?= ($editing['estado']??'')==='en_proceso'?'selected':'' ?>>En proceso</option>
        <option value="completado" <?= ($editing['estado']??'')==='completado'?'selected':'' ?>>Completado</option>
        <option value="cancelado" <?= ($editing['estado']??'')==='cancelado'?'selected':'' ?>>Cancelado</option>
      </select>
    </div>
    <div class="flex gap-2"><button type="submit" class="btn btn-primary">💾 Guardar</button><a href="?p=grooming" class="btn btn-ghost">Cancelar</a></div>
  </form>
</div>

<?php elseif($action==='reporte'): ?>
<?php
// ===================== REPORTE DE GROOMING =====================
$hoy = date('Y-m-d');
$preset = $_GET['preset'] ?? 'mes';
if     ($preset==='mes_ant') { $desde=date('Y-m-01',strtotime('first day of last month')); $hasta=date('Y-m-t',strtotime('last day of last month')); }
elseif ($preset==='30d')     { $desde=date('Y-m-d',strtotime('-29 days')); $hasta=$hoy; }
elseif ($preset==='anio')    { $desde=date('Y-01-01'); $hasta=date('Y-12-31'); }
elseif ($preset==='rango')   { $desde=$_GET['desde']??date('Y-m-01'); $hasta=$_GET['hasta']??$hoy; }
else   { $preset='mes'; $desde=date('Y-m-01'); $hasta=date('Y-m-t'); }
if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$desde)) $desde=date('Y-m-01');
if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$hasta)) $hasta=date('Y-m-t');

// Filtro por sede (igual que en la lista)
$sede_sql='';
try { $_r=$db->query("SHOW COLUMNS FROM mascotas LIKE 'sede_id'")->fetchAll();
  if(!empty($_r) && !verTodasSedes()) $sede_sql=" AND m.sede_id=".(int)getSede(); } catch(Exception $e){}

$FROM = "FROM grooming g
  JOIN mascotas m ON m.id=g.mascota_id
  JOIN usuarios u ON u.id=g.groomer_id
  JOIN clientes c ON c.id=m.cliente_id
  WHERE DATE(g.fecha) BETWEEN ? AND ? $sede_sql";
$P=[$desde,$hasta];

$q=$db->prepare("SELECT COUNT(*) tot,
  SUM(g.estado='completado') comp, SUM(g.estado='programado') prog,
  SUM(g.estado='en_proceso') proc, SUM(g.estado='cancelado') canc,
  SUM(CASE WHEN g.estado='completado' THEN g.precio ELSE 0 END) ingresos,
  SUM(CASE WHEN g.estado<>'cancelado' THEN g.precio ELSE 0 END) agendado
  $FROM"); $q->execute($P); $K=$q->fetch();
$ingresos=(float)($K['ingresos']??0); $comp=(int)($K['comp']??0); $tot=(int)($K['tot']??0);
$ticket = $comp>0 ? $ingresos/$comp : 0;

$q=$db->prepare("SELECT COALESCE(NULLIF(g.metodo_pago,''),'No registrado') mp, COUNT(*) n, SUM(g.precio) monto
  $FROM AND g.estado='completado' GROUP BY mp ORDER BY monto DESC"); $q->execute($P); $MP=$q->fetchAll();
$q=$db->prepare("SELECT g.tipo_servicio ts, COUNT(*) n, SUM(CASE WHEN g.estado='completado' THEN g.precio ELSE 0 END) monto
  $FROM GROUP BY g.tipo_servicio ORDER BY n DESC"); $q->execute($P); $TS=$q->fetchAll();
$q=$db->prepare("SELECT u.nombre groomer, COUNT(*) n, SUM(g.estado='completado') comp, SUM(CASE WHEN g.estado='completado' THEN g.precio ELSE 0 END) monto
  $FROM GROUP BY u.id ORDER BY monto DESC, n DESC"); $q->execute($P); $GG=$q->fetchAll();
$q=$db->prepare("SELECT c.nombre dueno, COUNT(*) n, SUM(CASE WHEN g.estado='completado' THEN g.precio ELSE 0 END) monto
  $FROM GROUP BY c.id ORDER BY n DESC, monto DESC LIMIT 8"); $q->execute($P); $TC=$q->fetchAll();
$q=$db->prepare("SELECT DATE(g.fecha) d, COUNT(*) n, SUM(CASE WHEN g.estado='completado' THEN g.precio ELSE 0 END) monto
  $FROM GROUP BY DATE(g.fecha) ORDER BY d"); $q->execute($P); $SD=$q->fetchAll();

$mp_icon=['Efectivo'=>'💵','Yape'=>'📲','Plin'=>'💙','Tarjeta'=>'💳','Transferencia'=>'🏦','No registrado'=>'❔'];
$periodo_lbl = date('d/m/Y',strtotime($desde)).' – '.date('d/m/Y',strtotime($hasta));
$sd_max = 1; foreach($SD as $r) $sd_max=max($sd_max,(int)$r['n']);
?>
<style>
.rp-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-bottom:16px}
.rp-kpi{background:var(--bg2);border:1px solid var(--border);border-radius:14px;padding:16px 18px}
.rp-kpi .n{font-size:26px;font-weight:800;line-height:1.1}
.rp-kpi .l{font-size:12px;color:var(--text3);margin-top:4px;font-weight:600}
.rp-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px}
.rp-card{background:var(--bg2);border:1px solid var(--border);border-radius:14px;padding:16px 18px}
.rp-card h3{font-size:13px;font-weight:800;color:var(--text2);text-transform:uppercase;letter-spacing:.4px;margin:0 0 12px;display:flex;align-items:center;gap:7px}
.rp-row{display:flex;align-items:center;gap:10px;padding:7px 0;border-bottom:1px solid var(--border);font-size:13px}
.rp-row:last-child{border-bottom:none}
.rp-row .name{flex:1;min-width:0;font-weight:600;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.rp-row .n{color:var(--text3);font-size:12px;font-weight:600}
.rp-row .amt{font-weight:800;color:var(--success);min-width:78px;text-align:right}
.rp-bar{height:7px;border-radius:999px;background:var(--bg3);overflow:hidden;flex:1.4;max-width:140px}
.rp-bar>i{display:block;height:100%;border-radius:999px}
.rp-chart{display:flex;align-items:flex-end;gap:4px;height:120px;padding-top:8px}
.rp-chart .bar{flex:1;background:#5eead4;border:1px solid #2dd4bf;border-bottom:none;border-radius:4px 4px 0 0;position:relative;min-height:3px;display:flex;align-items:flex-end;justify-content:center}
.rp-chart .bar:hover{background:#2dd4bf}
.rp-chart .bar>span{position:absolute;bottom:-18px;font-size:9px;color:var(--text3);white-space:nowrap}
.rp-empty{text-align:center;padding:36px;color:var(--text3);font-size:13px}
@media(max-width:820px){ .rp-grid{grid-template-columns:1fr} }
@media print{ .no-print{display:none!important} .side-nav,.sidebar,.topbar,nav{display:none!important} }
</style>

<div class="page">
  <div class="gr-topbar no-print" style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;flex-wrap:wrap;gap:10px">
    <div>
      <div class="page-title" style="font-size:18px">📊 Reporte de Grooming</div>
      <div class="page-desc">Periodo: <strong><?= $periodo_lbl ?></strong></div>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <a href="?p=grooming" class="btn btn-ghost btn-sm">← Volver</a>
      <button onclick="window.print()" class="btn btn-primary btn-sm">🖨️ Imprimir / PDF</button>
    </div>
  </div>

  <!-- Selector de periodo -->
  <div class="no-print" style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:14px;align-items:center">
    <?php foreach(['mes'=>'Este mes','mes_ant'=>'Mes anterior','30d'=>'Últimos 30 días','anio'=>'Este año'] as $pk=>$pl): ?>
    <a href="?p=grooming&action=reporte&preset=<?= $pk ?>" class="gr-filter-btn <?= $preset===$pk?'active':'' ?>" style="text-decoration:none"><?= $pl ?></a>
    <?php endforeach; ?>
    <form method="GET" style="display:flex;gap:6px;align-items:center;margin-left:auto;flex-wrap:wrap">
      <input type="hidden" name="p" value="grooming"><input type="hidden" name="action" value="reporte"><input type="hidden" name="preset" value="rango">
      <input type="date" name="desde" value="<?= clean($desde) ?>" class="form-input" style="width:150px;padding:6px 10px">
      <span style="color:var(--text3)">→</span>
      <input type="date" name="hasta" value="<?= clean($hasta) ?>" class="form-input" style="width:150px;padding:6px 10px">
      <button class="btn btn-ghost btn-sm">Aplicar</button>
    </form>
  </div>

  <!-- KPIs -->
  <div class="rp-kpis">
    <div class="rp-kpi"><div class="n" style="color:var(--primary)"><?= $tot ?></div><div class="l">🛁 Servicios en el periodo</div></div>
    <div class="rp-kpi"><div class="n" style="color:#10b981"><?= $comp ?></div><div class="l">✅ Completados</div></div>
    <div class="rp-kpi"><div class="n" style="color:#10b981">S/. <?= number_format($ingresos,2) ?></div><div class="l">💰 Ingresos (completados)</div></div>
    <div class="rp-kpi"><div class="n" style="color:var(--text)">S/. <?= number_format($ticket,2) ?></div><div class="l">🎫 Ticket promedio</div></div>
    <div class="rp-kpi"><div class="n" style="color:#f59e0b"><?= (int)($K['prog']??0)+(int)($K['proc']??0) ?></div><div class="l">📅 Pendientes (prog. + en proceso)</div></div>
    <div class="rp-kpi"><div class="n" style="color:#ef4444"><?= (int)($K['canc']??0) ?></div><div class="l">✕ Cancelados</div></div>
  </div>

  <div class="rp-grid">
    <!-- Método de pago -->
    <div class="rp-card">
      <h3>💳 Ingresos por método de pago</h3>
      <?php if(empty($MP)): ?><div class="rp-empty">Sin servicios completados en el periodo.</div>
      <?php else: $mp_max=1; foreach($MP as $r)$mp_max=max($mp_max,(float)$r['monto']); foreach($MP as $r): ?>
      <div class="rp-row">
        <span style="width:22px"><?= $mp_icon[$r['mp']]??'💳' ?></span>
        <span class="name"><?= clean($r['mp']) ?> <span class="n">· <?= $r['n'] ?></span></span>
        <span class="rp-bar"><i style="width:<?= max(3,round(100*$r['monto']/$mp_max)) ?>%;background:#10b981"></i></span>
        <span class="amt">S/. <?= number_format($r['monto'],2) ?></span>
      </div>
      <?php endforeach; endif; ?>
    </div>

    <!-- Tipo de servicio -->
    <div class="rp-card">
      <h3>🧼 Por tipo de servicio</h3>
      <?php if(empty($TS)): ?><div class="rp-empty">Sin datos en el periodo.</div>
      <?php else: $ts_max=1; foreach($TS as $r)$ts_max=max($ts_max,(int)$r['n']); foreach($TS as $r): ?>
      <div class="rp-row">
        <span style="width:22px"><?= $servicio_icons[$r['ts']]??'🐾' ?></span>
        <span class="name"><?= $servicio_labels[$r['ts']]??ucfirst($r['ts']) ?></span>
        <span class="rp-bar"><i style="width:<?= max(3,round(100*$r['n']/$ts_max)) ?>%;background:#0ea5a4"></i></span>
        <span class="n" style="min-width:34px;text-align:right"><?= $r['n'] ?></span>
        <span class="amt">S/. <?= number_format($r['monto'],2) ?></span>
      </div>
      <?php endforeach; endif; ?>
    </div>
  </div>

  <div class="rp-grid">
    <!-- Groomers -->
    <div class="rp-card">
      <h3>✂️ Producción por groomer</h3>
      <?php if(empty($GG)): ?><div class="rp-empty">Sin datos en el periodo.</div>
      <?php else: $gg_max=1; foreach($GG as $r)$gg_max=max($gg_max,(float)$r['monto']); foreach($GG as $r): ?>
      <div class="rp-row">
        <span class="name"><?= clean($r['groomer']) ?> <span class="n">· <?= $r['comp'] ?>/<?= $r['n'] ?> compl.</span></span>
        <span class="rp-bar"><i style="width:<?= max(3,round(100*$r['monto']/$gg_max)) ?>%;background:#8b5cf6"></i></span>
        <span class="amt">S/. <?= number_format($r['monto'],2) ?></span>
      </div>
      <?php endforeach; endif; ?>
    </div>

    <!-- Top clientes -->
    <div class="rp-card">
      <h3>👥 Clientes más frecuentes</h3>
      <?php if(empty($TC)): ?><div class="rp-empty">Sin datos en el periodo.</div>
      <?php else: foreach($TC as $r): ?>
      <div class="rp-row">
        <span class="name"><?= clean($r['dueno']) ?></span>
        <span class="n" style="min-width:60px;text-align:right"><?= $r['n'] ?> servicio<?= $r['n']!=1?'s':'' ?></span>
        <span class="amt">S/. <?= number_format($r['monto'],2) ?></span>
      </div>
      <?php endforeach; endif; ?>
    </div>
  </div>

  <!-- Actividad diaria -->
  <div class="rp-card" style="margin-bottom:14px">
    <h3>📈 Actividad diaria (servicios por día)</h3>
    <?php if(empty($SD)): ?><div class="rp-empty">Sin actividad en el periodo.</div>
    <?php else: ?>
    <div class="rp-chart">
      <?php foreach($SD as $r): $h=round(100*$r['n']/$sd_max); ?>
      <div class="bar" style="height:<?= max(3,$h) ?>%" title="<?= date('d/m',strtotime($r['d'])) ?>: <?= $r['n'] ?> servicio(s), S/. <?= number_format($r['monto'],2) ?>">
        <span><?= date('d/m',strtotime($r['d'])) ?></span>
      </div>
      <?php endforeach; ?>
    </div>
    <div style="margin-top:26px;font-size:11px;color:var(--text3)">Pasa el cursor sobre cada barra para ver el detalle del día.</div>
    <?php endif; ?>
  </div>
</div>

<?php else: ?>
<?php
// ── Traer TODAS las citas (no solo una fecha) con filtros opcionales ──
$filtro_estado = $_GET['estado'] ?? '';
$filtro_q      = trim($_GET['q'] ?? '');
$where_all = "1=1";
$params_all = [];
if ($filtro_estado) { $where_all .= " AND g.estado=?"; $params_all[] = $filtro_estado; }
if ($filtro_q)      { $where_all .= " AND (m.nombre LIKE ? OR c.nombre LIKE ?)"; $like="%$filtro_q%"; $params_all=array_merge($params_all,[$like,$like]); }
try {
    $_r=$db->query("SHOW COLUMNS FROM `mascotas` LIKE 'sede_id'")->fetchAll();
    if(!empty($_r)) {
        if(!verTodasSedes()) { $where_all.=" AND m.sede_id=".getSede(); }
    }
} catch(Exception $e) {}
$all_groomings = $db->prepare("
    SELECT g.*,
           m.nombre as mascota, m.especie, m.foto as foto_mascota, m.raza,
           m.alergias as alergias_mascota,
           u.nombre as groomer,
           c.nombre as dueno, c.telefono, c.email
    FROM grooming g
    JOIN mascotas m ON m.id=g.mascota_id
    JOIN usuarios u ON u.id=g.groomer_id
    JOIN clientes c ON c.id=m.cliente_id
    WHERE $where_all
    ORDER BY g.fecha DESC
    LIMIT 200
");
$all_groomings->execute($params_all);
$all_groomings = $all_groomings->fetchAll();

// Agrupar por fecha
$por_fecha = [];
foreach ($all_groomings as $g) {
    $dia = date('Y-m-d', strtotime($g['fecha']));
    $por_fecha[$dia][] = $g;
}

// Stats globales
$stats_all = ['programado'=>0,'en_proceso'=>0,'completado'=>0,'cancelado'=>0];
foreach ($all_groomings as $g) $stats_all[$g['estado']]++;

// Consulta seleccionada
$sel_id = (int)($_GET['gid'] ?? 0);
$groom_sel = null;
if ($sel_id) {
    foreach ($all_groomings as $g) {
        if ($g['id'] == $sel_id) { $groom_sel = $g; break; }
    }
}
// Si no hay selección, usar la primera
if (!$groom_sel && !empty($all_groomings)) {
    $groom_sel = $all_groomings[0];
    $sel_id    = $groom_sel['id'];
}
?>

<style>
.gr-layout { display:grid; grid-template-columns:320px 1fr; gap:0; align-items:start; min-height:420px; background:var(--bg2); border:1px solid var(--border); border-radius:16px; overflow:hidden; }
/* Lista izquierda */
.gr-list { border-right:1px solid var(--border); display:flex; flex-direction:column; overflow:hidden; max-height:calc(100vh - 175px); }
.gr-list-head { padding:14px 16px; border-bottom:1px solid var(--border); flex-shrink:0; background:var(--bg2); }
.gr-search { display:flex; align-items:center; gap:8px; background:var(--bg3); border:1.5px solid var(--border); border-radius:8px; padding:7px 12px; }
.gr-search input { border:none; background:transparent; outline:none; font-size:12px; color:var(--text); width:100%; font-family:var(--font); }
.gr-filters { display:flex; gap:5px; margin-top:10px; flex-wrap:wrap; }
.gr-filter-btn { padding:4px 11px; font-size:11px; font-weight:600; border:1.5px solid var(--border); border-radius:999px; background:var(--bg2); color:var(--text3); cursor:pointer; transition:all .15s; }
.gr-filter-btn.active { border-color:var(--primary); color:var(--primary); background:var(--primary-l); }
.gr-scroll { flex:1; overflow-y:auto; }
/* Grupos de fecha */
.gr-date-group { }
.gr-date-header { padding:8px 16px; background:var(--bg3); border-bottom:1px solid var(--border); border-top:1px solid var(--border); position:sticky; top:0; z-index:10; }
.gr-date-label { font-size:11px; font-weight:700; color:var(--text2); text-transform:uppercase; letter-spacing:.5px; display:flex; align-items:center; gap:6px; }
.gr-date-hoy { background:var(--primary); color:#fff; font-size:9px; padding:1px 7px; border-radius:999px; font-weight:700; }
/* Items de cita */
.gr-item { display:flex; align-items:center; gap:11px; padding:11px 16px; border-bottom:1px solid var(--border); cursor:pointer; transition:background .12s; }
.gr-item:hover { background:var(--bg3); }
.gr-item.active { background:var(--primary-l); border-left:3px solid var(--primary); }
.gr-item-photo { width:40px; height:40px; border-radius:10px; object-fit:cover; border:1.5px solid var(--border); flex-shrink:0; }
.gr-item-emoji { width:40px; height:40px; border-radius:10px; background:var(--primary-l); display:flex; align-items:center; justify-content:center; font-size:19px; flex-shrink:0; }
.gr-item-info { flex:1; min-width:0; }
.gr-item-name { font-size:13px; font-weight:600; color:var(--text); }
.gr-item-sub  { font-size:11px; color:var(--text3); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; margin-top:1px; }
.gr-item-right { text-align:right; flex-shrink:0; }
.gr-item-hora { font-size:12px; font-weight:700; color:var(--primary); }
.gr-item-dur  { font-size:10px; color:var(--text3); margin-top:1px; }
.gr-estado-dot { width:8px; height:8px; border-radius:50%; display:inline-block; margin-right:3px; }

/* Panel detalle derecho */
.gr-detail { display:flex; flex-direction:column; }
/* Barra de acciones (siempre visible, debajo del encabezado) */
.gr-actions-bar { display:flex; gap:8px; flex-wrap:wrap; align-items:center; padding:13px 24px; border-bottom:1px solid var(--border); background:var(--bg2); }
.gr-act-primary { background:#10b981; border-color:#10b981; color:#fff; font-weight:700; }
.gr-det-head { padding:20px 24px; border-bottom:1px solid var(--border); flex-shrink:0; }
.gr-det-body { padding:20px 24px; flex:1; overflow-y:auto; }
.gr-det-grid { display:grid; grid-template-columns:1fr 1fr; gap:0; margin-bottom:16px; }
.gr-det-grid > .gr-det-box:first-child { padding-right:28px; }
.gr-det-grid > .gr-det-box:last-child { border-left:1px solid var(--border); padding-left:28px; }
.gr-det-box { background:transparent; border:none; border-radius:0; padding:0; }
.gr-det-box-title { font-size:12px; font-weight:800; color:var(--text2); text-transform:uppercase; letter-spacing:.5px; margin-bottom:12px; display:flex; align-items:center; gap:8px; }
.gr-det-box-title::before { content:''; width:3px; height:14px; border-radius:2px; background:var(--gr-accent,var(--primary)); flex-shrink:0; }
.gr-det-row { display:flex; justify-content:space-between; align-items:center; padding:5px 0; border-bottom:1px solid var(--border); font-size:13px; }
.gr-det-row:last-child { border-bottom:none; }
@media(max-width:768px){
  .gr-det-grid { grid-template-columns:1fr; gap:16px; }
  .gr-det-grid > .gr-det-box:first-child { padding-right:0; }
  .gr-det-grid > .gr-det-box:last-child { border-left:none; padding-left:0; }
}
.gr-det-lbl { color:var(--text3); font-weight:500; }
.gr-det-val { color:var(--text); font-weight:600; text-align:right; max-width:160px; }
.gr-estado-badge { display:inline-flex; align-items:center; gap:5px; padding:5px 14px; border-radius:999px; font-size:12px; font-weight:700; }
.gr-empty { display:flex; flex-direction:column; align-items:center; justify-content:center; height:100%; text-align:center; padding:48px; color:var(--text3); }
.gr-bottom-bar { padding:14px 24px; border-top:1px solid var(--border); background:var(--bg2); display:flex; gap:8px; flex-wrap:wrap; flex-shrink:0; }
/* Cambio de estado rápido */
.gr-estado-btns { display:flex; gap:6px; flex-wrap:wrap; }
.gr-est-btn { padding:6px 14px; border-radius:999px; font-size:11px; font-weight:700; border:1.5px solid; cursor:pointer; background:transparent; font-family:var(--font); transition:all .15s; }

/* Botón "Volver a la lista" — solo visible en móvil */
.gr-back-btn { display:none; align-items:center; gap:6px; font-size:13px; font-weight:700; color:var(--primary); background:var(--bg3); border:1px solid var(--border); border-radius:9px; padding:10px 14px; margin:14px 16px 0; text-decoration:none; width:calc(100% - 32px); box-sizing:border-box; }

/* ─────────── RESPONSIVE MÓVIL / TABLET ─────────── */
@media (max-width:860px) {
  /* Stats en 2 columnas */
  .gr-stats { grid-template-columns:repeat(2,1fr)!important; gap:10px!important; }
  /* El master-detail deja de ser rígido: se apila y crece con el contenido */
  .gr-layout { display:block!important; height:auto!important; border:none!important; border-radius:0!important; background:transparent!important; overflow:visible!important; }
  .gr-list { border-right:none!important; border:1px solid var(--border); border-radius:14px; overflow:hidden; max-height:none!important; }
  .gr-scroll { overflow:visible!important; }
  .gr-detail { border:1px solid var(--border); border-radius:14px; overflow:hidden; height:auto; }
  .gr-det-body { overflow:visible!important; }
  .gr-det-grid { grid-template-columns:1fr!important; gap:0!important; }
  .gr-det-grid > .gr-det-box:first-child { padding-right:0!important; padding-bottom:14px; border-bottom:1px solid var(--border); margin-bottom:14px; }
  .gr-det-grid > .gr-det-box:last-child { border-left:none!important; padding-left:0!important; }
  .gr-det-val { max-width:60%; }
  .gr-actions-bar { padding:12px 16px; }
  .gr-actions-bar .btn { flex:1 1 42%; justify-content:center; }
  .gr-actions-bar form { margin-left:0!important; flex:1 1 42%; }
  .gr-actions-bar form .btn { width:100%; }
  /* Alternancia lista ⇄ detalle (tipo app) */
  .gr-wrap.show-detail .gr-stats,
  .gr-wrap.show-detail .gr-topbar,
  .gr-wrap.show-detail .gr-list { display:none!important; }
  .gr-wrap.show-list .gr-detail { display:none!important; }
  .gr-back-btn { display:flex; }
}
</style>

<div class="gr-wrap <?= isset($_GET['gid'])?'show-detail':'show-list' ?>">
<!-- Stats -->
<div class="gr-stats" style="display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-bottom:14px">
  <?php foreach(['programado'=>['icon'=>'📅','label'=>'Programados','col'=>'#3b82f6'],'en_proceso'=>['icon'=>'✂️','label'=>'En proceso','col'=>'#f59e0b'],'completado'=>['icon'=>'✅','label'=>'Completados','col'=>'#10b981'],'cancelado'=>['icon'=>'✕','label'=>'Cancelados','col'=>'#ef4444']] as $k=>$v): ?>
  <div style="background:var(--bg2);border:1px solid var(--border);border-radius:12px;padding:12px 10px;text-align:center;cursor:pointer;transition:all .15s" onclick="setFilter('<?= $k ?>')" onmouseover="this.style.borderColor='<?= $v['col'] ?>'" onmouseout="this.style.borderColor='var(--border)'">
    <div style="font-size:22px;font-weight:800;color:<?= $v['col'] ?>"><?= $stats_all[$k] ?></div>
    <div style="font-size:11px;color:var(--text3);margin-top:2px"><?= $v['icon'] ?> <?= $v['label'] ?></div>
  </div>
  <?php endforeach; ?>
</div>

<!-- Barra superior -->
<div class="gr-topbar" style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px">
  <div>
    <div class="page-title" style="font-size:18px">✨ Grooming / Peluquería</div>
    <div class="page-desc"><?= count($all_groomings) ?> servicios registrados</div>
  </div>
  <div style="display:flex;gap:8px">
    <a href="?p=grooming&action=reporte" class="btn btn-ghost">📊 Reporte</a>
    <a href="?p=grooming&action=nuevo" class="btn btn-primary">＋ Agendar Servicio</a>
  </div>
</div>

<!-- LAYOUT 2 COLUMNAS -->
<div class="gr-layout">

  <!-- ── LISTA IZQUIERDA ── -->
  <div class="gr-list">
    <div class="gr-list-head">
      <!-- Búsqueda -->
      <form method="GET" id="grFilterForm">
        <input type="hidden" name="p" value="grooming">
        <input type="hidden" name="estado" id="grEstadoInput" value="<?= clean($filtro_estado) ?>">
        <input type="hidden" name="gid" id="grGidInput" value="<?= $sel_id ?>">
        <div class="gr-search">
          <span style="font-size:14px;color:var(--text3)">🔍</span>
          <input name="q" value="<?= clean($filtro_q) ?>" placeholder="Buscar mascota o dueño..."
                 onkeydown="if(event.key==='Enter'){event.preventDefault();document.getElementById('grFilterForm').submit();}">
        </div>
        <div class="gr-filters">
          <button type="button" class="gr-filter-btn <?= !$filtro_estado?'active':'' ?>" onclick="setFilter('')">Todos</button>
          <button type="button" class="gr-filter-btn <?= $filtro_estado==='programado'?'active':'' ?>" onclick="setFilter('programado')">📅 Programado</button>
          <button type="button" class="gr-filter-btn <?= $filtro_estado==='en_proceso'?'active':'' ?>" onclick="setFilter('en_proceso')">✂️ En proceso</button>
          <button type="button" class="gr-filter-btn <?= $filtro_estado==='completado'?'active':'' ?>" onclick="setFilter('completado')">✅ Completado</button>
        </div>
      </form>
    </div>
    <div class="gr-scroll">
      <?php if (empty($all_groomings)): ?>
      <div style="text-align:center;padding:48px 20px;color:var(--text3)">
        <div style="font-size:40px;margin-bottom:12px;opacity:.3">✂️</div>
        <div style="font-size:13px;font-weight:600">Sin servicios registrados</div>
        <a href="?p=grooming&action=nuevo" class="btn btn-primary btn-sm" style="margin-top:14px">Agendar primer servicio</a>
      </div>
      <?php else:
        $estado_dot_color = ['programado'=>'#3b82f6','en_proceso'=>'#f59e0b','completado'=>'#10b981','cancelado'=>'#ef4444'];
        foreach ($por_fecha as $dia => $items):
          $esHoy    = $dia === date('Y-m-d');
          $esPasado = strtotime($dia) < strtotime(date('Y-m-d'));
          $diasDif  = (int)round((strtotime($dia) - strtotime(date('Y-m-d'))) / 86400);
          if ($diasDif === 0)       $label_dia = 'Hoy · ' . date('d/m/Y', strtotime($dia));
          elseif ($diasDif === 1)   $label_dia = 'Mañana · ' . date('d/m/Y', strtotime($dia));
          elseif ($diasDif === -1)  $label_dia = 'Ayer · ' . date('d/m/Y', strtotime($dia));
          else                      $label_dia = date('l d/m/Y', strtotime($dia));
      ?>
      <div class="gr-date-group">
        <div class="gr-date-header">
          <div class="gr-date-label">
            <?= ucfirst($label_dia) ?>
            <?php if($esHoy): ?><span class="gr-date-hoy">HOY</span><?php endif; ?>
            <span style="margin-left:auto;font-size:11px;color:var(--text3);font-weight:400"><?= count($items) ?> servicio<?= count($items)!=1?'s':'' ?></span>
          </div>
        </div>
        <?php foreach ($items as $g):
          $foto_url = !empty($g['foto_mascota']) && file_exists(UPLOADS_PATH.'/'.$g['foto_mascota'])
                    ? BASE_URL.'/public/uploads/'.$g['foto_mascota'] : null;
          $is_sel   = $g['id'] == $sel_id;
          $dc       = $estado_dot_color[$g['estado']] ?? '#94a3b8';
        ?>
        <div class="gr-item <?= $is_sel?'active':'' ?>" onclick="selectGroom(<?= $g['id'] ?>)" data-id="<?= $g['id'] ?>">
          <?php if ($foto_url): ?>
          <img src="<?= $foto_url ?>" class="gr-item-photo" alt="">
          <?php else: ?>
          <div class="gr-item-emoji"><?= $ei[$g['especie']]??'🐾' ?></div>
          <?php endif; ?>
          <div class="gr-item-info">
            <div class="gr-item-name">
              <span class="gr-estado-dot" style="background:<?= $dc ?>"></span>
              <?= clean($g['mascota']) ?>
            </div>
            <div class="gr-item-sub">
              <?= $servicio_icons[$g['tipo_servicio']] ?> <?= $servicio_labels[$g['tipo_servicio']] ?>
              <?= $g['tipo_corte'] ? ' · '.clean($g['tipo_corte']) : '' ?>
            </div>
            <div class="gr-item-sub"><?= clean($g['dueno']) ?></div>
          </div>
          <div class="gr-item-right">
            <div class="gr-item-hora"><?= date('H:i', strtotime($g['fecha'])) ?></div>
            <div class="gr-item-dur"><?= $g['duracion_minutos'] ?>min</div>
            <?php if($g['precio']>0): ?>
            <div style="font-size:11px;font-weight:700;color:var(--success);margin-top:2px">S/<?= number_format($g['precio'],0) ?></div>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

  <!-- ── PANEL DETALLE DERECHO ── -->
  <div class="gr-detail">
    <?php if ($groom_sel):
      $foto_det = !empty($groom_sel['foto_mascota']) && file_exists(UPLOADS_PATH.'/'.$groom_sel['foto_mascota'])
               ? BASE_URL.'/public/uploads/'.$groom_sel['foto_mascota'] : null;
      $tel_det = preg_replace('/[^0-9]/','',ltrim($groom_sel['telefono'],'+'));
      if(strlen($tel_det)<11) $tel_det='51'.$tel_det;
      // Mensajes de WhatsApp: usan las plantillas EDITABLES del módulo WhatsApp.
      //  - wa_tpl_grooming_cita  → confirmación de la cita de grooming
      //  - wa_tpl_grooming       → aviso de recojo ("ya está listo")
      $wa_clinica = 'VetPro'; $wa_tpl = ''; $wa_tpl_cita = '';
      try {
        $_cfg = $db->query("SELECT clave,valor FROM configuracion WHERE clave IN ('nombre_clinica','clinica_nombre','wa_tpl_grooming','wa_tpl_grooming_cita')")->fetchAll(PDO::FETCH_KEY_PAIR);
        foreach (['nombre_clinica','clinica_nombre'] as $_k) {
          if (!empty(trim($_cfg[$_k] ?? ''))) { $wa_clinica = trim($_cfg[$_k]); break; }
        }
        $wa_tpl      = trim($_cfg['wa_tpl_grooming'] ?? '');
        $wa_tpl_cita = trim($_cfg['wa_tpl_grooming_cita'] ?? '');
      } catch (Exception $e) {}
      if ($wa_tpl === '') {
        $wa_tpl = "🛁 *Grooming — {clinica}*\n\nHola {nombre_cliente} 👋\n\n¡*{nombre_mascota}* ya está listo! 🛁✨\n\n🧼 *Servicio:* {servicio}\n📅 *Fecha:* {fecha}\n🕐 *Hora:* {hora}\n💰 *Total:* S/. {precio}\n\nPuedes pasar a recogerlo cuando gustes.\n\n{clinica} 🐾";
      }
      if ($wa_tpl_cita === '') {
        $wa_tpl_cita = "✂️ *Cita de Grooming — {clinica}*\n\nHola {nombre_cliente} 👋\n\nTe confirmamos la cita de *{nombre_mascota}* 🐾\n\n🧼 *Servicio:* {servicio}\n📅 *Fecha:* {fecha}\n🕐 *Hora:* {hora}\n\nPor favor llega unos minutos antes.\n_Responde si necesitas reprogramar._\n\n{clinica} 🐾";
      }
      $_serv_txt = ($servicio_labels[$groom_sel['tipo_servicio']] ?? $groom_sel['tipo_servicio'])
                 . (!empty($groom_sel['tipo_corte']) ? ' — '.$groom_sel['tipo_corte'] : '');
      $_wa_map = [
        '{clinica}'        => $wa_clinica,
        '{veterinaria}'    => $wa_clinica,
        '{nombre_cliente}' => (string)$groom_sel['dueno'],
        '{nombre_mascota}' => (string)$groom_sel['mascota'],
        '{servicio}'       => $_serv_txt,
        '{fecha}'          => date('d/m/Y', strtotime($groom_sel['fecha'])),
        '{hora}'           => date('H:i', strtotime($groom_sel['fecha'])),
        '{precio}'         => number_format((float)($groom_sel['precio'] ?? 0), 2),
        '{veterinario}'    => (string)($groom_sel['groomer'] ?? ''),
      ];
      $wa_msg      = strtr($wa_tpl, $_wa_map);       // recojo ("ya está listo")
      $wa_msg_cita = strtr($wa_tpl_cita, $_wa_map);  // confirmación de cita
      // URLs de WhatsApp Web. Solo si el teléfono tiene dígitos suficientes.
      $_tel_raw     = preg_replace('/[^0-9]/', '', (string)($groom_sel['telefono'] ?? ''));
      $_tel_ok      = (strlen($_tel_raw) >= 6);
      $wa_url_listo = $_tel_ok ? 'https://wa.me/'.$tel_det.'?text='.rawurlencode($wa_msg) : '';
      $wa_url_cita  = $_tel_ok ? 'https://wa.me/'.$tel_det.'?text='.rawurlencode($wa_msg_cita) : '';
      $estado_cfg_det = [
        'programado'  => ['bg'=>'#dbeafe','color'=>'#1e3a8a','icon'=>'📅'],
        'en_proceso'  => ['bg'=>'#fef3c7','color'=>'#78350f','icon'=>'✂️'],
        'completado'  => ['bg'=>'#d1fae5','color'=>'#065f46','icon'=>'✅'],
        'cancelado'   => ['bg'=>'#fee2e2','color'=>'#7f1d1d','icon'=>'✕'],
      ];
      $ecd = $estado_cfg_det[$groom_sel['estado']] ?? ['bg'=>'#f1f5f9','color'=>'#475569','icon'=>'•'];
    ?>
    <?php
      $banner_col = ['programado'=>'#3b82f6','en_proceso'=>'#f59e0b','completado'=>'#10b981','cancelado'=>'#ef4444'][$groom_sel['estado']] ?? '#3b82f6';
      $back_url = '?p=grooming'.($filtro_estado?'&estado='.urlencode($filtro_estado):'').($filtro_q?'&q='.urlencode($filtro_q):'');
    ?>
    <!-- Volver a la lista (solo móvil) -->
    <a href="<?= $back_url ?>" class="gr-back-btn">← Volver a la lista</a>
    <!-- BANNER del servicio -->
    <div class="gr-det-head" style="background:<?= $banner_col ?>12;border-bottom:3px solid <?= $banner_col ?>">
      <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap">
        <!-- Foto -->
        <?php if ($foto_det): ?>
        <img src="<?= $foto_det ?>" style="width:60px;height:60px;border-radius:14px;object-fit:cover;flex-shrink:0">
        <?php else: ?>
        <div style="width:60px;height:60px;border-radius:14px;background:<?= $banner_col ?>25;display:flex;align-items:center;justify-content:center;font-size:30px;flex-shrink:0">
          <?= $ei[$groom_sel['especie']]??'🐾' ?>
        </div>
        <?php endif; ?>
        <div class="flex-1" style="min-width:150px">
          <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
            <div style="font-size:19px;font-weight:800;color:var(--text)"><?= clean($groom_sel['mascota']) ?></div>
            <span class="gr-estado-badge" style="background:<?= $ecd['bg'] ?>;color:<?= $ecd['color'] ?>">
              <?= $ecd['icon'] ?> <?= ucfirst(str_replace('_',' ',$groom_sel['estado'])) ?>
            </span>
          </div>
          <div style="font-size:12px;color:var(--text2);margin-top:3px">
            <?= $servicio_icons[$groom_sel['tipo_servicio']] ?> <strong><?= $servicio_labels[$groom_sel['tipo_servicio']] ?></strong><?= $groom_sel['tipo_corte'] ? ' — '.clean($groom_sel['tipo_corte']) : '' ?>
            · 📅 <?= date('d/m/Y',strtotime($groom_sel['fecha'])) ?> <?= date('H:i',strtotime($groom_sel['fecha'])) ?> · ⏱ <?= $groom_sel['duracion_minutos'] ?> min
          </div>
        </div>
        <!-- Precio -->
        <?php if ($groom_sel['precio'] > 0): ?>
        <div style="text-align:right;flex-shrink:0">
          <div style="font-size:22px;font-weight:800;color:var(--success);font-family:var(--font-display)">S/. <?= number_format($groom_sel['precio'],2) ?></div>
          <div style="font-size:11px;color:var(--text3)">Precio del servicio</div>
        </div>
        <?php endif; ?>
      </div>

      <!-- Cambio rápido de estado -->
      <div style="margin-top:14px;display:flex;align-items:center;gap:8px;flex-wrap:wrap">
        <span style="font-size:11px;font-weight:700;color:var(--text3);text-transform:uppercase;letter-spacing:.5px">Cambiar estado:</span>
        <div class="gr-estado-btns">
          <?php foreach(['programado'=>['#dbeafe','#1e3a8a','📅'],'en_proceso'=>['#fef3c7','#78350f','✂️'],'completado'=>['#d1fae5','#065f46','✅'],'cancelado'=>['#fee2e2','#7f1d1d','✕']] as $es=>$ecfg): ?>
          <button class="gr-est-btn" onclick="cambiarEstado(<?= $groom_sel['id'] ?>,'<?= $es ?>')"
                  style="background:<?= $groom_sel['estado']===$es?$ecfg[0]:'transparent' ?>;color:<?= $ecfg[1] ?>;border-color:<?= $ecfg[0] ?>">
            <?= $ecfg[2] ?> <?= ucfirst(str_replace('_',' ',$es)) ?>
          </button>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <!-- ACCIONES (siempre visibles, debajo del encabezado) -->
    <div class="gr-actions-bar">
      <?php if(!in_array($groom_sel['estado'],['completado','cancelado'])): ?>
      <button type="button" onclick="banoTerminado(<?= (int)$groom_sel['id'] ?>)" class="btn btn-sm gr-act-primary">🛁 Baño terminado + avisar</button>
      <?php if($wa_url_cita): ?><a href="<?= $wa_url_cita ?>" target="_blank" class="btn btn-wa btn-sm">📅 Confirmar cita</a><?php endif; ?>
      <?php else: ?>
      <button type="button" onclick="avisarListo()" class="btn btn-wa btn-sm">💬 Avisar que ya está listo</button>
      <?php endif; ?>
      <a href="?p=grooming&action=editar&id=<?= $groom_sel['id'] ?>" class="btn btn-primary btn-sm">✏️ Editar</a>
      <a href="?p=mascotas&action=ver&id=<?= $groom_sel['mascota_id'] ?>" class="btn btn-ghost btn-sm">🐾 Ficha</a>
      <a href="?p=historial&mascota_id=<?= $groom_sel['mascota_id'] ?>" class="btn btn-ghost btn-sm">🏥 Historia</a>
      <a href="https://wa.me/<?= $tel_det ?>" target="_blank" class="btn btn-ghost btn-sm">💬 Chat</a>
      <form method="POST" style="display:inline;margin-left:auto">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="id" value="<?= $groom_sel['id'] ?>">
        <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--danger)"
                onclick="return confirm('¿Eliminar este servicio de grooming?')">🗑️ Eliminar</button>
      </form>
    </div>

    <!-- Cuerpo del detalle -->
    <div class="gr-det-body">

      <!-- Grid info -->
      <div class="gr-det-grid" style="--gr-accent:<?= $banner_col ?>">
        <!-- Info del servicio -->
        <div class="gr-det-box">
          <div class="gr-det-box-title">Datos del servicio</div>
          <div class="gr-det-row"><span class="gr-det-lbl">Tipo de servicio</span><span class="gr-det-val"><?= $servicio_icons[$groom_sel['tipo_servicio']] ?> <?= $servicio_labels[$groom_sel['tipo_servicio']] ?></span></div>
          <?php if ($groom_sel['tipo_corte']): ?>
          <div class="gr-det-row"><span class="gr-det-lbl">Tipo de corte</span><span class="gr-det-val"><?= clean($groom_sel['tipo_corte']) ?></span></div>
          <?php endif; ?>
          <div class="gr-det-row"><span class="gr-det-lbl">Duración estimada</span><span class="gr-det-val"><?= $groom_sel['duracion_minutos'] ?> minutos</span></div>
          <div class="gr-det-row"><span class="gr-det-lbl">Groomer</span><span class="gr-det-val"><?= clean($groom_sel['groomer']) ?></span></div>
          <div class="gr-det-row"><span class="gr-det-lbl">Condición del pelaje</span><span class="gr-det-val"><?= ucfirst(str_replace('_',' ',$groom_sel['condicion_pelo']??'normal')) ?></span></div>
          <?php if ($groom_sel['precio'] > 0): ?>
          <div class="gr-det-row"><span class="gr-det-lbl">Precio</span><span class="gr-det-val" style="color:var(--success);font-size:14px">S/. <?= number_format($groom_sel['precio'],2) ?></span></div>
          <?php endif; ?>
          <div class="gr-det-row"><span class="gr-det-lbl">Método de pago</span><span class="gr-det-val"><?= !empty($groom_sel['metodo_pago']) ? '💳 '.clean($groom_sel['metodo_pago']) : '<span style="color:var(--text3);font-weight:500">—</span>' ?></span></div>
        </div>
        <!-- Info del cliente/mascota -->
        <div class="gr-det-box">
          <div class="gr-det-box-title">Cliente / Mascota</div>
          <div class="gr-det-row"><span class="gr-det-lbl">Dueño</span><span class="gr-det-val"><?= clean($groom_sel['dueno']) ?></span></div>
          <div class="gr-det-row"><span class="gr-det-lbl">Teléfono</span>
            <span class="gr-det-val"><a href="https://wa.me/<?= $tel_det ?>" target="_blank" style="color:var(--wa);text-decoration:none">💬 <?= clean($groom_sel['telefono']) ?></a></span>
          </div>
          <div class="gr-det-row"><span class="gr-det-lbl">Mascota</span><span class="gr-det-val"><?= $ei[$groom_sel['especie']]??'🐾' ?> <?= clean($groom_sel['mascota']) ?></span></div>
          <div class="gr-det-row"><span class="gr-det-lbl">Especie</span><span class="gr-det-val"><?= ucfirst($groom_sel['especie']) ?><?= ($groom_sel['raza']??'')?' · '.clean($groom_sel['raza']):'' ?></span></div>
          <?php
            // Coherencia de alergias: usar la reportada en el servicio, o la de la ficha, o "Ninguna"
            $alergia_txt = trim($groom_sel['alergias_reportadas'] ?? '') ?: trim($groom_sel['alergias_mascota'] ?? '');
          ?>
          <div class="gr-det-row"><span class="gr-det-lbl">⚠️ Alergias</span>
            <?php if ($alergia_txt): ?>
              <span class="gr-det-val" style="color:var(--danger)"><?= clean($alergia_txt) ?></span>
            <?php else: ?>
              <span class="gr-det-val" style="color:var(--text3)">Ninguna</span>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <!-- Secciones condicionales -->
      <?php if ($groom_sel['alergias_reportadas']??''): ?>
      <div style="background:#fffbeb;border-left:3px solid #f59e0b;border-radius:0 10px 10px 0;padding:11px 14px;margin-bottom:10px">
        <div style="font-size:11px;font-weight:700;color:#92400e;margin-bottom:3px">⚠️ ALERGIAS REPORTADAS</div>
        <div style="font-size:13px;color:#78350f"><?= nl2br(clean($groom_sel['alergias_reportadas'])) ?></div>
      </div>
      <?php endif; ?>

      <?php if ($groom_sel['productos_usados']??''): ?>
      <div style="background:#f0fdf4;border-left:3px solid #10b981;border-radius:0 10px 10px 0;padding:11px 14px;margin-bottom:10px">
        <div style="font-size:11px;font-weight:700;color:#065f46;margin-bottom:3px">🧴 PRODUCTOS UTILIZADOS</div>
        <div style="font-size:13px;color:var(--text2);line-height:1.7"><?= nl2br(clean($groom_sel['productos_usados'])) ?></div>
      </div>
      <?php endif; ?>

      <?php if ($groom_sel['observaciones']??''): ?>
      <div style="background:var(--bg3);border-left:3px solid #6366f1;border-radius:0 10px 10px 0;padding:11px 14px;margin-bottom:10px">
        <div style="font-size:11px;font-weight:700;color:var(--text2);margin-bottom:3px">📝 OBSERVACIONES DEL SERVICIO</div>
        <div style="font-size:13px;color:var(--text2);line-height:1.7"><?= nl2br(clean($groom_sel['observaciones'])) ?></div>
      </div>
      <?php endif; ?>

      <?php if ($groom_sel['notas_internas']??''): ?>
      <div style="background:var(--bg3);border-left:3px solid var(--border);border-radius:0 10px 10px 0;padding:11px 14px">
        <div style="font-size:11px;font-weight:700;color:var(--text3);margin-bottom:3px">🔒 NOTAS INTERNAS</div>
        <div style="font-size:13px;color:var(--text2)"><?= nl2br(clean($groom_sel['notas_internas'])) ?></div>
      </div>
      <?php endif; ?>

    </div>

    <script>
    // Datos del servicio seleccionado para el aviso por WhatsApp ("mascota lista")
    window.GR_WA = {
      id: <?= (int)$groom_sel['id'] ?>,
      url: <?= json_encode($wa_url_listo, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>,
      mascota: <?= json_encode((string)$groom_sel['mascota'], JSON_UNESCAPED_UNICODE) ?>,
      dueno: <?= json_encode((string)$groom_sel['dueno'], JSON_UNESCAPED_UNICODE) ?>
    };
    </script>

    <?php else: ?>
    <div class="gr-empty">
      <div style="font-size:56px;margin-bottom:16px;opacity:.25">✂️</div>
      <div style="font-size:15px;font-weight:600;margin-bottom:6px">Sin servicios registrados</div>
      <div style="font-size:13px;margin-bottom:20px">Agenda el primer servicio de grooming</div>
      <a href="?p=grooming&action=nuevo" class="btn btn-primary">＋ Agendar Servicio</a>
    </div>
    <?php endif; ?>
  </div>

</div><!-- fin gr-layout -->
</div><!-- fin gr-wrap -->

<?php endif; ?>
</div>

<script>
function selectGroom(id) {
  // Highlight en lista
  document.querySelectorAll('.gr-item').forEach(el => el.classList.remove('active'));
  const item = document.querySelector(`.gr-item[data-id="${id}"]`);
  if (item) item.classList.add('active');
  // Navegar manteniendo filtros
  const url = new URL(window.location.href);
  url.searchParams.set('gid', id);
  window.location.href = url.toString();
}

function setFilter(estado) {
  document.getElementById('grEstadoInput').value = estado;
  document.getElementById('grGidInput').value = '';
  document.getElementById('grFilterForm').submit();
}

async function cambiarEstado(id, estado) {
  // Si se marca "completado", usar el flujo que además ofrece avisar por WhatsApp
  if (estado === 'completado') { banoTerminado(id); return; }
  try {
    const fd = new FormData();
    fd.append('action', 'cambiar_estado');
    fd.append('id', id);
    fd.append('estado', estado);
    await fetch(window.location.href, { method:'POST', body:fd });
    window.location.reload();
  } catch(e) { alert('Error al cambiar estado.'); }
}

// Marca el servicio como completado (baño terminado) y ofrece avisar al dueño por WhatsApp.
// La ventana de WhatsApp se abre DENTRO del clic para que el navegador no la bloquee.
function banoTerminado(id){
  var G = window.GR_WA || {};
  var puedeAvisar = G.url && String(G.id) === String(id);
  var enviar = false;
  if (puedeAvisar) {
    enviar = confirm('✅ Baño terminado.\n\n¿Avisar por WhatsApp a ' + (G.dueno||'el dueño') + ' que ' + (G.mascota||'la mascota') + ' ya está listo/a?');
  }
  if (enviar) { window.open(G.url, '_blank'); }
  var fd = new FormData();
  fd.append('action', 'cambiar_estado');
  fd.append('id', id);
  fd.append('estado', 'completado');
  fetch(window.location.href, { method:'POST', body:fd })
    .then(function(){ window.location.reload(); })
    .catch(function(){ alert('Error al cambiar estado.'); });
}

// Solo abre WhatsApp con el aviso (para servicios ya completados).
function avisarListo(){
  var G = window.GR_WA || {};
  if (G.url) { window.open(G.url, '_blank'); }
  else { alert('Este cliente no tiene un teléfono válido registrado.'); }
}

function updateServUI(){
  document.querySelectorAll('.serv-opt').forEach(d=>{d.style.borderColor='var(--border)';d.style.background='var(--bg3)';d.style.transform='scale(1)';});
  const sel=document.querySelector('input[name="tipo_servicio"]:checked');
  if(sel){const d=document.querySelector('.serv-opt[data-val="'+sel.value+'"]');if(d){d.style.borderColor='var(--primary)';d.style.background='var(--primary-l)';d.style.transform='scale(1.04)';}}
}
function aplicarServCatalogo(){}
var SERV_CAT = window.SERV_CAT || [];
function scEsc(s){ return (s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function scFilter(){
  var inp=document.getElementById('serv-cat-inp'), drop=document.getElementById('serv-cat-drop');
  if(!inp||!drop||typeof SERV_CAT==='undefined') return;
  var q=(inp.value||'').trim().toLowerCase();
  var res=SERV_CAT.filter(function(s){ return q==='' || (s.nombre||'').toLowerCase().indexOf(q)>=0 || (s.tipo||'').toLowerCase().indexOf(q)>=0; }).slice(0,25);
  if(!res.length){ drop.innerHTML='<div style="padding:9px 11px;font-size:12px;color:var(--text3)">Sin resultados</div>'; drop.style.display='block'; return; }
  drop.innerHTML=res.map(function(s){
    return '<div onmousedown="scPick('+s.id+')" style="padding:8px 11px;cursor:pointer;border-bottom:1px solid var(--border);font-size:13px">'
      +'<strong>'+scEsc(s.nombre)+'</strong> — S/ '+(Number(s.precio)||0).toFixed(2)+' <span style="color:var(--text3)">('+scEsc(s.tipo)+')</span></div>';
  }).join('');
  drop.style.display='block';
}
function scPick(id){
  var s=SERV_CAT.filter(function(x){return x.id===id;})[0]; if(!s) return;
  var inp=document.getElementById('serv-cat-inp'); if(inp) inp.value=s.nombre;
  var p=document.querySelector('[name="precio"]'); if(p) p.value=(Number(s.precio)||0).toFixed(2);
  var tc=document.querySelector('[name="tipo_corte"]'); if(tc && !tc.value) tc.value=s.nombre;
  var drop=document.getElementById('serv-cat-drop'); if(drop) drop.style.display='none';
}
function scBlur(){ setTimeout(function(){ var d=document.getElementById('serv-cat-drop'); if(d) d.style.display='none'; },150); }
document.addEventListener('DOMContentLoaded',()=>{
  updateServUI();
  // Auto scroll al item seleccionado
  const sel = document.querySelector('.gr-item.active');
  if (sel) sel.scrollIntoView({ block:'nearest', behavior:'smooth' });
});
document.querySelectorAll('input[name="tipo_servicio"]').forEach(r=>r.addEventListener('change',updateServUI));
</script>
<?php
$_js_mas = array_map(fn($m)=>['id'=>$m['id'],'label'=>$m['label']], $mascotas_sel??[]);
$_js_grm = array_map(fn($v)=>['id'=>$v['id'],'label'=>$v['nombre']], $groomers??[]);
?>
<script>
document.addEventListener('DOMContentLoaded',function(){
    var _M=<?= json_encode(array_values($_js_mas)) ?>;
    var _G=<?= json_encode(array_values($_js_grm)) ?>;
    vetSearchSelect('inp-mas-grm','drop-mas-grm','hid-mas-grm',_M,'label');
    vetSearchSelect('inp-grm-grm','drop-grm-grm','hid-grm-grm',_G,'label');
});
</script>
<?php $RR_HID='hid-mas-grm'; $RR_INP='inp-mas-grm'; include __DIR__ . '/../includes/registro_rapido_modal.php'; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
