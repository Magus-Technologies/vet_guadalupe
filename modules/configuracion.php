<?php
$page = 'configuracion'; $pageTitle = 'Configuración';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/config_sunat.php';
if (!hasRole(['admin'])) {
    echo '<div class="alert alert-danger">🔒 Solo administradores.</div>';
    require_once __DIR__.'/../includes/footer.php'; exit;
}
$db = getDB();

// ── Asegurar tabla configuracion_sede para series por sede ────
try {
    $db->exec("CREATE TABLE IF NOT EXISTS configuracion_sede (
        id INT AUTO_INCREMENT PRIMARY KEY,
        sede_id INT NOT NULL,
        clave VARCHAR(100) NOT NULL,
        valor TEXT,
        UNIQUE KEY uk_sede_clave (sede_id, clave)
    )");
} catch(Exception $e) {}

$msg = ''; $msg_tipo = 'success';
$tab = $_GET['tab'] ?? 'clinica';
$sede_cfg = (int)($_GET['sede_cfg'] ?? 1); // sede seleccionada en config

// ── POST ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pa = $_POST['action'] ?? '';

    // Guardar config global
    if ($pa === 'save_global') {
        $campos = [
            'clinica_nombre','clinica_ruc','clinica_telefono','clinica_email',
            'clinica_direccion','director_nombre','director_cmp',
            'moneda','igv','cuenta_yape','cuenta_bcp',
            'hora_inicio','hora_fin','duracion_cita','plantilla_wa_cita'
        ];
        try {
            foreach ($campos as $k) {
                $v = trim($_POST[$k] ?? '');
                $db->prepare("INSERT INTO configuracion (clave,valor) VALUES (?,?) ON DUPLICATE KEY UPDATE valor=?")
                   ->execute([$k, $v, $v]);
            }
            // Guardar también en claves legacy que usa facturacion
            $mapa = [
                'clinica_nombre'   => 'nombre_clinica',
                'clinica_ruc'      => 'ruc_clinica',
                'clinica_telefono' => 'telefono_clinica',
                'clinica_email'    => 'email_clinica',
                'clinica_direccion'=> 'direccion_clinica',
                'igv'              => 'igv_porcentaje',
            ];
            foreach ($mapa as $new => $old) {
                $v = trim($_POST[$new] ?? '');
                $db->prepare("INSERT INTO configuracion (clave,valor) VALUES (?,?) ON DUPLICATE KEY UPDATE valor=?")
                   ->execute([$old, $v, $v]);
            }
            $msg = '✅ Configuración general guardada correctamente.';
        } catch(Exception $e) { $msg='❌ Error: '.$e->getMessage(); $msg_tipo='danger'; }
    }

    // Guardar series por sede
    if ($pa === 'save_series') {
        $sid = (int)($_POST['sede_id'] ?? 1);
        $series = [
            'serie_factura','serie_boleta','serie_ticket',
            'serie_nota_credito','serie_nota_debito'
        ];
        $avisos = [];
        try {
            foreach ($series as $k) {
                $v = strtoupper(trim($_POST[$k] ?? ''));
                if (!$v) continue;
                $db->prepare("INSERT INTO configuracion_sede (sede_id,clave,valor) VALUES (?,?,?) ON DUPLICATE KEY UPDATE valor=?")
                   ->execute([$sid, $k, $v, $v]);

                // Correlativo inicial (para migración desde sistema anterior).
                // Solo se acepta si es MAYOR al MAX(numero) ya emitido en `ventas`,
                // porque SUNAT no permite saltar hacia atrás ni reusar correlativos.
                $rawInicio = trim($_POST['correlativo_inicio_' . $k] ?? '');
                if ($rawInicio !== '') {
                    $inicio = (int)$rawInicio;
                    $st = $db->prepare("SELECT COALESCE(MAX(numero),0) FROM ventas WHERE serie=?");
                    $st->execute([$v]);
                    $maxEmitido = (int)$st->fetchColumn();

                    if ($inicio <= 0) {
                        // Vacío/0 => quitar el override
                        $db->prepare("DELETE FROM configuracion WHERE clave=?")
                           ->execute(['correlativo_inicio_' . $v]);
                    } elseif ($inicio <= $maxEmitido) {
                        $avisos[] = "Serie $v: el próximo n° debe ser mayor a $maxEmitido (ya emitido). Se ignoró '$inicio'.";
                    } else {
                        $db->prepare("INSERT INTO configuracion (clave,valor) VALUES (?,?) ON DUPLICATE KEY UPDATE valor=?")
                           ->execute(['correlativo_inicio_' . $v, $inicio, $inicio]);
                    }
                }
            }
            // Guardar también en tabla configuracion global (legacy para facturacion)
            // Solo si es la sede principal (sede 1) o si no hay config sede-específica
            $sf = strtoupper(trim($_POST['serie_factura'] ?? ''));
            $sb = strtoupper(trim($_POST['serie_boleta']  ?? ''));
            if ($sid == 1) {
                if ($sf) $db->prepare("INSERT INTO configuracion (clave,valor) VALUES ('serie_factura',?) ON DUPLICATE KEY UPDATE valor=?")->execute([$sf,$sf]);
                if ($sb) $db->prepare("INSERT INTO configuracion (clave,valor) VALUES ('serie_boleta',?) ON DUPLICATE KEY UPDATE valor=?")->execute([$sb,$sb]);
            }
            if ($avisos) {
                $msg = '⚠️ Series guardadas con observaciones: <br>• ' . implode('<br>• ', $avisos);
                $msg_tipo = 'warning';
            } else {
                $msg = '✅ Series de la sede actualizadas correctamente.';
            }
            $tab = 'series';
        } catch(Exception $e) { $msg='❌ Error: '.$e->getMessage(); $msg_tipo='danger'; }
    }

    // Subir logo
    if ($pa === 'save_logo') {
        if (!empty($_FILES['logo']['tmp_name']) && $_FILES['logo']['error']===0) {
            $mime = mime_content_type($_FILES['logo']['tmp_name']);
            if (in_array($mime,['image/jpeg','image/png','image/webp','image/gif'])) {
                $ext  = $mime==='image/png'?'png':($mime==='image/webp'?'webp':'jpg');
                $path = UPLOADS_PATH . '/logo_clinica.'.$ext;
                move_uploaded_file($_FILES['logo']['tmp_name'], $path);
                $rel  = 'logo_clinica.'.$ext.'?v='.time();
                $db->prepare("INSERT INTO configuracion (clave,valor) VALUES ('logo_path',?) ON DUPLICATE KEY UPDATE valor=?")->execute([$rel,$rel]);
                $msg = '✅ Logo actualizado correctamente.';
            } else { $msg='❌ Formato no válido. Usa JPG, PNG o WebP.'; $msg_tipo='danger'; }
        }
    }

    // ── Guardar config SUNAT ─────────────────────────────────────
    if ($pa === 'save_sunat') {
        $campos = ['sunat_usuario_sol','sunat_clave_sol','sunat_modo'];
        try {
            foreach ($campos as $k) {
                $v = trim($_POST[$k] ?? '');
                $db->prepare("INSERT INTO configuracion (clave,valor) VALUES (?,?) ON DUPLICATE KEY UPDATE valor=?")
                   ->execute([$k, $v, $v]);
            }
            // Limpiar cache de sunat
            unset($GLOBALS['__sunat_cfg_loaded'], $GLOBALS['__sunat_cfg_db']);
            $msg = '✅ Configuración SUNAT guardada correctamente.';
        } catch(Exception $e) { $msg='❌ Error: '.$e->Message(); $msg_tipo='danger'; }
        $tab = 'sunat';
    }

    // ── Subir .pem al API Laravel ──────────────────────────────
    if ($pa === 'subir_pem') {
        $ruc = trim($_POST['sunat_ruc'] ?? '');
        if (strlen($ruc) !== 11) {
            $msg = '❌ Primero guarda un RUC válido (11 dígitos) antes de subir el certificado.';
            $msg_tipo = 'danger';
        } elseif (empty($_FILES['pem']['name']) || $_FILES['pem']['error'] !== UPLOAD_ERR_OK) {
            $msg = '❌ Selecciona un archivo .pem válido.';
            $msg_tipo = 'danger';
        } else {
            $tmp = $_FILES['pem']['tmp_name'];
            $ext = strtolower(pathinfo($_FILES['pem']['name'], PATHINFO_EXTENSION));
            if ($ext !== 'pem') {
                $msg = '❌ Solo se acepta archivo .pem (convertido desde tu .pfx con OpenSSL).';
                $msg_tipo = 'danger';
            } elseif ($_FILES['pem']['size'] > 512 * 1024) {
                $msg = '❌ El certificado no debe superar 512 KB.';
                $msg_tipo = 'danger';
            } else {
                $apiUrl = $cfg['sunat_api_url'] ?? SUNAT_API_URL;
                $endpoint = rtrim($apiUrl, '/').'/guardar/certificado/'.$ruc;
                $cfile = curl_file_create($tmp, 'application/x-pem-file', $_FILES['pem']['name']);
                $ch = curl_init($endpoint);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST           => true,
                    CURLOPT_POSTFIELDS     => ['certificado' => $cfile],
                    CURLOPT_TIMEOUT        => 60,
                    CURLOPT_HTTPHEADER     => ['Accept: application/json'],
                    CURLOPT_SSL_VERIFYPEER => false,
                ]);
                $res  = curl_exec($ch);
                $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $err  = curl_error($ch);
                curl_close($ch);

                if ($res === false) {
                    $msg = "❌ No se pudo conectar al API SUNAT ($endpoint): $err";
                    $msg_tipo = 'danger';
                } else {
                    $j = json_decode($res, true);
                    if (!is_array($j) || empty($j['estado'])) {
                        $msg = "❌ El API rechazó el certificado: " . ($j['mensaje'] ?? "Respuesta HTTP $code: " . substr($res, 0, 200));
                        $msg_tipo = 'danger';
                    } else {
                        $db->prepare("INSERT INTO configuracion (clave,valor) VALUES ('certificado_subido',?) ON DUPLICATE KEY UPDATE valor=?")
                           ->execute(['1', '1']);
                        $db->prepare("INSERT INTO configuracion (clave,valor) VALUES ('certificado_fecha',?) ON DUPLICATE KEY UPDATE valor=?")
                           ->execute([date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]);
                        $msg = '✅ Certificado .pem subido correctamente al API SUNAT (RUC '.$ruc.').';
                    }
                }
            }
        }
        $tab = 'sunat';
    }
}

