<?php
$page = 'whatsapp_conexion'; $pageTitle = 'Conexión WhatsApp';

// ─────────────────────────────────────────────────────────────
// Handlers AJAX (ANTES del header → respuesta JSON pura).
// La página (PHP, lado servidor) habla con el microservicio por
// localhost vía cURL. El navegador NUNCA accede al puerto 3031
// directamente, así que el micro sigue protegido (solo localhost).
// ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['wa_action'])) {
  require_once __DIR__ . '/../includes/config.php';
  requireLogin();
  require_once __DIR__ . '/../includes/wa_notify.php'; // define WA_MICRO_URL y WA_MICRO_TOKEN
  header('Content-Type: application/json');

  $accion = $_POST['wa_action'];

  // Helper local: pega al microservicio
  $wa_call = function(string $ruta, string $metodo = 'GET') {
    $ch = curl_init(WA_MICRO_URL . $ruta);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_TIMEOUT        => 8,
      CURLOPT_CUSTOMREQUEST  => $metodo,
      CURLOPT_HTTPHEADER     => ['x-token: ' . WA_MICRO_TOKEN],
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    return ['code' => $code, 'body' => $resp, 'err' => $err];
  };

  if ($accion === 'status') {
    $r = $wa_call('/status');
    if ($r['code'] === 0) { echo json_encode(['ok'=>false,'offline'=>true,'msg'=>'El microservicio no responde']); exit; }
    $data = json_decode($r['body'], true) ?: [];
    echo json_encode(['ok'=>true,'connected'=>!empty($data['connected']),'hasQR'=>!empty($data['hasQR'])]); exit;
  }

  if ($accion === 'qr') {
    $r = $wa_call('/qr.json');
    if ($r['code'] === 0) { echo json_encode(['ok'=>false,'offline'=>true]); exit; }
    $data = json_decode($r['body'], true) ?: [];
    echo json_encode(['ok'=>true,'connected'=>!empty($data['connected']),'qr'=>$data['qr']??null]); exit;
  }

  if ($accion === 'logout') {
    $r = $wa_call('/logout', 'POST');
    if ($r['code'] === 0) { echo json_encode(['ok'=>false,'offline'=>true,'msg'=>'El microservicio no responde']); exit; }
    $data = json_decode($r['body'], true) ?: [];
    echo json_encode(['ok'=>!empty($data['ok']),'msg'=>$data['msg']??$data['error']??'']); exit;
  }

  echo json_encode(['ok'=>false,'error'=>'acción no válida']); exit;
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page">
  <div class="sec-header">
    <div>
      <div class="page-title">💬 Conexión de WhatsApp</div>
      <div class="page-desc">Vincula o cambia el número de WhatsApp que envía los mensajes a tus clientes</div>
    </div>
  </div>

  <div style="max-width:560px;margin:0 auto">
    <div class="card" style="text-align:center;padding:28px 24px">

      <!-- Estado -->
      <div id="wa-estado" style="display:inline-flex;align-items:center;gap:8px;padding:7px 16px;border-radius:999px;font-size:13px;font-weight:600;background:var(--bg3);color:var(--text3);margin-bottom:20px">
        <span style="width:9px;height:9px;border-radius:50%;background:#94a3b8" id="wa-dot"></span>
        <span id="wa-estado-txt">Consultando estado…</span>
      </div>

      <!-- Zona dinámica: QR o mensaje de conectado -->
      <div id="wa-zona">
        <div style="padding:40px 0;color:var(--text3)">
          <div style="width:36px;height:36px;border:3px solid var(--border);border-top-color:var(--primary);border-radius:50%;margin:0 auto 12px;animation:waSpin .8s linear infinite"></div>
          Cargando…
        </div>
      </div>

      <!-- Acciones -->
      <div id="wa-acciones" style="margin-top:22px;display:none">
        <button class="btn btn-ghost btn-sm" onclick="waCambiarNumero()" id="wa-btn-cambiar" style="border-color:#ef4444;color:#ef4444">
          🔄 Cambiar de número / celular
        </button>
        <div style="font-size:11px;color:var(--text3);margin-top:10px;line-height:1.6">
          Esto <strong>desvincula el celular actual</strong> y genera un código QR nuevo<br>para vincular otro teléfono.
        </div>
      </div>

    </div>

    <!-- Ayuda -->
    <div class="card" style="margin-top:14px;padding:16px 18px;background:var(--bg3)">
      <div style="font-size:12px;font-weight:700;color:var(--text2);margin-bottom:8px">ℹ️ Cómo vincular un celular</div>
      <ol style="font-size:12px;color:var(--text3);line-height:1.9;margin:0;padding-left:18px">
        <li>En el celular, abre <strong>WhatsApp</strong>.</li>
        <li>Ve a <strong>Configuración → Dispositivos vinculados</strong>.</li>
        <li>Toca <strong>Vincular un dispositivo</strong>.</li>
        <li>Escanea el código QR que aparece en esta pantalla.</li>
      </ol>
    </div>
  </div>
</div>

