<?php
require_once __DIR__ . '/includes/config.php';
// No requiere login — acceso público por token
session_write_close();

$token = trim($_GET['t'] ?? '');
if (!$token) { http_response_code(404); die('Acceso no válido.'); }

// Decodificar token
$decoded = base64_decode($token);
if (!$decoded || !strpos($decoded,':')) { http_response_code(403); die('Token inválido.'); }
[$cliente_id, $hash] = explode(':', $decoded, 2);
$cliente_id = (int)$cliente_id;
if ($hash !== md5($cliente_id . 'vetpro_salt_2025')) { http_response_code(403); die('Token inválido.'); }

$db = getDB();
$cliente = $db->prepare("SELECT * FROM clientes WHERE id=? AND activo=1"); $cliente->execute([$cliente_id]); $cliente=$cliente->fetch();
if (!$cliente) { http_response_code(404); die('Cliente no encontrado.'); }

$mascotas = $db->prepare("SELECT * FROM mascotas WHERE cliente_id=? AND estado='activo' ORDER BY nombre"); $mascotas->execute([$cliente_id]); $mascotas=$mascotas->fetchAll();

$mascota_sel = (int)($_GET['m'] ?? ($mascotas[0]['id'] ?? 0));
$mascota_act = null;
foreach($mascotas as $m) if($m['id']===$mascota_sel) { $mascota_act=$m; break; }
if(!$mascota_act && !empty($mascotas)) { $mascota_act=$mascotas[0]; $mascota_sel=$mascota_act['id']; }

