<?php
$page = 'clientes'; $pageTitle = 'Clientes';
// ── MODO DIAGNÓSTICO TEMPORAL: agrega &debug=1 a la URL para ver errores ──
if (isset($_GET['debug'])) {
    ini_set('display_errors','1'); error_reporting(E_ALL);
    register_shutdown_function(function(){
        $e = error_get_last();
        if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            echo "<div style='background:#fee;border:2px solid #c00;padding:16px;margin:16px;font-family:monospace;font-size:13px;white-space:pre-wrap'>";
            echo "<strong>⚠️ ERROR DETECTADO:</strong>\n\n";
            echo "Mensaje: ".htmlspecialchars($e['message'])."\n";
            echo "Archivo: ".htmlspecialchars($e['file'])."\n";
            echo "Línea:   ".$e['line'];
            echo "</div>";
        }
    });
}
require_once __DIR__ . '/../includes/header.php';
$db = getDB();

// ── Lectores de archivos para importación (declarados arriba para que estén
//    disponibles antes del bloque que los usa) ──
if (!function_exists('leer_excel_xml')) {
function leer_excel_xml($raw) {
    $filas = [];
    if (!function_exists('simplexml_load_string')) return $filas;
    $prev = libxml_use_internal_errors(true);
    $xml = simplexml_load_string($raw);
    libxml_use_internal_errors($prev);
    if (!$xml) return $filas;
    $ns = $xml->getNamespaces(true);
    $ssns = $ns['ss'] ?? 'urn:schemas-microsoft-com:office:spreadsheet';
    foreach ($xml->xpath('//ss:Row') ?: [] as $row) {
        $celdas = []; $colIdx = 0;
        foreach ($row->children($ssns)->Cell as $cell) {
            $attrs = $cell->attributes($ssns);
            if (isset($attrs['Index'])) { $colIdx = ((int)$attrs['Index']) - 1; }
            $val = '';
            $data = $cell->children($ssns)->Data;
            if ($data !== null && count($data)) $val = (string)$data;
            $celdas[$colIdx] = $val; $colIdx++;
        }
        if ($celdas) { ksort($celdas); $filas[] = array_values($celdas); }
    }
    return $filas;
}
}
if (!function_exists('col_a_num')) {
function col_a_num($letras) {
    $n = 0;
    for ($i=0; $i<strlen($letras); $i++) { $n = $n*26 + (ord($letras[$i]) - 64); }
    return $n - 1;
}
}
if (!function_exists('leer_xlsx')) {
function leer_xlsx($path) {
    $filas = [];
    if (!class_exists('ZipArchive') || !function_exists('simplexml_load_string')) return $filas;
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) return $filas;
    $shared = [];
    if (($ss = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
        $p = libxml_use_internal_errors(true);
        $sx = simplexml_load_string($ss);
        libxml_use_internal_errors($p);
        if ($sx) foreach ($sx->si as $si) {
            $t = '';
            if (isset($si->t)) $t = (string)$si->t;
            elseif (isset($si->r)) foreach ($si->r as $r) $t .= (string)$r->t;
            $shared[] = $t;
        }
    }
    $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    if ($sheet === false) return $filas;
    $p = libxml_use_internal_errors(true);
    $x = simplexml_load_string($sheet);
    libxml_use_internal_errors($p);
    if (!$x) return $filas;
    foreach ($x->sheetData->row as $row) {
        $celdas = []; $colIdx = 0;
        foreach ($row->c as $c) {
            $ref = (string)($c['r'] ?? '');
            if ($ref && preg_match('/^([A-Z]+)/', $ref, $m)) { $colIdx = col_a_num($m[1]); }
            $tipo = (string)($c['t'] ?? '');
            $v = isset($c->v) ? (string)$c->v : '';
            if ($tipo === 's') { $v = $shared[(int)$v] ?? ''; }
            elseif ($tipo === 'inlineStr' && isset($c->is->t)) { $v = (string)$c->is->t; }
            $celdas[$colIdx] = $v; $colIdx++;
        }
        if ($celdas) { ksort($celdas); $filas[] = array_values($celdas); }
    }
    return $filas;
}
}

$action = $_GET['action'] ?? 'list';
$msg = '';

// Permiso de exportación para este rol (Administrador siempre puede).
$puede_exportar = canExport('clientes');

if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['action'])) {
    $a = $_POST['action'];
    if ($a === 'save') {
        $fields = ['nombre','dni','ruc','telefono','email','direccion','como_conocio','notas'];
        $data = [];
        foreach ($fields as $f) $data[$f] = trim($_POST[$f] ?? '');
        $id = (int)($_POST['id'] ?? 0);

        // ── Validar duplicados ──
        $nombre = $data['nombre'];
        $dni    = $data['dni'];
        $ruc    = $data['ruc'];
        $tel    = $data['telefono'];

        // Duplicado por DNI
        if ($dni) {
            $dup = $db->prepare("SELECT id,nombre FROM clientes WHERE dni=? AND activo=1 AND id!=?");
            $dup->execute([$dni, $id]); $d = $dup->fetch();
            if ($d) { $msg = 'dup:El DNI '.$dni.' ya está registrado para: "'.$d['nombre'].'"'; goto fin_save; }
        }
        // Duplicado por RUC
        if ($ruc) {
            $dup = $db->prepare("SELECT id,nombre FROM clientes WHERE ruc=? AND activo=1 AND id!=?");
            $dup->execute([$ruc, $id]); $d = $dup->fetch();
            if ($d) { $msg = 'dup:El RUC '.$ruc.' ya está registrado para: "'.$d['nombre'].'"'; goto fin_save; }
        }
        // Duplicado por nombre + teléfono
        if ($nombre && $tel) {
            $dup = $db->prepare("SELECT id FROM clientes WHERE nombre=? AND telefono=? AND activo=1 AND id!=?");
            $dup->execute([$nombre, $tel, $id]); $d = $dup->fetch();
            if ($d) { $msg = 'dup:Ya existe un cliente con ese nombre y teléfono.'; goto fin_save; }
        }

        if ($id) {
            $sets = implode(',', array_map(fn($f)=>"$f=:$f", $fields));
            $st = $db->prepare("UPDATE clientes SET $sets WHERE id=:id");
            $data['id'] = $id;
        } else {
            $cols = implode(',', $fields);
            $pls  = implode(',', array_map(fn($f)=>":$f", $fields));
            $st = $db->prepare("INSERT INTO clientes ($cols,sede_id) VALUES ($pls,:sede_id)");
            $data['sede_id'] = getSede(); // usar sede activa, no la del usuario
        }
        $st->execute($data);
        $msg = 'success';
        fin_save:
        $action = ($msg === 'success') ? 'list' : 'nuevo';
    }
}

