<?php
$page = 'cirugias'; $pageTitle = 'Cirugías';
require_once __DIR__ . '/../includes/header.php';
$db = getDB();

// Crear tabla si no existe
$db->exec("CREATE TABLE IF NOT EXISTS cirugias (
  id INT AUTO_INCREMENT PRIMARY KEY,
  mascota_id INT NOT NULL,
  veterinario_id INT NOT NULL,
  anestesiologo_id INT NULL,
  sede_id INT DEFAULT 1,
  tipo_cirugia VARCHAR(200) NOT NULL,
  descripcion TEXT,
  fecha_programada DATETIME NOT NULL,
  duracion_estimada INT DEFAULT 60,
  estado ENUM('programada','en_curso','completada','cancelada','pospuesta') DEFAULT 'programada',
  tipo_anestesia ENUM('local','sedacion','general','regional') DEFAULT 'general',
  protocolo_anestesia TEXT,
  riesgo_anestesico ENUM('bajo','moderado','alto','muy_alto') DEFAULT 'bajo',
  consentimiento_firmado TINYINT(1) DEFAULT 0,
  firma_digital TEXT,
  nombre_responsable VARCHAR(150),
  dni_responsable VARCHAR(20),
  hallazgos_intraop TEXT,
  complicaciones TEXT,
  seguimiento_postop TEXT,
  proxima_revision DATE,
  notas TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (mascota_id) REFERENCES mascotas(id),
  FOREIGN KEY (veterinario_id) REFERENCES usuarios(id)
)");

$action = $_GET['action'] ?? 'list';
$msg = '';

if ($_SERVER['REQUEST_METHOD']==='POST') {
  $pa = $_POST['action']??'';
  if ($pa==='save') {
    $id = (int)($_POST['id']??0);
    $fields = ['mascota_id','veterinario_id','anestesiologo_id','tipo_cirugia','descripcion',
               'fecha_programada','duracion_estimada','estado','tipo_anestesia','protocolo_anestesia',
               'riesgo_anestesico','nombre_responsable','dni_responsable',
               'hallazgos_intraop','complicaciones','seguimiento_postop','proxima_revision','notas'];
    $data=[]; foreach($fields as $f) $data[$f]=trim($_POST[$f]??'')?:null;
    $data['consentimiento_firmado'] = isset($_POST['consentimiento_firmado'])?1:0;
    $data['firma_digital'] = trim($_POST['firma_digital']??'');
    // datetime-local manda "2026-06-25T11:30" -> normalizar a "2026-06-25 11:30"
    if (!empty($data['fecha_programada'])) $data['fecha_programada'] = str_replace('T',' ',$data['fecha_programada']);

    // Validar obligatorios (columnas NOT NULL / FK) para no reventar en SQL
    if (empty($data['mascota_id']) || empty($data['veterinario_id']) || empty($data['tipo_cirugia']) || empty($data['fecha_programada'])) {
      $msg='error_req';
    } else {
      try {
        if ($id) {
          // UPDATE: NO se incluye sede_id (no está en el SET) para que coincidan los parámetros
          $sets = implode(',',array_map(fn($f)=>"$f=:$f",$fields));
          $sets .= ",consentimiento_firmado=:consentimiento_firmado,firma_digital=:firma_digital";
          $st=$db->prepare("UPDATE cirugias SET $sets WHERE id=:id");
          $data['id']=$id;
          $st->execute($data);
        } else {
          $data['sede_id'] = $user['sede_id']??1;
          $allf = array_merge($fields,['consentimiento_firmado','firma_digital','sede_id']);
          $cols=implode(',',$allf); $pls=implode(',',array_map(fn($f)=>":$f",$allf));
          $db->prepare("INSERT INTO cirugias ($cols) VALUES ($pls)")->execute($data);
        }
        $msg='success'; $action='list';
      } catch (Exception $e) {
        $msg='error_db';
      }
    }
  }
  if ($pa==='delete') { $db->prepare("DELETE FROM cirugias WHERE id=?")->execute([(int)$_POST['id']]); $action='list'; }
}

$editing=null;
if (in_array($action,['editar']) && isset($_GET['id'])) {
  $st=$db->prepare("SELECT * FROM cirugias WHERE id=?"); $st->execute([(int)$_GET['id']]); $editing=$st->fetch();
}

