<?php
$page = 'movimientos'; $pageTitle = 'Movimientos de inventario';

// ════════════════════════════════════════════════════════════════
// MOVIMIENTOS DE INVENTARIO (Kárdex)
// Registra entradas/salidas/ajustes/traslados de productos con su
// motivo. Aprovecha la tabla `kardex` existente (la extiende si
// faltan columnas). Solo admin/encargado puede registrar.
// ════════════════════════════════════════════════════════════════

require_once __DIR__ . '/../includes/config.php';
$db = getDB();
if (function_exists('requireLogin')) requireLogin();

// ── Extender la tabla kardex con columnas nuevas (idempotente) ──
$mov_cols_actuales = [];
foreach ($db->query("SHOW COLUMNS FROM kardex")->fetchAll() as $c) $mov_cols_actuales[$c['Field']] = true;
if (!isset($mov_cols_actuales['origen']))           $db->exec("ALTER TABLE kardex ADD COLUMN origen VARCHAR(20) DEFAULT 'farmacia'");
if (!isset($mov_cols_actuales['motivo']))           $db->exec("ALTER TABLE kardex ADD COLUMN motivo VARCHAR(40) NULL");
if (!isset($mov_cols_actuales['costo_unitario']))   $db->exec("ALTER TABLE kardex ADD COLUMN costo_unitario DECIMAL(10,2) DEFAULT 0");
if (!isset($mov_cols_actuales['sede_id']))          $db->exec("ALTER TABLE kardex ADD COLUMN sede_id INT DEFAULT 1");
if (!isset($mov_cols_actuales['sede_destino_id']))  $db->exec("ALTER TABLE kardex ADD COLUMN sede_destino_id INT NULL");
// Ampliar enum tipo para incluir 'traslado' (manteniendo los existentes)
try { $db->exec("ALTER TABLE kardex MODIFY COLUMN tipo ENUM('entrada','salida','ajuste','venta','traslado') NOT NULL"); } catch(Exception $e){}

// Catálogos para los motivos (etiqueta, ícono, color, ¿cuenta como pérdida?)
$MOV_MOTIVOS = [
    'consumo_propio'   => ['Consumo propio',     '🏥', '#3b82f6', false],
    'merma'            => ['Merma',              '💔', '#ef4444', true],
    'ajuste'           => ['Ajuste de inventario','⚖️', '#8b5cf6', false],
    'deterioro'        => ['Deterioro',          '🗑️', '#f97316', true],
    'traslado'         => ['Traslado entre sedes','🔁', '#06b6d4', false],
    'caducidad'        => ['Caducidad / vencido','⏰', '#ef4444', true],
    'donacion'         => ['Donación / regalo',  '🎁', '#10b981', false],
    'compra'           => ['Compra / ingreso',   '📥', '#10b981', false],
    'devolucion'       => ['Devolución a proveedor','↩️', '#64748b', false],
    'otros'            => ['Otros',              '📝', '#64748b', false],
];

// Tipos válidos
$MOV_TIPOS = ['entrada','salida','ajuste','traslado'];

// Helper para autorizar — solo admin/encargado
function mov_puede_registrar() {
    $u = function_exists('getUser') ? getUser() : ($GLOBALS['user'] ?? []);
    $rol = $u['rol'] ?? '';
    return in_array($rol, ['admin','encargado','administrador'], true);
}