// ── Satisfacción: tabla + guardar evaluación (antes de imprimir HTML) ──
try {
    $db->exec("CREATE TABLE IF NOT EXISTS satisfaccion (
        id INT AUTO_INCREMENT PRIMARY KEY, cliente_id INT NULL, cita_id INT NULL, mascota_id INT NULL,
        puntuacion TINYINT NOT NULL, comentario TEXT NULL, origen VARCHAR(20) DEFAULT 'portal',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, KEY idx_cli (cliente_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {}

if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['accion'] ?? '')==='satisfaccion') {
    $punt = (int)($_POST['puntuacion'] ?? 0);
    $com  = trim($_POST['comentario'] ?? '');
    if ($punt>=1 && $punt<=5) {
        try {
            $db->prepare("INSERT INTO satisfaccion (cliente_id,mascota_id,puntuacion,comentario,origen) VALUES (?,?,?,?, 'portal')")
               ->execute([$cliente_id, ($mascota_sel?:null), $punt, ($com?:null)]);
        } catch (Exception $e) {}
    }
    header('Location: portal_cliente.php?t='.urlencode($token).'&m='.$mascota_sel.'&grx=1'); exit;
}

$consultas = $vacunas = $citas_prox = [];
if ($mascota_act) {
    $st=$db->prepare("SELECT con.*,u.nombre as veterinario FROM consultas con JOIN usuarios u ON u.id=con.veterinario_id WHERE con.mascota_id=? ORDER BY con.fecha DESC LIMIT 10"); $st->execute([$mascota_sel]); $consultas=$st->fetchAll();
    $st=$db->prepare("SELECT * FROM vacunas WHERE mascota_id=? ORDER BY fecha_aplicacion DESC"); $st->execute([$mascota_sel]); $vacunas=$st->fetchAll();
    $st=$db->prepare("SELECT ci.*,u.nombre as veterinario FROM citas ci JOIN usuarios u ON u.id=ci.veterinario_id WHERE ci.mascota_id=? AND ci.fecha >= CURDATE() AND ci.estado IN ('pendiente','confirmada') ORDER BY ci.fecha ASC LIMIT 5"); $st->execute([$mascota_sel]); $citas_prox=$st->fetchAll();
}
$cfg = $db->query("SELECT clave,valor FROM configuracion")->fetchAll(PDO::FETCH_KEY_PAIR);
$ei=['perro'=>'🐕','gato'=>'🐈','conejo'=>'🐰','ave'=>'🐦','reptil'=>'🦎','roedor'=>'🐭','otro'=>'🐾'];
?><!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Portal VetPro — <?= clean($cliente['nombre']) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
*{margin:0;padding:0;box-sizing:border-box}
:root{--teal:#0d9f7a;--teal-l:#e0f5ee;--teal-d:#0a7a5e;--text:#1a1d23;--text2:#5a6072;--text3:#9299a8;--border:#e2e5eb;--bg:#f0f2f5;--bg2:#fff;--red:#dc2626;--red-l:#fef2f2;--amber:#d97706;--amber-l:#fffbeb;--blue:#2563eb;--green:#16a34a;--green-l:#f0fdf4;--wa:#25D366;--wa-d:#128C7E}
body{font-family:'Plus Jakarta Sans',sans-serif;background:var(--bg);color:var(--text);font-size:14px}
.topbar{background:#111827;padding:14px 20px;display:flex;align-items:center;justify-content:center;gap:10px}
.logo{width:36px;height:36px;background:var(--teal);border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:18px}
.logo-t{font-size:17px;font-weight:800;color:#fff}
.logo-sub{font-size:11px;color:rgba(255,255,255,.4)}
.container{max-width:800px;margin:0 auto;padding:20px 16px}
.card{background:var(--bg2);border:1px solid var(--border);border-radius:14px;padding:18px;margin-bottom:14px}
.badge{display:inline-flex;align-items:center;gap:3px;padding:3px 8px;border-radius:20px;font-size:11px;font-weight:600}
.b-teal{background:var(--teal-l);color:var(--teal-d)}.b-red{background:var(--red-l);color:var(--red)}.b-amber{background:var(--amber-l);color:var(--amber)}.b-gray{background:#f1f5f9;color:#64748b}
.dot{width:6px;height:6px;border-radius:50%;background:currentColor}
.tabs{display:flex;gap:4px;background:#f1f5f9;border-radius:10px;padding:4px;margin-bottom:16px}
.tab{flex:1;padding:8px;border-radius:7px;border:none;font-family:inherit;font-size:13px;font-weight:500;background:transparent;color:var(--text2);cursor:pointer;transition:all .15s}
.tab.active{background:#fff;color:var(--text);font-weight:700;box-shadow:0 1px 3px rgba(0,0,0,.1)}
.mascotas-tabs{display:flex;gap:8px;overflow-x:auto;margin-bottom:16px;padding-bottom:4px}
.mas-btn{display:flex;align-items:center;gap:6px;padding:8px 14px;border:1px solid var(--border);border-radius:20px;background:#fff;font-size:13px;font-weight:500;cursor:pointer;text-decoration:none;white-space:nowrap;transition:all .15s}
.mas-btn.active,.mas-btn:hover{background:var(--teal);border-color:var(--teal);color:#fff}
.stat-row{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:16px}
.stat-box{background:#f8f9fb;border-radius:10px;padding:12px;text-align:center}
.stat-val{font-size:22px;font-weight:800;color:var(--teal);font-family:'Plus Jakarta Sans',sans-serif}
.stat-lbl{font-size:11px;color:var(--text3);margin-top:2px}
.wa-btn{display:flex;align-items:center;justify-content:center;gap:8px;padding:12px;background:var(--wa);color:#fff;border-radius:10px;font-size:14px;font-weight:700;text-decoration:none;transition:background .15s;margin-top:8px}
.wa-btn:hover{background:var(--wa-d)}
table{width:100%;border-collapse:collapse;font-size:13px}
th{text-align:left;font-size:11px;font-weight:600;color:var(--text3);text-transform:uppercase;letter-spacing:.4px;padding:8px 0;border-bottom:1px solid var(--border)}
td{padding:10px 0;border-bottom:1px solid var(--border);color:var(--text2);vertical-align:top}
tr:last-child td{border-bottom:none}
.alert{display:flex;gap:8px;padding:10px 12px;border-radius:9px;margin-bottom:12px;font-size:13px}
.alert-warn{background:var(--amber-l);border:1px solid #fcd34d;color:#92400e}
</style>
</head>
<body>
<div class="topbar">
  <?php $logo_pt = trim($cfg['logo_path'] ?? ''); ?>
  <div class="logo">
    <?php if ($logo_pt !== ''): ?>
      <img src="<?= UPLOADS_URL . '/' . htmlspecialchars($logo_pt) ?>" alt="<?= clean($cfg['nombre_clinica']??'VetPro') ?>"
           style="width:100%;height:100%;object-fit:contain;background:#fff;border-radius:inherit;padding:2px"
           onerror="this.style.display='none';this.parentNode.textContent='🐾'">
    <?php else: ?>🐾<?php endif; ?>
  </div>
  <div><div class="logo-t"><?= clean($cfg['nombre_clinica']??'VetPro') ?></div><div class="logo-sub">Portal del cliente</div></div>
</div>

<div class="container">
  <!-- Bienvenida -->
  <div class="card" style="background:var(--teal);border-color:var(--teal);color:#fff;margin-bottom:14px">
    <div style="font-size:15px;font-weight:700">Hola, <?= clean(explode(' ',$cliente['nombre'])[0]) ?> 👋</div>
    <div style="font-size:13px;opacity:.8;margin-top:2px">Aquí puedes ver el historial de tus mascotas</div>
  </div>

  <!-- SELECTOR DE MASCOTA -->
  <?php if(count($mascotas)>1): ?>
  <div class="mascotas-tabs">
    <?php foreach($mascotas as $m): ?>
    <a href="?t=<?= urlencode($token) ?>&m=<?= $m['id'] ?>" class="mas-btn <?= $m['id']===$mascota_sel?'active':'' ?>"><?= $ei[$m['especie']]??'🐾' ?> <?= clean($m['nombre']) ?></a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php if($mascota_act): ?>
  <!-- INFO MASCOTA -->
  <div class="card">
    <div style="display:flex;align-items:center;gap:14px">
      <div style="font-size:48px"><?= $ei[$mascota_act['especie']]??'🐾' ?></div>
      <div>
        <div style="font-size:20px;font-weight:800"><?= clean($mascota_act['nombre']) ?></div>
        <div style="color:var(--text2)"><?= clean($mascota_act['raza']??'') ?> · <?= ucfirst($mascota_act['sexo']??'') ?></div>
        <?php if($mascota_act['alergias']): ?><div style="color:var(--red);font-size:12px;margin-top:3px">⚠️ <?= clean($mascota_act['alergias']) ?></div><?php endif; ?>
      </div>
    </div>
    <div class="stat-row" style="margin-top:14px">
      <div class="stat-box"><div class="stat-val"><?= count($consultas) ?></div><div class="stat-lbl">Consultas</div></div>
      <div class="stat-box"><div class="stat-val"><?= count($vacunas) ?></div><div class="stat-lbl">Vacunas</div></div>
      <div class="stat-box"><div class="stat-val"><?= count($citas_prox) ?></div><div class="stat-lbl">Citas próximas</div></div>
    </div>
  </div>

  <!-- TABS -->
  <div class="tabs">
    <button class="tab active" id="tb-cons" onclick="showTab('cons')">🏥 Consultas</button>
    <button class="tab" id="tb-vac" onclick="showTab('vac')">💉 Vacunas</button>
    <button class="tab" id="tb-citas" onclick="showTab('citas')">📅 Próximas citas</button>
  </div>

  <!-- CONSULTAS -->
  <div id="sec-cons">
    <?php if(empty($consultas)): ?><div class="card" style="text-align:center;color:var(--text3);padding:32px">Sin consultas registradas.</div><?php endif; ?>
    <?php foreach($consultas as $c): ?>
    <div class="card">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px">
        <span class="badge b-teal"><?= ucfirst($c['tipo']) ?></span>
        <span style="font-size:12px;color:var(--text3)"><?= date('d/m/Y H:i',strtotime($c['fecha'])) ?> · <?= clean($c['veterinario']) ?></span>
      </div>
      <?php if($c['sintomas']): ?><div style="margin-bottom:8px"><div style="font-size:11px;color:var(--text3);text-transform:uppercase;font-weight:600;margin-bottom:3px">Motivo</div><div style="font-size:13px"><?= clean($c['sintomas']) ?></div></div><?php endif; ?>
      <div><div style="font-size:11px;color:var(--text3);text-transform:uppercase;font-weight:600;margin-bottom:3px">Diagnóstico</div><div style="font-size:13px;font-weight:600"><?= clean($c['diagnostico']) ?></div></div>
      <?php if($c['tratamiento']): ?><div style="margin-top:8px"><div style="font-size:11px;color:var(--text3);text-transform:uppercase;font-weight:600;margin-bottom:3px">Tratamiento</div><div style="font-size:13px"><?= clean($c['tratamiento']) ?></div></div><?php endif; ?>
      <?php if($c['proximo_control']): ?><div style="margin-top:10px"><span class="badge b-amber">📅 Próximo control: <?= date('d/m/Y',strtotime($c['proximo_control'])) ?></span></div><?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- VACUNAS -->
  <div id="sec-vac" style="display:none">
    <?php if(empty($vacunas)): ?><div class="card" style="text-align:center;color:var(--text3);padding:32px">Sin vacunas registradas.</div><?php endif; ?>
    <?php foreach($vacunas as $v):
      $dias=(strtotime($v['proxima_dosis'])-time())/86400;
      $estado=$dias<0?'Vencida':($dias<=7?'Por vencer':'Vigente');
      $badge=$estado==='Vencida'?'b-red':($estado==='Por vencer'?'b-amber':'b-teal');
      if($estado==='Vencida'||$estado==='Por vencer'):
    ?><div class="alert alert-warn"><span>⚠️</span><span><strong><?= clean($v['tipo_vacuna']) ?></strong> — <?= $estado === 'Vencida' ? 'venció el' : 'vence el' ?> <?= date('d/m/Y',strtotime($v['proxima_dosis'])) ?></span></div><?php endif; ?>
    <div class="card">
      <div style="display:flex;align-items:center;justify-content:space-between">
        <div><div style="font-weight:700"><?= clean($v['tipo_vacuna']) ?></div><div style="font-size:12px;color:var(--text3)"><?= clean($v['laboratorio']??'') ?> <?= $v['lote']?"· Lote: {$v['lote']}":''; ?></div></div>
        <span class="badge <?= $badge ?>"><span class="dot"></span> <?= $estado ?></span>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:10px;background:#f8f9fb;padding:10px;border-radius:8px">
        <div><div style="font-size:11px;color:var(--text3)">Aplicada</div><div style="font-size:13px;font-weight:600"><?= date('d/m/Y',strtotime($v['fecha_aplicacion'])) ?></div></div>
        <div><div style="font-size:11px;color:var(--text3)">Próxima dosis</div><div style="font-size:13px;font-weight:600;color:<?= $dias<0?'var(--red)':($dias<=7?'var(--amber)':'var(--text)') ?>"><?= date('d/m/Y',strtotime($v['proxima_dosis'])) ?></div></div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- PRÓXIMAS CITAS -->
  <div id="sec-citas" style="display:none">
    <?php if(empty($citas_prox)): ?><div class="card" style="text-align:center;color:var(--text3);padding:32px">No hay citas próximas programadas.</div><?php endif; ?>
    <?php foreach($citas_prox as $c): ?>
    <div class="card">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px">
        <span class="badge b-teal"><?= ucfirst($c['tipo']) ?></span>
        <span class="badge <?= $c['estado']==='confirmada'?'b-teal':'b-gray' ?>"><?= ucfirst($c['estado']) ?></span>
      </div>
      <div style="font-size:17px;font-weight:700"><?= date('d/m/Y',strtotime($c['fecha'])) ?> a las <?= substr($c['hora'],0,5) ?></div>
      <div style="color:var(--text2);font-size:13px;margin-top:3px">👨‍⚕️ <?= clean($c['veterinario']) ?></div>
      <?php if($c['motivo']): ?><div style="color:var(--text3);font-size:12px;margin-top:4px"><?= clean($c['motivo']) ?></div><?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
  <?php else: ?>
  <div class="card" style="text-align:center;color:var(--text3);padding:48px">No tienes mascotas registradas.</div>
  <?php endif; ?>

  <!-- RESERVAR + SATISFACCIÓN -->
  <a href="reservar.php" class="wa-btn" style="background:var(--teal);margin-top:16px;text-decoration:none">📅 Reservar una nueva cita</a>

  <div class="card" style="margin-top:14px">
    <div style="font-weight:700;margin-bottom:4px">⭐ ¿Cómo fue tu experiencia?</div>
    <div style="font-size:12.5px;color:var(--text3);margin-bottom:12px">Tu opinión nos ayuda a mejorar la atención.</div>
    <?php if(!empty($_GET['grx'])): ?>
      <div class="alert" style="background:var(--green-l);color:var(--green)"><span>✅</span><span>¡Gracias por tu evaluación! 🐾</span></div>
    <?php else: ?>
    <form method="post">
      <input type="hidden" name="accion" value="satisfaccion">
      <input type="hidden" name="puntuacion" id="punt" value="0">
      <div id="stars" style="display:flex;gap:8px;font-size:36px;margin-bottom:12px">
        <?php for($i=1;$i<=5;$i++): ?><span class="star" data-v="<?= $i ?>" onclick="setStar(<?= $i ?>)" style="color:#d1d5db;cursor:pointer;transition:color .1s;line-height:1">★</span><?php endfor; ?>
      </div>
      <textarea name="comentario" rows="3" maxlength="500" placeholder="Cuéntanos tu experiencia (opcional)" style="width:100%;font-family:inherit;font-size:14px;padding:11px 13px;border:1px solid var(--border);border-radius:10px;resize:vertical;color:var(--text)"></textarea>
      <button class="wa-btn" style="background:var(--teal);margin-top:10px;border:0;cursor:pointer;width:100%" type="submit" onclick="return (document.getElementById('punt').value>0) || (alert('Por favor selecciona una puntuación (1 a 5 estrellas).'),false)">Enviar evaluación</button>
    </form>
    <?php endif; ?>
  </div>

  <!-- FOOTER CONTACTO -->
  <div class="card" style="background:#f8f9fb;margin-top:16px">
    <div style="font-weight:700;margin-bottom:8px">📍 <?= clean($cfg['nombre_clinica']??'VetPro') ?></div>
    <div style="font-size:13px;color:var(--text2)"><?= clean($cfg['direccion_clinica']??'') ?></div>
    <div style="font-size:13px;color:var(--text2)"><?= clean($cfg['telefono_clinica']??'') ?></div>
    <?php if(!empty($cfg['telefono_clinica'])): ?>
    <?php $tel_cl=preg_replace('/[^0-9]/','',ltrim($cfg['telefono_clinica'],'+'));if(strlen($tel_cl)<11)$tel_cl='51'.$tel_cl; ?>
    <a href="https://wa.me/<?= $tel_cl ?>" target="_blank" class="wa-btn">💬 Contactar por WhatsApp</a>
    <?php endif; ?>
  </div>
</div>

<script>
function showTab(t) {
  ['cons','vac','citas'].forEach(function(s){
    document.getElementById('sec-'+s).style.display=s===t?'block':'none';
    var b=document.getElementById('tb-'+s);if(b)b.classList.toggle('active',s===t);
  });
}
function setStar(v){
  document.getElementById('punt').value=v;
  document.querySelectorAll('#stars .star').forEach(function(s){ s.style.color = (parseInt(s.dataset.v,10)<=v)?'#f59e0b':'#d1d5db'; });
}
</script>
</body>
</html>
