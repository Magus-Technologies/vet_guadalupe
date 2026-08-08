<?php
/**
 * VetPro — Módulo Triaje Inteligente (Fase 1 / MVP)
 * Motor de formularios dinámicos + triaje de prioridad (semáforo).
 * - Crea/siembra sus tablas solo (self-healing).
 * - Registra un triaje, calcula prioridad por reglas + banderas rojas.
 * - Permite "Crear consulta desde triaje" pre-llenando la Historia Clínica.
 */
$page = 'triaje'; $pageTitle = 'Triaje';
require_once __DIR__ . '/../includes/config.php';
requireLogin();
$db = getDB();
$action = $_GET['action'] ?? 'list';
$msg = $_GET['msg'] ?? '';

require_once __DIR__ . '/../includes/triaje_lib.php';
$NIVELES = triaje_niveles();

// Asegurar tablas + plantilla base (idempotente, biblioteca compartida)
triaje_bootstrap($db);

// ── Plantilla activa de triaje + sus campos ──
$plantilla = triaje_plantilla_activa($db);
$campos = $plantilla ? triaje_campos($db, (int)$plantilla['id']) : [];

// ─────────────────────────────────────────────────────────────
// POST: guardar triaje
// ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='save_triaje' && $plantilla) {
    $user = getUser();
    $resp = $_POST['r'] ?? [];
    $mascota_id = (int)($_POST['mascota_id'] ?? 0) ?: null;
    $cita_id    = (int)($_POST['cita_id'] ?? 0) ?: null;

    $calc = triaje_calcular($campos, $resp, [
        'amarillo'=>(int)$plantilla['umbral_amarillo'],
        'naranja' =>(int)$plantilla['umbral_naranja'],
        'rojo'    =>(int)$plantilla['umbral_rojo'],
    ]);

    // Datos mapeados a la consulta + cliente de la mascota
    $map = []; foreach ($campos as $c) { $cfg=json_decode($c['config']??'{}',true); if(!empty($cfg['mapea'])) $map[$cfg['mapea']] = trim((string)($resp[$c['clave']]??'')); }
    $cliente_id = null;
    if ($mascota_id) { $q=$db->prepare("SELECT cliente_id FROM mascotas WHERE id=?"); $q->execute([$mascota_id]); $cliente_id=$q->fetchColumn()?:null; }

    $ins = $db->prepare("INSERT INTO triaje (plantilla_id,plantilla_version,sede_id,mascota_id,cliente_id,cita_id,canal,motivo,respuestas,puntaje,nivel,banderas,realizado_por,estado)
                         VALUES (?,?,?,?,?,?,'recepcion',?,?,?,?,?,?,'abierto')");
    $ins->execute([
        $plantilla['id'], $plantilla['version'], getSede(), $mascota_id, $cliente_id, $cita_id,
        substr($map['sintomas'] ?? '', 0, 255),
        json_encode($calc['snapshot'], JSON_UNESCAPED_UNICODE),
        $calc['puntaje'], $calc['nivel'],
        ($calc['banderas'] ? json_encode($calc['banderas'], JSON_UNESCAPED_UNICODE) : null),
        $user['id'] ?? null,
    ]);
    $tid = (int)$db->lastInsertId();

    // Reflejar prioridad en la cita si se enlazó
    if ($cita_id) { try { $db->prepare("UPDATE citas SET triaje_nivel=?, triaje_id=? WHERE id=?")->execute([$calc['nivel'],$tid,$cita_id]); } catch(Exception $e){} }

    // ¿Crear consulta desde el triaje?
    if (($_POST['despues'] ?? '') === 'consulta' && $mascota_id) {
        $qs = http_build_query(array_filter([
            'p'=>'historial','action'=>'nueva','mascota_id'=>$mascota_id,'triaje_id'=>$tid,
            'pf_sintomas'=>$map['sintomas'] ?? '', 'pf_peso'=>$map['peso'] ?? '', 'pf_temp'=>$map['temperatura'] ?? '',
        ]));
        header('Location: '.BASE_URL.'/index.php?'.$qs); exit;
    }
    header('Location: '.BASE_URL.'/index.php?p=triaje&msg=ok'); exit;
}

// Anular un triaje
if ($action==='anular' && isset($_GET['id'])) {
    $db->prepare("UPDATE triaje SET estado='anulado' WHERE id=?")->execute([(int)$_GET['id']]);
    header('Location: '.BASE_URL.'/index.php?p=triaje'); exit;
}

