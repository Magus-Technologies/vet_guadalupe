<?php
$page = 'cuentas'; $pageTitle = 'Cuentas por cobrar';

// ════════════════════════════════════════════════════════════════
// CUENTAS POR COBRAR (crédito a clientes / tratamientos largos)
// Una cuenta se abre, se le agregan consumos día a día, admite
// abonos parciales, y al cerrar genera UN comprobante con todo el
// detalle + registra el pago en caja.
// Handlers van ANTES del header (para redirects/JSON limpios).
// ════════════════════════════════════════════════════════════════

require_once __DIR__ . '/../includes/config.php';
$db = getDB();

// Auto-crear tablas si no existen
$db->exec("CREATE TABLE IF NOT EXISTS cuentas (
  id INT AUTO_INCREMENT PRIMARY KEY, sede_id INT DEFAULT 1, cliente_id INT NOT NULL,
  mascota_id INT NULL, usuario_id INT NULL, fecha_apertura DATETIME DEFAULT CURRENT_TIMESTAMP,
  fecha_cierre DATETIME NULL, estado ENUM('abierta','cerrada','anulada') DEFAULT 'abierta',
  nota VARCHAR(255) NULL, venta_id INT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX(cliente_id), INDEX(mascota_id), INDEX(estado))");
$db->exec("CREATE TABLE IF NOT EXISTS cuenta_items (
  id INT AUTO_INCREMENT PRIMARY KEY, cuenta_id INT NOT NULL, fecha DATE NULL,
  descripcion VARCHAR(255) NOT NULL, cantidad DECIMAL(10,2) DEFAULT 1,
  precio_unitario DECIMAL(10,2) DEFAULT 0, subtotal DECIMAL(10,2) DEFAULT 0,
  tipo VARCHAR(30) DEFAULT 'servicio', usuario_id INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX(cuenta_id))");
$db->exec("CREATE TABLE IF NOT EXISTS cuenta_abonos (
  id INT AUTO_INCREMENT PRIMARY KEY, cuenta_id INT NOT NULL, fecha DATETIME DEFAULT CURRENT_TIMESTAMP,
  monto DECIMAL(10,2) NOT NULL, metodo_pago VARCHAR(40) DEFAULT 'efectivo',
  usuario_id INT NULL, nota VARCHAR(200) NULL, INDEX(cuenta_id))");

// Helpers de saldo
function cta_consumido($db, $cuenta_id) {
    $st = $db->prepare("SELECT COALESCE(SUM(subtotal),0) FROM cuenta_items WHERE cuenta_id=?");
    $st->execute([$cuenta_id]); return (float)$st->fetchColumn();
}
function cta_abonado($db, $cuenta_id) {
    $st = $db->prepare("SELECT COALESCE(SUM(monto),0) FROM cuenta_abonos WHERE cuenta_id=?");
    $st->execute([$cuenta_id]); return (float)$st->fetchColumn();
}