// ── Cargar configs ────────────────────────────────────────────
$cfg = [];
try { $rows=$db->query("SELECT clave,valor FROM configuracion")->fetchAll(); foreach($rows as $r) $cfg[$r['clave']]=$r['valor']; } catch(Exception $e){}

function cfgV($cfg, $k, $d='') { return htmlspecialchars($cfg[$k]??$d, ENT_QUOTES,'UTF-8'); }

// Series por sede
$sedes = [];
try { $sedes=$db->query("SELECT * FROM sedes ORDER BY nombre")->fetchAll(); } catch(Exception $e){}

$series_sede = []; // [sede_id][clave] = valor
try {
    $rows=$db->query("SELECT * FROM configuracion_sede")->fetchAll();
    foreach ($rows as $r) $series_sede[$r['sede_id']][$r['clave']] = $r['valor'];
} catch(Exception $e){}

// Series por defecto si no están configuradas
function getSerieDefault($tipo, $sede_id) {
    // Nota de crédito/débito: 4 caracteres con prefijo F/B según el comprobante
    // que modifica. El módulo de notas valida y deriva la serie si no aplica.
    if ($tipo === 'serie_nota_credito' || $tipo === 'serie_nota_debito') {
        $pref = $tipo === 'serie_nota_debito' ? 'BD' : 'BC';
        return $pref . str_pad($sede_id, 2, '0', STR_PAD_LEFT);
    }
    $prefijos = ['serie_factura'=>'F','serie_boleta'=>'B','serie_ticket'=>'T'];
    $pref = $prefijos[$tipo] ?? 'X';
    return $pref . str_pad($sede_id, 3, '0', STR_PAD_LEFT);
}

