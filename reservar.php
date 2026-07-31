<?php
/* ============================================================================
 * VetPro — Reserva de citas PÚBLICA (por URL, sin login)
 * El dueño de la mascota solicita una cita; queda como "pendiente" para que
 * el administrador la acepte o rechace (ver módulo Solicitudes).
 * URL de ejemplo:  https://tu-dominio/reservar.php
 * ==========================================================================*/
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/wa_notify.php';
session_write_close();

$db = getDB();

// ── Migración idempotente: tabla de solicitudes ──
try {
    $db->exec("CREATE TABLE IF NOT EXISTS solicitudes_cita (
        id INT AUTO_INCREMENT PRIMARY KEY,
        sede_id INT DEFAULT 1,
        servicio_id INT NULL,
        tipo VARCHAR(40) DEFAULT 'consulta',
        dni VARCHAR(8) NULL,
        dueno_nombre VARCHAR(150) NOT NULL,
        dueno_telefono VARCHAR(30) NOT NULL,
        dueno_email VARCHAR(150) NULL,
        mascota_nombre VARCHAR(100) NOT NULL,
        mascota_especie VARCHAR(30) DEFAULT 'perro',
        fecha_preferida DATE NOT NULL,
        hora_preferida TIME NULL,
        motivo TEXT NULL,
        estado ENUM('pendiente','aceptada','rechazada') NOT NULL DEFAULT 'pendiente',
        respuesta VARCHAR(255) NULL,
        cliente_id INT NULL,
        mascota_id INT NULL,
        veterinario_id INT NULL,
        cita_id INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // idempotente: agregar 'dni' si la tabla ya existía sin esa columna
    $c = $db->query("SHOW COLUMNS FROM solicitudes_cita LIKE 'dni'")->fetchAll();
    if (empty($c)) $db->exec("ALTER TABLE solicitudes_cita ADD COLUMN dni VARCHAR(8) NULL AFTER sede_id");
} catch (Exception $e) {}

$cfg     = $db->query("SELECT clave,valor FROM configuracion")->fetchAll(PDO::FETCH_KEY_PAIR);
$clinica = trim($cfg['nombre_clinica'] ?? $cfg['clinica_nombre'] ?? 'VetPro') ?: 'VetPro';

try { $sedes = $db->query("SELECT id,nombre FROM sedes WHERE activo=1 ORDER BY nombre")->fetchAll(); } catch(Exception $e){ $sedes=[]; }
if (empty($sedes)) $sedes = [['id'=>1,'nombre'=>'Sede principal']];
try { $servicios = $db->query("SELECT id,nombre,tipo FROM servicios WHERE activo=1 ORDER BY tipo,nombre")->fetchAll(); } catch(Exception $e){ $servicios=[]; }

$ok = false; $err = '';

// Disponibilidad: un horario está libre si las citas que se solapan < capacidad (vets activos)
function _slotDisponible(PDO $db, int $sede, string $fecha, string $hora, int $dur = 30): bool {
    try {
        $ini = substr($hora, 0, 5) . ':00';
        $cap = (int)$db->query("SELECT COUNT(*) FROM usuarios WHERE rol='veterinario' AND activo=1 AND sede_id={$sede}")->fetchColumn();
        if ($cap === 0) $cap = (int)$db->query("SELECT COUNT(*) FROM usuarios WHERE rol='veterinario' AND activo=1")->fetchColumn();
        if ($cap === 0) $cap = 1;
        $st = $db->prepare("SELECT COUNT(*) FROM citas
            WHERE sede_id=? AND fecha=? AND estado IN ('pendiente','confirmada')
              AND ? < ADDTIME(hora, SEC_TO_TIME(duracion_minutos*60))
              AND hora < ADDTIME(?, SEC_TO_TIME(?*60))");
        $st->execute([$sede, $fecha, $ini, $ini, $dur]);
        return (int)$st->fetchColumn() < $cap;
    } catch (Exception $e) { return true; } // si falla, no bloqueamos (el admin valida al aceptar)
}

// ── Recibir solicitud ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $sede_id  = (int)($_POST['sede_id'] ?? 1) ?: 1;
    $serv_id  = (int)($_POST['servicio_id'] ?? 0) ?: null;
    $tipo     = preg_replace('/[^a-z_]/','', strtolower($_POST['tipo'] ?? 'consulta')) ?: 'consulta';
    $dni      = preg_replace('/\D/','', $_POST['dni'] ?? '');
    $dnom     = trim($_POST['dueno_nombre'] ?? '');
    $dtel     = trim($_POST['dueno_telefono'] ?? '');
    $dmail    = trim($_POST['dueno_email'] ?? '');
    $mnom     = trim($_POST['mascota_nombre'] ?? '');
    $mesp     = preg_replace('/[^a-z]/','', strtolower($_POST['mascota_especie'] ?? 'perro')) ?: 'perro';
    $fecha    = trim($_POST['fecha_preferida'] ?? '');
    $hora     = trim($_POST['hora_preferida'] ?? '');
    $motivo   = trim($_POST['motivo'] ?? '');

    $tel_norm = preg_replace('/[^0-9]/','', $dtel);

    if ($dni !== '' && strlen($dni) !== 8) {
        $err = 'El DNI debe tener 8 dígitos.';
    } elseif ($dnom==='' || $tel_norm==='' || $mnom==='' || $fecha==='') {
        $err = 'Por favor completa tu nombre, teléfono, el nombre de tu mascota y la fecha deseada.';
    } elseif (strtotime($fecha) < strtotime(date('Y-m-d'))) {
        $err = 'La fecha deseada no puede ser anterior a hoy.';
    } elseif ($hora !== '' && !_slotDisponible($db, $sede_id, $fecha, $hora, 30)) {
        $err = 'Ese horario ('.date('d/m/Y',strtotime($fecha)).' a las '.substr($hora,0,5).') ya está reservado. Por favor elige otra hora.';
    } else {
        try {
            $db->prepare("INSERT INTO solicitudes_cita
                (sede_id,dni,servicio_id,tipo,dueno_nombre,dueno_telefono,dueno_email,mascota_nombre,mascota_especie,fecha_preferida,hora_preferida,motivo)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
               ->execute([$sede_id,($dni?:null),$serv_id,$tipo,$dnom,$tel_norm,($dmail?:null),$mnom,$mesp,$fecha,($hora?:null),($motivo?:null)]);
            $ok = true;
            // Aviso de recepción por WhatsApp (best-effort; no bloquea si falla)
            try {
                $f = date('d/m/Y', strtotime($fecha));
                $msg = "🐾 *{$clinica}*\n\nHola {$dnom} 👋\n\nRecibimos tu *solicitud de cita* para *{$mnom}*:\n📅 {$f}".($hora?(' · '.substr($hora,0,5)):'')."\n\nTe confirmaremos por este medio en cuanto la revisemos. ¡Gracias! 🐾";
                @wa_enviar($tel_norm, $msg);
            } catch (Exception $e) {}
        } catch (Exception $e) {
            $err = 'No pudimos registrar tu solicitud en este momento. Intenta nuevamente.';
        }
    }
}

$especies = ['perro'=>'🐕 Perro','gato'=>'🐈 Gato','conejo'=>'🐰 Conejo','ave'=>'🐦 Ave','reptil'=>'🦎 Reptil','roedor'=>'🐭 Roedor','otro'=>'🐾 Otro'];
?><!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Reservar cita — <?= htmlspecialchars($clinica) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
*{margin:0;padding:0;box-sizing:border-box}
:root{--teal:#0e9c8b;--teal-l:#e4f4f1;--teal-d:#0a7e70;--text:#102a2e;--text2:#33484c;--text3:#6e8589;--border:#e6eced;--bg:#f4f7f8;--bg2:#fff;--red:#e15b5b;--red-l:#fce9e9}
body{font-family:'Plus Jakarta Sans',sans-serif;background:var(--bg);color:var(--text);font-size:14px;line-height:1.5;padding:0 0 40px}
.top{background:#0c2227;color:#fff;padding:18px 20px;display:flex;align-items:center;justify-content:center;gap:12px}
.top .logo{width:40px;height:40px;border-radius:11px;background:linear-gradient(135deg,#14b8a2,#0c8b7c);display:grid;place-items:center;font-size:20px}
.top .t{font-size:18px;font-weight:800}
.top .s{font-size:11px;color:rgba(255,255,255,.5)}
.wrap{max-width:620px;margin:0 auto;padding:22px 16px}
.card{background:var(--bg2);border:1px solid var(--border);border-radius:16px;padding:22px;box-shadow:0 4px 16px rgba(16,42,46,.05)}
h1{font-size:22px;font-weight:800;letter-spacing:-.02em;margin-bottom:4px}
.sub{color:var(--text3);font-size:13px;margin-bottom:18px}
.sec{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--teal-d);margin:18px 0 10px}
label{display:block;font-size:12.5px;font-weight:600;color:var(--text2);margin-bottom:5px}
.req{color:var(--red)}
input,select,textarea{width:100%;font-family:inherit;font-size:14px;padding:11px 13px;border:1px solid var(--border);border-radius:10px;background:#fff;color:var(--text);outline:none}
input:focus,select:focus,textarea:focus{border-color:var(--teal);box-shadow:0 0 0 3px var(--teal-l)}
.row{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.grp{margin-bottom:13px}
.btn{display:flex;align-items:center;justify-content:center;gap:8px;width:100%;padding:14px;background:var(--teal);color:#fff;border:0;border-radius:12px;font-family:inherit;font-size:15px;font-weight:700;cursor:pointer;margin-top:8px}
.btn:hover{background:var(--teal-d)}
.alert{padding:12px 14px;border-radius:10px;font-size:13px;margin-bottom:16px}
.alert.err{background:var(--red-l);color:var(--red)}
.ok-box{text-align:center;padding:20px 6px}
.ok-ico{width:74px;height:74px;border-radius:50%;background:var(--teal-l);color:var(--teal-d);display:grid;place-items:center;font-size:38px;margin:0 auto 16px}
.foot{text-align:center;color:var(--text3);font-size:12px;margin-top:16px}
</style>
</head>
<body>
<div class="top">
  <?php $logo_rsv = trim($cfg['logo_path'] ?? ''); ?>
  <div class="logo">
    <?php if ($logo_rsv !== ''): ?>
      <img src="<?= UPLOADS_URL . '/' . htmlspecialchars($logo_rsv) ?>" alt="<?= htmlspecialchars($clinica) ?>"
           style="width:100%;height:100%;object-fit:contain;background:#fff;border-radius:inherit;padding:2px"
           onerror="this.style.display='none';this.parentNode.textContent='🐾'">
    <?php else: ?>🐾<?php endif; ?>
  </div>
  <div><div class="t"><?= htmlspecialchars($clinica) ?></div><div class="s">Reserva de citas en línea</div></div>
</div>

<div class="wrap">
<?php if ($ok): ?>
  <div class="card ok-box">
    <div class="ok-ico">✅</div>
    <h1>¡Solicitud enviada!</h1>
    <p class="sub" style="margin-top:6px">Recibimos tu solicitud de cita. La clínica la revisará y te confirmará por WhatsApp al número que indicaste. 🐾</p>
    <a href="reservar.php" class="btn" style="max-width:260px;margin:16px auto 0;text-decoration:none">Reservar otra cita</a>
  </div>
<?php else: ?>
  <div class="card">
    <h1>Reservar una cita 🗓️</h1>
    <div class="sub">Completa el formulario y te confirmaremos por WhatsApp. Los campos con <span class="req">*</span> son obligatorios.</div>
    <?php if ($err): ?><div class="alert err">⚠️ <?= htmlspecialchars($err) ?></div><?php endif; ?>

    <form method="post" autocomplete="off">
      <div class="sec">👤 Tus datos</div>
      <div class="grp"><label>DNI <span style="color:var(--text3);font-weight:400">(autocompleta tu nombre)</span></label>
        <div style="position:relative">
          <input name="dni" id="dni" maxlength="8" inputmode="numeric" placeholder="8 dígitos" autocomplete="off">
          <span id="dni-msg" style="position:absolute;right:12px;top:12px;font-size:12px"></span>
        </div>
      </div>
      <div class="grp"><label>Tu nombre <span class="req">*</span></label>
        <input name="dueno_nombre" id="dueno_nombre" maxlength="150" required placeholder="Nombre y apellido">
      </div>
      <div class="row">
        <div class="grp"><label>WhatsApp / teléfono <span class="req">*</span></label>
          <input name="dueno_telefono" maxlength="30" required inputmode="tel" placeholder="Ej: 999 888 777">
        </div>
        <div class="grp"><label>Email (opcional)</label>
          <input name="dueno_email" type="email" maxlength="150" placeholder="correo@ejemplo.com">
        </div>
      </div>

      <div class="sec">🐾 Tu mascota</div>
      <div class="row">
        <div class="grp"><label>Nombre de la mascota <span class="req">*</span></label>
          <input name="mascota_nombre" maxlength="100" required placeholder="Ej: Firulais">
        </div>
        <div class="grp"><label>Especie</label>
          <select name="mascota_especie"><?php foreach($especies as $k=>$lbl): ?><option value="<?= $k ?>"><?= $lbl ?></option><?php endforeach; ?></select>
        </div>
      </div>

      <div class="sec">🏥 La cita</div>
      <?php if (count($sedes) > 1): ?>
      <div class="grp"><label>Sede</label>
        <select name="sede_id"><?php foreach($sedes as $s): ?><option value="<?= (int)$s['id'] ?>"><?= htmlspecialchars($s['nombre']) ?></option><?php endforeach; ?></select>
      </div>
      <?php else: ?><input type="hidden" name="sede_id" value="<?= (int)$sedes[0]['id'] ?>"><?php endif; ?>

      <div class="grp"><label>Motivo / servicio</label>
        <select name="servicio_id" id="serv">
          <option value="">— Consulta general —</option>
          <?php foreach($servicios as $sv): ?><option value="<?= (int)$sv['id'] ?>" data-tipo="<?= htmlspecialchars($sv['tipo']) ?>"><?= htmlspecialchars($sv['nombre']) ?></option><?php endforeach; ?>
        </select>
        <input type="hidden" name="tipo" id="tipo" value="consulta">
      </div>

      <div class="row">
        <div class="grp"><label>Fecha deseada <span class="req">*</span></label>
          <input type="date" name="fecha_preferida" id="f_fecha" min="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d') ?>" required>
        </div>
        <div class="grp"><label>Hora aprox.</label>
          <input type="time" name="hora_preferida" id="f_hora">
        </div>
      </div>
      <div id="disp-msg" style="font-size:12.5px;margin:-6px 2px 6px;min-height:16px"></div>

      <div class="grp"><label>Motivo / comentario (opcional)</label>
        <textarea name="motivo" rows="3" maxlength="500" placeholder="Cuéntanos brevemente el motivo de la visita"></textarea>
      </div>

      <button class="btn" type="submit">📩 Enviar solicitud</button>
    </form>
  </div>
  <div class="foot">Tu solicitud será revisada por la clínica. La confirmación llega por WhatsApp.</div>
<?php endif; ?>
</div>

<script>
// Al elegir un servicio, guarda su tipo (para clasificar la cita)
var serv = document.getElementById('serv'), tipo = document.getElementById('tipo');
if (serv) serv.addEventListener('change', function(){
  var o = serv.options[serv.selectedIndex];
  tipo.value = (o && o.getAttribute('data-tipo')) ? o.getAttribute('data-tipo') : 'consulta';
});

// Autocompletar nombre desde el DNI (RENIEC) vía endpoint público
var dni = document.getElementById('dni'), nom = document.getElementById('dueno_nombre'), dmsg = document.getElementById('dni-msg');
if (dni) dni.addEventListener('input', function(){
  var v = dni.value.replace(/\D/g,''); dni.value = v;
  if (v.length !== 8) { dmsg.textContent = ''; return; }
  dmsg.textContent = '⏳'; dmsg.style.color = '#6e8589';
  fetch('api/consulta_dni_publico.php?numero=' + v)
    .then(function(r){ return r.json(); })
    .then(function(d){
      if (d && d.ok && d.nombre) {
        if (!nom.value.trim()) nom.value = d.nombre;
        dmsg.textContent = '✓'; dmsg.style.color = '#0e9c8b';
      } else {
        dmsg.textContent = '✕'; dmsg.style.color = '#e15b5b';
        dmsg.title = (d && d.error) ? d.error : 'No encontrado';
      }
    })
    .catch(function(){ dmsg.textContent = ''; });
});

// Disponibilidad de horario en vivo
var fF = document.getElementById('f_fecha'), fH = document.getElementById('f_hora'), dispMsg = document.getElementById('disp-msg');
function checkDisp(){
  if (!dispMsg) return;
  var fecha = fF ? fF.value : '', hora = fH ? fH.value : '';
  if (!fecha || !hora) { dispMsg.textContent = ''; return; }
  var sedeEl = document.querySelector('[name="sede_id"]'); var sede = sedeEl ? sedeEl.value : 1;
  dispMsg.textContent = 'Verificando disponibilidad…'; dispMsg.style.color = '#6e8589';
  fetch('api/disponibilidad_publico.php?sede_id=' + encodeURIComponent(sede) + '&fecha=' + encodeURIComponent(fecha) + '&hora=' + encodeURIComponent(hora))
    .then(function(r){ return r.json(); })
    .then(function(d){
      if (!d || !d.ok) { dispMsg.textContent = ''; return; }
      if (d.disponible) { dispMsg.innerHTML = '✅ Horario disponible'; dispMsg.style.color = '#0a7e70'; }
      else { dispMsg.innerHTML = '⛔ ' + (d.mensaje || 'Ese horario ya está reservado, elige otra hora.'); dispMsg.style.color = '#e15b5b'; }
    })
    .catch(function(){ dispMsg.textContent = ''; });
}
if (fF) fF.addEventListener('change', checkDisp);
if (fH) fH.addEventListener('change', checkDisp);
</script>
</body>
</html>
