<?php
$page = 'grooming'; $pageTitle = 'Grooming / Peluquería';
require_once __DIR__ . '/../includes/header.php';
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

$action = $_GET['action'] ?? 'list';
$msg = '';

if ($_SERVER['REQUEST_METHOD']==='POST') {
  $pa=$_POST['action']??'';
  if ($pa==='save') {
    $id=(int)($_POST['id']??0);
    $fields=['mascota_id','groomer_id','fecha','duracion_minutos','tipo_servicio','tipo_corte',
             'observaciones','condicion_pelo','alergias_reportadas','productos_usados','precio','estado','notas_internas'];
    $data=[]; foreach($fields as $f) $data[$f]=trim($_POST[$f]??'')?:null;
    if ($id) {
      $sets=implode(',',array_map(fn($f)=>"$f=:$f",$fields));
      $st=$db->prepare("UPDATE grooming SET $sets WHERE id=:id"); $data['id']=$id;
    } else {
      $cols=implode(',',$fields); $pls=implode(',',array_map(fn($f)=>":$f",$fields));
      $st=$db->prepare("INSERT INTO grooming ($cols) VALUES ($pls)");
    }
    $st->execute($data); $msg='success'; $action='list';
  }
  if ($pa==='delete') { $db->prepare("DELETE FROM grooming WHERE id=?")->execute([(int)$_POST['id']]); $action='list'; }
  if ($pa==='cambiar_estado') {
    $estados_ok=['programado','en_proceso','completado','cancelado'];
    $nuevo = $_POST['estado']??'';
    if(in_array($nuevo,$estados_ok)) {
      $db->prepare("UPDATE grooming SET estado=? WHERE id=?")->execute([$nuevo,(int)$_POST['id']]);
    }
    header('Content-Type: application/json'); echo json_encode(['ok'=>true]); exit;
  }
}

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
      <div class="form-group" style="position:relative"><label class="form-label required">Mascota</label>
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
.gr-layout { display:grid; grid-template-columns:320px 1fr; gap:0; height:calc(100vh - 130px); background:var(--bg2); border:1px solid var(--border); border-radius:16px; overflow:hidden; }
/* Lista izquierda */
.gr-list { border-right:1px solid var(--border); display:flex; flex-direction:column; overflow:hidden; }
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
.gr-detail { overflow-y:auto; display:flex; flex-direction:column; }
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
</style>

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
<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px">
  <div>
    <div class="page-title" style="font-size:18px">✨ Grooming / Peluquería</div>
    <div class="page-desc"><?= count($all_groomings) ?> servicios registrados</div>
  </div>
  <a href="?p=grooming&action=nuevo" class="btn btn-primary">＋ Agendar Servicio</a>
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
      // Mensaje de WhatsApp: usa la plantilla EDITABLE del módulo WhatsApp (Grooming/Baño)
      $wa_clinica = 'VetPro'; $wa_tpl = '';
      try {
        $_cfg = $db->query("SELECT clave,valor FROM configuracion WHERE clave IN ('nombre_clinica','clinica_nombre','wa_tpl_grooming')")->fetchAll(PDO::FETCH_KEY_PAIR);
        foreach (['nombre_clinica','clinica_nombre'] as $_k) {
          if (!empty(trim($_cfg[$_k] ?? ''))) { $wa_clinica = trim($_cfg[$_k]); break; }
        }
        $wa_tpl = trim($_cfg['wa_tpl_grooming'] ?? '');
      } catch (Exception $e) {}
      if ($wa_tpl === '') {
        $wa_tpl = "✂️ *Grooming — {clinica}*\n\nHola {nombre_cliente} 👋\n\n¡*{nombre_mascota}* ya está listo! 🛁✨\n\n🧼 *Servicio:* {servicio}\n📅 *Fecha:* {fecha}\n🕐 *Hora:* {hora}\n💰 *Total:* S/. {precio}\n\nPuedes pasar a recogerlo cuando gustes.\n\n{clinica} 🐾";
      }
      $_serv_txt = ($servicio_labels[$groom_sel['tipo_servicio']] ?? $groom_sel['tipo_servicio'])
                 . (!empty($groom_sel['tipo_corte']) ? ' — '.$groom_sel['tipo_corte'] : '');
      $wa_msg = strtr($wa_tpl, [
        '{clinica}'        => $wa_clinica,
        '{veterinaria}'    => $wa_clinica,
        '{nombre_cliente}' => (string)$groom_sel['dueno'],
        '{nombre_mascota}' => (string)$groom_sel['mascota'],
        '{servicio}'       => $_serv_txt,
        '{fecha}'          => date('d/m/Y', strtotime($groom_sel['fecha'])),
        '{hora}'           => date('H:i', strtotime($groom_sel['fecha'])),
        '{precio}'         => number_format((float)($groom_sel['precio'] ?? 0), 2),
        '{veterinario}'    => (string)($groom_sel['groomer'] ?? ''),
      ]);
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
    ?>
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

    <!-- Barra de acciones -->
    <div class="gr-bottom-bar">
      <a href="?p=grooming&action=editar&id=<?= $groom_sel['id'] ?>" class="btn btn-primary btn-sm">✏️ Editar servicio</a>
      <a href="?p=mascotas&action=ver&id=<?= $groom_sel['mascota_id'] ?>" class="btn btn-ghost btn-sm">🐾 Ver ficha mascota</a>
      <a href="?p=historial&mascota_id=<?= $groom_sel['mascota_id'] ?>" class="btn btn-ghost btn-sm">🏥 Historia clínica</a>
      <a href="https://wa.me/<?= $tel_det ?>?text=<?= rawurlencode($wa_msg) ?>" target="_blank" class="btn btn-wa btn-sm" style="margin-left:auto">💬 WhatsApp</a>
      <form method="POST" style="display:inline">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="id" value="<?= $groom_sel['id'] ?>">
        <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--danger)"
                onclick="return confirm('¿Eliminar este servicio de grooming?')">🗑️ Eliminar</button>
      </form>
    </div>

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
  try {
    const fd = new FormData();
    fd.append('action', 'cambiar_estado');
    fd.append('id', id);
    fd.append('estado', estado);
    const r = await fetch(window.location.href, { method:'POST', body:fd });
    // Recargar para reflejar cambio
    window.location.reload();
  } catch(e) { alert('Error al cambiar estado.'); }
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
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