function getSerieSede($series_sede, $cfg, $tipo, $sede_id) {
    if (isset($series_sede[$sede_id][$tipo])) return $series_sede[$sede_id][$tipo];
    // Fallback a config global para sede 1
    if ($sede_id == 1) {
        $legacy = ['serie_factura'=>'serie_factura','serie_boleta'=>'serie_boleta'];
        if (isset($legacy[$tipo]) && !empty($cfg[$legacy[$tipo]])) return $cfg[$legacy[$tipo]];
    }
    return getSerieDefault($tipo, $sede_id);
}

// Último número usado por serie y sede
function getUltimoNum($db, $serie) {
    try { return (int)$db->prepare("SELECT COALESCE(MAX(numero),0) FROM ventas WHERE serie=?")->execute([$serie]) ? $db->prepare("SELECT COALESCE(MAX(numero),0) FROM ventas WHERE serie=?")->execute([$serie]) && ($r=$db->query("SELECT COALESCE(MAX(numero),0) FROM ventas WHERE serie='$serie'")) ? (int)$r->fetchColumn() : 0 : 0; } catch(Exception $e){ return 0; }
}
// Versión limpia
function ultimoNumSerie($db, $serie) {
    try {
        $st = $db->prepare("SELECT COALESCE(MAX(numero),0) FROM ventas WHERE serie=?");
        $st->execute([$serie]);
        $maxVentas = (int)$st->fetchColumn();

        // Override para migración desde sistema anterior: si hay un "próximo n°" configurado,
        // forzamos que ultimoNum = inicio - 1 (siempre que sea mayor al MAX emitido real).
        $st = $db->prepare("SELECT valor FROM configuracion WHERE clave=?");
        $st->execute(['correlativo_inicio_' . $serie]);
        $inicio = (int)$st->fetchColumn();

        return $inicio > 0 ? max($maxVentas, $inicio - 1) : $maxVentas;
    } catch(Exception $e){ return 0; }
}

