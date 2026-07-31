<?php
$page = 'citas'; $pageTitle = 'Citas';
require_once __DIR__ . '/../includes/header.php';
$db = getDB();

// ════════════════════════════════════════════════════════════════
// Visitas recurrentes — columnas de agrupación (idempotente)
// grupo_recurrencia: UUID compartido por todas las sesiones de una serie
// sesion_numero / total_sesiones: posición dentro de la serie (1..N)
// ════════════════════════════════════════════════════════════════
try {
    $_c = $db->query("SHOW COLUMNS FROM `citas` LIKE 'grupo_recurrencia'")->fetchAll();
    if (empty($_c)) {
        $db->exec("ALTER TABLE `citas`
            ADD COLUMN `grupo_recurrencia` VARCHAR(36) NULL,
            ADD COLUMN `sesion_numero` SMALLINT NULL,
            ADD COLUMN `total_sesiones` SMALLINT NULL");
        $db->exec("CREATE INDEX idx_citas_grupo ON `citas` (`grupo_recurrencia`)");
    }
} catch (Exception $e) { /* si ya existen, no pasa nada */ }

// Suma $i meses a una fecha conservando el día, recortado al último día
// del mes destino (ej: 31-ene +1 mes = 28-feb, no 03-mar).
if (!function_exists('_vp_add_months')) {
    function _vp_add_months($base, $i) {
        $ts = strtotime($base);
        $y=(int)date('Y',$ts); $m=(int)date('n',$ts); $d=(int)date('j',$ts);
        $m += $i; $y += intdiv($m-1,12); $m = (($m-1)%12)+1;
        $maxd = (int)date('t', strtotime(sprintf('%04d-%02d-01',$y,$m)));
        return sprintf('%04d-%02d-%02d', $y, $m, min($d,$maxd));
    }
}

$action = $_GET['action'] ?? 'list';
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        $fields = ['mascota_id','veterinario_id','tipo','fecha','hora','duracion_minutos','estado','motivo','notas'];
        $data = []; foreach ($fields as $f) $data[$f] = trim($_POST[$f] ?? '');
        $data['sede_id'] = getSede();
        $es_nueva = !$id;

        // ── ¿Visita recurrente? Solo aplica al crear (no al editar) ──
        $recurrente = $es_nueva && !empty($_POST['recurrente']);
        if ($id) {
            // UPDATE de una cita existente (igual que antes)
            $sets = implode(',', array_map(fn($f)=>"$f=:$f", $fields));
            $st = $db->prepare("UPDATE citas SET $sets WHERE id=:id"); $data['id'] = $id;
            $st->execute($data);
        } elseif ($recurrente) {
            // ── Crear toda la serie de citas de golpe ──
            $frecuencia = $_POST['frecuencia'] ?? 'semanal';
            $cada_dias  = max(1, (int)($_POST['cada_dias'] ?? 1));
            $n_sesiones = max(2, min(52, (int)($_POST['total_sesiones'] ?? 0))); // tope de seguridad: 52
            // UUID v4 para agrupar la serie
            $b = random_bytes(16);
            $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
            $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
            $grupo = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
            // Calcular las fechas de cada sesión a partir de la fecha base (sesión 1)
            $base = $data['fecha'];
            $fechas = [];
            for ($i = 0; $i < $n_sesiones; $i++) {
                if ($frecuencia === 'mensual') {
                    $fechas[] = _vp_add_months($base, $i);
                } else {
                    $step = $frecuencia==='diaria' ? 1 : ($frecuencia==='semanal' ? 7 : ($frecuencia==='quincenal' ? 15 : $cada_dias));
                    $fechas[] = date('Y-m-d', strtotime($base." +".($i*$step)." days"));
                }
            }
            $ins = $db->prepare("INSERT INTO citas
                (sede_id,mascota_id,veterinario_id,tipo,fecha,hora,duracion_minutos,estado,motivo,notas,grupo_recurrencia,sesion_numero,total_sesiones)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $db->beginTransaction();
            try {
                foreach ($fechas as $k => $fch) {
                    $ins->execute([
                        $data['sede_id'], $data['mascota_id'], $data['veterinario_id'], $data['tipo'],
                        $fch, $data['hora'], ($data['duracion_minutos']?:30), 'pendiente',
                        $data['motivo'], $data['notas'], $grupo, $k+1, $n_sesiones
                    ]);
                    if ($k === 0) $id = (int)$db->lastInsertId();
                }
                $db->commit();
            } catch (Exception $e) {
                $db->rollBack();
                throw $e;
            }
            // Para el hook de WhatsApp de abajo, la sesión 1 usa la fecha base (ya está en $data['fecha'])
        } else {
            // INSERT de una sola cita (igual que antes)
            $cols = implode(',', array_merge($fields,['sede_id']));
            $pls  = implode(',', array_map(fn($f)=>":$f", array_merge($fields,['sede_id'])));
            $st = $db->prepare("INSERT INTO citas ($cols) VALUES ($pls)");
            $st->execute($data);
            $id = (int)$db->lastInsertId();
        }

        // ── WhatsApp: confirmación automática al agendar una cita NUEVA ──
        // En series recurrentes se envía SOLO la sesión 1 (no se satura al cliente).
        if ($es_nueva) {
            $wa_log = function($txt){ @file_put_contents(__DIR__.'/../wa_debug.log', date('Y-m-d H:i:s')." | $txt\n", FILE_APPEND); };
            try {
                require_once __DIR__ . '/../includes/wa_notify.php';
                // Datos para el mensaje
                $info = $db->prepare("SELECT m.nombre AS mascota, u.nombre AS vet, c.nombre AS dueno, c.telefono
                    FROM citas ci
                    JOIN mascotas m ON m.id=ci.mascota_id
                    JOIN usuarios u ON u.id=ci.veterinario_id
                    JOIN clientes c ON c.id=m.cliente_id
                    WHERE ci.id=?");
                $info->execute([$id]);
                if ($cita = $info->fetch()) {
                    if (!empty($cita['telefono'])) {
                        $cfg = $db->query("SELECT clave,valor FROM configuracion")->fetchAll(PDO::FETCH_KEY_PAIR);
                        $clinica = trim($cfg['nombre_clinica'] ?? $cfg['clinica_nombre'] ?? 'VetPro') ?: 'VetPro';
                        $texto = wa_msg_confirmacion($clinica, $cita['dueno'], $cita['mascota'], $data['fecha'], $data['hora'], $cita['vet']);
                        $detalle_wa = '';
                        $ok = wa_enviar($cita['telefono'], $texto, $detalle_wa); // si el micro está caído, no rompe nada
                        $wa_log("cita #$id tel={$cita['telefono']} -> ".($ok?'OK ✅':'FALLÓ ❌').' | '.$detalle_wa.' | URL='.WA_MICRO_URL);
                    } else {
                        $wa_log("cita #$id -> SIN TELÉFONO en el cliente, no se envió");
                    }
                } else {
                    $wa_log("cita #$id -> no se pudo leer datos de la cita");
                }
            } catch (Exception $e) {
                $wa_log("cita #$id -> EXCEPCIÓN: ".$e->getMessage());
            }
        }

        $msg = ($recurrente ? 'serie:'.$n_sesiones : 'success'); $action = 'list';
    }
    if ($_POST['action'] === 'cambiar_estado') {
        $db->prepare("UPDATE citas SET estado=? WHERE id=?")->execute([$_POST['estado'], (int)$_POST['id']]);
        jsonResponse(['ok'=>true]);
    }
}
if ($action === 'delete' && isset($_GET['id'])) {
    $db->prepare("DELETE FROM citas WHERE id=?")->execute([(int)$_GET['id']]); $action = 'list';
}
$editing = null;
if (in_array($action,['editar']) && isset($_GET['id'])) {
    $st = $db->prepare("SELECT * FROM citas WHERE id=?"); $st->execute([(int)$_GET['id']]); $editing = $st->fetch();
}

$mascotas_sel = $db->query("SELECT m.id, CONCAT(m.nombre,' (',c.nombre,')') as label FROM mascotas m JOIN clientes c ON c.id=m.cliente_id WHERE m.estado='activo' ORDER BY m.nombre")->fetchAll();
$vets_sel = $db->query("SELECT id,nombre FROM usuarios WHERE rol IN ('veterinario','admin') AND activo=1")->fetchAll();

// ── Filtros de la lista ──────────────────────────────────────────────
// Modos: 'dia' (un día), 'rango' (desde-hasta), 'todas' (todas las citas)
$modo          = $_GET['modo'] ?? 'dia';
$fecha_filtro  = $_GET['fecha'] ?? date('Y-m-d');
$desde         = $_GET['desde'] ?? date('Y-m-d');
$hasta         = $_GET['hasta'] ?? date('Y-m-d', strtotime('+30 days'));
$vet_filtro    = $_GET['vet'] ?? '';
$estado_filtro = $_GET['estado'] ?? '';
$q_filtro      = trim($_GET['q'] ?? '');

$where = "1=1"; $params = [];
if ($modo === 'todas') {
    // sin filtro de fecha — todas las citas
} elseif ($modo === 'rango') {
    if ($desde) { $where .= " AND c.fecha>=?"; $params[] = $desde; }
    if ($hasta) { $where .= " AND c.fecha<=?"; $params[] = $hasta; }
} else { // 'dia'
    if ($fecha_filtro) { $where .= " AND c.fecha=?"; $params[] = $fecha_filtro; }
}
if ($vet_filtro)    { $where .= " AND c.veterinario_id=?"; $params[] = $vet_filtro; }
if ($estado_filtro) { $where .= " AND c.estado=?";         $params[] = $estado_filtro; }
if ($q_filtro)      { $where .= " AND (m.nombre LIKE ? OR cl.nombre LIKE ? OR cl.telefono LIKE ?)";
                      $lk = "%$q_filtro%"; $params[] = $lk; $params[] = $lk; $params[] = $lk; }
try {
    $_r=$db->query("SHOW COLUMNS FROM `citas` LIKE 'sede_id'")->fetchAll();
    if(!empty($_r)) {
        if(!verTodasSedes()) { $where .=" AND c.sede_id=".getSede(); }
    }
} catch(Exception $e) {}


// Origen de la cita: distinguir reservas web de las agendadas en clínica
try {
    $_o = $db->query("SHOW COLUMNS FROM `citas` LIKE 'origen'")->fetchAll();
    if (empty($_o)) $db->exec("ALTER TABLE `citas` ADD COLUMN origen ENUM('interno','web') NOT NULL DEFAULT 'interno'");
} catch (Exception $e) {}


// En modo día se ordena solo por hora; en los demás, por fecha y luego hora
$order = ($modo === 'dia') ? "c.hora ASC" : "c.fecha ASC, c.hora ASC";
$st = $db->prepare("SELECT c.*,m.nombre as mascota,m.especie,u.nombre as veterinario,cl.nombre as dueno,cl.telefono FROM citas c JOIN mascotas m ON m.id=c.mascota_id JOIN usuarios u ON u.id=c.veterinario_id JOIN clientes cl ON cl.id=m.cliente_id WHERE $where ORDER BY $order");
$st->execute($params); $citas = $st->fetchAll();

// Stats calculadas sobre las citas mostradas (cuadran con el filtro activo)
$stats = [];
foreach($citas as $c) { $stats[$c['estado']] = ($stats[$c['estado']] ?? 0) + 1; }

$especie_icons = ['perro'=>'🐕','gato'=>'🐈','conejo'=>'🐰','ave'=>'🐦','reptil'=>'🦎','roedor'=>'🐭','otro'=>'🐾'];
$tipo_labels   = ['consulta'=>'Consulta','vacuna'=>'Vacuna','control'=>'Control','cirugia'=>'Cirugía','bano'=>'Baño','grooming'=>'Grooming','emergencia'=>'Emergencia','hospitalizacion'=>'Hospitalización'];
$tipo_badge    = ['consulta'=>'b-teal','vacuna'=>'b-blue','cirugia'=>'b-red','bano'=>'b-amber','control'=>'b-purple','grooming'=>'b-green','emergencia'=>'b-red','hospitalizacion'=>'b-purple'];
$estado_badge  = ['pendiente'=>'b-gray','confirmada'=>'b-blue','atendida'=>'b-teal','cancelada'=>'b-red','no_asistio'=>'b-amber'];
?>
<?php if($msg==='success'): ?><div class="alert alert-success mb-2">✅ Cita guardada correctamente.</div><?php elseif(strpos($msg,'serie:')===0): ?><div class="alert alert-success mb-2">✅ Serie creada: <strong><?= (int)substr($msg,6) ?> sesiones</strong> agendadas correctamente. 🔁</div><?php endif; ?>

<?php if(in_array($action,['nueva','editar'])): ?>
<?php
// Preparar datos para buscadores
$_mascotas_js = array_map(fn($m)=>['id'=>$m['id'],'label'=>$m['label']], $mascotas_sel);
$_vets_js     = array_map(fn($v)=>['id'=>$v['id'],'label'=>$v['nombre'],'rol'=>$v['rol']??''], $vets_sel);
$_editing_mas = $editing ? array_filter($mascotas_sel, fn($m)=>$m['id']==$editing['mascota_id']) : [];
$_editing_mas = $_editing_mas ? reset($_editing_mas) : null;
$_editing_vet = $editing ? array_filter($vets_sel, fn($v)=>$v['id']==$editing['veterinario_id']) : [];
$_editing_vet = $_editing_vet ? reset($_editing_vet) : null;
// Primer vet por defecto
$_default_vet = $vets_sel[0] ?? null;
?>
<div class="card" style="max-width:680px">
  <div class="sec-header"><div class="sec-title"><?= $action==='editar'?'Editar':'Nueva'?> Cita</div><a href="?p=citas" class="btn btn-sm">← Volver</a></div>
  <form method="POST">
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?= $editing['id']??'' ?>">
    <div class="form-row">
      <!-- Mascota buscador -->
      <div class="form-group" style="position:relative">
        <label class="form-label required">Mascota</label>
        <input type="text" id="inp-mas-cita" class="form-input"
               placeholder="🐾 Buscar mascota..."
               value="<?= clean($_editing_mas['label']??'') ?>"
               autocomplete="off">
        <input type="hidden" name="mascota_id" id="hid-mas-cita" value="<?= $editing['mascota_id']??'' ?>" required>
        <div id="drop-mas-cita" style="display:none;position:absolute;top:100%;left:0;right:0;background:var(--bg2);border:1px solid var(--border);border-radius:8px;box-shadow:0 8px 24px rgba(0,0,0,.12);z-index:300;max-height:220px;overflow-y:auto"></div>
      </div>
      <!-- Veterinario buscador -->
      <div class="form-group" style="position:relative">
        <label class="form-label required">Veterinario</label>
        <input type="text" id="inp-vet-cita" class="form-input"
               placeholder="👨‍⚕️ Buscar veterinario..."
               value=""
               autocomplete="off">
        <input type="hidden" name="veterinario_id" id="hid-vet-cita" value="<?= $editing['veterinario_id'] ?? '' ?>" required>
        <div id="drop-vet-cita" style="display:none;position:absolute;top:100%;left:0;right:0;background:var(--bg2);border:1px solid var(--border);border-radius:8px;box-shadow:0 8px 24px rgba(0,0,0,.12);z-index:300;max-height:200px;overflow-y:auto"></div>
      </div>
    </div>
    <div class="form-row">
      <div class="form-group"><label class="form-label">Fecha *</label><input class="form-input" type="date" name="fecha" value="<?= clean($editing['fecha'] ?? ($_GET['fecha'] ?? date('Y-m-d'))) ?>" required></div>
      <div class="form-group"><label class="form-label">Hora *</label><input class="form-input" type="time" name="hora" value="<?= clean(substr($editing['hora']??'09:00',0,5)) ?>" required></div>
    </div>
    <div class="form-row">
      <div class="form-group"><label class="form-label">Tipo *</label>
        <select class="form-input" name="tipo" required>
          <?php foreach($tipo_labels as $k=>$v): ?><option value="<?= $k ?>" <?= ($editing['tipo']??'consulta')===$k?'selected':'' ?>><?= $v ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="form-group"><label class="form-label">Duración</label>
        <select class="form-input" name="duracion_minutos">
          <?php foreach([15,20,30,45,60,90,120,180,240] as $d): ?><option value="<?= $d ?>" <?= ($editing['duracion_minutos']??30)==$d?'selected':'' ?>><?= $d ?> min</option><?php endforeach; ?>
        </select>
      </div>
    </div>
    <?php if($editing): ?><div class="form-group"><label class="form-label">Estado</label>
      <select class="form-input" name="estado">
        <?php foreach(['pendiente','confirmada','atendida','cancelada','no_asistio'] as $e): ?><option value="<?= $e ?>" <?= ($editing['estado']??'pendiente')===$e?'selected':'' ?>><?= ucfirst(str_replace('_',' ',$e)) ?></option><?php endforeach; ?>
      </select></div><?php else: ?><input type="hidden" name="estado" value="pendiente"><?php endif; ?>
    <div class="form-group"><label class="form-label">Motivo / Síntomas</label><textarea class="form-input" name="motivo"><?= clean($editing['motivo']??'') ?></textarea></div>
    <div class="form-group"><label class="form-label">Notas internas</label><input class="form-input" name="notas" value="<?= clean($editing['notas']??'') ?>"></div>
    <?php if(!$editing): ?>
    <div class="form-group" style="border:1px dashed var(--border);border-radius:10px;padding:12px 14px;background:var(--bg2)">
      <label class="flex items-center gap-1" style="cursor:pointer;font-weight:600;margin:0">
        <input type="checkbox" name="recurrente" value="1" id="chk-recur" onchange="toggleRecur()" style="width:auto;margin:0">
        🔁 Visita recurrente (crear varias sesiones)
      </label>
      <div id="recur-box" style="display:none;margin-top:12px">
        <div class="form-row">
          <div class="form-group"><label class="form-label">Frecuencia</label>
            <select class="form-input" name="frecuencia" id="recur-frec" onchange="toggleCadaDias();previewRecur()">
              <option value="diaria">Diaria</option>
              <option value="semanal" selected>Semanal (cada 7 días)</option>
              <option value="quincenal">Quincenal (cada 15 días)</option>
              <option value="mensual">Mensual</option>
              <option value="personalizado">Personalizado (cada N días)</option>
            </select>
          </div>
          <div class="form-group" id="cada-dias-box" style="display:none"><label class="form-label">Cada cuántos días</label>
            <input class="form-input" type="number" name="cada_dias" id="recur-cada" min="1" value="3" onchange="previewRecur()">
          </div>
          <div class="form-group"><label class="form-label">N° de sesiones</label>
            <input class="form-input" type="number" name="total_sesiones" id="recur-n" min="2" max="52" value="4" onchange="previewRecur()">
          </div>
        </div>
        <div id="recur-preview" class="text-xs text-muted" style="margin-top:2px"></div>
        <div class="text-xs text-muted" style="margin-top:8px;line-height:1.5">ℹ️ La <strong>sesión 1</strong> usa la fecha y hora de arriba; las demás se calculan automáticamente con la misma hora, mascota y veterinario. No se ajustan por fines de semana ni feriados — puedes reagendar cualquier sesión después. El recordatorio de WhatsApp se envía solo para la sesión 1.</div>
      </div>
    </div>
    <?php endif; ?>
    <div class="flex gap-1"><button type="submit" class="btn btn-primary">💾 Guardar cita</button><a href="?p=citas" class="btn">Cancelar</a></div>
  </form>
</div>
<script>
var _MAS_CITA = <?= json_encode(array_values($_mascotas_js)) ?>;
var _VET_CITA = <?= json_encode(array_values($_vets_js)) ?>;
document.addEventListener('DOMContentLoaded', function() {
    vetSearchSelect('inp-mas-cita','drop-mas-cita','hid-mas-cita', _MAS_CITA, 'label');
    vetSearchSelect('inp-vet-cita','drop-vet-cita','hid-vet-cita', _VET_CITA, 'label');
    var fInp = document.querySelector('input[name=fecha]');
    if (fInp) fInp.addEventListener('change', previewRecur);
});
function toggleRecur(){
    var on = document.getElementById('chk-recur').checked;
    document.getElementById('recur-box').style.display = on ? '' : 'none';
    previewRecur();
}
function toggleCadaDias(){
    document.getElementById('cada-dias-box').style.display =
        document.getElementById('recur-frec').value === 'personalizado' ? '' : 'none';
}
function previewRecur(){
    var box = document.getElementById('recur-preview'); if(!box) return;
    var chk = document.getElementById('chk-recur');
    if(!chk || !chk.checked){ box.textContent=''; return; }
    var fInp = document.querySelector('input[name=fecha]');
    var f = fInp ? fInp.value : '';
    if(!f){ box.textContent='Selecciona la fecha de la 1ª sesión arriba.'; return; }
    var n = Math.max(2, Math.min(52, parseInt(document.getElementById('recur-n').value||'0')));
    var frec = document.getElementById('recur-frec').value;
    var cada = Math.max(1, parseInt(document.getElementById('recur-cada').value||'1'));
    var d0 = new Date(f+'T00:00:00'), fechas=[];
    for(var i=0;i<n;i++){
        var d = new Date(d0);
        if(frec==='mensual'){ var _m=d0.getMonth()+i, _y=d0.getFullYear()+Math.floor(_m/12); _m=((_m%12)+12)%12; var _maxd=new Date(_y,_m+1,0).getDate(); d=new Date(_y,_m,Math.min(d0.getDate(),_maxd)); }
        else { var step = frec==='diaria'?1:(frec==='semanal'?7:(frec==='quincenal'?15:cada)); d.setDate(d.getDate()+i*step); }
        fechas.push(d);
    }
    var fmt = function(x){ return ('0'+x.getDate()).slice(-2)+'/'+('0'+(x.getMonth()+1)).slice(-2)+'/'+x.getFullYear(); };
    var muestra = fechas.slice(0,4).map(fmt).join(' · ');
    box.innerHTML = '📅 <strong>'+n+' sesiones</strong> · de '+fmt(fechas[0])+' a '+fmt(fechas[n-1])+'<br>'+muestra+(n>4?' …':'');
}
</script>

<?php else: ?>
<div class="grid g4 mb-2">
  <div class="stat-card"><div class="stat-icon si-blue">📅</div><div class="stat-value"><?= array_sum($stats) ?></div><div class="stat-label">Total</div></div>
  <div class="stat-card"><div class="stat-icon si-amber">⏳</div><div class="stat-value"><?= ($stats['pendiente']??0)+($stats['confirmada']??0) ?></div><div class="stat-label">Pendientes</div></div>
  <div class="stat-card"><div class="stat-icon si-teal">✅</div><div class="stat-value"><?= $stats['atendida']??0 ?></div><div class="stat-label">Atendidas</div></div>
  <div class="stat-card"><div class="stat-icon si-red">✕</div><div class="stat-value"><?= ($stats['cancelada']??0)+($stats['no_asistio']??0) ?></div><div class="stat-label">Canceladas</div></div>
</div>
<div class="card mb-2" style="padding:14px 18px">
  <form method="GET" class="flex items-center gap-2" style="flex-wrap:wrap">
    <input type="hidden" name="p" value="citas">
    <!-- Selector de modo de vista -->
    <select class="form-input" name="modo" id="filtro-modo" style="width:150px" onchange="toggleFechas()">
      <option value="dia"   <?= $modo==='dia'?'selected':'' ?>>📅 Un día</option>
      <option value="rango" <?= $modo==='rango'?'selected':'' ?>>📆 Rango de fechas</option>
      <option value="todas" <?= $modo==='todas'?'selected':'' ?>>📋 Todas las citas</option>
    </select>
    <!-- Campo de un día -->
    <input class="form-input filtro-dia" type="date" name="fecha" value="<?= clean($fecha_filtro) ?>" style="width:160px;<?= $modo!=='dia'?'display:none':'' ?>">
    <!-- Campos de rango -->
    <span class="filtro-rango flex items-center gap-1" style="<?= $modo!=='rango'?'display:none':'' ?>">
      <input class="form-input" type="date" name="desde" value="<?= clean($desde) ?>" style="width:150px" title="Desde">
      <span class="text-muted" style="font-size:12px">a</span>
      <input class="form-input" type="date" name="hasta" value="<?= clean($hasta) ?>" style="width:150px" title="Hasta">
    </span>
    <!-- Buscador -->
    <input class="form-input" type="text" name="q" value="<?= clean($q_filtro) ?>" placeholder="🔍 Buscar mascota o cliente..." style="width:210px">
    <select class="form-input" name="vet" style="width:170px"><option value="">Todos los vets</option><?php foreach($vets_sel as $v): ?><option value="<?= $v['id'] ?>" <?= $vet_filtro==$v['id']?'selected':'' ?>><?= clean($v['nombre']) ?></option><?php endforeach; ?></select>
    <select class="form-input" name="estado" style="width:150px"><option value="">Todos los estados</option><?php foreach(['pendiente','confirmada','atendida','cancelada','no_asistio'] as $e): ?><option value="<?= $e ?>" <?= $estado_filtro===$e?'selected':'' ?>><?= ucfirst(str_replace('_',' ',$e)) ?></option><?php endforeach; ?></select>
    <button type="submit" class="btn btn-primary">Filtrar</button>
    <a href="?p=citas" class="btn">Hoy</a>
    <a href="?p=calendario" class="btn">📅 Calendario</a>
    <a href="?p=citas&action=nueva" class="btn btn-primary" style="margin-left:auto">+ Nueva Cita</a>
  </form>
</div>
<div class="mb-2 text-muted" style="font-size:13px;padding:0 4px">
  <?php
    if($modo==='todas')      echo '📋 Mostrando <strong>todas</strong> las citas';
    elseif($modo==='rango')  echo '📆 Citas del <strong>'.date('d/m/Y',strtotime($desde)).'</strong> al <strong>'.date('d/m/Y',strtotime($hasta)).'</strong>';
    else                     echo '📅 Citas del <strong>'.date('d/m/Y',strtotime($fecha_filtro)).'</strong>';
    echo ' · '.count($citas).' cita'.(count($citas)!=1?'s':'');
    if($q_filtro!=='') echo ' · búsqueda: "'.clean($q_filtro).'"';
  ?>
</div>
<div class="card" style="padding:0">
  <div class="table-wrap">
    <table class="vtable">
      <thead><tr><th>Hora</th><th>Paciente</th><th>Dueño / Tel.</th><th>Tipo</th><th>Veterinario</th><th>Estado</th><th>Acciones</th></tr></thead>
      <tbody>
        <?php foreach($citas as $c):
          $tel = preg_replace('/[^0-9]/','',ltrim($c['telefono'],'+'));
          if(strlen($tel)<11) $tel='51'.$tel;
          $wa_msg = "⏰ *Recordatorio VetPro*\n\nHola {$c['dueno']} 👋\n\nTe recordamos la cita de *{$c['mascota']}*:\n📅 ".date('d/m/Y',strtotime($c['fecha']))." a las ".substr($c['hora'],0,5)."\n👨‍⚕️ {$c['veterinario']}\n\n¿Confirmas? Responde SÍ o NO\n\nVetPro 🐾";
        ?>
        <tr>
          <td class="td-main" style="font-size:15px;white-space:nowrap">
            <?php if($modo!=='dia'): ?><div class="text-xs text-muted" style="font-weight:600"><?= date('d/m/Y',strtotime($c['fecha'])) ?></div><?php endif; ?>
            <?= substr($c['hora'],0,5) ?>
          </td>
          <td><div class="flex items-center gap-1"><span style="font-size:18px"><?= $especie_icons[$c['especie']]??'🐾' ?></span><div><div class="td-main"><?= clean($c['mascota']) ?></div><div class="text-xs text-muted"><?= $c['duracion_minutos'] ?>min</div><?php if(!empty($c['sesion_numero'])): ?><div class="text-xs" style="color:#7c3aed;font-weight:600">🔁 Sesión <?= (int)$c['sesion_numero'] ?>/<?= (int)$c['total_sesiones'] ?></div><?php endif; ?></div></div></td>
          <td><div><?= clean($c['dueno']) ?></div><div class="text-xs text-muted"><?= clean($c['telefono']) ?></div></td>

<td>
            <span class="badge <?= $tipo_badge[$c['tipo']]??'b-gray' ?>"><?= $tipo_labels[$c['tipo']]??$c['tipo'] ?></span>
            <?php if(($c['origen']??'interno')==='web'): ?>
              <span class="badge" style="background:#e0f2fe;color:#0369a1" title="Reservada por el cliente vía web">🌐 Web</span>
            <?php else: ?>
              <span class="badge" style="background:#f1f5f9;color:#64748b" title="Agendada en la clínica">🏥 Clínica</span>
            <?php endif; ?>
          </td>

<td class="text-muted"><?= clean($c['veterinario']) ?></td>
          <td><select class="form-input" style="width:130px;padding:5px 8px;font-size:12px" onchange="cambiarEstado(<?= $c['id'] ?>,this.value)"><?php foreach(['pendiente','confirmada','atendida','cancelada','no_asistio'] as $e): ?><option value="<?= $e ?>" <?= $c['estado']===$e?'selected':'' ?>><?= ucfirst(str_replace('_',' ',$e)) ?></option><?php endforeach; ?></select></td>
          <td><div class="flex gap-1">
            <?php if($c['estado']!=='atendida'): ?><a href="?p=historial&action=nueva&cita_id=<?= $c['id'] ?>&mascota_id=<?= $c['mascota_id'] ?>" class="btn btn-xs btn-primary">Atender</a><?php endif; ?>
            <a href="?p=citas&action=editar&id=<?= $c['id'] ?>" class="btn btn-xs">Editar</a>
            <a href="https://wa.me/<?= $tel ?>?text=<?= rawurlencode($wa_msg) ?>" target="_blank" class="btn btn-xs btn-wa" title="Recordatorio WhatsApp">💬</a>
            <a href="?p=citas&action=delete&id=<?= $c['id'] ?>" class="btn btn-xs" style="color:var(--red)" onclick="return confirm('¿Eliminar esta cita?')">✕</a>
          </div></td>
        </tr>
        <?php endforeach; ?>
        <?php if(empty($citas)): ?><tr><td colspan="7" class="text-center text-muted" style="padding:32px">No hay citas que coincidan con el filtro.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>
<script>
function cambiarEstado(id, estado) {
  fetch('?p=citas',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=cambiar_estado&id='+id+'&estado='+estado});
}
function toggleFechas() {
  var modo = document.getElementById('filtro-modo').value;
  document.querySelectorAll('.filtro-dia').forEach(function(el){ el.style.display = (modo==='dia')?'':'none'; });
  document.querySelectorAll('.filtro-rango').forEach(function(el){ el.style.display = (modo==='rango')?'':'none'; });
}
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