// ── HANDLERS (POST) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = function_exists('getUser') ? getUser() : ($GLOBALS['user'] ?? []);
    $uid  = (int)($user['id'] ?? 0);
    $accion = $_POST['accion'] ?? '';

    if ($accion === 'registrar_movimiento') {
        if (!mov_puede_registrar()) {
            header('Location: '.BASE_URL.'/index.php?p=movimientos&err=permiso'); exit;
        }

        $origen      = $_POST['origen'] ?? 'farmacia';            // farmacia | petshop
        $producto_id = (int)($_POST['producto_id'] ?? 0);
        $tipo        = $_POST['tipo'] ?? 'salida';
        $motivo      = $_POST['motivo'] ?? 'otros';
        $cantidad    = (int)($_POST['cantidad'] ?? 0);
        $costo       = (float)str_replace([',','S/',' '],['.','',''], $_POST['costo_unitario'] ?? '0');
        $sede_id     = (int)($_POST['sede_id'] ?? ($user['sede_id'] ?? 1));
        $sede_destino= (int)($_POST['sede_destino_id'] ?? 0) ?: null;
        $nota        = trim($_POST['notas'] ?? '');

        if ($producto_id <= 0 || $cantidad <= 0 || !in_array($tipo, ['entrada','salida','ajuste','traslado'], true)) {
            header('Location: '.BASE_URL.'/index.php?p=movimientos&err=datos'); exit;
        }

        // Tabla del producto según origen
        $tabla = ($origen === 'petshop') ? 'petshop_productos' : 'productos';

        // Stock actual
        $st = $db->prepare("SELECT stock, nombre FROM $tabla WHERE id=?");
        $st->execute([$producto_id]); $prod = $st->fetch();
        if (!$prod) { header('Location: '.BASE_URL.'/index.php?p=movimientos&err=producto'); exit; }

        $stock_antes = (int)$prod['stock'];
        // Calcular cambio según tipo
        if ($tipo === 'entrada') {
            $stock_nuevo = $stock_antes + $cantidad;
            $cant_kardex = $cantidad;
        } elseif ($tipo === 'salida' || $tipo === 'traslado') {
            if ($cantidad > $stock_antes) {
                header('Location: '.BASE_URL.'/index.php?p=movimientos&err=stock&actual='.$stock_antes); exit;
            }
            $stock_nuevo = $stock_antes - $cantidad;
            $cant_kardex = -$cantidad; // negativo para salidas
        } else { // ajuste (queda en la cantidad que pongan)
            $stock_nuevo = $cantidad;
            $cant_kardex = $cantidad - $stock_antes;
        }

        // Validar traslado (necesita sede destino)
        if ($tipo === 'traslado' && (!$sede_destino || $sede_destino === $sede_id)) {
            header('Location: '.BASE_URL.'/index.php?p=movimientos&err=destino'); exit;
        }

        $db->beginTransaction();
        try {
            // 1) Actualizar stock real del producto
            $db->prepare("UPDATE $tabla SET stock=? WHERE id=?")->execute([$stock_nuevo, $producto_id]);

            // 2) Registrar en kardex (auditoría)
            $db->prepare(
                "INSERT INTO kardex (producto_id,usuario_id,tipo,motivo,cantidad,stock_anterior,stock_nuevo,costo_unitario,origen,sede_id,sede_destino_id,notas)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?)"
            )->execute([$producto_id, $uid, $tipo, $motivo, $cant_kardex, $stock_antes, $stock_nuevo, $costo, $origen, $sede_id, $sede_destino, $nota]);

            // 3) Si es traslado, sumar al stock de la sede destino (en la sede destino, vía inventario_sedes)
            if ($tipo === 'traslado' && $sede_destino) {
                // Verificar si hay registro de inventario en la sede destino
                $ex = $db->prepare("SELECT id, stock FROM inventario_sedes WHERE producto_id=? AND sede_id=?");
                $ex->execute([$producto_id, $sede_destino]); $row = $ex->fetch();
                if ($row) {
                    $db->prepare("UPDATE inventario_sedes SET stock=stock+? WHERE id=?")->execute([$cantidad, $row['id']]);
                } else {
                    $db->prepare("INSERT INTO inventario_sedes (producto_id,sede_id,stock) VALUES (?,?,?)")->execute([$producto_id, $sede_destino, $cantidad]);
                }
                // Registro adicional en transferencias_stock
                try {
                    $db->prepare("INSERT INTO transferencias_stock (producto_id,producto_nombre,sede_origen,sede_destino,cantidad,usuario_id,nota) VALUES (?,?,?,?,?,?,?)")
                       ->execute([$producto_id, $prod['nombre'], $sede_id, $sede_destino, $cantidad, $uid, $nota]);
                } catch(Exception $e){}
            }

            $db->commit();
            header('Location: '.BASE_URL.'/index.php?p=movimientos&msg=ok'); exit;
        } catch(Exception $e) {
            $db->rollBack();
            header('Location: '.BASE_URL.'/index.php?p=movimientos&err='.urlencode(substr($e->getMessage(),0,80))); exit;
        }
    }
}

require_once __DIR__ . '/../includes/header.php';

// ───── VISTA ─────
$puede = mov_puede_registrar();