function correlativoInicio($db, $serie): int {
    try {
        $st = $db->prepare("SELECT valor FROM configuracion WHERE clave=?");
        $st->execute(['correlativo_inicio_' . $serie]);
        return (int)$st->fetchColumn();
    } catch(Exception $e){ return 0; }
}

$logo_path = $cfg['logo_path'] ?? '';
?>

<style>
.cfg-tab-nav { display:flex; gap:4px; background:var(--bg3); padding:4px; border-radius:10px; margin-bottom:20px; flex-wrap:wrap; }
.cfg-tab-btn { padding:8px 16px; border-radius:8px; font-size:13px; font-weight:600; text-decoration:none; color:var(--text2); border:none; background:none; cursor:pointer; transition:all .15s; white-space:nowrap; }
.cfg-tab-btn.active { background:var(--bg2); color:var(--text); box-shadow:0 2px 8px rgba(0,0,0,.08); }
.serie-card { background:var(--bg2); border:1.5px solid var(--border); border-radius:12px; padding:16px; margin-bottom:12px; }
.serie-card-header { display:flex; align-items:center; justify-content:space-between; margin-bottom:14px; }
.serie-badge { padding:3px 10px; border-radius:999px; font-size:11px; font-weight:700; }
</style>

<div class="page">
<?php if($msg): ?><div class="alert alert-<?= $msg_tipo ?> mb-3"><?= $msg ?></div><?php endif; ?>

<div class="sec-header mb-3">
  <div><div class="page-title">⚙️ Configuración del Sistema</div><div class="page-desc">Ajustes globales y por sede</div></div>
</div>

<!-- Tabs -->
<div class="cfg-tab-nav">
  <a href="?p=configuracion&tab=clinica"  class="cfg-tab-btn <?= $tab==='clinica'?'active':'' ?>">🏥 Clínica</a>
  <a href="?p=configuracion&tab=series"  class="cfg-tab-btn <?= $tab==='series'?'active':'' ?>">🧾 Series por Sede</a>
  <a href="?p=configuracion&tab=sunat"    class="cfg-tab-btn <?= $tab==='sunat'?'active':'' ?>">🏛️ SUNAT</a>
  <a href="?p=configuracion&tab=agenda"   class="cfg-tab-btn <?= $tab==='agenda'?'active':'' ?>">📅 Agenda</a>
  <a href="?p=configuracion&tab=notif"    class="cfg-tab-btn <?= $tab==='notif'?'active':'' ?>">📱 Notificaciones</a>
  <a href="?p=configuracion&tab=logo"     class="cfg-tab-btn <?= $tab==='logo'?'active':'' ?>">🖼️ Logo</a>
</div>