// ── HANDLERS (POST) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (function_exists('requireLogin')) requireLogin();
    $user = function_exists('getUser') ? getUser() : ($GLOBALS['user'] ?? []);
    $uid  = (int)($user['id'] ?? 0);
    $accion = $_POST['accion'] ?? '';

    // Abrir nueva cuenta
    if ($accion === 'abrir_cuenta') {
        $cliente_id = (int)($_POST['cliente_id'] ?? 0);
        $mascota_id = (int)($_POST['mascota_id'] ?? 0) ?: null;
        $nota = trim($_POST['nota'] ?? '');
        $sede = $user['sede_id'] ?? 1;
        if ($cliente_id) {
            $st = $db->prepare("INSERT INTO cuentas (sede_id,cliente_id,mascota_id,usuario_id,nota) VALUES (?,?,?,?,?)");
            $st->execute([$sede,$cliente_id,$mascota_id,$uid,$nota]);
            $nueva = (int)$db->lastInsertId();
            header('Location: '.BASE_URL.'/index.php?p=cuentas&id='.$nueva.'&msg=abierta'); exit;
        }
        header('Location: '.BASE_URL.'/index.php?p=cuentas&err=cliente'); exit;
    }

    // Agregar consumo(s) a la cuenta — acepta uno o VARIOS ítems
    if ($accion === 'agregar_item') {
        $cuenta_id = (int)($_POST['cuenta_id'] ?? 0);
        $fecha = $_POST['fecha'] ?? date('Y-m-d');
        if ($cuenta_id) {
            $st = $db->prepare("INSERT INTO cuenta_items (cuenta_id,fecha,descripcion,cantidad,precio_unitario,subtotal,tipo,usuario_id) VALUES (?,?,?,?,?,?,?,?)");
            // Modo múltiple: arrays it_desc[], it_cant[], it_precio[], it_tipo[]
            if (isset($_POST['it_desc']) && is_array($_POST['it_desc'])) {
                $descs   = $_POST['it_desc'];
                $cants   = $_POST['it_cant']   ?? [];
                $precios = $_POST['it_precio'] ?? [];
                $tipos   = $_POST['it_tipo']   ?? [];
                foreach ($descs as $i => $d) {
                    $d = trim($d ?? '');
                    if ($d === '') continue;
                    $cant = (float)($cants[$i] ?? 1); if ($cant <= 0) $cant = 1;
                    $precio = (float)str_replace([',','S/',' '],['.','',''], $precios[$i] ?? '0');
                    if ($precio < 0) continue;
                    $tipo = ($tipos[$i] ?? 'servicio') === 'producto' ? 'producto' : 'servicio';
                    $subtotal = round($cant * $precio, 2);
                    $st->execute([$cuenta_id,$fecha,$d,$cant,$precio,$subtotal,$tipo,$uid]);
                }
            } else {
                // Modo simple (compatibilidad): un solo ítem
                $desc = trim($_POST['descripcion'] ?? '');
                $cant = (float)($_POST['cantidad'] ?? 1); if ($cant <= 0) $cant = 1;
                $precio = (float)str_replace([',','S/',' '],['.','',''], $_POST['precio_unitario'] ?? '0');
                $tipo = ($_POST['tipo'] ?? 'servicio') === 'producto' ? 'producto' : 'servicio';
                $subtotal = round($cant * $precio, 2);
                if ($desc !== '' && $precio >= 0) {
                    $st->execute([$cuenta_id,$fecha,$desc,$cant,$precio,$subtotal,$tipo,$uid]);
                }
            }
        }
        header('Location: '.BASE_URL.'/index.php?p=cuentas&id='.$cuenta_id); exit;
    }

    // Eliminar un consumo (solo si la cuenta sigue abierta)
    if ($accion === 'eliminar_item') {
        $cuenta_id = (int)($_POST['cuenta_id'] ?? 0);
        $item_id = (int)($_POST['item_id'] ?? 0);
        $est = $db->prepare("SELECT estado FROM cuentas WHERE id=?"); $est->execute([$cuenta_id]);
        if ($est->fetchColumn() === 'abierta') {
            $db->prepare("DELETE FROM cuenta_items WHERE id=? AND cuenta_id=?")->execute([$item_id,$cuenta_id]);
        }
        header('Location: '.BASE_URL.'/index.php?p=cuentas&id='.$cuenta_id); exit;
    }

    // Registrar abono parcial
    if ($accion === 'registrar_abono') {
        $cuenta_id = (int)($_POST['cuenta_id'] ?? 0);
        $monto = (float)str_replace([',','S/',' '],['.','',''], $_POST['monto'] ?? '0');
        $metodo = $_POST['metodo_pago'] ?? 'efectivo';
        $nota = trim($_POST['nota'] ?? '');
        if ($cuenta_id && $monto > 0) {
            $st = $db->prepare("INSERT INTO cuenta_abonos (cuenta_id,monto,metodo_pago,usuario_id,nota) VALUES (?,?,?,?,?)");
            $st->execute([$cuenta_id,$monto,$metodo,$uid,$nota]);
            // Registrar el abono también como ingreso en la caja abierta (si hay)
            cta_registrar_en_caja($db, $uid, $monto, $metodo, 'Abono a cuenta #'.$cuenta_id);
        }
        header('Location: '.BASE_URL.'/index.php?p=cuentas&id='.$cuenta_id.'&msg=abono'); exit;
    }

    // Cobrar y cerrar: genera UN comprobante (venta) con todo el detalle
    if ($accion === 'cerrar_cuenta') {
        $cuenta_id = (int)($_POST['cuenta_id'] ?? 0);
        $metodo = $_POST['metodo_pago'] ?? 'efectivo';
        $tipo_comp = $_POST['tipo_comprobante'] ?? 'boleta';
        $cuenta = $db->prepare("SELECT * FROM cuentas WHERE id=?"); $cuenta->execute([$cuenta_id]);
        $cuenta = $cuenta->fetch();
        if ($cuenta && $cuenta['estado'] === 'abierta') {
            $items = $db->prepare("SELECT * FROM cuenta_items WHERE cuenta_id=? ORDER BY fecha,id"); $items->execute([$cuenta_id]);
            $items = $items->fetchAll();
            $consumido = cta_consumido($db, $cuenta_id);
            $abonado   = cta_abonado($db, $cuenta_id);
            $saldo     = round($consumido - $abonado, 2);

            // Generar la venta (comprobante) con todo el detalle
            $venta_id = cta_generar_venta($db, $cuenta, $items, $consumido, $tipo_comp, $metodo, $uid);

            // El saldo pendiente que se paga AHORA entra a caja (el abonado ya entró antes)
            if ($saldo > 0) {
                cta_registrar_en_caja($db, $uid, $saldo, $metodo, 'Cobro cuenta #'.$cuenta_id, $venta_id);
            }
            // Cerrar la cuenta
            $db->prepare("UPDATE cuentas SET estado='cerrada', fecha_cierre=NOW(), venta_id=? WHERE id=?")
               ->execute([$venta_id, $cuenta_id]);
            header('Location: '.BASE_URL.'/index.php?p=cuentas&id='.$cuenta_id.'&msg=cerrada'); exit;
        }
        header('Location: '.BASE_URL.'/index.php?p=cuentas&id='.$cuenta_id); exit;
    }
}