// Filtros
$f_desde  = $_GET['desde']  ?? date('Y-m-01');
$f_hasta  = $_GET['hasta']  ?? date('Y-m-d');
$f_tipo   = $_GET['tipo']   ?? 'todos';
$f_origen = $_GET['origen'] ?? 'todos';
$f_motivo = $_GET['motivo'] ?? 'todos';

$wheres = ["DATE(k.created_at) BETWEEN ? AND ?"];
$params = [$f_desde, $f_hasta];
if ($f_tipo   !== 'todos') { $wheres[] = "k.tipo=?";   $params[] = $f_tipo; }
if ($f_origen !== 'todos') { $wheres[] = "k.origen=?"; $params[] = $f_origen; }
if ($f_motivo !== 'todos') { $wheres[] = "k.motivo=?"; $params[] = $f_motivo; }
try { if (function_exists('verTodasSedes') && !verTodasSedes()) { $wheres[] = "k.sede_id=".(int)getSede(); } } catch(Exception $e){}
$where_sql = implode(' AND ', $wheres);

// Query principal: une con el catálogo según origen (LEFT JOIN a ambos)
$sql = "SELECT k.*, u.nombre AS usuario,
        COALESCE(pf.nombre, ps.nombre, '—') AS producto_nombre
        FROM kardex k
        LEFT JOIN usuarios u ON u.id=k.usuario_id
        LEFT JOIN productos pf ON pf.id=k.producto_id AND k.origen='farmacia'
        LEFT JOIN petshop_productos ps ON ps.id=k.producto_id AND k.origen='petshop'
        WHERE $where_sql
        ORDER BY k.created_at DESC LIMIT 200";
$st = $db->prepare($sql); $st->execute($params);
$movimientos = $st->fetchAll();

// Resumen del periodo
$n_entradas = 0; $n_salidas = 0; $perdida_total = 0; $n_traslados = 0;
$motivos_perdida = ['merma','deterioro','caducidad'];
foreach ($movimientos as $m) {
    if ($m['tipo']==='entrada') $n_entradas += abs((int)$m['cantidad']);
    elseif ($m['tipo']==='salida' || $m['tipo']==='traslado') $n_salidas += abs((int)$m['cantidad']);
    if ($m['tipo']==='traslado') $n_traslados++;
    if (in_array($m['motivo'], $motivos_perdida, true)) {
        $perdida_total += abs((int)$m['cantidad']) * (float)$m['costo_unitario'];
    }
}

// Mensajes flash
$msg = $_GET['msg'] ?? ''; $err = $_GET['err'] ?? '';
?>

<?php if ($msg==='ok'): ?>
<div class="alert alert-success mb-2">✅ Movimiento registrado correctamente. Stock actualizado.</div>
<?php elseif ($err==='permiso'): ?>
<div class="alert alert-danger mb-2">⛔ No tienes permiso para registrar movimientos. Esta función es solo para administradores y encargados.</div>
<?php elseif ($err==='stock'): ?>
<div class="alert alert-danger mb-2">⚠️ La cantidad supera el stock actual (<?= (int)($_GET['actual']??0) ?> uds). Ajusta la cantidad o registra una entrada primero.</div>
<?php elseif ($err==='destino'): ?>
<div class="alert alert-danger mb-2">⚠️ Para un traslado debes elegir una sede de destino distinta a la actual.</div>
<?php elseif ($err==='datos'): ?>
<div class="alert alert-danger mb-2">⚠️ Faltan datos: revisa producto, tipo y cantidad.</div>
<?php elseif ($err==='producto'): ?>
<div class="alert alert-danger mb-2">⚠️ El producto no se encontró.</div>
<?php elseif ($err): ?>
<div class="alert alert-danger mb-2">⚠️ <?= clean($err) ?></div>
<?php endif; ?>

<div class="sec-header">
  <div>
    <div class="page-title">📦 Movimientos de inventario</div>
    <div class="page-desc">Entradas, salidas, mermas, ajustes y traslados</div>
  </div>
  <?php if ($puede): ?>
    <button class="btn btn-primary" onclick="document.getElementById('m-mov').style.display='flex'">+ Registrar movimiento</button>
  <?php else: ?>
    <span class="text-muted text-sm">🔒 Solo lectura (necesitas rol admin/encargado para registrar)</span>
  <?php endif; ?>
</div>