<?php if($tab==='clinica'): ?>
<!-- ═══ CLÍNICA ═══ -->
<form method="POST">
<input type="hidden" name="action" value="save_global">
<div class="grid g2" style="gap:16px">
  <div class="card">
    <div style="font-size:14px;font-weight:700;color:var(--text2);margin-bottom:14px">🏥 Datos de la clínica</div>
    <div class="form-group"><label class="form-label required">Nombre de la clínica</label><input type="text" name="clinica_nombre" class="form-input" value="<?= cfgV($cfg,'clinica_nombre') ?>" placeholder="VetPro Veterinaria"></div>
    <div class="form-row">
      <div class="form-group"><label class="form-label">RUC</label>
        <div style="display:flex;gap:8px;align-items:center">
          <input type="text" name="clinica_ruc" id="clinica_ruc" class="form-input" value="<?= cfgV($cfg,'clinica_ruc') ?>" placeholder="20123456789" maxlength="11" style="flex:1">
          <button type="button" id="btnBuscRuc" class="btn btn-sm" style="background:var(--primary);color:white;white-space:nowrap">🔍 Buscar SUNAT</button>
        </div>
      </div>
      <div class="form-group"><label class="form-label">Teléfono</label><input type="text" name="clinica_telefono" class="form-input" value="<?= cfgV($cfg,'clinica_telefono') ?>"></div>
    </div>
    <div class="form-group"><label class="form-label">Dirección</label><input type="text" name="clinica_direccion" id="clinica_direccion" class="form-input" value="<?= cfgV($cfg,'clinica_direccion') ?>"></div>
    <div class="form-group"><label class="form-label">Email</label><input type="email" name="clinica_email" class="form-input" value="<?= cfgV($cfg,'clinica_email') ?>"></div>
    <div class="form-row">
      <div class="form-group"><label class="form-label">Director / Responsable</label><input type="text" name="director_nombre" class="form-input" value="<?= cfgV($cfg,'director_nombre') ?>"></div>
      <div class="form-group"><label class="form-label">N° CMP</label><input type="text" name="director_cmp" class="form-input" value="<?= cfgV($cfg,'director_cmp') ?>"></div>
    </div>
  </div>
  <div class="card">
    <div style="font-size:14px;font-weight:700;color:var(--text2);margin-bottom:14px">💰 Configuración financiera</div>
    <div class="form-row">
      <div class="form-group"><label class="form-label">Moneda</label><input type="text" name="moneda" class="form-input" value="<?= cfgV($cfg,'moneda','S/') ?>" placeholder="S/"></div>
      <div class="form-group"><label class="form-label">IGV %</label><input type="number" name="igv" class="form-input" value="<?= cfgV($cfg,'igv','18') ?>" min="0" max="100"></div>
    </div>
    <div class="form-group"><label class="form-label">📱 N° Yape</label><input type="text" name="cuenta_yape" class="form-input" value="<?= cfgV($cfg,'cuenta_yape') ?>" placeholder="9XX XXX XXX"></div>
    <div class="form-group"><label class="form-label">🏦 N° Cuenta BCP</label><input type="text" name="cuenta_bcp" class="form-input" value="<?= cfgV($cfg,'cuenta_bcp') ?>"></div>
    <div class="card" style="background:rgba(30,168,161,.06);border-color:rgba(30,168,161,.2);margin-top:12px">
      <div style="font-size:12px;font-weight:700;color:var(--primary);margin-bottom:8px">📜 Cumplimiento SUNAT / SIHCE</div>
      <ul style="font-size:11px;color:var(--text3);margin:0;padding-left:16px">
        <li>✅ Auditoría de accesos y cambios</li>
        <li>✅ Historia clínica electrónica</li>
        <li>✅ Control de sesiones y roles</li>
        <li>✅ Logs de actividad completos</li>
        <li>✅ Series de comprobantes por sede</li>
      </ul>
    </div>
  </div>
</div>
<div style="margin-top:16px"><button type="submit" class="btn btn-primary btn-lg">💾 Guardar configuración</button></div>
</form>

<script>
function showToast(msg, type) {
  type = type || 'info';
  var el = document.createElement('div');
  el.style.cssText = 'position:fixed;top:20px;right:20px;z-index:9999;padding:12px 20px;border-radius:8px;font-size:14px;font-weight:600;max-width:350px;animation:slideIn .3s ease';
  el.className = 'toast toast-' + type;
  var colors = {'success':'#10b981,#d1fae5','error':'#ef4444,#fee2e2','info':'#3b82f6,#dbeafe'};
  var c = colors[type] ? colors[type].split(',') : colors['info'].split(',');
  el.style.background = c[1];
  el.style.color = c[0];
  el.style.border = '1px solid ' + c[0];
  el.innerHTML = msg;
  document.body.appendChild(el);
  setTimeout(function(){ el.style.opacity='0'; el.style.transition='opacity .3s'; setTimeout(function(){ el.remove(); }, 300); }, 3000);
}
var style = document.createElement('style');
style.textContent = '@keyframes slideIn{from{transform:translateX(100%);opacity:0}to{transform:translateX(0);opacity:1}}';
document.head.appendChild(style);
(function() {
  var rucInp = document.getElementById('clinica_ruc');
  var btn    = document.getElementById('btnBuscRuc');
  if (!rucInp || !btn) return;
  btn.addEventListener('click', async function() {
    var doc = rucInp.value.trim();
    if (doc.length !== 11) { showToast('El RUC debe tener 11 digitos.', 'error'); return; }
    var orig = btn.innerHTML;
    btn.disabled = true; btn.innerHTML = 'Consultando...';
    try {
      var r = await fetch('<?= BASE_URL ?>/api/proxy_documento.php?tipo=ruc&numero=' + doc);
      var j = await r.json();
      if (!j.ok) { showToast(j.error || 'No se encontro el RUC.', 'error'); return; }
      document.querySelector('input[name=clinica_nombre]').value = j.nombre || '';
      document.querySelector('input[name=clinica_direccion]').value = j.direccion || '';
      showToast('Encontrado: ' + j.nombre, 'success');
    } catch(e) { showToast('Error de red: ' + e.message, 'error'); }
    finally { btn.disabled = false; btn.innerHTML = orig; }
  });
  rucInp.addEventListener('keydown', function(e) { if (e.key === 'Enter') { e.preventDefault(); btn.click(); } });
})();
</script>