if ($action === 'delete' && isset($_GET['id'])) {
    $db->prepare("UPDATE clientes SET activo=0 WHERE id=?")->execute([(int)$_GET['id']]);
    $action = 'list';
}

$editing = null;
if (in_array($action, ['editar','ver']) && isset($_GET['id'])) {
    $st = $db->prepare("SELECT * FROM clientes WHERE id=?");
    $st->execute([(int)$_GET['id']]); $editing = $st->fetch();
}

$search = trim($_GET['q'] ?? '');
$pg = max(1,(int)($_GET['pg']??1));
$per = 20; $offset = ($pg-1)*$per;
$where = "activo=1"; $params = [];
if ($search) {
    $where .= " AND (nombre LIKE ? OR dni LIKE ? OR ruc LIKE ? OR telefono LIKE ? OR email LIKE ?)";
    $like = "%$search%"; $params = [$like,$like,$like,$like,$like];
}
// Filtro sede — sin alias (la query no hace JOIN en el WHERE)
try {
    $_r=$db->query("SHOW COLUMNS FROM `clientes` LIKE 'sede_id'")->fetchAll();
    if(!empty($_r)) {
        if(!verTodasSedes()) { $where .=" AND sede_id=".getSede(); }
    }
} catch(Exception $e) {}
$total_rows = $db->prepare("SELECT COUNT(*) FROM clientes WHERE $where");
$total_rows->execute($params); $total = $total_rows->fetchColumn();
$st = $db->prepare("SELECT c.*, (SELECT COUNT(*) FROM mascotas WHERE cliente_id=c.id AND estado='activo') as n_mascotas FROM clientes c WHERE $where ORDER BY c.created_at DESC LIMIT $per OFFSET $offset");
$st->execute($params); $clientes = $st->fetchAll();
$total_pag = ceil($total/$per);