<!-- Tarjetas resumen -->
<div class="grid g4 mb-3">
  <div class="stat-card" style="border-color:var(--green,#10b981)"><div class="stat-icon si-teal">📥</div><div class="stat-value"><?= number_format($n_entradas) ?></div><div class="stat-label">Unidades de entrada</div></div>
  <div class="stat-card" style="border-color:var(--red,#ef4444)"><div class="stat-icon si-red">📤</div><div class="stat-value"><?= number_format($n_salidas) ?></div><div class="stat-label">Unidades de salida</div></div>
  <div class="stat-card"><div class="stat-icon si-blue">🔁</div><div class="stat-value"><?= $n_traslados ?></div><div class="stat-label">Traslados entre sedes</div></div>
  <div class="stat-card" style="border-color:var(--red,#ef4444)"><div class="stat-icon si-red">💔</div><div class="stat-value">S/ <?= number_format($perdida_total,2) ?></div><div class="stat-label">Pérdidas (mermas, caducidad)</div></div>
</div>

<!-- Filtros -->
<div class="card mb-3" style="padding:14px">
  <form method="GET" class="flex gap-2 items-end" style="flex-wrap:wrap">
    <input type="hidden" name="p" value="movimientos">
    <div class="form-group" style="margin:0"><label class="form-label">Desde</label><input type="date" name="desde" class="form-input" value="<?= clean($f_desde) ?>"></div>
    <div class="form-group" style="margin:0"><label class="form-label">Hasta</label><input type="date" name="hasta" class="form-input" value="<?= clean($f_hasta) ?>"></div>
    <div class="form-group" style="margin:0"><label class="form-label">Tipo</label>
      <select name="tipo" class="form-input">
        <option value="todos" <?= $f_tipo==='todos'?'selected':'' ?>>Todos</option>
        <option value="entrada"  <?= $f_tipo==='entrada'?'selected':'' ?>>Entrada</option>
        <option value="salida"   <?= $f_tipo==='salida'?'selected':'' ?>>Salida</option>
        <option value="ajuste"   <?= $f_tipo==='ajuste'?'selected':'' ?>>Ajuste</option>
        <option value="traslado" <?= $f_tipo==='traslado'?'selected':'' ?>>Traslado</option>
      </select>
    </div>
    <div class="form-group" style="margin:0"><label class="form-label">Origen</label>
      <select name="origen" class="form-input">
        <option value="todos" <?= $f_origen==='todos'?'selected':'' ?>>Todos</option>
        <option value="farmacia" <?= $f_origen==='farmacia'?'selected':'' ?>>Farmacia</option>
        <option value="petshop"  <?= $f_origen==='petshop'?'selected':'' ?>>Pet Shop</option>
      </select>
    </div>
    <div class="form-group" style="margin:0"><label class="form-label">Motivo</label>
      <select name="motivo" class="form-input">
        <option value="todos" <?= $f_motivo==='todos'?'selected':'' ?>>Todos</option>
        <?php foreach ($MOV_MOTIVOS as $k=>$v): ?>
          <option value="<?= $k ?>" <?= $f_motivo===$k?'selected':'' ?>><?= $v[1] ?> <?= $v[0] ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <button type="submit" class="btn btn-primary">🔍 Filtrar</button>
  </form>
</div>