<?php elseif($tab==='series'): ?>
<!-- ═══ SERIES POR SEDE ═══ -->
<div class="card mb-4" style="background:rgba(99,102,241,.06);border-color:rgba(99,102,241,.2)">
  <div style="font-size:13px;color:var(--accent);font-weight:600;margin-bottom:6px">ℹ️ Cómo funcionan las series por sede</div>
  <div style="font-size:12px;color:var(--text2);line-height:1.6">
    Cada sede emite comprobantes con su propia serie. Ejemplo: <strong>Sede Principal → F001</strong>, <strong>Sede San Isidro → F002</strong>.
    Al facturar, el sistema detecta automáticamente la sede activa y usa su serie correspondiente.
    El número correlativo es independiente por serie.
  </div>
</div>

<?php foreach($sedes as $s): $sid=$s['id']; $color=$s['color']??'#1ea8a1'; ?>
<div class="card mb-3" style="border-top:3px solid <?= $color ?>">
  <div class="serie-card-header">
    <div style="display:flex;align-items:center;gap:10px">
      <div style="width:10px;height:10px;border-radius:50%;background:<?= $color ?>"></div>
      <div style="font-size:15px;font-weight:700"><?= clean($s['nombre']) ?></div>
    </div>
    <div style="font-size:11px;color:var(--text3)">Sede ID: <?= $sid ?></div>
  </div>

  <form method="POST">
    <input type="hidden" name="action" value="save_series">
    <input type="hidden" name="sede_id" value="<?= $sid ?>">

    <div class="grid g3" style="gap:12px;margin-bottom:14px">
      <?php
      $tipos = [
        'serie_factura'      => ['label'=>'Factura','icon'=>'🟦','desc'=>'Para clientes con RUC'],
        'serie_boleta'       => ['label'=>'Boleta', 'icon'=>'🟩','desc'=>'Para personas naturales'],
        'serie_ticket'       => ['label'=>'Ticket',  'icon'=>'⬜','desc'=>'Venta interna / sin IGV'],
        'serie_nota_credito' => ['label'=>'N. Crédito','icon'=>'🟧','desc'=>'Devoluciones'],
        'serie_nota_debito'  => ['label'=>'N. Débito', 'icon'=>'🟥','desc'=>'Cobros adicionales'],
      ];
      foreach($tipos as $tipo_key => $tipo_info):
        $serie_val   = getSerieSede($series_sede, $cfg, $tipo_key, $sid);
        $ultimo_num  = ultimoNumSerie($db, $serie_val);
        $siguiente   = $ultimo_num + 1;
        $st_max = $db->prepare("SELECT COALESCE(MAX(numero),0) FROM ventas WHERE serie=?");
        $st_max->execute([$serie_val]);
        $max_real    = (int)$st_max->fetchColumn();
        $inicio_cfg  = correlativoInicio($db, $serie_val);
        $forzado     = $inicio_cfg > 0 && $inicio_cfg > $max_real;
      ?>
      <div style="background:var(--bg3);border-radius:10px;padding:12px">
        <div style="font-size:11px;color:var(--text3);margin-bottom:4px"><?= $tipo_info['icon'] ?> <?= $tipo_info['label'] ?></div>
        <input type="text" name="<?= $tipo_key ?>" class="form-input"
               value="<?= htmlspecialchars($serie_val) ?>"
               placeholder="<?= getSerieDefault($tipo_key, $sid) ?>"
               style="font-size:16px;font-weight:700;text-transform:uppercase;letter-spacing:1px;margin-bottom:6px"
               oninput="this.value=this.value.toUpperCase()">
        <div style="font-size:10px;color:var(--text3)"><?= $tipo_info['desc'] ?></div>
        <div style="font-size:11px;color:var(--text2);margin-top:4px">
          Último emitido: <strong style="color:var(--primary)"><?= $serie_val ?>-<?= str_pad($ultimo_num,5,'0',STR_PAD_LEFT) ?></strong>
          · Próximo: <strong><?= $serie_val ?>-<?= str_pad($siguiente,5,'0',STR_PAD_LEFT) ?></strong>
          <?php if ($forzado): ?><span style="font-size:10px;background:rgba(245,158,11,.15);color:#b45309;padding:1px 6px;border-radius:6px;margin-left:4px" title="Correlativo inicial configurado manualmente">🔧 forzado</span><?php endif; ?>
        </div>
        <div style="margin-top:8px;padding-top:8px;border-top:1px dashed var(--border);display:flex;align-items:center;gap:6px">
          <label style="font-size:10px;color:var(--text3);white-space:nowrap" title="Para empresas que migran desde otro sistema. Debe ser mayor al último emitido.">Próximo n°:</label>
          <input type="number"
                 name="correlativo_inicio_<?= $tipo_key ?>"
                 class="form-input"
                 value="<?= $inicio_cfg > 0 ? $inicio_cfg : '' ?>"
                 placeholder="<?= $siguiente ?>"
                 min="<?= $max_real + 1 ?>"
                 style="font-size:12px;padding:4px 8px;width:100px">
          <span style="font-size:10px;color:var(--text3)">(dejar vacío = automático)</span>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <button type="submit" class="btn btn-primary btn-sm" style="background:<?= $color ?>;border-color:<?= $color ?>">
      💾 Guardar series de <?= clean($s['nombre']) ?>
    </button>
  </form>