<style>
@keyframes waSpin { to { transform:rotate(360deg) } }
</style>

<script>
var _waTimer = null;

// Pega a los handlers AJAX de esta misma página
function waPost(accion){
  return fetch('<?= BASE_URL ?>/index.php?p=whatsapp_conexion', {
    method:'POST',
    headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body:'wa_action='+encodeURIComponent(accion)
  }).then(r=>r.json());
}

function setEstado(estado){
  var dot = document.getElementById('wa-dot');
  var txt = document.getElementById('wa-estado-txt');
  var box = document.getElementById('wa-estado');
  var acc = document.getElementById('wa-acciones');
  if (estado === 'conectado') {
    dot.style.background = '#10b981';
    txt.textContent = 'Conectado';
    box.style.background = '#d1fae5'; box.style.color = '#065f46';
    acc.style.display = 'block';
  } else if (estado === 'esperando') {
    dot.style.background = '#f59e0b';
    txt.textContent = 'Esperando escaneo del QR';
    box.style.background = '#fef3c7'; box.style.color = '#78350f';
    acc.style.display = 'none';
  } else if (estado === 'offline') {
    dot.style.background = '#ef4444';
    txt.textContent = 'Microservicio apagado';
    box.style.background = '#fee2e2'; box.style.color = '#7f1d1d';
    acc.style.display = 'none';
  } else {
    dot.style.background = '#94a3b8';
    txt.textContent = 'Consultando…';
    box.style.background = 'var(--bg3)'; box.style.color = 'var(--text3)';
    acc.style.display = 'none';
  }
}

function pintarConectado(){
  document.getElementById('wa-zona').innerHTML =
    '<div style="padding:30px 0"><div style="font-size:54px;margin-bottom:10px">✅</div>'
    + '<div style="font-size:16px;font-weight:700;color:var(--text)">WhatsApp está conectado</div>'
    + '<div style="font-size:13px;color:var(--text3);margin-top:6px">Los mensajes a tus clientes se envían normalmente.</div></div>';
}

function pintarQR(qr){
  if (qr) {
    document.getElementById('wa-zona').innerHTML =
      '<div style="font-size:13px;color:var(--text2);margin-bottom:14px">Escanea este código con WhatsApp para vincular el celular</div>'
      + '<img src="'+qr+'" style="width:260px;height:260px;border:1px solid var(--border);border-radius:14px;padding:8px;background:#fff">'
      + '<div style="font-size:11px;color:var(--text3);margin-top:10px">El código se actualiza solo. Si expira, espera unos segundos.</div>';
  } else {
    document.getElementById('wa-zona').innerHTML =
      '<div style="padding:34px 0;color:var(--text3)">'
      + '<div style="width:34px;height:34px;border:3px solid var(--border);border-top-color:var(--primary);border-radius:50%;margin:0 auto 12px;animation:waSpin .8s linear infinite"></div>'
      + 'Generando código QR…</div>';
  }
}

function pintarOffline(){
  document.getElementById('wa-zona').innerHTML =
    '<div style="padding:26px 0"><div style="font-size:44px;margin-bottom:10px;opacity:.5">🔌</div>'
    + '<div style="font-size:15px;font-weight:700;color:var(--text)">El servicio de WhatsApp está apagado</div>'
    + '<div style="font-size:12px;color:var(--text3);margin-top:8px;line-height:1.6">Pídele a tu administrador que inicie el servicio en el servidor.<br>Mientras tanto, los mensajes automáticos no se enviarán.</div></div>';
}

// Ciclo de actualización
function waRefresh(){
  waPost('status').then(function(d){
    if (!d.ok && d.offline) { setEstado('offline'); pintarOffline(); return; }
    if (d.connected) { setEstado('conectado'); pintarConectado(); return; }
    // No conectado → buscar QR
    setEstado('esperando');
    waPost('qr').then(function(q){
      if (q.ok && q.connected) { setEstado('conectado'); pintarConectado(); }
      else pintarQR(q.qr);
    });
  }).catch(function(){ setEstado('offline'); pintarOffline(); });
}

function waCambiarNumero(){
  if (!confirm('¿Seguro que quieres desvincular el celular actual?\n\nDejará de enviar mensajes hasta que vincules un teléfono nuevo escaneando el QR.')) return;
  var btn = document.getElementById('wa-btn-cambiar');
  btn.disabled = true; btn.textContent = 'Desvinculando…';
  waPost('logout').then(function(d){
    btn.disabled = false; btn.innerHTML = '🔄 Cambiar de número / celular';
    if (d.ok) { setEstado('esperando'); pintarQR(null); setTimeout(waRefresh, 2500); }
    else if (d.offline) { setEstado('offline'); pintarOffline(); }
    else alert('No se pudo desvincular: ' + (d.msg||'error'));
  });
}

// Iniciar y refrescar cada 4 segundos
waRefresh();
_waTimer = setInterval(waRefresh, 4000);
window.addEventListener('beforeunload', function(){ if(_waTimer) clearInterval(_waTimer); });
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