<!-- Tabla de movimientos -->
<div class="card" style="padding:0;overflow:hidden;border:1.5px solid var(--border)">
  <div class="table-wrap">
    <table class="vtable" style="width:100%;border-collapse:collapse">
      <thead><tr>
        <th>Fecha</th><th>Producto</th><th>Origen</th><th>Tipo</th><th>Motivo</th>
        <th style="text-align:right">Cant.</th><th>Stock</th><th>Usuario</th><th>Nota</th>
      </tr></thead>
      <tbody>
        <?php foreach($movimientos as $m):
          $mot = $MOV_MOTIVOS[$m['motivo']] ?? ['Sin motivo','📝','#64748b',false];
          $tcolor = ['entrada'=>'#10b981','salida'=>'#ef4444','ajuste'=>'#8b5cf6','traslado'=>'#06b6d4','venta'=>'#3b82f6'][$m['tipo']] ?? '#64748b';
        ?>
        <tr style="border-bottom:1px solid var(--border)">
          <td class="text-muted text-sm"><?= date('d/m/Y H:i', strtotime($m['created_at'])) ?></td>
          <td><?= clean($m['producto_nombre']) ?></td>
          <td><span class="badge" style="background:<?= $m['origen']==='petshop'?'#fef3c7':'#dbeafe' ?>;color:<?= $m['origen']==='petshop'?'#92400e':'#1e40af' ?>"><?= $m['origen']==='petshop'?'🛒 Pet Shop':'💊 Farmacia' ?></span></td>
          <td><span style="color:<?= $tcolor ?>;font-weight:700;text-transform:uppercase;font-size:11px"><?= $m['tipo'] ?></span></td>
          <td><?= $mot[1] ?> <?= clean($mot[0]) ?></td>
          <td style="text-align:right;font-weight:700;color:<?= ((int)$m['cantidad'])>=0?'#10b981':'#ef4444' ?>"><?= ((int)$m['cantidad'])>=0?'+':'' ?><?= (int)$m['cantidad'] ?></td>
          <td class="text-xs text-muted"><?= (int)$m['stock_anterior'] ?> → <?= (int)$m['stock_nuevo'] ?></td>
          <td class="text-sm"><?= clean($m['usuario'] ?: '—') ?></td>
          <td class="text-xs text-muted"><?= clean($m['notas'] ?: '—') ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($movimientos)): ?>
        <tr><td colspan="9" style="text-align:center;color:var(--text3);padding:30px">Sin movimientos en el periodo seleccionado.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($puede): ?>