</div>
<?php endforeach; ?>

<?php elseif($tab==='agenda'): ?>
<!-- ═══ AGENDA ═══ -->
<form method="POST">
<input type="hidden" name="action" value="save_global">
<div class="card" style="max-width:600px">
  <div style="font-size:14px;font-weight:700;color:var(--text2);margin-bottom:14px">📅 Configuración de agenda</div>
  <div class="form-row">
    <div class="form-group"><label class="form-label">Hora inicio</label><input type="time" name="hora_inicio" class="form-input" value="<?= cfgV($cfg,'hora_inicio','08:00') ?>"></div>
    <div class="form-group"><label class="form-label">Hora fin</label><input type="time" name="hora_fin" class="form-input" value="<?= cfgV($cfg,'hora_fin','20:00') ?>"></div>
    <div class="form-group"><label class="form-label">Duración cita (min)</label><input type="number" name="duracion_cita" class="form-input" value="<?= cfgV($cfg,'duracion_cita','30') ?>" min="5" max="120"></div>
  </div>
  <button type="submit" class="btn btn-primary">💾 Guardar</button>
</div>
</form>

<?php elseif($tab==='notif'): ?>
<!-- ═══ NOTIFICACIONES ═══ -->
<form method="POST">
<input type="hidden" name="action" value="save_global">
<div class="card" style="max-width:700px">
  <div style="font-size:14px;font-weight:700;color:var(--text2);margin-bottom:14px">📱 Plantilla WhatsApp / Recordatorios</div>
  <div class="form-group">
    <label class="form-label">Plantilla recordatorio de cita</label>
    <textarea name="plantilla_wa_cita" class="form-input" rows="5"><?= cfgV($cfg,'plantilla_wa_cita','Estimado(a) *{nombre}*, le recordamos su cita en *{clinica}* el *{fecha}* a las *{hora}*. Ante consultas: {telefono}') ?></textarea>
    <div style="font-size:11px;color:var(--text3);margin-top:4px">Variables: <code>{nombre}</code> <code>{clinica}</code> <code>{fecha}</code> <code>{hora}</code> <code>{telefono}</code></div>
  </div>
  <button type="submit" class="btn btn-primary">💾 Guardar</button>
</div>
</form>

<?php elseif($tab==='logo'): ?>
<!-- ═══ LOGO ═══ -->
<form method="POST" enctype="multipart/form-data">
  <input type="hidden" name="action" value="save_logo">
  <div class="card" style="max-width:500px">
    <div style="font-size:14px;font-weight:700;color:var(--text2);margin-bottom:14px">🖼️ Logo de la clínica</div>
    <?php if($logo_path): ?>
    <div style="margin-bottom:16px;text-align:center">
      <img src="<?= UPLOADS_URL.'/'.clean($logo_path) ?>" alt="Logo" style="max-height:120px;max-width:300px;object-fit:contain;border:1px solid var(--border);border-radius:8px;padding:8px;background:#fff">
      <div style="font-size:11px;color:var(--text3);margin-top:6px">Logo actual</div>
    </div>
    <?php endif; ?>
    <div class="form-group">
      <label class="form-label">Subir nuevo logo</label>
      <input type="file" name="logo" accept="image/jpeg,image/png,image/webp" class="form-input" style="cursor:pointer">
      <div style="font-size:11px;color:var(--text3);margin-top:4px">Formatos: JPG, PNG, WebP. Tamaño recomendado: 300×100px</div>
    </div>
    <button type="submit" class="btn btn-primary">📤 Subir logo</button>
  </div>
