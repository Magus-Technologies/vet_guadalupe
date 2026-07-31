<?php
$page = 'whatsapp'; $pageTitle = 'WhatsApp Web';

// ─────────────────────────────────────────────────────────────
// Handlers AJAX (deben ir ANTES de incluir el header, para que la
// respuesta sea JSON puro y no la página HTML completa).
// ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['action'])
    && in_array($_POST['action'], ['log','save_template','reset_template'], true)) {

  require_once __DIR__ . '/../includes/config.php';
  requireLogin();
  $user = getUser();
  $db   = getDB();

  // Guardar en log de envíos
  if ($_POST['action']==='log') {
    $st = $db->prepare("INSERT INTO whatsapp_log (cliente_id,mascota_id,usuario_id,tipo,mensaje,telefono,url_generada,estado) VALUES (?,?,?,?,?,?,?,'generado')");
    $st->execute([
      (int)$_POST['cliente_id'],
      (int)($_POST['mascota_id']??0) ?: null,
      $user['id'],
      $_POST['tipo'] ?? 'personalizado',
      $_POST['mensaje'] ?? '',
      $_POST['telefono'] ?? '',
      $_POST['url'] ?? ''
    ]);
    jsonResponse(['ok'=>true]);
  }

  // Guardar plantilla personalizada (persistente en configuracion)
  if ($_POST['action']==='save_template') {
    $tipo  = preg_replace('/[^a-z_]/','', strtolower($_POST['tipo'] ?? ''));
    $texto = (string)($_POST['mensaje'] ?? '');
    if ($tipo === '') { jsonResponse(['ok'=>false,'error'=>'tipo inválido']); }
    $clave = 'wa_tpl_'.$tipo;
    // Asegurar que la columna 'valor' soporte emojis (utf8mb4). La tabla original
    // suele venir en latin1 y rechaza caracteres de 4 bytes como 🐾, 💰, ✅.
    try {
      $col = $db->query("SHOW FULL COLUMNS FROM configuracion WHERE Field='valor'")->fetch();
      $coll = $col['Collation'] ?? '';
      if (stripos($coll, 'utf8mb4') === false) {
        $db->exec("ALTER TABLE configuracion MODIFY `valor` TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
      }
    } catch (Exception $e) { /* si no se puede alterar, intentamos igual abajo */ }
    try {
      $db->prepare("INSERT INTO configuracion (clave,valor) VALUES (?,?) ON DUPLICATE KEY UPDATE valor=?")
         ->execute([$clave, $texto, $texto]);
      jsonResponse(['ok'=>true]);
    } catch (Exception $e) {
      jsonResponse(['ok'=>false,'error'=>'No se pudo guardar (¿emojis no soportados?). '.$e->getMessage()]);
    }
  }

  // Restablecer una plantilla a su valor por defecto
  if ($_POST['action']==='reset_template') {
    $tipo = preg_replace('/[^a-z_]/','', strtolower($_POST['tipo'] ?? ''));
    if ($tipo !== '') {
      $db->prepare("DELETE FROM configuracion WHERE clave=?")->execute(['wa_tpl_'.$tipo]);
    }
    jsonResponse(['ok'=>true]);
  }
}

require_once __DIR__ . '/../includes/header.php';
$db = getDB();


// Datos para selects
$clientes = $db->query("SELECT id,nombre,telefono FROM clientes WHERE activo=1 ORDER BY nombre")->fetchAll();
$mascotas = $db->query("SELECT m.id,m.nombre,m.especie,c.nombre as dueno,c.id as cliente_id FROM mascotas m JOIN clientes c ON c.id=m.cliente_id WHERE m.estado='activo' ORDER BY m.nombre")->fetchAll();
$veterinarios = $db->query("SELECT id,nombre FROM usuarios WHERE rol IN ('veterinario','admin') AND activo=1")->fetchAll();
// Servicios de Baño/Grooming del catálogo (para la plantilla de grooming)
$serv_groom = [];
try { $serv_groom = $db->query("SELECT nombre,precio FROM servicios WHERE activo=1 AND LOWER(tipo) IN ('baño','bano','grooming') ORDER BY nombre")->fetchAll(); } catch (Exception $e) {}

// Historial
$log = $db->query("SELECT l.*,c.nombre as cliente,m.nombre as mascota FROM whatsapp_log l JOIN clientes c ON c.id=l.cliente_id LEFT JOIN mascotas m ON m.id=l.mascota_id ORDER BY l.created_at DESC LIMIT 50")->fetchAll();

// Precarga por parámetro
$pre_cliente = (int)($_GET['cliente_id'] ?? 0);
$pre_mascota = (int)($_GET['mascota_id'] ?? 0);
$pre_tipo = $_GET['tipo'] ?? 'cita';

// Citas próximas para recordatorios
$proximas_citas = $db->query("
  SELECT c.*,m.nombre as mascota,m.especie,cl.nombre as dueno,cl.telefono,u.nombre as vet
  FROM citas c JOIN mascotas m ON m.id=c.mascota_id JOIN clientes cl ON cl.id=m.cliente_id JOIN usuarios u ON u.id=c.veterinario_id
  WHERE c.fecha >= CURDATE() AND c.estado IN ('pendiente','confirmada') AND c.recordatorio_enviado=0
  ORDER BY c.fecha,c.hora LIMIT 10
")->fetchAll();

// Vacunas por vencer
$vac_alerta = $db->query("
  SELECT v.*,m.nombre as mascota,cl.nombre as dueno,cl.telefono,cl.id as cliente_id
  FROM vacunas v JOIN mascotas m ON m.id=v.mascota_id JOIN clientes cl ON cl.id=m.cliente_id
  WHERE v.proxima_dosis <= DATE_ADD(CURDATE(), INTERVAL 14 DAY)
  ORDER BY v.proxima_dosis LIMIT 10
")->fetchAll();

$tipos_label = ['cita'=>'Confirmación cita','recibo'=>'Recibo','informe'=>'Informe médico','historial'=>'Historial','vacuna'=>'Recordatorio vacuna','recordatorio'=>'Recordatorio cita','receta'=>'Receta médica','grooming'=>'Grooming/Baño','personalizado'=>'Personalizado'];
$tipos_badge = ['cita'=>'b-blue','recibo'=>'b-teal','informe'=>'b-red','historial'=>'b-gray','vacuna'=>'b-purple','recordatorio'=>'b-amber','receta'=>'b-green','grooming'=>'b-teal','personalizado'=>'b-gray'];

// Nombre de la clínica/veterinaria desde configuración (para reemplazar "VetPro")
$cfg = $db->query("SELECT clave,valor FROM configuracion")->fetchAll(PDO::FETCH_KEY_PAIR);
$nombre_clinica = trim($cfg['nombre_clinica'] ?? $cfg['clinica_nombre'] ?? 'VetPro');
if ($nombre_clinica === '') $nombre_clinica = 'VetPro';

// Plantillas guardadas por el usuario (clave wa_tpl_<tipo>)
$tpl_guardadas = [];
foreach ($cfg as $k => $v) {
  if (strpos($k, 'wa_tpl_') === 0) {
    $tpl_guardadas[substr($k, 7)] = $v;
  }
}
?>

<div class="page">

<!-- HEADER -->
<div class="flex items-center justify-between mb-2">
  <div class="flex items-center gap-2">
    <div style="width:44px;height:44px;background:#25D366;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:24px">💬</div>
    <div>
      <div class="sec-title">WhatsApp Web — Mensajería directa</div>
      <div class="sec-sub">Sin API. Abre WhatsApp Web con el mensaje listo para enviar</div>
    </div>
  </div>
  <span class="badge b-teal"><span class="dot"></span> Disponible sin API</span>
</div>

<!-- TABS -->
<div style="display:flex;gap:4px;background:var(--bg3);border-radius:10px;padding:4px;margin-bottom:18px;width:fit-content">
  <button class="btn btn-primary btn-sm" id="tab-btn-0" onclick="waTab(0)">✉️ Nuevo mensaje</button>
  <button class="btn btn-sm" id="tab-btn-1" onclick="waTab(1)">📋 Plantillas</button>
  <button class="btn btn-sm" id="tab-btn-2" onclick="waTab(2)">⏰ Recordatorios pendientes</button>
  <button class="btn btn-sm" id="tab-btn-3" onclick="waTab(3)">📊 Historial</button>
</div>

<!-- TAB 0: NUEVO MENSAJE -->
<div id="wa-tab-0">
  <div style="display:grid;grid-template-columns:1fr 300px;gap:18px">
    <div>
      <!-- TIPO -->
      <div class="card mb-2">
        <div class="sec-title mb-1">1. Tipo de mensaje</div>
        <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:8px" id="type-grid">
          <?php foreach(['cita'=>['📅','Confirmación cita'],'recibo'=>['🧾','Recibo/Boleta'],'informe'=>['🏥','Informe médico'],'historial'=>['📋','Historial clínico'],'vacuna'=>['💉','Recordatorio vacuna'],'recordatorio'=>['⏰','Recordatorio cita'],'receta'=>['💊','Receta médica'],'grooming'=>['✂️','Grooming/Baño'],'personalizado'=>['✏️','Personalizado']] as $k=>[$ico,$lbl]): ?>
          <div class="type-card <?= $pre_tipo===$k?'selected':'' ?>" onclick="selectType('<?= $k ?>')" id="tc-<?= $k ?>" style="border:1px solid var(--border);border-radius:8px;padding:12px;cursor:pointer;transition:all .15s;position:relative">
            <div style="position:absolute;top:6px;right:6px;width:16px;height:16px;border-radius:50%;background:var(--wa);display:<?= $pre_tipo===$k?'flex':'none' ?>;align-items:center;justify-content:center;font-size:10px;color:#fff" id="chk-<?= $k ?>">✓</div>
            <div style="font-size:20px;margin-bottom:5px"><?= $ico ?></div>
            <div style="font-size:12px;font-weight:600;color:var(--text)"><?= $lbl ?></div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- DESTINATARIO -->
      <div class="card mb-2">
        <div class="sec-title mb-1">2. Destinatario</div>
        <div class="form-row">
          <div class="form-group" style="position:relative">
            <label class="form-label">Cliente</label>
            <input class="form-input" id="ac-cli-inp" autocomplete="off" placeholder="🔍 Escribe el nombre del cliente..."
                   oninput="acCliFilter()" onfocus="acCliFilter()" onblur="acCliBlur()">
            <input type="hidden" id="sel-cliente" value="" data-nombre="" data-tel="">
            <div id="ac-cli-drop" style="display:none;position:absolute;z-index:70;left:0;right:0;top:100%;margin-top:2px;max-height:220px;overflow:auto;background:var(--bg2);border:1px solid var(--border);border-radius:8px;box-shadow:var(--shadow-lg,0 10px 30px rgba(0,0,0,.12))"></div>
          </div>
          <div class="form-group">
            <label class="form-label">Teléfono WhatsApp</label>
            <input class="form-input" id="inp-tel" placeholder="+51 987 654 321" oninput="updatePreview()">
          </div>
        </div>
        <div class="form-group" style="position:relative">
          <label class="form-label">Mascota</label>
          <input class="form-input" id="ac-mas-inp" autocomplete="off" placeholder="🔍 Escribe el nombre de la mascota..."
                 oninput="acMasFilter()" onfocus="acMasFilter()" onblur="acMasBlur()">
          <input type="hidden" id="sel-mascota" value="" data-nombre="">
          <div id="ac-mas-drop" style="display:none;position:absolute;z-index:70;left:0;right:0;top:100%;margin-top:2px;max-height:220px;overflow:auto;background:var(--bg2);border:1px solid var(--border);border-radius:8px;box-shadow:var(--shadow-lg,0 10px 30px rgba(0,0,0,.12))"></div>
          <div class="text-xs text-muted" id="ac-mas-hint" style="margin-top:4px"></div>
        </div>
      </div>

      <!-- CAMPOS EXTRA + MENSAJE -->
      <div class="card">
        <div class="sec-title mb-1">3. Contenido</div>
        <div id="extra-fields"></div>
        <div class="form-group">
          <label class="form-label">Mensaje (editable)</label>
          <textarea class="form-input" id="msg-text" rows="7" oninput="updatePreview()" style="min-height:140px;font-size:13px"></textarea>
          <div style="display:flex;gap:6px;margin-top:6px;flex-wrap:wrap">
            <button type="button" class="btn btn-sm btn-primary" onclick="guardarPlantilla()" title="Guarda este texto como predeterminado para este tipo de mensaje">💾 Guardar plantilla</button>
            <button type="button" class="btn btn-sm" onclick="resetPlantilla()" title="Vuelve al texto original">↩️ Restablecer</button>
            <span style="font-size:11px;color:var(--text3);align-self:center">Tu texto quedará guardado para próximas veces.</span>
          </div>
        </div>
        <div>
          <div class="form-label">Variables</div>
          <div class="flex flex-wrap gap-1" style="flex-wrap:wrap">
            <?php foreach(['{clinica}','{nombre_cliente}','{nombre_mascota}','{fecha}','{hora}','{veterinario}','{diagnostico}','{total}','{proxima_vacuna}','{numero_boleta}','{tipo_vacuna}'] as $v): ?>
            <span onclick="insertVar('<?= $v ?>')" style="font-size:11px;background:var(--bg3);border:0.5px solid var(--border2);border-radius:5px;padding:3px 8px;cursor:pointer;color:var(--text2)"><?= $v ?></span>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    </div>

    <!-- PREVIEW -->
    <div>
      <div style="position:sticky;top:12px">
        <div class="font-bold text-sm mb-1">Vista previa</div>
        <div style="background:var(--bg3);border-radius:12px;padding:14px;display:flex;justify-content:center">
          <div style="width:240px;background:#111;border-radius:22px;padding:8px;min-height:380px;display:flex;flex-direction:column">
            <div style="background:#e5ddd5;border-radius:14px;flex:1;display:flex;flex-direction:column;overflow:hidden">
              <div style="background:#128C7E;padding:9px 12px;display:flex;align-items:center;gap:8px">
                <div style="width:30px;height:30px;border-radius:50%;background:#25D366;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;color:#fff;flex-shrink:0" id="prev-av">?</div>
                <div><div style="font-size:12px;font-weight:600;color:#fff" id="prev-name">Cliente</div><div style="font-size:10px;color:rgba(255,255,255,.7)" id="prev-tel">+51 ...</div></div>
              </div>
              <div style="flex:1;padding:10px;overflow-y:auto">
                <div style="background:#fff;border-radius:0 9px 9px 9px;padding:8px 10px;max-width:95%;box-shadow:0 1px 2px rgba(0,0,0,.1)">
                  <div style="font-size:11px;color:#303030;line-height:1.5;white-space:pre-wrap;word-break:break-word" id="prev-msg">Selecciona tipo de mensaje...</div>
                  <div style="font-size:9px;color:#888;text-align:right;margin-top:3px">Ahora ✓✓</div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div style="background:var(--bg2);border:0.5px solid var(--border2);border-radius:8px;padding:10px;margin-top:10px">
          <div style="font-size:10px;color:var(--text3);text-transform:uppercase;font-weight:600;letter-spacing:.4px;margin-bottom:4px">URL generada</div>
          <div style="font-size:10px;color:var(--blue);word-break:break-all;font-family:monospace;line-height:1.5" id="prev-url">Completa el formulario...</div>
        </div>

        <div style="display:flex;flex-direction:column;gap:7px;margin-top:12px">
          <a id="btn-abrir-wa" href="#" target="_blank" class="btn btn-wa" style="justify-content:center" onclick="return openWA()">
            💬 Abrir en WhatsApp Web
          </a>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px">
            <button class="btn btn-sm" onclick="copyURL()">📋 Copiar URL</button>
            <button class="btn btn-sm" onclick="guardarLog()" title="Registra en el historial que enviaste este mensaje (requiere cliente y teléfono)">🗂️ Registrar envío</button>
          </div>
        </div>
        <div id="wa-ok" style="display:none;text-align:center;font-size:12px;color:#128C7E;font-weight:600;margin-top:6px"></div>
      </div>
    </div>
  </div>
</div>

<!-- TAB 1: PLANTILLAS -->
<div id="wa-tab-1" style="display:none">
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
    <?php
    $plantillas = [
      ['cita','📅','Confirmación de cita','b-blue', $tpl_final['cita'] ?? ''],
      ['recordatorio','⏰','Recordatorio 24h antes','b-amber', $tpl_final['recordatorio'] ?? ''],
      ['recibo','🧾','Recibo de venta','b-teal', $tpl_final['recibo'] ?? ''],
      ['informe','🏥','Informe médico','b-red', $tpl_final['informe'] ?? ''],
      ['vacuna','💉','Recordatorio de vacuna','b-purple', $tpl_final['vacuna'] ?? ''],
      ['receta','💊','Receta médica','b-green', $tpl_final['receta'] ?? ''],
      ['historial','📋','Resumen historial clínico','b-gray', $tpl_final['historial'] ?? ''],
      ['personalizado','✏️','Mensaje de bienvenida','b-teal', "🐾 *Bienvenido a {clinica}, {nombre_cliente}!*\n\nNos complace que confíes en nosotros para el cuidado de *{nombre_mascota}* 🐾\n\nEn cualquier consulta o emergencia, escríbenos aquí.\n\n{clinica} — Cuidamos a tus mascotas ❤️"],
    ];
    foreach($plantillas as [$tipo,$ico,$titulo,$badge,$msg]):
    ?>
    <div style="border:0.5px solid var(--border2);border-radius:var(--radius);padding:14px;cursor:pointer;transition:border-color .15s" onmouseover="this.style.borderColor='#25D366'" onmouseout="this.style.borderColor=''">
      <div class="flex items-center gap-2 mb-2">
        <span style="font-size:20px"><?= $ico ?></span>
        <div class="flex-1 font-bold text-sm"><?= $titulo ?></div>
        <span class="badge <?= $badge ?>"><?= $tipos_label[$tipo] ?? $tipo ?></span>
      </div>
      <div style="font-size:11px;color:var(--text3);line-height:1.5;max-height:55px;overflow:hidden"><?= nl2br(htmlspecialchars(preg_replace('/\*(.*?)\*/', '$1', substr(str_replace(['{clinica}','{veterinaria}'], $NC, $msg),0,140)))) ?>...</div>
      <button class="btn btn-sm btn-wa mt-1 w-full" onclick="loadPlantilla(<?= htmlspecialchars(json_encode($tipo)) ?>,<?= htmlspecialchars(json_encode($msg)) ?>)" style="justify-content:center">Usar plantilla</button>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<!-- TAB 2: RECORDATORIOS PENDIENTES -->
<div id="wa-tab-2" style="display:none">
  <div class="grid g2">
    <div>
      <div class="card mb-2">
        <div class="sec-header"><div class="sec-title">Citas sin recordatorio</div><div class="sec-sub"><?= count($proximas_citas) ?> pendientes</div></div>
        <?php if(empty($proximas_citas)): ?>
          <div class="text-muted text-sm text-center" style="padding:24px 0">✅ Todos los recordatorios enviados</div>
        <?php endif; ?>
        <?php foreach($proximas_citas as $c): 
          $tel = preg_replace('/[^0-9]/','',$c['telefono']);
          $msg = "⏰ *Recordatorio VetPro*\n\nHola {$c['dueno']} 👋\n\nTe recordamos la cita de *{$c['mascota']}*:\n📅 ".date('d/m/Y',strtotime($c['fecha']))." a las ".substr($c['hora'],0,5)."\n👨‍⚕️ {$c['vet']}\n\n¿Confirmas? Responde SÍ o NO\n\nVetPro 🐾";
          $url = "https://wa.me/51{$tel}?text=".rawurlencode($msg);
        ?>
        <div class="flex items-center gap-2" style="padding:10px 0;border-bottom:0.5px solid var(--border)">
          <div class="flex-1">
            <div class="font-bold text-sm"><?= clean($c['mascota']) ?> · <span class="text-muted"><?= clean($c['dueno']) ?></span></div>
            <div class="text-xs text-muted"><?= date('d/m',strtotime($c['fecha'])) ?> <?= substr($c['hora'],0,5) ?> · <?= clean($c['vet']) ?></div>
          </div>
          <a href="<?= $url ?>" target="_blank" class="btn btn-xs btn-wa">💬 Enviar</a>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <div>
      <div class="card">
        <div class="sec-header"><div class="sec-title">Vacunas por vencer</div><div class="sec-sub"><?= count($vac_alerta) ?> próximas</div></div>
        <?php if(empty($vac_alerta)): ?>
          <div class="text-muted text-sm text-center" style="padding:24px 0">✅ Sin alertas de vacunas</div>
        <?php endif; ?>
        <?php foreach($vac_alerta as $v):
          $tel = preg_replace('/[^0-9]/','',$v['telefono']);
          $dias = round((strtotime($v['proxima_dosis'])-time())/86400);
          $msg = "💉 *Alerta de Vacuna — VetPro*\n\nHola {$v['dueno']} 👋\n\nLa vacuna de *{$v['mascota_nombre']}* ".($dias<0?'ha vencido':'vence en '.$dias.' días').":\n🗓️ ".date('d/m/Y',strtotime($v['proxima_dosis']))."\n💉 {$v['tipo_vacuna']}\n\n👉 Agenda su cita respondiendo aquí.\n\nVetPro 🐾";
          $url = "https://wa.me/51{$tel}?text=".rawurlencode($msg);
        ?>
        <div class="flex items-center gap-2" style="padding:10px 0;border-bottom:0.5px solid var(--border)">
          <div class="flex-1">
            <div class="font-bold text-sm"><?= clean($v['mascota_nombre']) ?> · <?= clean($v['tipo_vacuna']) ?></div>
            <div class="text-xs text-muted"><?= clean($v['dueno']) ?> · Vence: <?= date('d/m/Y',strtotime($v['proxima_dosis'])) ?></div>
          </div>
          <span class="badge <?= $dias<0?'b-red':'b-amber' ?>"><?= $dias<0?'Vencida':'En '.$dias.'d' ?></span>
          <a href="<?= $url ?>" target="_blank" class="btn btn-xs btn-wa">💬 Enviar</a>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<!-- TAB 3: HISTORIAL -->
<div id="wa-tab-3" style="display:none">
  <div class="card" style="padding:0">
    <div class="table-wrap">
      <table class="vtable">
        <thead><tr><th>Fecha/Hora</th><th>Cliente</th><th>Mascota</th><th>Tipo</th><th>Teléfono</th><th>Estado</th><th>Reenviar</th></tr></thead>
        <tbody>
          <?php foreach($log as $l): ?>
          <tr>
            <td class="text-muted text-xs"><?= date('d/m/Y H:i',strtotime($l['created_at'])) ?></td>
            <td class="td-main"><?= clean($l['cliente']) ?></td>
            <td><?= clean($l['mascota'] ?? '—') ?></td>
            <td><span class="badge <?= $tipos_badge[$l['tipo']] ?? 'b-gray' ?>"><?= $tipos_label[$l['tipo']] ?? $l['tipo'] ?></span></td>
            <td class="text-muted"><?= clean($l['telefono']) ?></td>
            <td><span class="badge b-teal"><span class="dot"></span> <?= ucfirst($l['estado']) ?></span></td>
            <td>
              <?php if($l['url_generada']): ?>
              <a href="<?= clean($l['url_generada']) ?>" target="_blank" class="btn btn-xs btn-wa">💬 Reenviar</a>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if(empty($log)): ?><tr><td colspan="7" class="text-center text-muted" style="padding:32px">Sin mensajes registrados aún.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

</div>

<script>
var currentType = '<?= clean($pre_tipo) ?>';
var WA_BASE_URL = '<?= BASE_URL ?>';
var WA_CLINICA = <?= json_encode($nombre_clinica, JSON_UNESCAPED_UNICODE) ?>;

<?php
// Nombre de la clínica (para el marcador {clinica})
$NC = $nombre_clinica;
// Plantillas por defecto. Usan el marcador {clinica}, que se reemplaza dinámicamente
// por el nombre actual de la veterinaria (así sigue actualizándose aunque se guarde).
$tpl_default = [
  'cita'        => "🐾 *{clinica}*\n\nHola {nombre_cliente} 👋\n\nTe confirmamos tu cita:\n\n📅 *Fecha:* {fecha}\n🕐 *Hora:* {hora}\n🐶 *Paciente:* {nombre_mascota}\n👨‍⚕️ *Veterinario:* {veterinario}\n\nPor favor llega 10 min antes.\n_Responde si necesitas reprogramar._\n\n✅ {clinica} — Cuidamos a tus mascotas",
  'recibo'      => "🧾 *Boleta {clinica}*\nN° {numero_boleta}\n\nCliente: {nombre_cliente}\nMascota: {nombre_mascota}\nFecha: {fecha}\n\n💰 *Total: S/. {total}*\nMétodo: Yape ✅\n\nGracias por confiar en {clinica} 🐾",
  'informe'     => "🏥 *Informe Médico — {clinica}*\n\nPaciente: *{nombre_mascota}*\nDueño: {nombre_cliente}\nFecha: {fecha}\nVet.: {veterinario}\n\n🔍 *Diagnóstico:*\n{diagnostico}\n\n💊 Tratamiento indicado por el veterinario.\n\n{clinica} 🐾",
  'historial'   => "📋 *Historial Clínico — {clinica}*\n\nPaciente: *{nombre_mascota}*\nDueño: {nombre_cliente}\n\n🗓️ *Últimas consultas:*\n• Consulta reciente ✅\n• Vacunas al día ✅\n\nPara historial completo, visítanos o escríbenos.\n\n{clinica} 🐾",
  'vacuna'      => "💉 *Alerta de Vacuna — {clinica}*\n\nHola {nombre_cliente} 👋\n\nLa vacuna de *{nombre_mascota}* vence pronto:\n🗓️ *Vencimiento:* {proxima_vacuna}\n💉 {tipo_vacuna}\n\n👉 Agenda su cita respondiendo este mensaje.\n\n{clinica} 🐾",
  'recordatorio'=> "⏰ *Recordatorio {clinica}*\n\nHola {nombre_cliente} 👋\n\nMañana es la cita de *{nombre_mascota}*:\n📅 {fecha} a las {hora}\n👨‍⚕️ {veterinario}\n\n¿Confirmas tu asistencia?\nResponde *SÍ* o *NO*\n\n{clinica} 🐾",
  'receta'      => "💊 *Receta Médica — {clinica}*\n\nPaciente: *{nombre_mascota}*\nVet.: {veterinario}\nFecha: {fecha}\n\n📋 *Medicamentos:*\n• Amoxicilina 500mg — 1 comp c/12h x 7 días\n• Meloxicam — 1 vez al día con comida x 5 días\n\n{clinica} 🐾",
  'grooming'    => "✂️ *Grooming — {clinica}*\n\nHola {nombre_cliente} 👋\n\n¡*{nombre_mascota}* ya está listo! 🛁✨\n\n🧼 *Servicio:* {servicio}\n📅 *Fecha:* {fecha}\n🕐 *Hora:* {hora}\n💰 *Total:* S/. {precio}\n\nPuedes pasar a recogerlo cuando gustes.\n\n{clinica} 🐾",
  'personalizado'=> "",
];
// Aplicar encima las plantillas guardadas por el usuario
$tpl_final = $tpl_default;
foreach ($tpl_guardadas as $t => $v) { $tpl_final[$t] = $v; }
?>
// Plantillas por defecto (con el nombre de tu veterinaria) — referencia para "Restablecer"
var TEMPLATES_DEFAULT = <?= json_encode($tpl_default, JSON_UNESCAPED_UNICODE) ?>;
// Plantillas activas (incluye tus versiones guardadas)
var TEMPLATES = <?= json_encode($tpl_final, JSON_UNESCAPED_UNICODE) ?>;


var EXTRA_FIELDS = {
  cita: '<div class="form-row mb-2"><div class="form-group"><label class="form-label">Fecha cita</label><input class="form-input" id="ef-fecha" type="date" oninput="updatePreview()"></div><div class="form-group"><label class="form-label">Hora</label><input class="form-input" id="ef-hora" type="time" value="09:00" oninput="updatePreview()"></div></div><div class="form-row mb-2"><div class="form-group"><label class="form-label">Veterinario</label><select class="form-input" id="ef-vet" onchange="updatePreview()"><?php foreach($veterinarios as $v): ?><option><?= clean($v['nombre']) ?></option><?php endforeach; ?></select></div><div class="form-group"><label class="form-label">Tipo atención</label><select class="form-input" id="ef-tipo" onchange="updatePreview()"><option>Consulta general</option><option>Vacuna</option><option>Control</option><option>Cirugía</option><option>Baño</option></select></div></div>',
  recibo: '<div class="form-row mb-2"><div class="form-group"><label class="form-label">N° Boleta</label><input class="form-input" id="ef-nro" value="B001-00001" oninput="updatePreview()"></div><div class="form-group"><label class="form-label">Total (S/.)</label><input class="form-input" id="ef-total" type="number" value="0.00" step="0.01" oninput="updatePreview()"></div></div><div class="form-row mb-2"><div class="form-group"><label class="form-label">Método de pago</label><select class="form-input" id="ef-metodo" onchange="updatePreview()"><option>Yape</option><option>Plin</option><option>Efectivo</option><option>Tarjeta</option><option>Transferencia</option></select></div><div class="form-group"><label class="form-label">Fecha</label><input class="form-input" id="ef-fecha" type="date" oninput="updatePreview()"></div></div>',
  vacuna: '<div class="form-row mb-2"><div class="form-group"><label class="form-label">Tipo vacuna</label><select class="form-input" id="ef-vacuna" onchange="updatePreview()"><option>Antirrábica</option><option>Óctuple</option><option>Triple Felina</option><option>Mixomatosis</option><option>Parvovirus</option></select></div><div class="form-group"><label class="form-label">Fecha vencimiento</label><input class="form-input" id="ef-proxima" type="date" oninput="updatePreview()"></div></div>',
  informe: '<div class="form-group mb-2"><label class="form-label">Diagnóstico</label><input class="form-input" id="ef-diag" placeholder="Diagnóstico clínico" oninput="updatePreview()"></div><div class="form-row mb-2"><div class="form-group"><label class="form-label">Veterinario</label><select class="form-input" id="ef-vet" onchange="updatePreview()"><?php foreach($veterinarios as $v): ?><option><?= clean($v['nombre']) ?></option><?php endforeach; ?></select></div><div class="form-group"><label class="form-label">Fecha consulta</label><input class="form-input" id="ef-fecha" type="date" oninput="updatePreview()"></div></div>',
  recordatorio: '<div class="form-row mb-2"><div class="form-group"><label class="form-label">Fecha cita</label><input class="form-input" id="ef-fecha" type="date" oninput="updatePreview()"></div><div class="form-group"><label class="form-label">Hora</label><input class="form-input" id="ef-hora" type="time" value="09:00" oninput="updatePreview()"></div></div><div class="form-group mb-2"><label class="form-label">Veterinario</label><select class="form-input" id="ef-vet" onchange="updatePreview()"><?php foreach($veterinarios as $v): ?><option><?= clean($v['nombre']) ?></option><?php endforeach; ?></select></div>',
  receta: '<div class="form-group mb-2"><label class="form-label">Veterinario</label><select class="form-input" id="ef-vet" onchange="updatePreview()"><?php foreach($veterinarios as $v): ?><option><?= clean($v['nombre']) ?></option><?php endforeach; ?></select></div><div class="form-group mb-2"><label class="form-label">Fecha receta</label><input class="form-input" id="ef-fecha" type="date" oninput="updatePreview()"></div>',
  grooming: '<div class="form-group mb-2"><label class="form-label">Servicio</label><select class="form-input" id="ef-servicio" onchange="groomPrecio()"><?php if(empty($serv_groom)): ?><option value="Baño y corte">Baño y corte</option><option value="Baño completo">Baño completo</option><option value="Corte de pelo">Corte de pelo</option><?php else: foreach($serv_groom as $s): ?><option value="<?= clean($s['nombre']) ?>" data-precio="<?= number_format($s['precio'],2,'.','') ?>"><?= clean($s['nombre']) ?> — S/ <?= number_format($s['precio'],2) ?></option><?php endforeach; endif; ?></select></div><div class="form-row mb-2"><div class="form-group"><label class="form-label">Fecha</label><input class="form-input" id="ef-fecha" type="date" oninput="updatePreview()"></div><div class="form-group"><label class="form-label">Hora</label><input class="form-input" id="ef-hora" type="time" value="10:00" oninput="updatePreview()"></div></div><div class="form-group mb-2"><label class="form-label">Precio (S/.)</label><input class="form-input" id="ef-precio" type="number" step="0.01" value="0.00" oninput="updatePreview()"></div>',
  historial: '',
  personalizado: ''
};

function selectType(t) {
  currentType = t;
  document.querySelectorAll('[id^="tc-"]').forEach(el => {
    el.style.borderColor = '';el.style.background = '';
  });
  document.querySelectorAll('[id^="chk-"]').forEach(el => el.style.display='none');
  const tc = document.getElementById('tc-'+t);
  if (tc) { tc.style.borderColor='#25D366'; tc.style.background='#e8fdf0'; }
  const chk = document.getElementById('chk-'+t);
  if (chk) chk.style.display='flex';
  document.getElementById('extra-fields').innerHTML = EXTRA_FIELDS[t] || '';
  // Set today as default date
  const df = document.getElementById('ef-fecha');
  if (df && !df.value) df.value = new Date().toISOString().split('T')[0];
  document.getElementById('msg-text').value = TEMPLATES[t] || '';
  updatePreview();
}

function getV(id) { const e=document.getElementById(id); return e?e.value:''; }

function resolveMsg() {
  var tpl = document.getElementById('msg-text').value;
  var cH = document.getElementById('sel-cliente');
  var cName = (cH && cH.value) ? (cH.getAttribute('data-nombre') || '[Cliente]') : '[Cliente]';
  var mH = document.getElementById('sel-mascota');
  var mName = (mH && mH.value) ? (mH.getAttribute('data-nombre') || '[Mascota]') : '[Mascota]';
  var fd = getV('ef-fecha');
  var fmtDate = fd ? new Date(fd+'T12:00').toLocaleDateString('es-PE',{day:'2-digit',month:'2-digit',year:'numeric'}) : '[Fecha]';
  var hr = getV('ef-hora');
  var fmtHora = '[Hora]';
  if (hr) { var p=hr.split(':'); var h=parseInt(p[0]); var ap=h>=12?'PM':'AM'; h=h%12||12; fmtHora=h+':'+p[1]+' '+ap; }
  var pv = getV('ef-proxima');
  var fmtPv = pv ? new Date(pv+'T12:00').toLocaleDateString('es-PE',{day:'2-digit',month:'2-digit',year:'numeric'}) : '[Fecha]';
  return tpl
    .replace(/\{clinica\}/g, WA_CLINICA)
    .replace(/\{veterinaria\}/g, WA_CLINICA)
    .replace(/\{nombre_cliente\}/g, cName)
    .replace(/\{nombre_mascota\}/g, mName)
    .replace(/\{fecha\}/g, fmtDate)
    .replace(/\{hora\}/g, fmtHora)
    .replace(/\{veterinario\}/g, getV('ef-vet') || '[Veterinario]')
    .replace(/\{diagnostico\}/g, getV('ef-diag') || '[Diagnóstico]')
    .replace(/\{total\}/g, getV('ef-total') || '0.00')
    .replace(/\{numero_boleta\}/g, getV('ef-nro') || 'B001-00001')
    .replace(/\{proxima_vacuna\}/g, fmtPv)
    .replace(/\{servicio\}/g, getV('ef-servicio') || '[Servicio]')
    .replace(/\{precio\}/g, getV('ef-precio') || '0.00')
    .replace(/\{tipo_vacuna\}/g, getV('ef-vacuna') || 'Vacuna');
}

// Al elegir un servicio de grooming, copiar su precio del catálogo
function groomPrecio(){
  var s=document.getElementById('ef-servicio');
  if(s){ var o=s.options[s.selectedIndex]; var p=o?o.getAttribute('data-precio'):null;
    var inp=document.getElementById('ef-precio'); if(inp && p) inp.value=p; }
  updatePreview();
}

function buildURL() {
  var tel = (document.getElementById('inp-tel')?.value || '').replace(/[\s+\-()\[\]]/g,'');
  var msg = resolveMsg();
  if (!tel || !msg) return '';
  return 'https://wa.me/' + tel + '?text=' + encodeURIComponent(msg);
}

function updatePreview() {
  var msg = resolveMsg();
  var display = msg.replace(/\*(.*?)\*/g,'$1').replace(/_(.*?)_/g,'$1');
  var el = document.getElementById('prev-msg');
  if (el) el.textContent = display || 'Escribe tu mensaje...';
  var cH = document.getElementById('sel-cliente');
  if (cH && cH.value) {
    var name = cH.getAttribute('data-nombre') || '';
    if (name) {
      var av = name.split(' ').map(n=>n[0]).join('').slice(0,2).toUpperCase();
      var el2 = document.getElementById('prev-av'); if(el2) el2.textContent=av;
      var el3 = document.getElementById('prev-name'); if(el3) el3.textContent=name;
    }
  }
  var tel = document.getElementById('inp-tel');
  if (tel && tel.value) { var e4=document.getElementById('prev-tel'); if(e4) e4.textContent=tel.value; }
  var url = buildURL();
  var el5 = document.getElementById('prev-url');
  if (el5) el5.textContent = url ? url.substring(0,100)+'...' : 'Completa teléfono y mensaje...';
}

// ═══ Autocompletar Cliente / Mascota ═══
var WA_CLIENTES = <?= json_encode(array_map(fn($c)=>['id'=>(int)$c['id'],'nombre'=>$c['nombre'],'tel'=>$c['telefono']], $clientes), JSON_UNESCAPED_UNICODE) ?>;
var WA_MASCOTAS = <?= json_encode(array_map(fn($m)=>['id'=>(int)$m['id'],'nombre'=>$m['nombre'],'dueno'=>$m['dueno'],'cli'=>(int)$m['cliente_id'],'esp'=>$m['especie']], $mascotas), JSON_UNESCAPED_UNICODE) ?>;
function _wEsc(s){ return (s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

function acCliFilter(){
  var inp=document.getElementById('ac-cli-inp'), drop=document.getElementById('ac-cli-drop');
  if(!inp||!drop) return;
  var q=(inp.value||'').trim().toLowerCase();
  var res=WA_CLIENTES.filter(function(c){ return q==='' || (c.nombre||'').toLowerCase().indexOf(q)>=0 || (c.tel||'').indexOf(q)>=0; }).slice(0,25);
  drop.innerHTML = res.length ? res.map(function(c){
      return '<div onmousedown="acCliPick('+c.id+')" style="padding:8px 11px;cursor:pointer;border-bottom:1px solid var(--border);font-size:13px">'
        +'<strong>'+_wEsc(c.nombre)+'</strong>'+(c.tel?' <span style="color:var(--text3)">· '+_wEsc(c.tel)+'</span>':'')+'</div>';
    }).join('') : '<div style="padding:9px 11px;font-size:12px;color:var(--text3)">Sin resultados</div>';
  drop.style.display='block';
}
function acCliPick(id){
  var c=WA_CLIENTES.filter(function(x){return x.id===id;})[0]; if(!c) return;
  var inp=document.getElementById('ac-cli-inp'); if(inp) inp.value=c.nombre;
  var h=document.getElementById('sel-cliente');
  if(h){ h.value=c.id; h.setAttribute('data-nombre',c.nombre); h.setAttribute('data-tel',c.tel||''); }
  var tel=document.getElementById('inp-tel'); if(tel && c.tel) tel.value=c.tel;
  var d=document.getElementById('ac-cli-drop'); if(d) d.style.display='none';
  // Si la mascota elegida no es de este cliente, se limpia
  var mh=document.getElementById('sel-mascota');
  if(mh && mh.value){
    var m=WA_MASCOTAS.filter(function(x){return x.id==mh.value;})[0];
    if(m && m.cli!==c.id) acMasClear();
  }
  var hint=document.getElementById('ac-mas-hint');
  if(hint){ var n=WA_MASCOTAS.filter(function(x){return x.cli===c.id;}).length; hint.textContent = n ? '🐾 '+n+' mascota(s) registradas de este cliente' : ''; }
  updatePreview();
}
function acCliBlur(){ setTimeout(function(){ var d=document.getElementById('ac-cli-drop'); if(d) d.style.display='none'; },150); }

function acMasClear(){
  var h=document.getElementById('sel-mascota'); if(h){ h.value=''; h.setAttribute('data-nombre',''); }
  var i=document.getElementById('ac-mas-inp'); if(i) i.value='';
}
function acMasFilter(){
  var inp=document.getElementById('ac-mas-inp'), drop=document.getElementById('ac-mas-drop');
  if(!inp||!drop) return;
  var q=(inp.value||'').trim().toLowerCase();
  var cid=parseInt((document.getElementById('sel-cliente')||{}).value||'0',10);
  var base=WA_MASCOTAS;
  // Si ya hay cliente elegido, priorizar sus mascotas
  if(cid){ var suyas=base.filter(function(m){return m.cli===cid;}); if(suyas.length) base=suyas; }
  var res=base.filter(function(m){ return q==='' || (m.nombre||'').toLowerCase().indexOf(q)>=0 || (m.dueno||'').toLowerCase().indexOf(q)>=0; }).slice(0,25);
  drop.innerHTML = res.length ? res.map(function(m){
      var ic=({perro:'🐕',gato:'🐈',conejo:'🐰',ave:'🐦',reptil:'🦎',roedor:'🐭'})[m.esp]||'🐾';
      return '<div onmousedown="acMasPick('+m.id+')" style="padding:8px 11px;cursor:pointer;border-bottom:1px solid var(--border);font-size:13px">'
        +ic+' <strong>'+_wEsc(m.nombre)+'</strong> <span style="color:var(--text3)">('+_wEsc(m.dueno)+')</span></div>';
    }).join('') : '<div style="padding:9px 11px;font-size:12px;color:var(--text3)">Sin resultados</div>';
  drop.style.display='block';
}
function acMasPick(id){
  var m=WA_MASCOTAS.filter(function(x){return x.id===id;})[0]; if(!m) return;
  var inp=document.getElementById('ac-mas-inp'); if(inp) inp.value=m.nombre;
  var h=document.getElementById('sel-mascota');
  if(h){ h.value=m.id; h.setAttribute('data-nombre',m.nombre); }
  // Si no había cliente, se toma el dueño de la mascota
  var ch=document.getElementById('sel-cliente');
  if(ch && !ch.value){ acCliPick(m.cli); var ci=document.getElementById('ac-cli-inp'); if(ci) ci.value=m.dueno; }
  var d=document.getElementById('ac-mas-drop'); if(d) d.style.display='none';
  updatePreview();
}
function acMasBlur(){ setTimeout(function(){ var d=document.getElementById('ac-mas-drop'); if(d) d.style.display='none'; },150); }

function loadCliente(id) { acCliPick(parseInt(id,10)); }

function openWA() {
  var url = buildURL();
  if (!url) { alert('Completa el teléfono y el mensaje primero.'); return false; }
  window.open(url, '_blank');
  guardarLog(true);
  return false;
}

function copyURL() {
  var url = buildURL();
  if (!url) { alert('Completa el formulario primero.'); return; }
  navigator.clipboard.writeText(url).then(() => showOk('✓ URL copiada'));
}

function guardarLog(silent) {
  var cSel = document.getElementById('sel-cliente');
  var mSel = document.getElementById('sel-mascota');
  var cid = cSel ? cSel.value : '';
  var mid = mSel ? mSel.value : '';
  var tel = document.getElementById('inp-tel')?.value || '';
  var msg = resolveMsg();
  var url = buildURL();
  if (!cid || !tel) return;
  fetch('?p=whatsapp', {
    method:'POST',
    headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body: new URLSearchParams({action:'log',cliente_id:cid,mascota_id:mid||0,tipo:currentType,mensaje:msg,telefono:tel,url:url})
  }).then(() => { if(!silent) showOk('✓ Registrado en historial'); });
}

// Guardar la plantilla editada de forma permanente (por tipo de mensaje)
function guardarPlantilla() {
  var texto = document.getElementById('msg-text').value;
  if (!currentType) { alert('Selecciona primero un tipo de mensaje.'); return; }
  fetch('?p=whatsapp', {
    method:'POST',
    headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body: new URLSearchParams({action:'save_template', tipo:currentType, mensaje:texto})
  }).then(r=>r.json()).then(function(d){
    if (d && d.ok) {
      TEMPLATES[currentType] = texto;  // reflejar el cambio en memoria
      showOk('✓ Plantilla guardada');
    } else {
      showOk('⚠️ No se pudo guardar');
    }
  }).catch(function(){ showOk('⚠️ No se pudo guardar'); });
}

// Restablecer la plantilla actual a su texto por defecto
function resetPlantilla() {
  if (!currentType) return;
  if (!confirm('¿Restablecer este mensaje a su versión original? Se perderá tu texto guardado para este tipo.')) return;
  fetch('?p=whatsapp', {
    method:'POST',
    headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body: new URLSearchParams({action:'reset_template', tipo:currentType})
  }).then(r=>r.json()).then(function(){
    var def = TEMPLATES_DEFAULT[currentType] || '';
    TEMPLATES[currentType] = def;
    document.getElementById('msg-text').value = def;
    updatePreview();
    showOk('✓ Plantilla restablecida');
  });
}

function showOk(txt) {
  var el = document.getElementById('wa-ok');
  if (!el) return;
  el.textContent = txt; el.style.display='block';
  setTimeout(() => { el.style.display='none'; }, 2500);
}

function insertVar(v) {
  var ta = document.getElementById('msg-text');
  if (!ta) return;
  var pos = ta.selectionStart;
  ta.value = ta.value.slice(0,pos) + v + ta.value.slice(pos);
  ta.selectionStart = ta.selectionEnd = pos + v.length;
  ta.focus(); updatePreview();
}

function loadPlantilla(tipo, msg) {
  waTab(0);
  selectType(tipo);
  document.getElementById('msg-text').value = msg;
  updatePreview();
}

function waTab(n) {
  for(var i=0;i<4;i++){
    var el=document.getElementById('wa-tab-'+i);
    var btn=document.getElementById('tab-btn-'+i);
    if(el) el.style.display=i===n?'block':'none';
    if(btn){btn.className=i===n?'btn btn-primary btn-sm':'btn btn-sm';}
  }
}

// Init
selectType(currentType);
<?php if($pre_cliente): ?>
acCliPick(<?= $pre_cliente ?>);
<?php endif; ?>
<?php if($pre_mascota): ?>
acMasPick(<?= $pre_mascota ?>);
<?php endif; ?>
updatePreview();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