<!-- MODAL: registrar movimiento -->
<div id="m-mov" class="modal-overlay" style="display:none">
  <div class="modal" style="max-width:580px">
    <div class="modal-header"><div class="modal-title">📦 Registrar movimiento de inventario</div><button class="modal-close" onclick="document.getElementById('m-mov').style.display='none'">✕</button></div>
    <div class="modal-body"><form method="POST" id="mov-form">
      <input type="hidden" name="accion" value="registrar_movimiento">

      <!-- Origen del producto -->
      <label class="form-label">Almacén</label>
      <div class="flex gap-1 mb-2">
        <button type="button" class="btn btn-sm mov-orig-btn" data-orig="farmacia" onclick="movSetOrigen('farmacia')" style="background:#dbeafe;color:#1e40af;border-color:#93c5fd">💊 Farmacia</button>
        <button type="button" class="btn btn-sm mov-orig-btn" data-orig="petshop" onclick="movSetOrigen('petshop')">🛒 Pet Shop</button>
      </div>
      <input type="hidden" name="origen" id="mov-origen" value="farmacia">

      <!-- 📷 ESCANEO DE CÓDIGO DE BARRAS (busca y selecciona el producto) -->
      <div class="form-group" style="background:#f0fdfa;border:1px dashed #6ee7b7;border-radius:8px;padding:8px 12px;margin-bottom:10px">
        <div style="display:flex;align-items:center;gap:8px">
          <span style="font-size:16px">📷</span>
          <input type="text" id="mov-scan" class="form-input" placeholder="Escanear código de barras (Enter para seleccionar)..." autocomplete="off"
                 onkeydown="if(event.key==='Enter'){event.preventDefault();movEscanear(this.value);this.value='';}" style="flex:1;font-size:12px">
        </div>
        <div id="mov-scan-fb" style="min-height:14px;font-size:11px;margin-top:4px;color:var(--text3)"></div>
      </div>

      <!-- Buscador de producto -->
      <div class="form-group" style="position:relative">
        <label class="form-label">Producto *</label>
        <input type="text" id="mov-inp-prod" class="form-input" autocomplete="off" placeholder="Buscar producto...">
        <input type="hidden" name="producto_id" id="mov-hid-prod" required>
        <input type="hidden" id="mov-stock-actual" value="0">
        <input type="hidden" id="mov-costo-prod" value="0">
        <div id="mov-drop-prod" style="display:none;position:absolute;top:100%;left:0;right:0;margin-top:4px;background:var(--bg2);border:1px solid var(--border);border-radius:10px;box-shadow:0 8px 28px rgba(0,0,0,.12);z-index:60;max-height:220px;overflow-y:auto"></div>
        <div id="mov-prod-info" class="text-xs text-muted mt-1" style="display:none;background:var(--bg3);border-radius:6px;padding:6px 10px"></div>
      </div>

      <!-- Tipo de movimiento -->
      <label class="form-label">Tipo de movimiento *</label>
      <div class="flex gap-1 mb-2" style="flex-wrap:wrap">
        <button type="button" class="btn btn-sm mov-tipo-btn" data-tipo="salida" onclick="movSetTipo('salida')" style="background:#fee2e2;color:#991b1b;border-color:#fca5a5">📤 Salida</button>
        <button type="button" class="btn btn-sm mov-tipo-btn" data-tipo="entrada" onclick="movSetTipo('entrada')">📥 Entrada</button>
        <button type="button" class="btn btn-sm mov-tipo-btn" data-tipo="ajuste" onclick="movSetTipo('ajuste')">⚖️ Ajuste</button>
        <button type="button" class="btn btn-sm mov-tipo-btn" data-tipo="traslado" onclick="movSetTipo('traslado')">🔁 Traslado</button>
      </div>
      <input type="hidden" name="tipo" id="mov-tipo" value="salida">

      <!-- Motivo (cambia las opciones según tipo) -->
      <div class="form-group">
        <label class="form-label">Motivo *</label>
        <select class="form-input" name="motivo" id="mov-motivo">
          <?php foreach ($MOV_MOTIVOS as $k=>$v): ?>
            <option value="<?= $k ?>" data-perdida="<?= $v[3]?'1':'0' ?>"><?= $v[1] ?> <?= clean($v[0]) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <!-- Sede destino (solo para traslado) -->
      <div class="form-group" id="mov-destino-wrap" style="display:none">
        <label class="form-label">Sede destino *</label>
        <select class="form-input" name="sede_destino_id" id="mov-destino">
          <option value="">— Selecciona la sede destino —</option>
          <?php try { foreach ($db->query("SELECT id,nombre FROM sedes WHERE activo=1 ORDER BY nombre")->fetchAll() as $s): ?>
            <option value="<?= (int)$s['id'] ?>"><?= clean($s['nombre']) ?></option>
          <?php endforeach; } catch(Exception $e){} ?>
        </select>
      </div>

      <div class="form-row">
        <div class="form-group"><label class="form-label">Cantidad *</label><input class="form-input" type="number" min="1" name="cantidad" id="mov-cantidad" required oninput="movCalcImpacto()"></div>
        <div class="form-group" id="mov-costo-wrap" style="display:none"><label class="form-label">Costo unit. (S/)</label><input class="form-input" type="number" step="0.01" name="costo_unitario" id="mov-costo" value="0"></div>
      </div>

      <!-- Vista previa del impacto -->
      <div id="mov-impacto" class="flex items-center justify-between" style="background:#eff6ff;border-radius:8px;padding:9px 12px;margin-bottom:10px;display:none">
        <span class="text-sm text-muted">Stock cambiará</span>
        <span class="font-bold" id="mov-impacto-txt"></span>
      </div>

      <div class="form-group"><label class="form-label">Nota (opcional)</label><input class="form-input" name="notas" placeholder="Ej. Vencimiento lote 2024-05"></div>

      <button class="btn btn-primary w-full">💾 Registrar movimiento</button>
    </form></div>
  </div>
</div>

<script>
var MOV_FARMACIA = <?= json_encode(array_values(array_map(fn($p)=>['id'=>(int)$p['id'],'nombre'=>$p['nombre'],'stock'=>(int)$p['stock'],'costo'=>(float)($p['precio_costo']??0),'codigo'=>$p['codigo_barras']??''], $db->query("SELECT id,nombre,stock,precio_costo,COALESCE(codigo_barras,'') AS codigo_barras FROM productos WHERE activo=1 ORDER BY nombre")->fetchAll()))) ?>;
var MOV_PETSHOP = <?= json_encode(array_values(array_map(fn($p)=>['id'=>(int)$p['id'],'nombre'=>$p['nombre'],'stock'=>(int)$p['stock'],'costo'=>(float)($p['precio_costo']??0),'codigo'=>$p['codigo_barras']??''], (function($db){ try { return $db->query("SELECT id,nombre,stock,precio_costo,COALESCE(codigo_barras,'') AS codigo_barras FROM petshop_productos WHERE activo=1 ORDER BY nombre")->fetchAll(); } catch(Exception $e){ return []; } })($db)))) ?>;

var movOrigen = 'farmacia';
var movTipo = 'salida';

function movListaActual(){ return movOrigen==='petshop' ? MOV_PETSHOP : MOV_FARMACIA; }