</form>

<?php elseif($tab==='sunat'): ?>
<!-- ═══ SUNAT ═══ -->
<?php
$cert_subido = sunatCertSubido();
$cert_fecha  = sunatCertFecha();
?>

<!-- Credenciales SOL -->
<form method="POST" class="mb-3">
  <input type="hidden" name="action" value="save_sunat">
  <div class="card mb-3">
    <div style="font-size:14px;font-weight:700;color:var(--text2);margin-bottom:14px">🔐 Credenciales SUNAT (SOL)</div>
    <div class="grid g2" style="gap:14px;margin-bottom:14px">
      <div class="form-group">
        <label class="form-label">Usuario SOL</label>
        <input type="text" name="sunat_usuario_sol" class="form-input"
               value="<?= cfgV($cfg,'sunat_usuario_sol','MODDATOS') ?>"
               placeholder="Ej: MODDATOS" maxlength="45">
        <div style="font-size:11px;color:var(--text3);margin-top:4px">Para BETA usa <code>MODDATOS</code></div>
      </div>
      <div class="form-group">
        <label class="form-label">Clave SOL</label>
        <input type="password" name="sunat_clave_sol" class="form-input"
               value="<?= cfgV($cfg,'sunat_clave_sol','MODDATOS') ?>"
               placeholder="••••••••" maxlength="45">
        <div style="font-size:11px;color:var(--text3);margin-top:4px">Para BETA usa <code>MODDATOS</code></div>
      </div>
    </div>
    <div class="grid g2" style="gap:14px;margin-bottom:14px">
      <div class="form-group">
        <label class="form-label">Modo SUNAT</label>
        <select name="sunat_modo" class="form-input">
          <option value="beta"       <?= ($cfg['sunat_modo'] ?? 'beta')==='beta'?'selected':'' ?>>BETA (pruebas)</option>
          <option value="produccion" <?= ($cfg['sunat_modo'] ?? '')==='produccion'?'selected':'' ?>>PRODUCCIÓN (real)</option>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">📄 Certificado digital (.pem)</label>
        <?php if($cert_subido): ?>
          <div style="background:var(--green-l);border:1px solid rgba(16,185,129,.3);border-radius:8px;padding:8px 12px;font-size:12px;color:var(--success)">
            ✅ Certificado cargado (<?= $cert_fecha ? date('d/m/Y', strtotime($cert_fecha)) : '—' ?>)
          </div>
          <button type="button" id="btnSelectPem" class="btn btn-sm" style="margin-top:6px;background:var(--primary);color:white">
            🔄 Reemplazar
          </button>
        <?php else: ?>
          <div style="background:var(--red-l);border:1px solid rgba(239,68,68,.3);border-radius:8px;padding:8px 12px;font-size:12px;color:var(--danger);margin-bottom:6px">
            ❌ Sin certificado
          </div>
          <button type="button" id="btnSelectPem" class="btn btn-sm" style="background:var(--primary);color:white">
            📤 Subir .pem
          </button>
        <?php endif; ?>
        <div style="font-size:11px;color:var(--text3);margin-top:4px">Envía directamente al API Laravel</div>
      </div>
    </div>
    <button type="submit" class="btn btn-primary">💾 Guardar credenciales</button>
  </div>
</form>

<!-- Form oculto para subir .pem al API Laravel -->
<form method="POST" enctype="multipart/form-data" id="frmPem" style="display:none">
  <input type="hidden" name="action" value="subir_pem">
  <input type="hidden" name="sunat_ruc" value="<?= cfgV($cfg,'clinica_ruc') ?>">
  <input type="file" name="pem" id="pemFile" accept=".pem">
</form>

<script>
(function() {
  var btnPem = document.getElementById('btnSelectPem');
  var pemFile = document.getElementById('pemFile');
  if (!btnPem || !pemFile) return;
  btnPem.addEventListener('click', function() { pemFile.click(); });
  pemFile.addEventListener('change', function() {
    if (!pemFile.files[0]) return;
    var f = pemFile.files[0];
    if (!/\.pem$/i.test(f.name)) { alert('Solo archivos .pem'); pemFile.value = ''; return; }
    if (f.size > 512 * 1024) { alert('El certificado no debe superar 512 KB.'); pemFile.value = ''; return; }
    if (confirm('Subir certificado "' + f.name + '"?')) { document.getElementById('frmPem').submit(); }
    else { pemFile.value = ''; }
  });
})();
</script>

<?php endif; ?>
</div>

<?php require_once __DIR__.'/../includes/footer.php'; ?>