// ── Genera una venta (comprobante) a partir de la cuenta ──
function cta_generar_venta($db, $cuenta, $items, $total, $tipo_comp, $metodo, $uid) {
    // Serie según tipo y sede (replica lógica de facturación)
    $sede = (int)($cuenta['sede_id'] ?? 1);
    $pref = $tipo_comp === 'factura' ? 'F' : ($tipo_comp === 'ticket' ? 'T' : 'B');
    $serie = $pref . str_pad($sede, 3, '0', STR_PAD_LEFT);
    // Siguiente número de esa serie
    $n = $db->prepare("SELECT COALESCE(MAX(numero),0)+1 FROM ventas WHERE serie=?");
    $n->execute([$serie]); $numero = (int)$n->fetchColumn();

    // ¿La tabla ventas usa IGV? Tomamos total como total final (sin desglosar IGV aquí)
    $st = $db->prepare("INSERT INTO ventas
        (sede_id,cliente_id,mascota_id,usuario_id,tipo_comprobante,serie,numero,fecha,subtotal,igv,descuento,total,metodo_pago,estado)
        VALUES (?,?,?,?,?,?,?,NOW(),?,?,0,?,?,'pagado')");
    $st->execute([
        $sede, $cuenta['cliente_id'], $cuenta['mascota_id'], $uid,
        $tipo_comp, $serie, $numero, $total, 0, $total, $metodo
    ]);
    $venta_id = (int)$db->lastInsertId();

    // Volcar items al detalle de la venta
    $vi = $db->prepare("INSERT INTO venta_items (venta_id,tipo,referencia_id,descripcion,cantidad,precio_unitario,descuento,subtotal) VALUES (?,?,0,?,?,?,0,?)");
    foreach ($items as $it) {
        $vi->execute([$venta_id, $it['tipo']==='producto'?'producto':'servicio', $it['descripcion'],
                      $it['cantidad'], $it['precio_unitario'], $it['subtotal']]);
    }
    return $venta_id;
}

// ── Registra un ingreso en la caja abierta (si existe) ──
function cta_registrar_en_caja($db, $uid, $monto, $metodo, $concepto, $venta_id = null) {
    try {
        $caja = $db->query("SELECT id FROM cajas WHERE estado='abierta' ORDER BY id DESC LIMIT 1")->fetch();
        if (!$caja) return; // si no hay caja abierta, no bloquea (el abono igual queda en la cuenta)
        $db->prepare("INSERT INTO movimientos_caja (caja_id,usuario_id,tipo,concepto,monto,metodo_pago,categoria,venta_id) VALUES (?,?,'ingreso',?,?,?,'servicio',?)")
           ->execute([$caja['id'],$uid,$concepto,$monto,$metodo,$venta_id]);
    } catch(Exception $e) { /* no bloquea la operación principal */ }
}

require_once __DIR__ . '/../includes/header.php';

$cuenta_id = (int)($_GET['id'] ?? 0);
$_metodos = ['efectivo'=>'Efectivo','yape'=>'Yape','plin'=>'Plin','tarjeta_debito'=>'Tarjeta','transferencia'=>'Transferencia'];
$msg = $_GET['msg'] ?? '';
?>

<?php if ($msg): ?>
<div class="alert alert-success mb-2">
  <?php
    if ($msg==='abierta')  echo '✅ Cuenta abierta. Ya puedes agregar consumos.';
    elseif ($msg==='abono')  echo '✅ Abono registrado correctamente.';
    elseif ($msg==='cerrada') echo '✅ Cuenta cobrada y cerrada. Se generó el comprobante.';
    else echo '✅ Operación realizada.';
  ?>
</div>
<?php endif; ?>

<?php if ($cuenta_id): // ───── VISTA DE CUENTA INDIVIDUAL ─────
  $c = $db->prepare("SELECT cu.*, cl.nombre AS cliente, cl.telefono, m.nombre AS mascota, m.especie
                     FROM cuentas cu
                     LEFT JOIN clientes cl ON cl.id=cu.cliente_id
                     LEFT JOIN mascotas m ON m.id=cu.mascota_id
                     WHERE cu.id=?");
  $c->execute([$cuenta_id]); $c = $c->fetch();
  if (!$c): ?>
    <div class="card" style="padding:30px;text-align:center">Cuenta no encontrada. <a href="?p=cuentas">← Volver</a></div>
  <?php else:
    $its = $db->prepare("SELECT * FROM cuenta_items WHERE cuenta_id=? ORDER BY fecha,id"); $its->execute([$cuenta_id]); $its = $its->fetchAll();
    $abs = $db->prepare("SELECT * FROM cuenta_abonos WHERE cuenta_id=? ORDER BY fecha,id"); $abs->execute([$cuenta_id]); $abs = $abs->fetchAll();
    $consumido = cta_consumido($db, $cuenta_id);
    $abonado   = cta_abonado($db, $cuenta_id);
    $saldo     = round($consumido - $abonado, 2);
    $abierta   = ($c['estado'] === 'abierta');
  ?>
  <div class="sec-header">
    <div>
      <a href="?p=cuentas" class="btn btn-sm">← Cuentas por cobrar</a>
    </div>
  </div>

  <!-- Cabecera de la cuenta -->
  <div class="card mb-2" style="border-left:4px solid <?= $abierta ? 'var(--red,#ef4444)' : 'var(--green,#10b981)' ?>">
    <div class="flex items-center justify-between" style="flex-wrap:wrap;gap:10px">
      <div>
        <div style="font-size:17px;font-weight:800"><i class="ti ti-wallet"></i> Cuenta de <?= clean($c['cliente']) ?><?= $c['mascota'] ? ' — '.clean($c['mascota']) : '' ?></div>
        <div class="text-sm text-muted">
          Abierta el <?= date('d/m/Y', strtotime($c['fecha_apertura'])) ?>
          <?= $c['nota'] ? ' · '.clean($c['nota']) : '' ?>
          <?php if(!$abierta): ?> · <strong>Cerrada</strong> el <?= date('d/m/Y', strtotime($c['fecha_cierre'])) ?><?php endif; ?>
        </div>
      </div>
      <div style="text-align:right">
        <div class="text-xs text-muted">Saldo pendiente</div>
        <div style="font-size:24px;font-weight:800;color:<?= $saldo>0 ? 'var(--red,#ef4444)' : 'var(--green,#10b981)' ?>">S/ <?= number_format($saldo,2) ?></div>
      </div>
    </div>
    <div class="flex gap-2 mt-2" style="flex-wrap:wrap">
      <div style="flex:1;min-width:120px;background:var(--bg3);border-radius:8px;padding:8px 12px"><div class="text-xs text-muted">Consumido</div><div style="font-size:16px;font-weight:700">S/ <?= number_format($consumido,2) ?></div></div>
      <div style="flex:1;min-width:120px;background:var(--bg3);border-radius:8px;padding:8px 12px"><div class="text-xs text-muted">Abonado</div><div style="font-size:16px;font-weight:700;color:var(--green,#10b981)">S/ <?= number_format($abonado,2) ?></div></div>
    </div>
  </div>

  <div class="grid g2">
    <!-- Consumos -->
    <div class="card">
      <div class="sec-header"><div class="sec-title">Consumos del tratamiento</div>
        <?php if($abierta): ?><button class="btn btn-sm btn-primary" onclick="document.getElementById('m-item').style.display='flex'">+ Agregar</button><?php endif; ?>
      </div>
      <?php if(empty($its)): ?><div class="text-center text-muted" style="padding:20px">Aún no hay consumos.</div><?php endif; ?>
      <?php foreach($its as $it): ?>
      <div class="flex items-center gap-2" style="padding:9px 0;border-bottom:1px solid var(--border)">
        <div class="flex-1">
          <div class="text-sm font-med"><?= $it['tipo']==='producto'?'📦':'🩺' ?> <?= clean($it['descripcion']) ?></div>
          <div class="text-xs text-muted"><?= $it['fecha'] ? date('d/m/Y', strtotime($it['fecha'])) : '' ?> · <?= rtrim(rtrim(number_format($it['cantidad'],2),'0'),'.') ?> × S/ <?= number_format($it['precio_unitario'],2) ?></div>
        </div>
        <span class="text-sm font-bold">S/ <?= number_format($it['subtotal'],2) ?></span>
        <?php if($abierta): ?>
        <form method="POST" onsubmit="return confirm('¿Eliminar este consumo?')" style="margin:0">
          <input type="hidden" name="accion" value="eliminar_item">
          <input type="hidden" name="cuenta_id" value="<?= $cuenta_id ?>">
          <input type="hidden" name="item_id" value="<?= $it['id'] ?>">
          <button class="btn btn-xs" style="color:var(--red,#ef4444)" title="Eliminar">✕</button>
        </form>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>

    <!-- Abonos + acciones -->
    <div>
      <div class="card mb-2">
        <div class="sec-header"><div class="sec-title">Abonos</div>
          <?php if($abierta): ?><button class="btn btn-sm" onclick="document.getElementById('m-abono').style.display='flex'">+ Abono</button><?php endif; ?>
        </div>
        <?php if(empty($abs)): ?><div class="text-center text-muted" style="padding:14px">Sin abonos.</div><?php endif; ?>
        <?php foreach($abs as $a): ?>
        <div class="flex items-center justify-between" style="padding:7px 0;border-bottom:1px solid var(--border)">
          <div><div class="text-sm"><?= $_metodos[$a['metodo_pago']] ?? $a['metodo_pago'] ?></div><div class="text-xs text-muted"><?= date('d/m/Y H:i', strtotime($a['fecha'])) ?></div></div>
          <span class="text-sm font-bold" style="color:var(--green,#10b981)">S/ <?= number_format($a['monto'],2) ?></span>
        </div>
        <?php endforeach; ?>
      </div>

      <?php if($abierta): ?>
      <div class="card">
        <div class="sec-header"><div class="sec-title">Cobrar y cerrar</div></div>
        <div class="text-sm text-muted mb-2">Genera un comprobante con todo el detalle y registra el pago del saldo (S/ <?= number_format(max($saldo,0),2) ?>).</div>
        <form method="POST" onsubmit="return confirm('¿Cobrar y cerrar la cuenta? Se generará el comprobante.')">
          <input type="hidden" name="accion" value="cerrar_cuenta">
          <input type="hidden" name="cuenta_id" value="<?= $cuenta_id ?>">
          <div class="form-row">
            <div class="form-group"><label class="form-label">Comprobante</label>
              <select class="form-input" name="tipo_comprobante"><option value="boleta">Boleta</option><option value="factura">Factura</option><option value="ticket">Ticket</option></select></div>
            <div class="form-group"><label class="form-label">Método de pago</label>
              <select class="form-input" name="metodo_pago"><?php foreach($_metodos as $k=>$v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?></select></div>
          </div>
          <button class="btn btn-primary w-full"><i class="ti ti-check"></i> Cobrar y cerrar — S/ <?= number_format(max($saldo,0),2) ?></button>
        </form>
      </div>
      <?php else: ?>
      <div class="card" style="text-align:center;padding:20px">
        <div style="font-size:32px">✅</div>
        <div class="font-bold">Cuenta cerrada</div>
        <?php if($c['venta_id']): ?><div class="text-sm text-muted">Comprobante generado (venta #<?= $c['venta_id'] ?>)</div><?php endif; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- MODAL: agregar consumo(s) — permite agregar VARIOS antes de guardar -->
  <div id="m-item" class="modal-overlay" style="display:none">
    <div class="modal" style="max-width:600px"><div class="modal-header"><div class="modal-title">Agregar consumos</div><button class="modal-close" onclick="document.getElementById('m-item').style.display='none'">✕</button></div>
      <div class="modal-body">

        <!-- Agregador de un ítem -->
        <div style="background:var(--bg3);border-radius:10px;padding:12px;margin-bottom:14px">
          <label class="form-label">¿De dónde sale el consumo?</label>
          <div class="flex gap-1 mb-2" style="flex-wrap:wrap">
            <button type="button" class="btn btn-sm cta-tbtn" data-tipo="servicio" onclick="ctaSetTipo('servicio')" style="background:#d1fae5;color:#065f46;border-color:#6ee7b7">🏥 Servicio</button>
            <button type="button" class="btn btn-sm cta-tbtn" data-tipo="producto" onclick="ctaSetTipo('producto')">📦 Producto</button>
            <button type="button" class="btn btn-sm cta-tbtn" data-tipo="petshop" onclick="ctaSetTipo('petshop')">🛒 Pet Shop</button>
            <button type="button" class="btn btn-sm cta-tbtn" data-tipo="manual" onclick="ctaSetTipo('manual')">✏️ Manual</button>
          </div>

          <div class="form-group" id="cta-buscador-wrap" style="position:relative;margin-bottom:8px">
            <input type="text" id="cta-it-buscar" class="form-input" autocomplete="off" placeholder="Escribe para buscar...">
            <div id="cta-it-drop" style="display:none;position:absolute;top:100%;left:0;right:0;margin-top:4px;background:var(--bg2);border:1px solid var(--border);border-radius:10px;box-shadow:0 8px 28px rgba(0,0,0,.12);z-index:60;max-height:200px;overflow-y:auto"></div>
          </div>

          <input class="form-input mb-1" id="cta-it-desc" placeholder="Descripción" style="margin-bottom:8px">
          <div class="flex gap-1" style="align-items:flex-end">
            <div style="flex:0 0 70px"><label class="form-label" style="font-size:11px">Cant.</label><input class="form-input" type="number" step="0.01" id="cta-it-cant" value="1" oninput="ctaCalcSub()"></div>
            <div style="flex:1"><label class="form-label" style="font-size:11px">Precio (S/)</label><input class="form-input" type="number" step="0.01" id="cta-it-precio" oninput="ctaCalcSub()"></div>
            <div style="flex:0 0 auto;text-align:right;padding-bottom:8px"><span class="text-xs text-muted">Subtotal</span><br><span class="font-bold" id="cta-it-sub">S/ 0.00</span></div>
          </div>
          <button type="button" class="btn btn-sm btn-primary w-full mt-2" onclick="ctaAddToList()">➕ Agregar a la lista</button>
        </div>

        <!-- Lista de ítems agregados (se guardan todos juntos) -->
        <form method="POST" id="cta-form-items">
          <input type="hidden" name="accion" value="agregar_item">
          <input type="hidden" name="cuenta_id" value="<?= $cuenta_id ?>">
          <div class="flex items-center justify-between mb-1">
            <div class="text-sm font-med">Consumos a agregar</div>
            <input class="form-input" type="date" name="fecha" value="<?= date('Y-m-d') ?>" style="width:auto;font-size:12px">
          </div>
          <div id="cta-lista" style="border:1px solid var(--border);border-radius:8px;min-height:60px;margin-bottom:10px"></div>
          <div class="flex items-center justify-between mb-2" style="padding:0 4px">
            <span class="font-bold">Total</span>
            <span class="font-bold" style="color:var(--teal,#0d9488);font-size:16px" id="cta-total">S/ 0.00</span>
          </div>
          <button class="btn btn-primary w-full" id="cta-guardar" disabled>💾 Guardar todos los consumos</button>
        </form>

      </div>
    </div>
  </div>

  <script>
  var CTA_SERVICIOS = <?= json_encode(array_values(array_map(fn($s)=>['id'=>(int)$s['id'],'nombre'=>$s['nombre'],'precio'=>(float)$s['precio']], $db->query("SELECT id,nombre,precio FROM servicios WHERE activo=1 ORDER BY nombre")->fetchAll()))) ?>;
  var CTA_PRODUCTOS = <?= json_encode(array_values(array_map(fn($p)=>['id'=>(int)$p['id'],'nombre'=>$p['nombre'],'precio'=>(float)$p['precio']], $db->query("SELECT id,nombre,precio_venta as precio FROM productos WHERE activo=1 ORDER BY nombre")->fetchAll()))) ?>;
  var CTA_PETSHOP = <?= json_encode(array_values(array_map(fn($p)=>['id'=>(int)$p['id'],'nombre'=>$p['nombre'],'precio'=>(float)$p['precio']], (function($db){ try { return $db->query("SELECT id, CONCAT(nombre, IFNULL(CONCAT(' (',contenido,')'),'')) as nombre, precio_venta as precio FROM petshop_productos WHERE activo=1 ORDER BY nombre")->fetchAll(); } catch(Exception $e){ return []; } })($db)))) ?>;

  var ctaTipoActual = 'servicio';
  var ctaItems = []; // lista temporal de consumos a guardar

  function ctaSetTipo(t){
    ctaTipoActual = t;
    document.querySelectorAll('.cta-tbtn').forEach(function(b){
      var on = b.getAttribute('data-tipo')===t;
      b.style.background = on ? '#d1fae5' : '';
      b.style.color = on ? '#065f46' : '';
      b.style.borderColor = on ? '#6ee7b7' : '';
    });
    var wrap = document.getElementById('cta-buscador-wrap');
    var inp = document.getElementById('cta-it-buscar');
    if (t==='manual'){
      wrap.style.display='none';
      document.getElementById('cta-it-desc').value='';
      document.getElementById('cta-it-precio').value='';
      document.getElementById('cta-it-desc').focus();
    } else {
      wrap.style.display='';
      inp.value=''; inp.placeholder = t==='servicio'?'Buscar servicio...' : t==='producto'?'Buscar producto (farmacia)...' : 'Buscar producto Pet Shop...';
      document.getElementById('cta-it-drop').style.display='none';
      setTimeout(function(){ inp.focus(); }, 50);
    }
  }
  function ctaListaActual(){
    return ctaTipoActual==='producto'?CTA_PRODUCTOS : ctaTipoActual==='petshop'?CTA_PETSHOP : CTA_SERVICIOS;
  }
  function ctaCalcSub(){
    var c = parseFloat(document.getElementById('cta-it-cant').value||'0');
    var p = parseFloat(document.getElementById('cta-it-precio').value||'0');
    document.getElementById('cta-it-sub').textContent = 'S/ ' + (Math.round(c*p*100)/100).toFixed(2);
  }
  // Agrega el ítem actual a la lista temporal
  function ctaAddToList(){
    var desc = document.getElementById('cta-it-desc').value.trim();
    var cant = parseFloat(document.getElementById('cta-it-cant').value||'1'); if(cant<=0) cant=1;
    var precio = parseFloat(document.getElementById('cta-it-precio').value||'0');
    if (!desc){ alert('Escribe o selecciona una descripción.'); return; }
    if (!(precio>=0) || isNaN(precio)){ alert('Ingresa un precio válido.'); return; }
    var tipoGuardar = (ctaTipoActual==='servicio'||ctaTipoActual==='manual') ? 'servicio' : 'producto';
    ctaItems.push({desc:desc, cant:cant, precio:precio, tipo:tipoGuardar, sub:Math.round(cant*precio*100)/100});
    // limpiar para el siguiente
    document.getElementById('cta-it-desc').value='';
    document.getElementById('cta-it-precio').value='';
    document.getElementById('cta-it-cant').value='1';
    document.getElementById('cta-it-buscar').value='';
    document.getElementById('cta-it-sub').textContent='S/ 0.00';
    ctaRenderList();
    if (ctaTipoActual!=='manual') document.getElementById('cta-it-buscar').focus();
    else document.getElementById('cta-it-desc').focus();
  }
  function ctaRemoveItem(i){ ctaItems.splice(i,1); ctaRenderList(); }
  function ctaRenderList(){
    var cont = document.getElementById('cta-lista');
    if (!ctaItems.length){ cont.innerHTML='<div style="padding:14px;text-align:center;color:var(--text3);font-size:12px">Aún no agregaste consumos. Usa "➕ Agregar a la lista".</div>'; }
    else {
      cont.innerHTML = ctaItems.map(function(it,i){
        var ic = it.tipo==='producto'?'📦':'🩺';
        return '<div class="flex items-center gap-2" style="padding:8px 10px;border-bottom:1px solid var(--border)">'
          + '<div class="flex-1"><div class="text-sm font-med">'+ic+' '+it.desc.replace(/</g,'&lt;')+'</div>'
          + '<div class="text-xs text-muted">'+(Math.round(it.cant*100)/100)+' × S/ '+it.precio.toFixed(2)+'</div></div>'
          + '<span class="text-sm font-bold">S/ '+it.sub.toFixed(2)+'</span>'
          + '<button type="button" onclick="ctaRemoveItem('+i+')" class="btn btn-xs" style="color:var(--red,#ef4444)">✕</button></div>';
      }).join('');
    }
    var total = ctaItems.reduce(function(s,it){return s+it.sub;},0);
    document.getElementById('cta-total').textContent = 'S/ ' + total.toFixed(2);
    document.getElementById('cta-guardar').disabled = ctaItems.length===0;
  }

  document.addEventListener('DOMContentLoaded', function(){
    var inp = document.getElementById('cta-it-buscar');
    var drop = document.getElementById('cta-it-drop');
    if (!inp) return;
    function pintar(lista){
      if(!lista.length){ drop.innerHTML='<div style="padding:10px 14px;font-size:12px;color:var(--text3)">Sin resultados</div>'; drop.style.display='block'; return; }
      drop.innerHTML = lista.map(function(x){
        return '<div class="cta-it-opt" data-nombre="'+x.nombre.replace(/"/g,'&quot;')+'" data-precio="'+x.precio+'" style="padding:9px 13px;cursor:pointer;border-bottom:1px solid var(--border)" onmouseover="this.style.background=\'var(--bg3)\'" onmouseout="this.style.background=\'\'">'
             + '<div style="font-size:13px;font-weight:600">'+x.nombre+' — <span style="color:var(--teal,#0d9488)">S/ '+x.precio.toFixed(2)+'</span></div></div>';
      }).join('');
      drop.style.display='block';
      drop.querySelectorAll('.cta-it-opt').forEach(function(el){
        el.addEventListener('mousedown', function(e){
          e.preventDefault();
          document.getElementById('cta-it-desc').value = el.getAttribute('data-nombre');
          document.getElementById('cta-it-precio').value = el.getAttribute('data-precio');
          inp.value = el.getAttribute('data-nombre');
          drop.style.display='none';
          ctaCalcSub();
          document.getElementById('cta-it-cant').focus();
        });
      });
    }
    inp.addEventListener('input', function(){
      var v = inp.value.toLowerCase().trim();
      var l = ctaListaActual();
      var f = v ? l.filter(function(x){ return x.nombre.toLowerCase().indexOf(v)>=0; }) : l;
      pintar(f.slice(0,15));
    });
    inp.addEventListener('focus', function(){ var l=ctaListaActual(); pintar(l.slice(0,15)); });
    document.addEventListener('click', function(e){ if(e.target!==inp && !drop.contains(e.target)) drop.style.display='none'; });

    // Al enviar el form, volcar la lista a inputs ocultos
    document.getElementById('cta-form-items').addEventListener('submit', function(e){
      if (!ctaItems.length){ e.preventDefault(); return; }
      var f = this;
      // limpiar ocultos previos
      f.querySelectorAll('.cta-hidden-item').forEach(function(x){ x.remove(); });
      ctaItems.forEach(function(it){
        ['it_desc:'+it.desc, 'it_cant:'+it.cant, 'it_precio:'+it.precio, 'it_tipo:'+it.tipo].forEach(function(pair){
          var idx = pair.indexOf(':'); var name = pair.substring(0,idx); var val = pair.substring(idx+1);
          var h = document.createElement('input'); h.type='hidden'; h.name=name+'[]'; h.value=val; h.className='cta-hidden-item';
          f.appendChild(h);
        });
      });
    });

    ctaSetTipo('servicio');
    ctaRenderList();
  });
  </script>

  <!-- MODAL: registrar abono -->
  <div id="m-abono" class="modal-overlay" style="display:none">
    <div class="modal"><div class="modal-header"><div class="modal-title">Registrar abono</div><button class="modal-close" onclick="document.getElementById('m-abono').style.display='none'">✕</button></div>
      <div class="modal-body"><form method="POST">
        <input type="hidden" name="accion" value="registrar_abono">
        <input type="hidden" name="cuenta_id" value="<?= $cuenta_id ?>">
        <div class="form-row">
          <div class="form-group"><label class="form-label">Monto (S/) *</label><input class="form-input" type="number" step="0.01" name="monto" required></div>
          <div class="form-group"><label class="form-label">Método</label><select class="form-input" name="metodo_pago"><?php foreach($_metodos as $k=>$v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?></select></div>
        </div>
        <div class="form-group"><label class="form-label">Nota (opcional)</label><input class="form-input" name="nota"></div>
        <button class="btn btn-primary">💾 Registrar abono</button>
      </form></div>
    </div>
  </div>

  <?php endif; ?>

<?php else: // ───── PANEL GENERAL ─────
  $_sid = function_exists('getSede') ? getSede() : 1;
  $_all = function_exists('verTodasSedes') ? verTodasSedes() : true;
  $sw = $_all ? '' : ' AND cu.sede_id='.(int)$_sid;
  $cuentas = $db->query("SELECT cu.*, cl.nombre AS cliente, m.nombre AS mascota,
                          (SELECT COALESCE(SUM(subtotal),0) FROM cuenta_items WHERE cuenta_id=cu.id) AS consumido,
                          (SELECT COALESCE(SUM(monto),0) FROM cuenta_abonos WHERE cuenta_id=cu.id) AS abonado
                         FROM cuentas cu
                         LEFT JOIN clientes cl ON cl.id=cu.cliente_id
                         LEFT JOIN mascotas m ON m.id=cu.mascota_id
                         WHERE cu.estado='abierta'$sw
                         ORDER BY cu.fecha_apertura ASC")->fetchAll();
  $total_cobrar = 0; $n_abiertas = count($cuentas);
  foreach($cuentas as $cc) $total_cobrar += ((float)$cc['consumido'] - (float)$cc['abonado']);
  // Abonado este mes
  $abonado_mes = (float)$db->query("SELECT COALESCE(SUM(monto),0) FROM cuenta_abonos WHERE MONTH(fecha)=MONTH(CURDATE()) AND YEAR(fecha)=YEAR(CURDATE())")->fetchColumn();
?>
  <div class="sec-header">
    <div><div class="page-title">💳 Cuentas por cobrar</div><div class="page-desc">Clientes con tratamientos en curso</div></div>
    <button class="btn btn-primary" onclick="document.getElementById('m-nueva').style.display='flex'">+ Nueva cuenta</button>
  </div>

  <div class="grid g3 mb-3">
    <div class="stat-card" style="border-color:var(--red,#ef4444)"><div class="stat-icon si-red">💰</div><div class="stat-value">S/ <?= number_format($total_cobrar,2) ?></div><div class="stat-label">Total por cobrar</div></div>
    <div class="stat-card"><div class="stat-icon si-blue">📂</div><div class="stat-value"><?= $n_abiertas ?></div><div class="stat-label">Cuentas abiertas</div></div>
    <div class="stat-card"><div class="stat-icon si-teal">✅</div><div class="stat-value">S/ <?= number_format($abonado_mes,2) ?></div><div class="stat-label">Abonado este mes</div></div>
  </div>

  <div class="card" style="padding:0">
    <div class="table-wrap">
      <table class="vtable">
        <thead><tr><th>Cliente</th><th>Mascota</th><th>Desde</th><th style="text-align:right">Consumido</th><th style="text-align:right">Abonado</th><th style="text-align:right">Saldo</th><th></th></tr></thead>
        <tbody>
          <?php foreach($cuentas as $cc): $sal = (float)$cc['consumido'] - (float)$cc['abonado']; ?>
          <tr onclick="location.href='?p=cuentas&id=<?= $cc['id'] ?>'" style="cursor:pointer">
            <td class="font-med"><?= clean($cc['cliente']) ?></td>
            <td class="text-muted"><?= clean($cc['mascota'] ?: '—') ?></td>
            <td class="text-muted"><?= date('d/m/Y', strtotime($cc['fecha_apertura'])) ?></td>
            <td style="text-align:right">S/ <?= number_format($cc['consumido'],2) ?></td>
            <td style="text-align:right;color:var(--green,#10b981)"><?= $cc['abonado']>0 ? 'S/ '.number_format($cc['abonado'],2) : '—' ?></td>
            <td style="text-align:right;font-weight:700;color:var(--red,#ef4444)">S/ <?= number_format($sal,2) ?></td>
            <td style="text-align:right;color:var(--text3)">ver ›</td>
          </tr>
          <?php endforeach; ?>
          <?php if(empty($cuentas)): ?>
          <tr><td colspan="7" style="text-align:center;color:var(--text3);padding:30px">No hay cuentas abiertas. Crea una con "+ Nueva cuenta".</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- MODAL: nueva cuenta -->
  <div id="m-nueva" class="modal-overlay" style="display:none">
    <div class="modal"><div class="modal-header"><div class="modal-title">Abrir nueva cuenta</div><button class="modal-close" onclick="document.getElementById('m-nueva').style.display='none'">✕</button></div>
      <div class="modal-body"><form method="POST">
        <input type="hidden" name="accion" value="abrir_cuenta">
        <div class="form-group" style="position:relative"><label class="form-label">Cliente *</label>
          <input type="text" id="cta-inp-cli" class="form-input" autocomplete="off" placeholder="Buscar cliente por nombre...">
          <input type="hidden" name="cliente_id" id="cta-hid-cli" required>
          <div id="cta-drop-cli" style="display:none;position:absolute;top:100%;left:0;right:0;margin-top:4px;background:var(--bg2);border:1px solid var(--border);border-radius:10px;box-shadow:0 8px 28px rgba(0,0,0,.12);z-index:50;max-height:220px;overflow-y:auto"></div>
        </div>
        <div class="form-group" style="position:relative"><label class="form-label">Mascota (opcional)</label>
          <input type="text" id="cta-inp-mas" class="form-input" autocomplete="off" placeholder="Buscar mascota...">
          <input type="hidden" name="mascota_id" id="cta-hid-mas">
          <div id="cta-drop-mas" style="display:none;position:absolute;top:100%;left:0;right:0;margin-top:4px;background:var(--bg2);border:1px solid var(--border);border-radius:10px;box-shadow:0 8px 28px rgba(0,0,0,.12);z-index:50;max-height:220px;overflow-y:auto"></div>
        </div>
        <div class="form-group"><label class="form-label">Nota (opcional)</label><input class="form-input" name="nota" placeholder="Ej. Tratamiento parvovirus"></div>
        <button class="btn btn-primary">Abrir cuenta</button>
      </form></div>
    </div>
  </div>

  <script>
  var _CTA_CLIENTES = <?= json_encode(array_map(fn($c)=>['id'=>$c['id'],'nombre'=>$c['nombre'],'label'=>$c['nombre'].(!empty($c['telefono'])?' · '.$c['telefono']:'')], $db->query("SELECT id,nombre,telefono FROM clientes WHERE activo=1 ORDER BY nombre")->fetchAll())) ?>;
  var _CTA_MASCOTAS = <?= json_encode(array_map(fn($m)=>['id'=>(int)$m['id'],'nombre'=>$m['nombre'],'cliente_id'=>(int)$m['cliente_id'],'sub'=>(!empty($m['especie'])?ucfirst($m['especie']):'').(!empty($m['raza'])?' · '.$m['raza']:'')], $db->query("SELECT id,nombre,especie,raza,cliente_id FROM mascotas WHERE estado='activo' ORDER BY nombre")->fetchAll())) ?>;

  document.addEventListener('DOMContentLoaded', function(){
    // Buscador de CLIENTE (usa el helper global)
    if (typeof vetSearchSelect === 'function') {
      vetSearchSelect('cta-inp-cli','cta-drop-cli','cta-hid-cli', _CTA_CLIENTES, 'nombre');
    }

    var hidCli = document.getElementById('cta-hid-cli');
    var inpMas = document.getElementById('cta-inp-mas');
    var hidMas = document.getElementById('cta-hid-mas');
    var dropMas = document.getElementById('cta-drop-mas');
    var mascotasDelCliente = []; // se actualiza al elegir cliente

    function pintarMas(lista){
      if (!lista.length) { dropMas.innerHTML = '<div style="padding:10px 14px;font-size:12px;color:var(--text3)">Sin mascotas</div>'; dropMas.style.display='block'; return; }
      dropMas.innerHTML = lista.map(function(m){
        return '<div class="cta-mas-opt" data-id="'+m.id+'" data-nombre="'+m.nombre.replace(/"/g,'&quot;')+'" style="padding:10px 14px;cursor:pointer;border-bottom:1px solid var(--border)" onmouseover="this.style.background=\'var(--bg3)\'" onmouseout="this.style.background=\'\'">'
             + '<div style="font-size:13px;font-weight:600">'+m.nombre+(m.sub?' · <span style="color:var(--text3)">'+m.sub+'</span>':'')+'</div></div>';
      }).join('');
      dropMas.style.display='block';
      dropMas.querySelectorAll('.cta-mas-opt').forEach(function(el){
        el.addEventListener('mousedown', function(e){
          e.preventDefault();
          inpMas.value = el.getAttribute('data-nombre');
          hidMas.value = el.getAttribute('data-id');
          dropMas.style.display='none';
        });
      });
    }

    // Filtrar mientras escribe (dentro de las mascotas del cliente)
    inpMas.addEventListener('input', function(){
      hidMas.value = '';
      var v = inpMas.value.toLowerCase().trim();
      var f = v ? mascotasDelCliente.filter(function(m){ return m.nombre.toLowerCase().indexOf(v)>=0; }) : mascotasDelCliente;
      pintarMas(f.slice(0,15));
    });
    inpMas.addEventListener('focus', function(){ if(!inpMas.disabled) pintarMas(mascotasDelCliente.slice(0,15)); });
    document.addEventListener('click', function(e){ if(e.target!==inpMas && !dropMas.contains(e.target)) dropMas.style.display='none'; });

    function setMascotas(){
      var cid = parseInt(hidCli.value || '0', 10);
      mascotasDelCliente = cid ? _CTA_MASCOTAS.filter(function(m){ return m.cliente_id===cid; }) : [];
      hidMas.value=''; inpMas.value='';
      if (cid) {
        inpMas.disabled = false;
        inpMas.placeholder = mascotasDelCliente.length ? 'Buscar mascota de este cliente...' : 'Este cliente no tiene mascotas';
      } else {
        inpMas.disabled = true;
        inpMas.placeholder = 'Primero selecciona un cliente';
      }
    }
    setMascotas(); // estado inicial

    // Detectar cuando cambia el cliente seleccionado
    var ultimo = '';
    setInterval(function(){ if (hidCli.value !== ultimo) { ultimo = hidCli.value; setMascotas(); } }, 250);
  });
  </script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