// ── Escaneo de código de barras: busca el producto y lo selecciona ──
function movEscanear(codigoRaw){
  var codigo = (codigoRaw||'').trim();
  var fb = document.getElementById('mov-scan-fb');
  if (!codigo){ fb.textContent=''; return; }
  // Buscar en farmacia primero, luego petshop. Si está en el otro almacén, cambiar de origen automáticamente
  var prod = MOV_FARMACIA.find(function(p){ return p.codigo && p.codigo === codigo; });
  var almNuevo = null;
  if (prod) {
    almNuevo = 'farmacia';
  } else {
    prod = MOV_PETSHOP.find(function(p){ return p.codigo && p.codigo === codigo; });
    if (prod) almNuevo = 'petshop';
  }
  if (!prod){
    fb.innerHTML = '<span style="color:#ef4444">❌ Producto con código <b>'+codigo.replace(/</g,'&lt;')+'</b> no encontrado en farmacia ni pet shop.</span>';
    return;
  }
  // Si está en otro almacén, cambiar el origen
  if (almNuevo && almNuevo !== movOrigen) {
    movSetOrigen(almNuevo);
  }
  // Rellenar el buscador y los campos como si se hubiera seleccionado del dropdown
  document.getElementById('mov-inp-prod').value = prod.nombre;
  document.getElementById('mov-hid-prod').value = prod.id;
  document.getElementById('mov-stock-actual').value = prod.stock;
  document.getElementById('mov-costo-prod').value = prod.costo;
  document.getElementById('mov-prod-info').textContent = 'Stock actual: '+prod.stock+' uds · Costo unit.: S/ '+(prod.costo||0).toFixed(2);
  document.getElementById('mov-prod-info').style.display='block';
  // Si hay costo visible (motivo de pérdida), prellenar
  if (document.getElementById('mov-costo-wrap') && document.getElementById('mov-costo-wrap').style.display!=='none') {
    document.getElementById('mov-costo').value = prod.costo;
  }
  movCalcImpacto();
  fb.innerHTML = '<span style="color:#10b981">✓ Seleccionado: <b>'+prod.nombre.replace(/</g,'&lt;')+'</b> ('+(almNuevo==='petshop'?'🛒 Pet Shop':'💊 Farmacia')+') · Stock: '+prod.stock+' uds</span>';
  // Enfocar el campo cantidad para que el usuario solo escriba la cantidad
  setTimeout(function(){
    var c = document.getElementById('mov-cantidad');
    if (c) c.focus();
  }, 100);
}

function movSetOrigen(o){
  movOrigen = o;
  document.getElementById('mov-origen').value = o;
  document.querySelectorAll('.mov-orig-btn').forEach(function(b){
    var on = b.getAttribute('data-orig')===o;
    b.style.background = on ? '#dbeafe' : '';
    b.style.color = on ? '#1e40af' : '';
    b.style.borderColor = on ? '#93c5fd' : '';
  });
  // Limpiar producto seleccionado
  document.getElementById('mov-inp-prod').value='';
  document.getElementById('mov-hid-prod').value='';
  document.getElementById('mov-stock-actual').value='0';
  document.getElementById('mov-prod-info').style.display='none';
  movCalcImpacto();
}

function movSetTipo(t){
  movTipo = t;
  document.getElementById('mov-tipo').value = t;
  document.querySelectorAll('.mov-tipo-btn').forEach(function(b){
    var on = b.getAttribute('data-tipo')===t;
    var col = {salida:['#fee2e2','#991b1b','#fca5a5'], entrada:['#d1fae5','#065f46','#6ee7b7'], ajuste:['#ede9fe','#5b21b6','#c4b5fd'], traslado:['#cffafe','#155e75','#67e8f9']}[t];
    b.style.background = on && col ? col[0] : '';
    b.style.color = on && col ? col[1] : '';
    b.style.borderColor = on && col ? col[2] : '';
  });
  // Mostrar sede destino solo si es traslado
  document.getElementById('mov-destino-wrap').style.display = (t==='traslado') ? '' : 'none';
  document.getElementById('mov-destino').required = (t==='traslado');
  // Ajustar motivos según el tipo (sugerencia automática)
  var sel = document.getElementById('mov-motivo');
  if (t==='traslado') sel.value = 'traslado';
  else if (t==='entrada') sel.value = 'compra';
  else if (t==='salida') sel.value = 'consumo_propio';
  else if (t==='ajuste') sel.value = 'ajuste';
  movCalcImpacto();
}