// Saldos pendientes por cliente (cuentas por cobrar abiertas). Si la tabla no
// existe todavía, queda vacío y no afecta nada.
$saldos_cli = [];
try {
    if (!empty($db->query("SHOW TABLES LIKE 'cuentas'")->fetchAll()) && !empty($clientes)) {
        $ids = array_map(fn($c)=>(int)$c['id'], $clientes);
        $in = implode(',', array_fill(0, count($ids), '?'));
        $sq = $db->prepare("SELECT cu.cliente_id,
            COALESCE(SUM((SELECT COALESCE(SUM(subtotal),0) FROM cuenta_items WHERE cuenta_id=cu.id)),0) -
            COALESCE(SUM((SELECT COALESCE(SUM(monto),0) FROM cuenta_abonos WHERE cuenta_id=cu.id)),0) AS saldo
            FROM cuentas cu WHERE cu.estado='abierta' AND cu.cliente_id IN ($in) GROUP BY cu.cliente_id");
        $sq->execute($ids);
        foreach ($sq->fetchAll() as $r) { if ((float)$r['saldo'] > 0) $saldos_cli[(int)$r['cliente_id']] = (float)$r['saldo']; }
    }
} catch(Exception $e) {}

$api_url = BASE_URL . '/api/consulta_documento.php';
?>

<?php if($msg==='success'): ?>
<div class="alert alert-success alert-dismiss mb-2">✅ Cliente guardado correctamente.</div>
<?php elseif(substr($msg,0,4)==='dup:'): ?>
<div class="alert alert-danger mb-2">⚠️ <?= clean(substr($msg,4)) ?></div>
<?php endif; ?>

<?php if($action==='ver' && $editing): // ───── FICHA DEL CLIENTE ─────
  $cli = $editing;
  // Mascotas del cliente
  $mascotas_cli = $db->prepare("SELECT id,nombre,especie,raza,estado FROM mascotas WHERE cliente_id=? AND estado='activo' ORDER BY nombre");
  $mascotas_cli->execute([$cli['id']]); $mascotas_cli = $mascotas_cli->fetchAll();
  // Cuentas por cobrar del cliente
  $cuentas_cli = []; $saldo_total = 0;
  try {
    if (!empty($db->query("SHOW TABLES LIKE 'cuentas'")->fetchAll())) {
      $cc = $db->prepare("SELECT cu.*, m.nombre AS mascota,
              (SELECT COALESCE(SUM(subtotal),0) FROM cuenta_items WHERE cuenta_id=cu.id) AS consumido,
              (SELECT COALESCE(SUM(monto),0) FROM cuenta_abonos WHERE cuenta_id=cu.id) AS abonado
              FROM cuentas cu LEFT JOIN mascotas m ON m.id=cu.mascota_id
              WHERE cu.cliente_id=? ORDER BY cu.estado='abierta' DESC, cu.fecha_apertura DESC");
      $cc->execute([$cli['id']]); $cuentas_cli = $cc->fetchAll();
      foreach ($cuentas_cli as $q) { if ($q['estado']==='abierta') $saldo_total += ((float)$q['consumido'] - (float)$q['abonado']); }
    }
  } catch(Exception $e) {}
  $esp_emoji = ['perro'=>'🐕','gato'=>'🐈','conejo'=>'🐇','ave'=>'🦜','reptil'=>'🦎','roedor'=>'🐹','otro'=>'🐾'];
?>
<div class="sec-header">
  <div><a href="?p=clientes" class="btn btn-sm">← Volver a clientes</a></div>
  <div class="flex gap-1">
    <a href="?p=clientes&action=editar&id=<?= $cli['id'] ?>" class="btn btn-sm">✏️ Editar</a>
    <a href="?p=mascotas&cliente_id=<?= $cli['id'] ?>" class="btn btn-sm">🐾 Mascotas</a>
  </div>
</div>

<div class="grid g2" style="align-items:start">
  <!-- Datos del cliente -->
  <div>
    <div class="card mb-2">
      <div class="flex items-center gap-2 mb-2">
        <div style="width:54px;height:54px;border-radius:50%;background:var(--teal-l,#e1f5ee);display:flex;align-items:center;justify-content:center;font-size:22px;font-weight:700;color:var(--teal,#0d9488)">
          <?= strtoupper(mb_substr($cli['nombre'],0,1)) ?>
        </div>
        <div>
          <div style="font-size:18px;font-weight:800"><?= clean($cli['nombre']) ?></div>
          <div class="text-sm text-muted">
            <?php if($cli['dni']): ?>DNI <?= clean($cli['dni']) ?><?php endif; ?>
            <?php if($cli['ruc']): ?>RUC <?= clean($cli['ruc']) ?><?php endif; ?>
          </div>
        </div>
      </div>
      <div class="mas-field" style="display:flex;justify-content:space-between;padding:7px 0;border-bottom:1px solid var(--border)">
        <span class="text-muted text-sm">Teléfono</span>
        <a href="https://wa.me/<?= preg_replace('/\D/','',ltrim($cli['telefono'],'+')) ?>" target="_blank" style="color:var(--wa-d,#0d9488);text-decoration:none;font-weight:600">💬 <?= clean($cli['telefono']) ?></a>
      </div>
      <?php if(!empty($cli['email'])): ?>
      <div style="display:flex;justify-content:space-between;padding:7px 0;border-bottom:1px solid var(--border)"><span class="text-muted text-sm">Email</span><span class="text-sm"><?= clean($cli['email']) ?></span></div>
      <?php endif; ?>
      <div style="display:flex;justify-content:space-between;padding:7px 0"><span class="text-muted text-sm">Dirección</span><span class="text-sm" style="text-align:right;max-width:60%"><?= clean($cli['direccion'] ?: '—') ?></span></div>
    </div>

    <!-- Mascotas -->
    <div class="card">
      <div class="sec-header"><div class="sec-title">Mascotas (<?= count($mascotas_cli) ?>)</div></div>
      <?php if(empty($mascotas_cli)): ?><div class="text-muted text-sm" style="padding:10px">Sin mascotas registradas.</div><?php endif; ?>
      <?php foreach($mascotas_cli as $ms): ?>
      <a href="?p=mascotas&action=ver&id=<?= $ms['id'] ?>" class="flex items-center gap-2" style="padding:9px 0;border-bottom:1px solid var(--border);text-decoration:none;color:inherit">
        <span style="font-size:22px"><?= $esp_emoji[$ms['especie']] ?? '🐾' ?></span>
        <div class="flex-1"><div class="text-sm font-med"><?= clean($ms['nombre']) ?></div><div class="text-xs text-muted"><?= ucfirst($ms['especie']) ?><?= $ms['raza']?' · '.clean($ms['raza']):'' ?></div></div>
        <span class="text-muted">›</span>
      </a>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- Cuenta por cobrar -->
  <div>
    <div class="card">
      <div class="sec-header">
        <div class="sec-title">💳 Cuenta por cobrar</div>
        <?php if($saldo_total>0): ?><span class="badge" style="background:#fef2f2;color:#b91c1c">Debe S/ <?= number_format($saldo_total,2) ?></span><?php endif; ?>
      </div>

      <?php if(empty($cuentas_cli)): ?>
        <div class="text-center text-muted" style="padding:18px">
          Este cliente no tiene cuentas registradas.
          <div class="mt-2"><a href="?p=cuentas" class="btn btn-sm btn-primary">+ Abrir cuenta en Cuentas por cobrar</a></div>
        </div>
      <?php else: ?>
        <?php foreach($cuentas_cli as $q): $sal = (float)$q['consumido'] - (float)$q['abonado']; $ab = ($q['estado']==='abierta'); ?>
        <a href="?p=cuentas&id=<?= $q['id'] ?>" style="display:block;text-decoration:none;color:inherit;padding:11px 12px;border:1px solid var(--border);border-left:3px solid <?= $ab?'var(--red,#ef4444)':'var(--green,#10b981)' ?>;border-radius:8px;margin-bottom:8px">
          <div class="flex items-center justify-between">
            <div>
              <div class="text-sm font-med"><?= $q['mascota'] ? clean($q['mascota']) : 'General' ?> <?= $q['nota']?'· '.clean($q['nota']):'' ?></div>
              <div class="text-xs text-muted">Desde <?= date('d/m/Y', strtotime($q['fecha_apertura'])) ?> · <?= $ab?'Abierta':'Cerrada' ?></div>
            </div>
            <div style="text-align:right">
              <div style="font-size:15px;font-weight:800;color:<?= $ab && $sal>0 ?'var(--red,#ef4444)':'var(--green,#10b981)' ?>">S/ <?= number_format($ab?$sal:0,2) ?></div>
              <div class="text-xs text-muted">consumido S/ <?= number_format($q['consumido'],2) ?></div>
            </div>
          </div>
        </a>
        <?php endforeach; ?>
        <div class="mt-2 text-center"><a href="?p=cuentas" class="btn btn-sm">Ver todas las cuentas por cobrar →</a></div>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if(in_array($action,['nuevo','editar'])): ?>
<style>
/* Fix scroll formulario clientes:
   En "Nuevo cliente" se renderiza el panel de búsqueda DNI/RUC, lo que hace el
   formulario más alto que el área visible en tablet/escritorio. El contenedor
   de scroll (.content) no tenía espacio inferior, así que los botones
   Guardar/Cancelar quedaban fuera de la zona desplazable. Garantizamos scroll
   y un colchón inferior para que el fondo del formulario sea siempre alcanzable. */
.content{ overflow-y:auto !important; padding-bottom:110px !important; }
</style>
<div class="card" style="max-width:700px">
  <div class="sec-header">
    <div>
      <div class="sec-title"><?= $action==='editar'?'Editar':'Nuevo'?> Cliente <span style="font-size:10px;color:var(--text3);font-weight:400">v2</span></div>
      <?php if($action==='nuevo'): ?><div class="sec-sub">Ingresa el DNI o RUC para autocompletar los datos</div><?php endif; ?>
    </div>
    <a href="?p=clientes" class="btn btn-sm">← Volver</a>
  </div>

  <!-- BUSCADOR DNI / RUC -->
  <?php if($action==='nuevo'): ?>
  <div style="background:var(--teal-l);border:1.5px solid var(--teal);border-radius:12px;padding:14px 16px;margin-bottom:18px">
    <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px">
      <span style="font-size:20px">🔍</span>
      <div>
        <div style="font-size:13px;font-weight:700;color:var(--teal-d)">Búsqueda automática RENIEC / SUNAT</div>
        <div style="font-size:11px;color:var(--teal-d);opacity:.7">Ingresa el DNI (8 dígitos) o RUC (11 dígitos) y los datos se autocompletan</div>
      </div>
    </div>

    <!-- DNI -->
    <div style="margin-bottom:10px">
      <label class="form-label" style="margin-bottom:4px;display:block">DNI del titular</label>
      <div style="display:flex;gap:6px">
        <div style="position:relative;flex:1">
          <input id="inp-dni-buscar" class="form-input" type="text" maxlength="8" placeholder="12345678"
            style="padding-right:36px;width:100%;box-sizing:border-box" oninput="this.value=this.value.replace(/\D/,'')" onkeydown="if(event.key==='Enter'){event.preventDefault();buscarDoc('dni')}">
          <span id="ico-dni" style="position:absolute;right:10px;top:50%;transform:translateY(-50%);font-size:15px;pointer-events:none"></span>
        </div>
        <button type="button" class="btn btn-primary" onclick="buscarDoc('dni')" id="btn-dni" style="flex-shrink:0">
          <span id="btn-dni-txt">Buscar</span>
        </button>
      </div>
    </div>

    <!-- RUC -->
    <div>
      <label class="form-label" style="margin-bottom:4px;display:block">RUC (persona jurídica)</label>
      <div style="display:flex;gap:6px">
        <div style="position:relative;flex:1">
          <input id="inp-ruc-buscar" class="form-input" type="text" maxlength="11" placeholder="20123456789"
            style="padding-right:36px;width:100%;box-sizing:border-box" oninput="this.value=this.value.replace(/\D/,'')" onkeydown="if(event.key==='Enter'){event.preventDefault();buscarDoc('ruc')}">
          <span id="ico-ruc" style="position:absolute;right:10px;top:50%;transform:translateY(-50%);font-size:15px;pointer-events:none"></span>
        </div>
        <button type="button" class="btn btn-primary" onclick="buscarDoc('ruc')" id="btn-ruc" style="flex-shrink:0">
          <span id="btn-ruc-txt">Buscar</span>
        </button>
      </div>
    </div>

    <!-- Resultado badge -->
    <div id="resultado-busqueda" style="display:none;margin-top:12px"></div>
  </div>
  <?php endif; ?>

  <!-- FORMULARIO PRINCIPAL -->
  <form method="POST" id="form-cliente">
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?= $editing['id'] ?? '' ?>">

    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Nombre completo / Razón social *</label>
        <input class="form-input" id="inp-nombre" name="nombre" value="<?= clean($editing['nombre']??'') ?>" required placeholder="Ej: María García López">
      </div>
      <div class="form-group">
        <label class="form-label">Teléfono WhatsApp *</label>
        <input class="form-input" name="telefono" value="<?= clean($editing['telefono']??'') ?>" placeholder="+51 987 654 321" required>
      </div>
    </div>

    <div class="form-row">
      <div class="form-group">
        <label class="form-label">DNI</label>
        <div style="position:relative">
          <input class="form-input" id="inp-dni" name="dni" maxlength="8" value="<?= clean($editing['dni']??'') ?>" placeholder="12345678"
            oninput="this.value=this.value.replace(/\D/,''); if(this.value.length===8 && document.getElementById('inp-nombre') && !document.getElementById('inp-nombre').value) buscarDocInline('dni',this.value)">
          <span id="ico-dni-inline" style="position:absolute;right:10px;top:50%;transform:translateY(-50%);font-size:13px;pointer-events:none"></span>
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">RUC</label>
        <div style="position:relative">
          <input class="form-input" id="inp-ruc" name="ruc" maxlength="11" value="<?= clean($editing['ruc']??'') ?>" placeholder="20123456789"
            oninput="this.value=this.value.replace(/\D/,''); if(this.value.length===11 && document.getElementById('inp-nombre') && !document.getElementById('inp-nombre').value) buscarDocInline('ruc',this.value)">
          <span id="ico-ruc-inline" style="position:absolute;right:10px;top:50%;transform:translateY(-50%);font-size:13px;pointer-events:none"></span>
        </div>
      </div>
    </div>

    <div class="form-group">
      <label class="form-label">Dirección</label>
      <input class="form-input" id="inp-direccion" name="direccion" value="<?= clean($editing['direccion']??'') ?>" placeholder="Av. Principal 123, Miraflores, Lima">
    </div>

    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Email</label>
        <input class="form-input" type="email" name="email" value="<?= clean($editing['email']??'') ?>">
      </div>
      <div class="form-group">
        <label class="form-label">¿Cómo nos conoció?</label>
        <select class="form-input" name="como_conocio">
          <?php foreach(['referido'=>'Referido','google'=>'Google','redes_sociales'=>'Redes sociales','otro'=>'Otro'] as $k=>$v): ?>
          <option value="<?= $k ?>" <?= ($editing['como_conocio']??'otro')===$k?'selected':'' ?>><?= $v ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div class="form-group">
      <label class="form-label">Notas</label>
      <textarea class="form-input" name="notas"><?= clean($editing['notas']??'') ?></textarea>
    </div>

    <div class="flex gap-1">
      <button type="submit" class="btn btn-primary">💾 Guardar cliente</button>
      <a href="?p=clientes" class="btn">Cancelar</a>
    </div>
  </form>
</div>
<div aria-hidden="true" style="height:24px"></div>

<script>
const API_URL = '<?= $api_url ?>';

// ── Búsqueda desde el panel superior (botón Buscar) ──
async function buscarDoc(tipo) {
  const numEl  = document.getElementById('inp-' + tipo + '-buscar');
  const btnTxt = document.getElementById('btn-' + tipo + '-txt');
  const btnEl  = document.getElementById('btn-' + tipo);
  const icoEl  = document.getElementById('ico-' + tipo);
  const resEl  = document.getElementById('resultado-busqueda');
  const num    = numEl ? numEl.value.trim() : '';

  if (!num) { numEl.focus(); return; }
  const len = tipo === 'dni' ? 8 : 11;
  if (num.length !== len) {
    showResultado('error', `El ${tipo.toUpperCase()} debe tener ${len} dígitos.`);
    return;
  }

  // Estado: cargando
  btnTxt.textContent = '...';
  btnEl.disabled = true;
  icoEl.textContent = '⏳';

  try {
    const resp = await fetch(`${API_URL}?tipo=${tipo}&numero=${num}`);
    const data = await resp.json();

    if (data.ok) {
      // Autocompletar formulario
      setField('inp-nombre',    data.nombre    || '');
      setField('inp-dni',       data.dni       || (tipo==='dni' ? num : ''));
      setField('inp-ruc',       data.ruc       || (tipo==='ruc' ? num : ''));
      setField('inp-direccion', data.direccion || '');

      icoEl.textContent = '✅';

      let extra = '';
      if (data.estado)    extra += ` · Estado: <strong>${data.estado}</strong>`;
      if (data.condicion) extra += ` · Condición: <strong>${data.condicion}</strong>`;
      if (data.tipo)      extra += ` · Tipo: ${data.tipo}`;

      showResultado('ok',
        `✅ <strong>${data.nombre}</strong> encontrado en <strong>${data.fuente}</strong>${extra}`
      );

      // Enfocar teléfono si está vacío
      const telEl = document.querySelector('input[name="telefono"]');
      if (telEl && !telEl.value) setTimeout(() => telEl.focus(), 100);

    } else {
      icoEl.textContent = '❌';
      let errMsg = `❌ ${data.error}`;
      if (data.tip === 'token') {
        errMsg += `<br><span style="font-size:11px">👉 Configura un token gratis en <a href="https://apis.net.pe" target="_blank" style="color:var(--red-d);font-weight:700">apis.net.pe</a> y agrégalo en <code>config_tokens.php</code></span>`;
      }
      showResultado('error', errMsg);
    }
  } catch(e) {
    icoEl.textContent = '⚠️';
    showResultado('error', '⚠️ Error de red. Verifica tu conexión o ingresa los datos manualmente.');
  } finally {
    btnTxt.textContent = 'Buscar';
    btnEl.disabled = false;
  }
}

// ── Búsqueda inline (cuando escriben en el campo DNI/RUC del formulario) ──
async function buscarDocInline(tipo, num) {
  const icoEl = document.getElementById('ico-' + tipo + '-inline');
  if (icoEl) icoEl.textContent = '⏳';
  try {
    const resp = await fetch(`${API_URL}?tipo=${tipo}&numero=${num}`);
    const data = await resp.json();
    if (data.ok) {
      if (!document.getElementById('inp-nombre').value) setField('inp-nombre', data.nombre || '');
      if (!document.getElementById('inp-direccion').value) setField('inp-direccion', data.direccion || '');
      if (icoEl) icoEl.textContent = '✅';
    } else {
      if (icoEl) icoEl.textContent = '❌';
    }
  } catch(e) {
    if (icoEl) icoEl.textContent = '⚠️';
  }
}

function setField(id, value) {
  const el = document.getElementById(id);
  if (el && value) {
    el.value = value;
    // Efecto visual de llenado
    el.style.background = 'var(--teal-l)';
    el.style.borderColor = 'var(--teal)';
    setTimeout(() => { el.style.background = ''; el.style.borderColor = ''; }, 1800);
  }
}

function showResultado(tipo, html) {
  const el = document.getElementById('resultado-busqueda');
  if (!el) return;
  el.style.display = 'block';
  el.innerHTML = `<div style="padding:10px 14px;border-radius:8px;font-size:13px;
    background:${tipo==='ok'?'var(--green-l)':'var(--red-l)'};
    border:1px solid ${tipo==='ok'?'#86efac':'#fca5a5'};
    color:${tipo==='ok'?'var(--green)':'var(--red)'}">${html}</div>`;
}

// Enter en los campos de búsqueda
['dni','ruc'].forEach(t => {
  const el = document.getElementById('inp-' + t + '-buscar');
  if (el) el.addEventListener('keydown', e => { if(e.key==='Enter'){e.preventDefault();buscarDoc(t);} });
});
</script>

<?php elseif($action!=='ver'): ?>
<!-- ──────────── LISTA ──────────── -->

<?php
// ── EXPORTAR EXCEL ──
if (($_GET['action']??'') === 'exportar_excel') {
    // Candado de permiso: sin "Exportar" en el rol, no se genera el archivo.
    if (!canExport('clientes')) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=UTF-8');
        exit('🔒 No tienes permiso para exportar clientes.');
    }
    $exp_sw = "activo=1";
    try { $r=$db->query("SHOW COLUMNS FROM clientes LIKE 'sede_id'")->fetchAll(); if(!empty($r)&&!verTodasSedes()) $exp_sw.=" AND sede_id=".getSede(); } catch(Exception $e){}
    $rows = $db->query("SELECT nombre,dni,ruc,telefono,email,direccion,como_conocio,notas FROM clientes WHERE $exp_sw ORDER BY nombre")->fetchAll();
    $sede_nombre = '';
    try { $sn=$db->prepare("SELECT nombre FROM sedes WHERE id=?"); $sn->execute([getSede()]); $sede_nombre=$sn->fetchColumn(); } catch(Exception $e){}

    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="clientes_' . date('Y-m-d') . '.xls"');
    header('Cache-Control: max-age=0');
    echo "\xEF\xBB\xBF"; // BOM UTF-8
    echo "EXPORTACIÓN DE CLIENTES - " . strtoupper($sede_nombre) . " - " . date('d/m/Y') . "\n\n";
    echo "Nombre\tDNI\tRUC\tTeléfono\tEmail\tDirección\tCómo nos conoció\tNotas\n";
    foreach ($rows as $row) {
        echo implode("\t", array_map(fn($v)=>str_replace(["\t","\n","\r"],['','',''], $v??''), array_values($row))) . "\n";
    }
    exit;
}

// ── IMPORTAR EXCEL ──
if (($_POST['action']??'') === 'importar_excel') {
  try {
    $resultado = ['ok'=>0,'error'=>0,'errores'=>[]];
    if (!empty($_FILES['archivo_excel']['tmp_name'])) {
        $tmp = $_FILES['archivo_excel']['tmp_name'];
        $ext = strtolower(pathinfo($_FILES['archivo_excel']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['csv','xls','xlsx','txt'])) {
            $msg = 'error:Formato no válido. Usa CSV, XLS o XLSX.';
        } else {
            $sede_id_imp = getSede();
            $raw = file_get_contents($tmp);

            // ── Extraer filas como matriz, detectando el formato real del archivo ──
            // (no importa la extensión: detectamos por el contenido)
            $filas = [];

            if (substr($raw,0,2) === "PK") {
                // .xlsx real (es un ZIP). Extraemos sharedStrings + sheet1 con ZipArchive.
                $filas = leer_xlsx($tmp);
            } elseif (stripos($raw, '<?mso-application') !== false || stripos($raw, 'urn:schemas-microsoft-com:office:spreadsheet') !== false) {
                // XML "SpreadsheetML" (Excel 2003 guardado como XML)
                $filas = leer_excel_xml($raw);
            } else {
                // CSV / TSV / texto plano
                $content = mb_convert_encoding($raw, 'UTF-8', 'UTF-8,ISO-8859-1,Windows-1252');
                $content = ltrim($content, "\xEF\xBB\xBF");
                $lines = preg_split('/\r\n|\r|\n/', trim($content));
                $sep = "\t";
                if (substr_count($lines[0]??'', ',') > substr_count($lines[0]??'', "\t")) $sep = ',';
                foreach ($lines as $line) {
                    if (trim($line)==='') continue;
                    $filas[] = str_getcsv($line, $sep);
                }
            }

            // ── Procesar las filas (común a todos los formatos) ──
            foreach ($filas as $i => $cols) {
                if (!is_array($cols)) continue;
                $nombre = trim($cols[0]??'');
                if (!$nombre || strlen($nombre) < 2) continue;
                // Evitar que entren restos de etiquetas XML como dato
                if ($nombre[0] === '<' || stripos($nombre,'xmlns')!==false || stripos($nombre,'<?')!==false) continue;

                // ── Saltar filas de TÍTULO o ENCABEZADO (en cualquier posición) ──
                $n0 = strtolower($nombre);
                // 1) Título de la plantilla / exportación
                if (strpos($n0,'plantilla') === 0 || strpos($n0,'exportaci') === 0) continue;
                // 2) Fila de encabezado: la 1ª columna dice "nombre" y/o las demás
                //    coinciden con los nombres de columna (dni, ruc, telefono...)
                $c1 = strtolower(trim($cols[1]??''));
                $c3 = strtolower(trim($cols[3]??''));
                $es_encabezado = ($n0 === 'nombre' || $n0 === 'cliente')
                    && (in_array($c1, ['dni','ruc',''], true) || in_array($c3, ['telefono','teléfono','celular',''], true));
                if ($es_encabezado) continue;
                // 3) Por si acaso: si la columna "nombre" dice literalmente "nombre"
                if ($n0 === 'nombre') continue;

                $dni   = trim($cols[1]??'');
                $ruc   = trim($cols[2]??'');
                $tel   = trim($cols[3]??'');
                $email = trim($cols[4]??'');
                $dir   = trim($cols[5]??'');
                $como  = trim($cols[6]??'');
                $notas = trim($cols[7]??'');

                // 'como_conocio' es un ENUM: normalizar para no romper la fila
                $como_norm = strtolower(str_replace([' ','-'],'_',$como));
                $mapa_como = ['internet'=>'google','web'=>'google','facebook'=>'redes_sociales',
                              'instagram'=>'redes_sociales','tiktok'=>'redes_sociales','redes'=>'redes_sociales',
                              'recomendado'=>'referido','recomendacion'=>'referido','amigo'=>'referido'];
                if (isset($mapa_como[$como_norm])) $como_norm = $mapa_como[$como_norm];
                if (!in_array($como_norm, ['referido','google','redes_sociales','otro'], true)) $como_norm = $como ? 'otro' : null;
                $como = $como_norm;

                // Duplicados por DNI o nombre+teléfono
                $dup = false;
                if ($dni) { $st=$db->prepare("SELECT id FROM clientes WHERE dni=? AND activo=1"); $st->execute([$dni]); if($st->fetch()) $dup=true; }
                if (!$dup && $nombre && $tel) { $st=$db->prepare("SELECT id FROM clientes WHERE nombre=? AND telefono=? AND activo=1"); $st->execute([$nombre,$tel]); if($st->fetch()) $dup=true; }
                if ($dup) { $resultado['errores'][] = "Fila ".($i+1).": '$nombre' ya existe (duplicado)."; $resultado['error']++; continue; }

                try {
                    $db->prepare("INSERT INTO clientes (nombre,dni,ruc,telefono,email,direccion,como_conocio,notas,sede_id,activo) VALUES (?,?,?,?,?,?,?,?,?,1)")
                       ->execute([$nombre,$dni?:null,$ruc?:null,$tel?:null,$email?:null,$dir?:null,$como?:null,$notas?:null,$sede_id_imp]);
                    $resultado['ok']++;
                } catch(Exception $e) {
                    $resultado['errores'][] = "Fila ".($i+1).": Error al insertar '$nombre'.";
                    $resultado['error']++;
                }
            }

            if ($resultado['ok'] === 0 && $resultado['error'] === 0) {
                $msg = 'error:No se encontraron datos válidos en el archivo. Descarga la plantilla y úsala como base.';
            } else {
                $msg = "import_ok:{$resultado['ok']}:{$resultado['error']}:" . implode('|', array_slice($resultado['errores'],0,5));
            }
        }
    } else {
        $msg = 'error:No se recibió ningún archivo.';
    }
  } catch (\Throwable $e) {
    // Nunca dejar pantalla en blanco: mostrar el motivo
    $msg = 'error:No se pudo procesar el archivo. Para .xls (XML) o .xlsx tu servidor necesita las extensiones PHP "xml" y "zip". Mientras tanto, exporta tu archivo como CSV y vuelve a intentar. ('.$e->getMessage().')';
  }

  // ── POST-Redirect-GET: recargar la lista para que aparezcan los nuevos
  //    clientes sin tener que refrescar a mano. El resultado viaja por la URL.
  $msg_url = $msg;
  if (strlen($msg_url) > 300) $msg_url = substr($msg_url, 0, 300); // URL corta
  $redir = '?p=clientes&imp=' . urlencode($msg_url);
  if (!headers_sent()) {
      header('Location: ' . $redir);
      exit;
  } else {
      // Si el header ya se envió, redirigir por JS (infalible)
      echo '<script>window.location.href=' . json_encode($redir) . ';</script>';
      exit;
  }
}

// Recibir el resultado de la importación tras el redirect (GET)
if (empty($msg) && isset($_GET['imp'])) {
    $msg = $_GET['imp'];
}

// ── DESCARGAR PLANTILLA ──
if (($_GET['action']??'') === 'plantilla_excel') {
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="plantilla_clientes.xls"');
    echo "\xEF\xBB\xBF";
    echo "Nombre\tDNI\tRUC\tTeléfono\tEmail\tDirección\tCómo nos conoció\tNotas\n";
    echo "Juan Pérez García\t12345678\t\t999123456\tjuan@email.com\tAv. Lima 123\tInternet\t\n";
    echo "María López\t87654321\t\t987654321\t\tCalle Arequipa 45\tReferido\t\n";
    exit;
}
?>

<?php
// Mostrar resultado de importación
if (!empty($msg) && strpos($msg,'import_ok:') === 0) {
    $parts = explode(':', $msg, 4);
    $ok_n = $parts[1]??0; $err_n = $parts[2]??0; $errs = $parts[3]??'';
?>
<div class="alert alert-success mb-3">
    ✅ Importación completada: <strong><?= $ok_n ?> clientes importados</strong>
    <?= $err_n>0 ? ", <strong style='color:var(--danger)'>$err_n con errores</strong>" : '' ?>.
    <?php if($errs): ?><div style="font-size:12px;margin-top:6px;color:var(--danger)"><?= clean(str_replace('|','<br>',$errs)) ?></div><?php endif; ?>
</div>
<?php } ?>

<div class="sec-header">
  <div><div class="sec-title">Clientes registrados</div><div class="sec-sub"><?= $total ?> clientes en total</div></div>
  <div class="flex gap-2" style="flex-wrap:wrap">
    <form class="flex gap-1" method="GET">
      <input type="hidden" name="p" value="clientes">
      <input class="form-input" name="q" value="<?= clean($search) ?>" placeholder="Nombre, DNI, RUC, teléfono..." style="width:220px">
      <button type="submit" class="btn">Buscar</button>
    </form>
    <!-- Exportar (solo si el rol tiene permiso) -->
    <?php if($puede_exportar): ?>
    <a href="<?= BASE_URL ?>/api/clientes_excel.php?action=exportar" class="btn btn-sm btn-ghost" style="color:var(--success);border-color:var(--success)" title="Exportar clientes a Excel">
      📥 Exportar Excel
    </a>
    <?php endif; ?>
    <!-- Importar -->
    <button type="button" onclick="document.getElementById('modal-import').style.display='flex'" class="btn btn-sm btn-ghost" style="color:var(--accent);border-color:var(--accent)">
      📤 Importar Excel
    </button>
    <a href="?p=clientes&action=nuevo" class="btn btn-primary">+ Nuevo Cliente</a>
  </div>
</div>

<!-- Modal importar -->
<div id="modal-import" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:2000;align-items:center;justify-content:center" onclick="if(event.target===this)this.style.display='none'">
  <div style="background:var(--bg2);border-radius:16px;padding:28px;width:520px;max-width:95vw;box-shadow:0 20px 60px rgba(0,0,0,.2)">
    <div style="font-size:16px;font-weight:700;margin-bottom:6px">📤 Importar Clientes desde Excel</div>
    <div style="font-size:13px;color:var(--text3);margin-bottom:16px">
      Los clientes se importarán a la sede activa: <strong style="color:var(--primary)"><?= clean((function() use ($db){ try{ $s=$db->prepare("SELECT nombre FROM sedes WHERE id=?"); $s->execute([getSede()]); return $s->fetchColumn(); }catch(Exception $e){return '';} })()) ?></strong>
    </div>

    <!-- Paso 1: Descargar plantilla -->
    <div style="background:var(--bg3);border-radius:10px;padding:14px;margin-bottom:14px">
      <div style="font-size:13px;font-weight:700;margin-bottom:6px">📋 Paso 1 — Descarga la plantilla</div>
      <div style="font-size:12px;color:var(--text2);margin-bottom:10px">
        Descarga la plantilla Excel, rellénala con tus clientes y súbela en el paso 2.
        Columnas: <code>Nombre · DNI · RUC · Teléfono · Email · Dirección · Cómo nos conoció · Notas</code>
      </div>
      <a href="<?= BASE_URL ?>/api/clientes_excel.php?action=plantilla" class="btn btn-sm" style="color:var(--success);border-color:var(--success)">
        ⬇️ Descargar plantilla
      </a>
    </div>

    <!-- Paso 2: Subir archivo -->
    <div style="background:var(--bg3);border-radius:10px;padding:14px;margin-bottom:16px">
      <div style="font-size:13px;font-weight:700;margin-bottom:6px">📂 Paso 2 — Sube el archivo completado</div>
      <form method="POST" enctype="multipart/form-data" onsubmit="document.getElementById('modal-import').style.display='none'">
        <input type="hidden" name="action" value="importar_excel">
        <input type="hidden" name="p" value="clientes">
        <div class="form-group">
          <input type="file" name="archivo_excel" accept=".csv,.xls,.xlsx,.txt" class="form-input" style="cursor:pointer" required>
          <div style="font-size:11px;color:var(--text3);margin-top:4px">Formatos aceptados: CSV, XLS, XLSX</div>
        </div>
        <div style="font-size:11px;color:var(--text3);background:rgba(99,102,241,.08);padding:10px;border-radius:8px;margin-bottom:12px">
          ⚠️ <strong>Notas:</strong> Los duplicados (mismo DNI o nombre+teléfono) serán ignorados. La primera fila de encabezado se salta automáticamente.
        </div>
        <div class="flex gap-2">
          <button type="submit" class="btn btn-primary" style="flex:1">📤 Importar ahora</button>
          <button type="button" onclick="document.getElementById('modal-import').style.display='none'" class="btn btn-ghost">Cancelar</button>
        </div>
      </form>
    </div>
  </div>
</div>

<div class="card" style="padding:0">
  <div class="table-wrap">
    <table class="vtable">
      <thead><tr><th>Cliente</th><th>DNI / RUC</th><th>Teléfono</th><th>Dirección</th><th>Mascotas</th><th>Acciones</th></tr></thead>
      <tbody>
        <?php foreach($clientes as $c): ?>
        <tr>
          <td>
            <div class="flex items-center gap-2">
              <div class="avatar" style="width:32px;height:32px;font-size:11px;flex-shrink:0"><?= strtoupper(substr($c['nombre'],0,1).(strstr($c['nombre'],' ') ? substr(strstr($c['nombre'],' '),1,1) : '')) ?></div>
              <div>
                <div class="td-main"><?= clean($c['nombre']) ?></div>
                <?php if($c['email']): ?><div class="text-xs text-muted" style="color:var(--blue)"><?= clean($c['email']) ?></div><?php endif; ?>
              </div>
            </div>
          </td>
          <td>
            <?php if($c['dni']): ?><div><span class="badge b-gray" style="font-family:monospace">DNI <?= clean($c['dni']) ?></span></div><?php endif; ?>
            <?php if($c['ruc']): ?><div><span class="badge b-blue" style="font-family:monospace">RUC <?= clean($c['ruc']) ?></span></div><?php endif; ?>
            <?php if(!$c['dni']&&!$c['ruc']): ?><span class="text-muted">—</span><?php endif; ?>
          </td>
          <td>
            <a href="https://wa.me/<?= preg_replace('/\D/','',ltrim($c['telefono'],'+')) ?>" target="_blank" class="flex items-center gap-1" style="color:var(--wa-d);text-decoration:none">
              💬 <?= clean($c['telefono']) ?>
            </a>
          </td>
          <td class="text-muted text-xs" style="max-width:180px"><?= clean($c['direccion']??'—') ?></td>
          <td><span class="badge b-teal"><?= $c['n_mascotas'] ?> mascota<?= $c['n_mascotas']!=1?'s':'' ?></span>
            <?php if(!empty($saldos_cli[$c['id']])): ?>
              <a href="?p=clientes&action=ver&id=<?= $c['id'] ?>" class="badge" style="background:#fef2f2;color:#b91c1c;text-decoration:none;margin-left:4px" title="Tiene saldo pendiente">💰 Debe S/ <?= number_format($saldos_cli[$c['id']],2) ?></a>
            <?php endif; ?>
          </td>
          <td>
            <div class="flex gap-1">
              <a href="?p=clientes&action=ver&id=<?= $c['id'] ?>" class="btn btn-xs">👁️ Ver</a>
              <a href="?p=clientes&action=editar&id=<?= $c['id'] ?>" class="btn btn-xs">✏️ Editar</a>
              <a href="?p=mascotas&cliente_id=<?= $c['id'] ?>" class="btn btn-xs">🐾 Mascotas</a>
              <a href="<?= BASE_URL ?>/index.php?p=whatsapp&cliente_id=<?= $c['id'] ?>" class="btn btn-xs btn-wa" title="WhatsApp">💬</a>
              <a href="?p=clientes&action=delete&id=<?= $c['id'] ?>" class="btn btn-xs" style="color:var(--red)" onclick="return confirm('¿Dar de baja a este cliente?')">✕</a>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if(empty($clientes)): ?>
        <tr><td colspan="6" class="text-center text-muted" style="padding:40px">
          <?php if($search): ?>No se encontraron clientes para "<?= clean($search) ?>".<?php else: ?>Aún no hay clientes registrados. <a href="?p=clientes&action=nuevo">Agregar el primero →</a><?php endif; ?>
        </td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
  <?php if($total_pag>1): ?>
  <div class="pagination" style="padding:16px">
    <?php if($pg>1): ?><a href="?p=clientes&pg=<?= $pg-1 ?>&q=<?= urlencode($search) ?>" class="page-btn">‹</a><?php endif; ?>
    <?php for($i=max(1,$pg-2);$i<=min($total_pag,$pg+2);$i++): ?>
    <a href="?p=clientes&pg=<?= $i ?>&q=<?= urlencode($search) ?>" class="page-btn <?= $i===$pg?'active':'' ?>"><?= $i ?></a>
    <?php endfor; ?>
    <?php if($pg<$total_pag): ?><a href="?p=clientes&pg=<?= $pg+1 ?>&q=<?= urlencode($search) ?>" class="page-btn">›</a><?php endif; ?>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