// Datos para el buscador de mascota
$mascotas_sel = $db->query("SELECT m.id,CONCAT(m.nombre,' (',c.nombre,')') as label FROM mascotas m JOIN clientes c ON c.id=m.cliente_id WHERE m.estado='activo' ORDER BY m.nombre")->fetchAll();
$mascota_pre = (int)($_GET['mascota_id'] ?? 0);

require_once __DIR__ . '/../includes/header.php';
?>
<?php if($msg==='ok'): ?><div class="alert alert-success mb-2">✅ Triaje registrado correctamente.</div><?php endif; ?>

<?php if($action==='nuevo'): // ── FORMULARIO DE TRIAJE ── ?>
<style>
.tri-form{max-width:760px}
.tri-live{position:sticky;top:8px;z-index:5;border-radius:12px;padding:12px 16px;color:#fff;display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:14px;transition:background .2s}
.tri-opt{display:flex;flex-wrap:wrap;gap:8px}
.tri-opt label{flex:1;min-width:110px;border:1px solid var(--border);border-radius:10px;padding:9px 12px;font-size:13px;cursor:pointer;text-align:center;transition:all .12s}
.tri-opt input{display:none}
.tri-opt input:checked + span{font-weight:700}
.tri-opt label:has(input:checked){border-color:var(--primary);background:var(--primary-l,#e0f2fe)}
.tri-campo{margin-bottom:14px}
.tri-campo>label.form-label{display:block;margin-bottom:6px}
</style>
<div class="tri-form">
  <div class="sec-header">
    <div class="sec-title">🩺 Nuevo triaje <span style="font-size:12px;font-weight:400;color:var(--text3)">— <?= clean($plantilla['nombre']) ?></span></div>
    <a href="?p=triaje" class="btn btn-sm">← Volver</a>
  </div>

  <!-- Indicador de prioridad en vivo -->
  <div class="tri-live" id="tri-live" style="background:#22c55e">
    <div><div style="font-size:11px;opacity:.9;text-transform:uppercase;letter-spacing:.5px">Prioridad estimada</div>
      <div style="font-size:18px;font-weight:800" id="tri-live-label">Rutina</div></div>
    <div style="text-align:right"><div style="font-size:11px;opacity:.9">Puntaje</div>
      <div style="font-size:18px;font-weight:800" id="tri-live-score">0</div></div>
  </div>

  <form method="POST" class="card">
    <input type="hidden" name="action" value="save_triaje">
    <?php if($cita = (int)($_GET['cita_id']??0)): ?><input type="hidden" name="cita_id" value="<?= $cita ?>"><?php endif; ?>

    <div class="tri-campo" style="position:relative">
      <label class="form-label required">Paciente</label>
      <?php $pre_lbl=''; if($mascota_pre){ foreach($mascotas_sel as $mm){ if($mm['id']==$mascota_pre){$pre_lbl=$mm['label'];break;} } } ?>
      <input type="text" id="inp-mas-tri" class="form-input" placeholder="🐾 Buscar mascota..." value="<?= clean($pre_lbl) ?>" autocomplete="off">
      <input type="hidden" name="mascota_id" id="hid-mas-tri" value="<?= $mascota_pre?:'' ?>" required>
      <div id="drop-mas-tri" style="display:none;position:absolute;top:100%;left:0;right:0;background:var(--bg2);border:1px solid var(--border);border-radius:8px;box-shadow:0 8px 24px rgba(0,0,0,.12);z-index:300;max-height:220px;overflow-y:auto"></div>
    </div>

    <?php foreach($campos as $c):
      $opts = $c['opciones'] ? (json_decode($c['opciones'],true)?:[]) : [];
      $cfg  = $c['config'] ? (json_decode($c['config'],true)?:[]) : [];
    ?>
    <div class="tri-campo">
      <label class="form-label <?= $c['requerido']?'required':'' ?>"><?= clean($c['etiqueta']) ?></label>
      <?php if(in_array($c['tipo'],['select','boolean'])): ?>
        <div class="tri-opt">
          <?php foreach($opts as $o): ?>
          <label>
            <input type="radio" name="r[<?= clean($c['clave']) ?>]" value="<?= clean($o['valor']) ?>"
                   data-peso="<?= (int)($o['peso']??0) ?>" data-critico="<?= !empty($o['critico'])?1:0 ?>"
                   onchange="triCalc()" <?= $c['requerido']?'required':'' ?>>
            <span><?= clean($o['etiqueta']) ?><?= !empty($o['critico'])?' 🔴':'' ?></span>
          </label>
          <?php endforeach; ?>
        </div>
      <?php elseif($c['tipo']==='textarea'): ?>
        <textarea class="form-input" name="r[<?= clean($c['clave']) ?>]" style="min-height:60px" <?= $c['requerido']?'required':'' ?> placeholder="Describe aquí..."></textarea>
      <?php elseif(in_array($c['tipo'],['numero','escala'])): ?>
        <input class="form-input" type="number" step="0.001" name="r[<?= clean($c['clave']) ?>]"
               data-rangos='<?= clean(json_encode($cfg['rangos']??[])) ?>' oninput="triCalc()"
               <?= $c['requerido']?'required':'' ?> placeholder="<?= $c['clave']==='temperatura'?'Ej: 38.5':'' ?>">
      <?php else: ?>
        <input class="form-input" type="text" name="r[<?= clean($c['clave']) ?>]" <?= $c['requerido']?'required':'' ?>>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>

    <div class="flex gap-1 mt-2" style="flex-wrap:wrap">
      <button type="submit" class="btn btn-primary" name="despues" value="consulta">💾 Guardar y crear consulta</button>
      <button type="submit" class="btn" name="despues" value="">Solo guardar triaje</button>
      <a href="?p=triaje" class="btn btn-ghost">Cancelar</a>
    </div>
  </form>
</div>

<script>
var TRI_UM = {amarillo:<?= (int)$plantilla['umbral_amarillo'] ?>, naranja:<?= (int)$plantilla['umbral_naranja'] ?>, rojo:<?= (int)$plantilla['umbral_rojo'] ?>};
var TRI_NIV = {rojo:['Emergencia','#ef4444'],naranja:['Urgente','#f59e0b'],amarillo:['Prioritario','#eab308'],verde:['Rutina','#22c55e']};
function triCalc(){
  var pts=0, bandera=false;
  document.querySelectorAll('input[type=radio]:checked').forEach(function(r){
    pts += parseInt(r.dataset.peso||0,10);
    if(r.dataset.critico==='1') bandera=true;
  });
  document.querySelectorAll('input[data-rangos]').forEach(function(inp){
    if(inp.value==='') return;
    var num=parseFloat(String(inp.value).replace(',','.')); if(isNaN(num)) return;
    (JSON.parse(inp.dataset.rangos||'[]')).forEach(function(g){
      var okMin=(g.min===undefined)||num>=g.min, okMax=(g.max===undefined)||num<=g.max;
      if(okMin&&okMax) pts+=parseInt(g.peso||0,10);
    });
  });
  var niv = bandera||pts>=TRI_UM.rojo ? 'rojo' : (pts>=TRI_UM.naranja?'naranja':(pts>=TRI_UM.amarillo?'amarillo':'verde'));
  var box=document.getElementById('tri-live');
  box.style.background=TRI_NIV[niv][1];
  document.getElementById('tri-live-label').textContent=TRI_NIV[niv][0]+(bandera?' 🔴':'');
  document.getElementById('tri-live-score').textContent=pts;
}
document.addEventListener('DOMContentLoaded',function(){
  var _M=<?= json_encode(array_values(array_map(fn($m)=>['id'=>$m['id'],'label'=>$m['label']],$mascotas_sel))) ?>;
  if(typeof vetSearchSelect==='function') vetSearchSelect('inp-mas-tri','drop-mas-tri','hid-mas-tri',_M,'label');
  triCalc();
});
</script>

<?php else: // ── LISTA: sala de espera por prioridad ──
  $hoy = date('Y-m-d');
  $where = "t.estado<>'anulado'"; $params=[];
  // Filtro por sede (respeta multi-sede)
  try { $_r=$db->query("SHOW COLUMNS FROM `mascotas` LIKE 'sede_id'")->fetchAll();
        if(!empty($_r) && !verTodasSedes()) { $where.=" AND m.sede_id=".getSede(); } } catch(Exception $e){}
  $ver = $_GET['ver'] ?? 'abiertos';
  if ($ver==='abiertos') $where .= " AND t.estado='abierto'";
  $st = $db->prepare("SELECT t.*, m.nombre AS mascota, m.especie, c.nombre AS dueno, u.nombre AS registrado
                      FROM triaje t
                      LEFT JOIN mascotas m ON m.id=t.mascota_id
                      LEFT JOIN clientes c ON c.id=t.cliente_id
                      LEFT JOIN usuarios u ON u.id=t.realizado_por
                      WHERE $where
                      ORDER BY FIELD(t.nivel,'rojo','naranja','amarillo','verde'), t.created_at DESC
                      LIMIT 200");
  $st->execute($params); $lista = $st->fetchAll();
  // Contadores del día (solo abiertos)
  $cont = ['rojo'=>0,'naranja'=>0,'amarillo'=>0,'verde'=>0];
  foreach($lista as $t){ if($t['estado']==='abierto' && isset($cont[$t['nivel']])) $cont[$t['nivel']]++; }
  $ei = ['perro'=>'🐕','gato'=>'🐈','conejo'=>'🐰','ave'=>'🐦','reptil'=>'🦎','roedor'=>'🐭','otro'=>'🐾'];
?>
<div class="grid g4 mb-2">
  <?php foreach(['rojo','naranja','amarillo','verde'] as $nv): ?>
  <div class="stat-card" style="border-left:4px solid <?= $NIVELES[$nv][1] ?>">
    <div class="stat-value" style="color:<?= $NIVELES[$nv][1] ?>"><?= $cont[$nv] ?></div>
    <div class="stat-label"><?= $NIVELES[$nv][0] ?></div>
  </div>
  <?php endforeach; ?>
</div>

<div class="sec-header">
  <div class="sec-title">🩺 Triaje — Sala de espera</div>
  <div class="flex gap-1">
    <a href="?p=triaje&ver=abiertos" class="btn btn-sm <?= $ver==='abiertos'?'btn-primary':'' ?>">En espera</a>
    <a href="?p=triaje&ver=todos" class="btn btn-sm <?= $ver==='todos'?'btn-primary':'' ?>">Todos</a>
    <a href="?p=triaje&action=nuevo" class="btn btn-primary">+ Nuevo triaje</a>
  </div>
</div>

<div class="card" style="padding:0">
  <div class="table-wrap">
    <table class="vtable">
      <thead><tr><th>Prioridad</th><th>Paciente</th><th>Dueño</th><th>Motivo</th><th>Registrado</th><th>Estado</th><th>Acciones</th></tr></thead>
      <tbody>
        <?php if(empty($lista)): ?>
          <tr><td colspan="7" class="text-center text-muted" style="padding:32px">Sin triajes. Pulsa <strong>+ Nuevo triaje</strong> para empezar.</td></tr>
        <?php else: foreach($lista as $t):
          $nv=$NIVELES[$t['nivel']]??['—','#94a3b8','']; $bander = $t['banderas']?json_decode($t['banderas'],true):[];
        ?>
        <tr<?= $t['estado']!=='abierto'?' style="opacity:.6"':'' ?>>
          <td>
            <span class="badge" style="background:<?= $nv[1] ?>;color:#fff"><span class="dot"></span> <?= $nv[0] ?></span>
            <div style="font-size:10px;color:var(--text3);margin-top:2px"><?= $nv[2] ?> · <?= (int)$t['puntaje'] ?> pts</div>
            <?php if($bander): ?><div style="font-size:10px;color:#ef4444;font-weight:700">🔴 <?= clean(implode(', ',$bander)) ?></div><?php endif; ?>
          </td>
          <td><div class="flex items-center gap-1"><span style="font-size:18px"><?= $ei[$t['especie']]??'🐾' ?></span><span class="td-main"><?= clean($t['mascota']??'—') ?></span></div></td>
          <td><?= clean($t['dueno']??'—') ?></td>
          <td class="text-muted text-xs" style="max-width:220px"><?= clean($t['motivo']??'—') ?></td>
          <td class="text-muted text-xs"><?= date('d/m H:i',strtotime($t['created_at'])) ?><br><?= clean($t['registrado']??'') ?></td>
          <td><span class="badge" style="<?= $t['estado']==='atendido'?'background:#dcfce7;color:#15803d':'background:#f1f5f9;color:#475569' ?>"><?= ucfirst($t['estado']) ?></span></td>
          <td><div class="flex gap-1">
            <?php if($t['estado']==='abierto' && $t['mascota_id']): ?>
              <a href="?p=historial&action=nueva&mascota_id=<?= (int)$t['mascota_id'] ?>&triaje_id=<?= (int)$t['id'] ?>&pf_sintomas=<?= rawurlencode($t['motivo']??'') ?>" class="btn btn-xs btn-primary" title="Crear consulta">🩺 Atender</a>
            <?php endif; ?>
            <?php if($t['estado']!=='anulado'): ?><a href="?p=triaje&action=anular&id=<?= (int)$t['id'] ?>" class="btn btn-xs" style="color:var(--red)" onclick="return confirm('¿Anular este triaje?')">✕</a><?php endif; ?>
          </div></td>
        </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