function movCalcImpacto(){
  var stock = parseInt(document.getElementById('mov-stock-actual').value||'0', 10);
  var cant  = parseInt(document.getElementById('mov-cantidad').value||'0', 10);
  var box = document.getElementById('mov-impacto');
  var txt = document.getElementById('mov-impacto-txt');
  if (!cant || !document.getElementById('mov-hid-prod').value){ box.style.display='none'; return; }
  var nuevo;
  if (movTipo==='entrada') nuevo = stock + cant;
  else if (movTipo==='salida' || movTipo==='traslado') nuevo = stock - cant;
  else nuevo = cant; // ajuste = queda en X
  txt.innerHTML = stock + ' → <span style="color:'+(nuevo<0?'#ef4444':(nuevo<stock?'#ef4444':'#10b981'))+'">' + nuevo + '</span> uds';
  if (nuevo < 0) txt.innerHTML += ' <span style="color:#ef4444;font-size:11px">(sin stock suficiente)</span>';
  box.style.display='';
}

// Mostrar costo si el motivo cuenta como pérdida
document.getElementById('mov-motivo').addEventListener('change', function(){
  var op = this.options[this.selectedIndex];
  var perdida = op.getAttribute('data-perdida')==='1';
  document.getElementById('mov-costo-wrap').style.display = perdida ? '' : 'none';
  if (perdida) {
    document.getElementById('mov-costo').value = document.getElementById('mov-costo-prod').value || 0;
  }
});

document.addEventListener('DOMContentLoaded', function(){
  var inp = document.getElementById('mov-inp-prod');
  var drop = document.getElementById('mov-drop-prod');
  function pintar(lista){
    if(!lista.length){ drop.innerHTML='<div style="padding:10px;font-size:12px;color:var(--text3)">Sin resultados</div>'; drop.style.display='block'; return; }
    drop.innerHTML = lista.map(function(p){
      return '<div class="mov-prod-opt" data-id="'+p.id+'" data-nombre="'+p.nombre.replace(/"/g,'&quot;')+'" data-stock="'+p.stock+'" data-costo="'+p.costo+'" style="padding:9px 13px;cursor:pointer;border-bottom:1px solid var(--border)" onmouseover="this.style.background=\'var(--bg3)\'" onmouseout="this.style.background=\'\'">'
           + '<div style="font-size:13px;font-weight:600">'+p.nombre+'</div>'
           + '<div style="font-size:11px;color:var(--text3)">Stock: '+p.stock+' uds · Costo: S/ '+p.costo.toFixed(2)+'</div></div>';
    }).join('');
    drop.style.display='block';
    drop.querySelectorAll('.mov-prod-opt').forEach(function(el){
      el.addEventListener('mousedown', function(e){
        e.preventDefault();
        inp.value = el.getAttribute('data-nombre');
        document.getElementById('mov-hid-prod').value = el.getAttribute('data-id');
        document.getElementById('mov-stock-actual').value = el.getAttribute('data-stock');
        document.getElementById('mov-costo-prod').value = el.getAttribute('data-costo');
        document.getElementById('mov-prod-info').textContent = 'Stock actual: '+el.getAttribute('data-stock')+' uds · Costo unit.: S/ '+parseFloat(el.getAttribute('data-costo')).toFixed(2);
        document.getElementById('mov-prod-info').style.display='block';
        // Si el costo está visible (motivo de pérdida), prellenar
        if (document.getElementById('mov-costo-wrap').style.display!=='none') {
          document.getElementById('mov-costo').value = el.getAttribute('data-costo');
        }
        drop.style.display='none';
        movCalcImpacto();
      });
    });
  }
  inp.addEventListener('input', function(){
    var v = inp.value.toLowerCase().trim();
    var l = movListaActual();
    var f = v ? l.filter(function(x){ return x.nombre.toLowerCase().indexOf(v)>=0; }) : l;
    pintar(f.slice(0,15));
  });
  inp.addEventListener('focus', function(){ pintar(movListaActual().slice(0,15)); });
  document.addEventListener('click', function(e){ if(e.target!==inp && !drop.contains(e.target)) drop.style.display='none'; });
  movSetTipo('salida');
});
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