$mascotas_sel=$db->query("SELECT m.id,CONCAT(m.nombre,' (',c.nombre,')') as label FROM mascotas m JOIN clientes c ON c.id=m.cliente_id WHERE m.estado='activo' ORDER BY m.nombre")->fetchAll();
$vets_sel=$db->query("SELECT id,nombre FROM usuarios WHERE rol IN ('veterinario','admin') AND activo=1")->fetchAll();

// Etiquetas actuales para precargar el buscador al EDITAR
$mas_label=''; $vet_label='';
if ($editing) {
  foreach($mascotas_sel as $m){ if((int)$m['id']===(int)$editing['mascota_id']){ $mas_label=$m['label']; break; } }
  foreach($vets_sel as $v){ if((int)$v['id']===(int)$editing['veterinario_id']){ $vet_label=$v['nombre']; break; } }
}

$estado_f=$_GET['estado']??'';
$where="1=1"; $params=[];
if ($estado_f){$where.=" AND estado=?";$params[]=$estado_f;}
$cirugias=$db->prepare("SELECT ci.*,m.nombre as mascota,m.especie,u.nombre as vet,c.nombre as dueno
  FROM cirugias ci JOIN mascotas m ON m.id=ci.mascota_id JOIN usuarios u ON u.id=ci.veterinario_id
  JOIN clientes c ON c.id=m.cliente_id WHERE $where ORDER BY ci.fecha_programada DESC LIMIT 80");
$cirugias->execute($params); $cirugias=$cirugias->fetchAll();

$estado_badge=['programada'=>'b-info','en_curso'=>'b-warning','completada'=>'b-success','cancelada'=>'b-danger','pospuesta'=>'b-gray'];
$riesgo_badge=['bajo'=>'b-success','moderado'=>'b-warning','alto'=>'b-orange','muy_alto'=>'b-danger'];
$ei=['perro'=>'🐕','gato'=>'🐈','conejo'=>'🐰','ave'=>'🐦','reptil'=>'🦎','roedor'=>'🐭','otro'=>'🐾'];
?>
<div class="page">
<?php if($msg==='success'): ?><div class="alert alert-success"><span class="alert-icon">✅</span>Cirugía guardada correctamente.</div><?php endif; ?>
<?php if($msg==='error_req'): ?><div class="alert alert-danger"><span class="alert-icon">⚠️</span>Faltan datos obligatorios: selecciona <strong>mascota</strong> y <strong>veterinario</strong>, e indica el tipo y la fecha.</div><?php endif; ?>
<?php if($msg==='error_db'): ?><div class="alert alert-danger"><span class="alert-icon">⛔</span>No se pudo guardar la cirugía. Revisa los datos e inténtalo de nuevo.</div><?php endif; ?>

<?php if(in_array($action,['nueva','editar'])): ?>
<!-- ── FORMULARIO ── -->
<div class="card" style="max-width:820px">
  <div class="sec-header">
    <div>
      <div class="sec-title"><?= $action==='editar'?'Editar':'Nueva'?> Cirugía</div>
      <div class="sec-sub">Programación y seguimiento quirúrgico</div>
    </div>
    <a href="?p=cirugias" class="btn btn-ghost btn-sm">← Volver</a>
  </div>

  <form method="POST">
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?= $editing['id']??'' ?>">

    <!-- Tabs -->
    <div class="tabs-pills mb-3">
      <button type="button" class="pill-btn active" onclick="showTab('tab-basico',this)">📋 Datos básicos</button>
      <button type="button" class="pill-btn" onclick="showTab('tab-anestesia',this)">💊 Anestesia</button>
      <button type="button" class="pill-btn" onclick="showTab('tab-consentimiento',this)">✍️ Consentimiento</button>
      <button type="button" class="pill-btn" onclick="showTab('tab-postop',this)">🔄 Post-operatorio</button>
    </div>

    <!-- Tab básico -->
    <div id="tab-basico" class="tab-content active">
      <div class="form-row">
        <div class="form-group" style="position:relative"><label class="form-label required">Mascota / Paciente</label>
          <input type="text" id="inp-mas-cir" class="form-input" placeholder="🐾 Buscar mascota..." autocomplete="off" value="<?= clean($mas_label) ?>">
          <input type="hidden" name="mascota_id" id="hid-mas-cir" value="<?= (int)($editing['mascota_id']??0)?:'' ?>" required>
          <div id="drop-mas-cir" style="display:none;position:absolute;top:100%;left:0;right:0;background:var(--bg2);border:1px solid var(--border);border-radius:8px;box-shadow:0 8px 24px rgba(0,0,0,.12);z-index:300;max-height:220px;overflow-y:auto"></div>
        </div>
        <div class="form-group" style="position:relative"><label class="form-label required">Veterinario responsable</label>
          <input type="text" id="inp-vet-cir" class="form-input" placeholder="👨‍⚕️ Buscar veterinario..." autocomplete="off" value="<?= clean($vet_label) ?>">
          <input type="hidden" name="veterinario_id" id="hid-vet-cir" value="<?= (int)($editing['veterinario_id']??0)?:'' ?>" required>
          <div id="drop-vet-cir" style="display:none;position:absolute;top:100%;left:0;right:0;background:var(--bg2);border:1px solid var(--border);border-radius:8px;box-shadow:0 8px 24px rgba(0,0,0,.12);z-index:300;max-height:200px;overflow-y:auto"></div>
        </div>
      </div>
      <div class="form-group"><label class="form-label required">Tipo / Nombre de la cirugía</label>
        <input class="form-input" name="tipo_cirugia" value="<?= clean($editing['tipo_cirugia']??'') ?>" placeholder="Ej: Ovariohisterectomía, Orquiectomía, Cesárea..." required>
      </div>
      <div class="form-row">
        <div class="form-group"><label class="form-label required">Fecha y hora programada</label>
          <input class="form-input" type="datetime-local" name="fecha_programada" value="<?= clean(str_replace(' ','T',substr($editing['fecha_programada']??date('Y-m-d\TH:i'),0,16))) ?>" required>
        </div>
        <div class="form-group"><label class="form-label">Duración estimada (min)</label>
          <select class="form-input" name="duracion_estimada">
            <?php foreach([30,45,60,90,120,150,180,240,300,360] as $d): ?>
            <option value="<?= $d ?>" <?= ($editing['duracion_estimada']??60)==$d?'selected':'' ?>><?= $d ?> min</option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="form-group"><label class="form-label">Estado</label>
        <select class="form-input" name="estado">
          <?php foreach(['programada'=>'Programada','en_curso'=>'En curso','completada'=>'Completada','cancelada'=>'Cancelada','pospuesta'=>'Pospuesta'] as $k=>$v): ?>
          <option value="<?= $k ?>" <?= ($editing['estado']??'programada')===$k?'selected':'' ?>><?= $v ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group"><label class="form-label">Descripción / Procedimiento</label>
        <textarea class="form-input" name="descripcion"><?= clean($editing['descripcion']??'') ?></textarea>
      </div>
    </div>

    <!-- Tab anestesia -->
    <div id="tab-anestesia" class="tab-content">
      <div class="form-row">
        <div class="form-group"><label class="form-label">Tipo de anestesia</label>
          <select class="form-input" name="tipo_anestesia">
            <?php foreach(['local'=>'Local','sedacion'=>'Sedación','general'=>'General','regional'=>'Regional/Epidural'] as $k=>$v): ?>
            <option value="<?= $k ?>" <?= ($editing['tipo_anestesia']??'general')===$k?'selected':'' ?>><?= $v ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group"><label class="form-label">Anestesiólogo / Asistente</label>
          <select class="form-input" name="anestesiologo_id">
            <option value="">— Mismo veterinario —</option>
            <?php foreach($vets_sel as $v): ?><option value="<?= $v['id'] ?>" <?= ($editing['anestesiologo_id']??'')==$v['id']?'selected':'' ?>><?= clean($v['nombre']) ?></option><?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="form-group"><label class="form-label">Riesgo anestésico (ASA)</label>
        <div class="flex gap-2 flex-wrap" style="margin-top:6px">
          <?php foreach(['bajo'=>['label'=>'ASA I — Bajo','color'=>'var(--success)'],'moderado'=>['label'=>'ASA II — Moderado','color'=>'var(--warning)'],'alto'=>['label'=>'ASA III — Alto','color'=>'var(--orange)'],'muy_alto'=>['label'=>'ASA IV — Muy alto','color'=>'var(--danger)']] as $k=>$v): ?>
          <label style="display:flex;align-items:center;gap:6px;padding:8px 14px;border:1.5px solid var(--border);border-radius:var(--r-sm);cursor:pointer;font-size:12px;font-weight:600;transition:all .15s"
                 id="asa_lbl_<?= $k ?>">
            <input type="radio" name="riesgo_anestesico" value="<?= $k ?>" <?= ($editing['riesgo_anestesico']??'bajo')===$k?'checked':'' ?> onchange="markASA('<?= $k ?>')">
            <span style="width:10px;height:10px;border-radius:50%;background:<?= $v['color'] ?>;flex-shrink:0"></span>
            <?= $v['label'] ?>
          </label>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="form-group"><label class="form-label">Protocolo anestésico</label>
        <textarea class="form-input" name="protocolo_anestesia" placeholder="Premedicación, inducción, mantenimiento, dosis..."><?= clean($editing['protocolo_anestesia']??'') ?></textarea>
      </div>
    </div>

    <!-- Tab consentimiento -->
    <div id="tab-consentimiento" class="tab-content">
      <div class="alert alert-info"><span class="alert-icon">ℹ️</span>El consentimiento informado es obligatorio antes de toda intervención quirúrgica.</div>
      <div class="form-row">
        <div class="form-group"><label class="form-label">Nombre del responsable / Tutor</label>
          <input class="form-input" name="nombre_responsable" value="<?= clean($editing['nombre_responsable']??'') ?>" placeholder="Nombre completo del propietario">
        </div>
        <div class="form-group"><label class="form-label">DNI del responsable</label>
          <input class="form-input" name="dni_responsable" value="<?= clean($editing['dni_responsable']??'') ?>" maxlength="11">
        </div>
      </div>
      <!-- Firma digital -->
      <div class="form-group">
        <label class="form-label">Firma digital del responsable</label>
        <div class="firma-canvas-wrap" style="height:160px">
          <canvas id="firmaCanvas" width="760" height="160"></canvas>
          <span class="firma-label">Firme aquí con el dedo o el mouse</span>
        </div>
        <input type="hidden" name="firma_digital" id="firmaData" value="<?= clean($editing['firma_digital']??'') ?>">
        <div class="flex gap-2 mt-2">
          <button type="button" class="btn btn-sm btn-ghost" onclick="limpiarFirma()">🗑️ Limpiar firma</button>
          <button type="button" class="btn btn-sm btn-primary" onclick="guardarFirma()">💾 Guardar firma</button>
          <?php if(!empty($editing['firma_digital'])): ?>
          <span class="badge b-success">✅ Firma registrada</span>
          <?php endif; ?>
        </div>
        <?php if(!empty($editing['firma_digital'])): ?>
        <div style="margin-top:10px"><img src="<?= $editing['firma_digital'] ?>" style="max-height:80px;border:1px solid var(--border);border-radius:var(--r-sm)"></div>
        <?php endif; ?>
      </div>
      <label style="display:flex;align-items:center;gap:10px;font-size:13px;padding:14px;background:var(--success-l);border:1px solid var(--success);border-radius:var(--r-sm);cursor:pointer">
        <input type="checkbox" name="consentimiento_firmado" value="1" style="accent-color:var(--success)" <?= ($editing['consentimiento_firmado']??0)?'checked':'' ?>>
        <strong style="color:var(--success-d)">✅ Consentimiento informado obtenido y firmado por el responsable</strong>
      </label>
    </div>

    <!-- Tab post-op -->
    <div id="tab-postop" class="tab-content">
      <div class="form-group"><label class="form-label">Hallazgos intraoperatorios</label>
        <textarea class="form-input" name="hallazgos_intraop" placeholder="Describe lo encontrado durante la cirugía..."><?= clean($editing['hallazgos_intraop']??'') ?></textarea>
      </div>
      <div class="form-group"><label class="form-label">Complicaciones</label>
        <textarea class="form-input" name="complicaciones" placeholder="Registrar cualquier complicación presentada..."><?= clean($editing['complicaciones']??'') ?></textarea>
      </div>
      <div class="form-group"><label class="form-label">Indicaciones post-operatorias</label>
        <textarea class="form-input" name="seguimiento_postop" placeholder="Cuidados en casa, medicación, restricciones..."><?= clean($editing['seguimiento_postop']??'') ?></textarea>
      </div>
      <div class="form-group"><label class="form-label">Próxima revisión</label>
        <input class="form-input" type="date" name="proxima_revision" value="<?= clean($editing['proxima_revision']??'') ?>">
      </div>
      <div class="form-group"><label class="form-label">Notas adicionales</label>
        <input class="form-input" name="notas" value="<?= clean($editing['notas']??'') ?>">
      </div>
    </div>

    <div class="flex gap-2 mt-3">
      <button type="submit" class="btn btn-primary">💾 Guardar cirugía</button>
      <a href="?p=cirugias" class="btn btn-ghost">Cancelar</a>
    </div>
  </form>
</div>

<?php else: ?>
<!-- ── LISTA ── -->
<div class="page-header">
  <div class="flex items-center justify-between">
    <div>
      <div class="page-title">Cirugías</div>
      <div class="page-desc"><?= count($cirugias) ?> registros — programaciones y seguimiento quirúrgico</div>
    </div>
    <a href="?p=cirugias&action=nueva" class="btn btn-primary">＋ Programar Cirugía</a>
  </div>
</div>

<!-- Filtros -->
<div class="flex gap-2 mb-3 flex-wrap">
  <?php foreach([''=>'Todas','programada'=>'Programadas','en_curso'=>'En curso','completada'=>'Completadas','cancelada'=>'Canceladas'] as $k=>$v): ?>
  <a href="?p=cirugias<?= $k?"&estado=$k":'' ?>"
     class="btn btn-sm <?= $estado_f===$k?'btn-primary':'btn-ghost' ?>"><?= $v ?></a>
  <?php endforeach; ?>
</div>

<div class="card" style="padding:0">
  <div class="table-wrap">
    <table class="vtable">
      <thead>
        <tr>
          <th>Paciente</th><th>Cirugía</th><th>Fecha</th>
          <th>Veterinario</th><th>Anestesia</th><th>Riesgo</th>
          <th>Consentimiento</th><th>Estado</th><th>Acciones</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach($cirugias as $c): ?>
        <tr>
          <td>
            <div class="flex items-center gap-2">
              <span style="font-size:20px"><?= $ei[$c['especie']]??'🐾' ?></span>
              <div>
                <div class="td-main"><?= clean($c['mascota']) ?></div>
                <div class="text-xs text-muted"><?= clean($c['dueno']) ?></div>
              </div>
            </div>
          </td>
          <td class="font-semi"><?= clean($c['tipo_cirugia']) ?></td>
          <td class="text-muted"><?= date('d/m/Y H:i',strtotime($c['fecha_programada'])) ?><br><span class="text-xs"><?= $c['duracion_estimada'] ?> min</span></td>
          <td class="text-muted"><?= clean($c['vet']) ?></td>
          <td><span class="badge b-purple"><?= ucfirst($c['tipo_anestesia']) ?></span></td>
          <td><span class="badge <?= $riesgo_badge[$c['riesgo_anestesico']]??'b-gray' ?>"><?= ucfirst(str_replace('_',' ',$c['riesgo_anestesico'])) ?></span></td>
          <td><?= $c['consentimiento_firmado']?'<span class="badge b-success">✅ Firmado</span>':'<span class="badge b-danger">⚠️ Pendiente</span>' ?></td>
          <td><span class="badge <?= $estado_badge[$c['estado']]??'b-gray' ?>"><?= ucfirst(str_replace('_',' ',$c['estado'])) ?></span></td>
          <td>
            <div class="flex gap-1">
              <a href="?p=cirugias&action=editar&id=<?= $c['id'] ?>" class="btn btn-xs btn-outline-primary">Editar</a>
              <form method="POST" style="display:inline"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $c['id'] ?>">
                <button type="submit" class="btn btn-xs btn-ghost" style="color:var(--danger)" onclick="return confirm('¿Eliminar esta cirugía?')">🗑️</button>
              </form>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if(empty($cirugias)): ?><tr><td colspan="9" class="text-center text-muted" style="padding:48px">No hay cirugías registradas.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>
</div>

<script>
// Tabs
function showTab(id, btn) {
  document.querySelectorAll('.tab-content').forEach(t=>t.classList.remove('active'));
  document.querySelectorAll('.pill-btn').forEach(b=>b.classList.remove('active'));
  document.getElementById(id)?.classList.add('active');
  btn?.classList.add('active');
}

// Firma digital
let firmaCanvas, firmaCtx, isDrawing=false, lastX=0, lastY=0;
document.addEventListener('DOMContentLoaded', () => {
  firmaCanvas = document.getElementById('firmaCanvas');
  if (!firmaCanvas) return;
  firmaCtx = firmaCanvas.getContext('2d');
  firmaCtx.strokeStyle = '#0f172a';
  firmaCtx.lineWidth = 2.5;
  firmaCtx.lineCap = 'round';
  firmaCtx.lineJoin = 'round';
  const getPos = (e) => {
    const r = firmaCanvas.getBoundingClientRect();
    const t = e.touches ? e.touches[0] : e;
    return [(t.clientX-r.left)*(firmaCanvas.width/r.width), (t.clientY-r.top)*(firmaCanvas.height/r.height)];
  };
  firmaCanvas.addEventListener('mousedown', e=>{isDrawing=true;[lastX,lastY]=getPos(e);});
  firmaCanvas.addEventListener('mousemove', e=>{if(!isDrawing)return;firmaCtx.beginPath();firmaCtx.moveTo(lastX,lastY);[lastX,lastY]=getPos(e);firmaCtx.lineTo(lastX,lastY);firmaCtx.stroke();});
  firmaCanvas.addEventListener('mouseup', ()=>isDrawing=false);
  firmaCanvas.addEventListener('mouseleave', ()=>isDrawing=false);
  firmaCanvas.addEventListener('touchstart', e=>{e.preventDefault();isDrawing=true;[lastX,lastY]=getPos(e);},{passive:false});
  firmaCanvas.addEventListener('touchmove', e=>{e.preventDefault();if(!isDrawing)return;firmaCtx.beginPath();firmaCtx.moveTo(lastX,lastY);[lastX,lastY]=getPos(e);firmaCtx.lineTo(lastX,lastY);firmaCtx.stroke();},{passive:false});
  firmaCanvas.addEventListener('touchend', ()=>isDrawing=false);
});
function limpiarFirma() { if(firmaCtx) firmaCtx.clearRect(0,0,firmaCanvas.width,firmaCanvas.height); document.getElementById('firmaData').value=''; }
function guardarFirma() { if(firmaCanvas){ document.getElementById('firmaData').value=firmaCanvas.toDataURL(); alert('✅ Firma guardada correctamente.'); } }
function markASA(k) { document.querySelectorAll('[id^=asa_lbl_]').forEach(l=>l.style.borderColor='var(--border)'); document.getElementById('asa_lbl_'+k).style.borderColor='var(--primary)'; }
// Marcar ASA inicial
document.addEventListener('DOMContentLoaded', ()=>{
  const sel = document.querySelector('input[name="riesgo_anestesico"]:checked');
  if(sel) markASA(sel.value);
});
</script>
<?php
$_js_mas = array_map(fn($m)=>['id'=>$m['id'],'label'=>$m['label']], $mascotas_sel??[]);
$_js_vet = array_map(fn($v)=>['id'=>$v['id'],'label'=>$v['nombre']], $vets_sel??[]);
?>
<script>
document.addEventListener('DOMContentLoaded',function(){
    var _M=<?= json_encode(array_values($_js_mas)) ?>;
    var _V=<?= json_encode(array_values($_js_vet)) ?>;
    vetSearchSelect('inp-mas-cir','drop-mas-cir','hid-mas-cir',_M,'label');
    vetSearchSelect('inp-vet-cir','drop-vet-cir','hid-vet-cir',_V,'label');
});
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
