<?php
$page = 'facturacion'; $pageTitle = 'Facturación';
$db   = getDB();
$user = getUser();
$action = $_GET['action'] ?? 'list';
$msg = '';

// ── Función global: serie según sede ─────────────────────────
function getSerieParaSede($db, $tipo_comp, $sede_id) {
    $clave = $tipo_comp === 'factura' ? 'serie_factura' :
             ($tipo_comp === 'ticket'  ? 'serie_ticket'  : 'serie_boleta');
    // 1. Buscar en configuracion_sede (específica por sede)
    try {
        $st = $db->prepare("SELECT valor FROM configuracion_sede WHERE sede_id=? AND clave=?");
        $st->execute([$sede_id, $clave]);
        $v = $st->fetchColumn();
        if ($v) return $v;
    } catch(Exception $e) {}
    // 2. Si es sede 1, buscar en config global
    if ($sede_id == 1) {
        try {
            $st = $db->prepare("SELECT valor FROM configuracion WHERE clave=?");
            $st->execute([$clave]);
            $v = $st->fetchColumn();
            if ($v) return $v;
        } catch(Exception $e) {}
    }
    // 3. Generar automáticamente: F+sede, B+sede, T+sede
    // Sede 1 → F001/B001/T001, Sede 2 → F002/B002/T002, etc.
    $pref = $tipo_comp === 'factura' ? 'F' : ($tipo_comp === 'ticket' ? 'T' : 'B');
    return $pref . str_pad($sede_id, 3, '0', STR_PAD_LEFT);
}

// ─── POST HANDLER ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pa = $_POST['action'] ?? '';

    // ── GUARDAR VENTA ──
    if ($pa === 'save') {
        $cliente_id = (int)($_POST['cliente_id'] ?? 0);
        $tipo  = $_POST['tipo_comprobante'] ?? 'boleta';
        $sede_id_actual = getSede();
        $serie = getSerieParaSede($db, $tipo, $sede_id_actual);

        $numero = siguienteNumeroSerie($db, $serie);

        // Si no hay cliente, usamos el cliente "CLIENTES VARIOS" (boleta/ticket).
        // Para FACTURA siempre debe haber un cliente con RUC válido (regla SUNAT).
        $save_blocked = false;
        if ($cliente_id === 0) {
            if ($tipo === 'factura') {
                $msg = 'cli_factura_req'; $action = 'nueva'; $save_blocked = true;
            } else {
                // Buscar (o crear) el cliente "CLIENTES VARIOS"
                $st = $db->prepare("SELECT id FROM clientes WHERE nombre='CLIENTES VARIOS' LIMIT 1");
                $st->execute();
                $cliente_id = (int)$st->fetchColumn();
                if ($cliente_id === 0) {
                    $db->prepare("INSERT INTO clientes (nombre,direccion,telefono,activo) VALUES ('CLIENTES VARIOS','-','-',1)")->execute();
                    $cliente_id = (int)$db->lastInsertId();
                }
            }
        } elseif ($tipo === 'factura') {
            // Si el tipo es FACTURA, el cliente debe tener RUC válido (11 dígitos)
            $st = $db->prepare("SELECT ruc FROM clientes WHERE id=?");
            $st->execute([$cliente_id]);
            $ruc = trim((string)$st->fetchColumn());
            if (strlen($ruc) !== 11) {
                $msg = 'cli_factura_ruc'; $action = 'nueva'; $save_blocked = true;
            }
        } elseif ($tipo === 'boleta') {
            // BOLETA: si el cliente se identifica solo con RUC, este debe ser de
            // persona natural (RUC 10). RUC 20 (persona jurídica) exige factura.
            $st = $db->prepare("SELECT COALESCE(dni,'') dni, COALESCE(ce,'') ce, COALESCE(pasaporte,'') pasaporte, COALESCE(ruc,'') ruc FROM clientes WHERE id=?");
            $st->execute([$cliente_id]);
            $cdoc = $st->fetch();
            $sin_otro_doc = strlen(trim($cdoc['dni'])) !== 8
                && strlen(trim($cdoc['ce'])) < 9
                && trim($cdoc['pasaporte']) === '';
            $ruc = trim($cdoc['ruc']);
            if ($sin_otro_doc && strlen($ruc) === 11 && substr($ruc, 0, 2) === '20') {
                $msg = 'cli_boleta_ruc20'; $action = 'nueva'; $save_blocked = true;
            }
        }
        if ($save_blocked) {
            // No procesamos el resto del bloque save; el form se vuelve a renderizar con $msg.
            goto end_save_block;
        }

        // ── Procesar ítems con campos planos (más robusto que arrays anidados) ──
        $items_ok = [];
        $subtotal = 0;
        $n = count($_POST['item_desc'] ?? []);
        for ($i = 0; $i < $n; $i++) {
            $desc  = trim(($_POST['item_desc'][$i])  ?? '');
            $qty   = max(1, (int)(($_POST['item_qty'][$i])   ?? 1));
            $price = (float)(($_POST['item_precio'][$i]) ?? 0);
            $tipo_it = ($_POST['item_tipo'][$i])  ?? 'servicio';
            $ref_id  = (int)(($_POST['item_ref'][$i])   ?? 0);
            if ($price <= 0 && $desc === '') continue;
            if ($price <= 0) continue;
            $sub = round($qty * $price, 2);
            $subtotal += $sub;
            $items_ok[] = [
                'tipo'  => $tipo_it ?: 'servicio',
                'ref'   => $ref_id,
                'desc'  => $desc ?: 'Servicio veterinario',
                'qty'   => $qty,
                'precio'=> $price,
                'sub'   => $sub,
            ];
        }

        if (empty($items_ok)) {
            $msg = 'error_items';
        } else {
            // Convención: precio_unitario YA INCLUYE IGV (cuando aplica_igv=1).
            // Si aplica_igv=0 → exonerado/inafecto: no se desglosa IGV, todo es base.
            $aplica_igv = isset($_POST['aplica_igv']) && $_POST['aplica_igv'] === '0' ? 0 : 1;
            $descuento  = (float)($_POST['descuento'] ?? 0);
            $bruto      = round($subtotal - $descuento, 2);     // lo que se cobra

            if ($aplica_igv) {
                $base  = round($bruto / 1.18, 2);   // op. gravadas
                $igv   = round($bruto - $base, 2);
            } else {
                $base  = $bruto;                    // op. inafectas/exoneradas
                $igv   = 0.00;
            }
            $total    = $bruto;
            // En BD: `ventas.subtotal` guarda la base (gravada o inafecta según el caso).
            $subtotal = $base;

            // Transacción atómica: si falla cualquier item, hacemos rollback para evitar
            // dejar la venta con totales que NO coinciden con sus items en BD.
            $db->beginTransaction();
            try {
                $st = $db->prepare("INSERT INTO ventas (sede_id,cliente_id,mascota_id,usuario_id,tipo_comprobante,serie,numero,subtotal,igv,aplica_igv,descuento,total,metodo_pago,estado,notas) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,'pagado',?)");
                $st->execute([
                    $user['sede_id'] ?? getSede(),
                    $cliente_id,
                    (int)($_POST['mascota_id'] ?? 0) ?: null,
                    $user['id'],
                    $tipo, $serie, $numero,
                    $subtotal, $igv, $aplica_igv, $descuento, $total,
                    'efectivo',  // placeholder, se actualiza abajo con el método real
                    trim($_POST['notas'] ?? '')
                ]);
                $venta_id = (int)$db->lastInsertId();

                $pagosRaw = json_decode($_POST['pagos_json'] ?? '[]', true);
                $pagosOk = is_array($pagosRaw) ? $pagosRaw : [];
                if (count($pagosOk) === 0) {
                    $pagosOk[] = ['metodo'=>'efectivo','monto'=>$total];
                }
                $stPago = $db->prepare("INSERT INTO venta_pagos (venta_id,metodo_pago,monto) VALUES (?,?,?)");
                foreach ($pagosOk as $p) {
                    $stPago->execute([$venta_id, $p['metodo'], (float)$p['monto']]);
                }

                // Si solo hay 1 método, guardar el nombre real
                // Si hay múltiples, concatenar todos: "yape + efectivo"
                $metodoFinal = count($pagosOk) === 1
                    ? $pagosOk[0]['metodo']
                    : implode(' + ', array_column($pagosOk, 'metodo'));

                $st2 = $db->prepare("INSERT INTO venta_items (venta_id,tipo,referencia_id,descripcion,cantidad,precio_unitario,subtotal) VALUES (?,?,?,?,?,?,?)");
                foreach ($items_ok as $it) {
                    $st2->execute([$venta_id, $it['tipo'], $it['ref'], $it['desc'], $it['qty'], $it['precio'], $it['sub']]);
                }

                // ── Generación de XML ANTES del commit ──
                // Si falla SUNAT para factura/boleta, se hace ROLLBACK y no se crea la venta.
                $sunat_xml_generado = null;
                if (in_array($tipo, ['factura', 'boleta'], true)) {
                    $sunat_cfg = __DIR__ . '/../includes/config_sunat.php';
                    $sunat_svc = __DIR__ . '/../includes/sunat/SunatService.php';
                    if (file_exists($sunat_cfg) && file_exists($sunat_svc)) {
                        require_once $sunat_cfg;
                        require_once $sunat_svc;
                        $sunat = new SunatService($db);
                        $resul = $sunat->generarXml($venta_id);
                        if (!$resul['ok']) {
                            // XML falló — rollback total, no se registra nada
                            $db->rollBack();
                            $_SESSION['flash_error'] = 'SUNAT_ERROR: ' . $resul['mensaje'];
                            header('Location: '.BASE_URL.'/index.php?p=facturacion&action=nueva&msg=sunat_fail');
                            exit;
                        }
                        $sunat_xml_generado = $resul['xml'] ?? null;
                    }
                }

                // ── Update metodo_pago real con transacción abierta ──
                // Ya tenemos $venta_id, ahora actualizamos el metodo_pago en ventas
                $db->prepare("UPDATE ventas SET metodo_pago=? WHERE id=?")->execute([$metodoFinal, $venta_id]);

                // Todo OK → commit
                $db->commit();

                // Movimiento de caja (post-commit, no es crítico)
                $caja = $db->query("SELECT id FROM cajas WHERE estado='abierta' ORDER BY id DESC LIMIT 1")->fetchColumn();
                if ($caja) {
                    $stCaja = $db->prepare("INSERT INTO movimientos_caja (caja_id,usuario_id,tipo,concepto,monto,metodo_pago,categoria,venta_id) VALUES (?,?,'ingreso',?,?,?,'servicio',?)");
                    foreach ($pagosOk as $p) {
                        $stCaja->execute([$caja, $user['id'], "Venta $serie-".str_pad($numero,5,'0',STR_PAD_LEFT), (float)$p['monto'], $p['metodo'], $venta_id]);
                    }
                }

                $_SESSION['flash_ok'] = $sunat_xml_generado
                    ? 'Venta registrada. XML generado correctamente.'
                    : 'Venta registrada (PDF/ticket sin XML SUNAT).';
                header('Location: '.BASE_URL.'/index.php?p=facturacion&action=ver&id='.$venta_id);
                exit;
            } catch (Throwable $e) {
                $db->rollBack();
                $_SESSION['flash_error'] = 'Error al guardar venta: ' . $e->getMessage();
                header('Location: '.BASE_URL.'/index.php?p=facturacion&action=nueva&msg=db_error');
                exit;
            }
        }
        end_save_block:;
    }

    if ($pa === 'anular') {
        $anular_id = (int)$_POST['id'];

        // Un comprobante ya emitido a SUNAT no se da de baja con un UPDATE:
        // requiere nota de crédito. Se redirige en vez de anular a ciegas.
        $st = $db->prepare("SELECT sunat_xml FROM ventas WHERE id=?");
        $st->execute([$anular_id]);
        if (!empty($st->fetchColumn())) {
            header('Location: '.BASE_URL.'/index.php?p=notas_credito&action=nueva&venta_id='.$anular_id);
            exit;
        }

        $db->prepare("UPDATE ventas SET estado='anulado' WHERE id=?")->execute([$anular_id]);
        // Descuenta de la caja abierta lo que esta venta había ingresado.
        $tuvo_ingreso = (int)$db->query("SELECT COUNT(*) FROM movimientos_caja WHERE tipo='ingreso' AND venta_id=".$anular_id)->fetchColumn();
        $egresos = registrarEgresoAnulacion($db, $anular_id, (int)$user['id'], 'Anulación');
        if ($tuvo_ingreso > 0 && $egresos === 0) {
            $_SESSION['flash_error'] = 'Venta anulada, pero NO se descontó de caja: no hay una caja abierta. Registra el egreso manualmente al abrir la próxima.';
        }
        $msg='anulado'; $action='list';
    }
    if ($pa === 'cobrar') {
        $db->prepare("UPDATE ventas SET estado='pagado',metodo_pago=? WHERE id=?")->execute([$_POST['metodo_pago'],(int)$_POST['id']]);
        $msg='cobrado'; $action='list';
    }

    // ── ENVIAR A SUNAT (manual, post-emisión) ──
    if ($pa === 'enviar_sunat') {
        $vid = (int)($_POST['id'] ?? 0);
        $sunat_cfg = __DIR__ . '/../includes/config_sunat.php';
        $sunat_svc = __DIR__ . '/../includes/sunat/SunatService.php';
        if (file_exists($sunat_cfg) && file_exists($sunat_svc)) {
            require_once $sunat_cfg;
            require_once $sunat_svc;
            $sunat = new SunatService($db);
            $resul = $sunat->enviarSunat($vid);
            $qs    = '&sunat=' . ($resul['ok'] ? 'env_ok' : 'env_err')
                   . '&sunat_msg=' . urlencode($resul['mensaje']);
        } else {
            $qs = '&sunat=xml_err&sunat_msg=' . urlencode('Módulo SUNAT no instalado.');
        }
        header('Location: '.BASE_URL.'/index.php?p=facturacion&action=ver&id='.$vid.$qs);
        exit;
    }
}

// ── AGREGAR / ELIMINAR MÉTODO DE PAGO ─────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_metodo_pago') {
    header('Content-Type: application/json');
    $nombre = trim($_POST['nombre'] ?? '');
    if ($nombre) {
        try {
            $st = $db->query("SELECT COALESCE(MAX(orden),0)+1 FROM metodos_pago");
            $orden = (int)$st->fetchColumn();
            $db->prepare("INSERT INTO metodos_pago (nombre, orden, activo) VALUES (?, ?, 1)")->execute([$nombre, $orden]);
        } catch(Exception $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]); exit;
        }
    }
    echo json_encode(['ok' => true]); exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'del_metodo_pago') {
    header('Content-Type: application/json');
    $id = (int)($_POST['id'] ?? 0);
    $db->prepare("DELETE FROM metodos_pago WHERE id=?")->execute([$id]);
    echo json_encode(['ok' => true]); exit;
}

// Cargar métodos de pago desde la tabla dedicada
$metodos_pago_list = [];
try {
    $rows = $db->query("SELECT id, nombre FROM metodos_pago WHERE activo=1 ORDER BY orden, id")->fetchAll();
    foreach ($rows as $r) $metodos_pago_list[] = ['id'=>$r['id'], 'nombre'=>$r['nombre']];
} catch(Exception $e) {}

// SUNAT — solo cargar si el módulo está instalado
$_sunat_disponible = false;
$_sunat_cfg = __DIR__ . '/../includes/config_sunat.php';
if (file_exists($_sunat_cfg)) {
    require_once $_sunat_cfg;
    $_sunat_disponible = true;
}
if (!defined('SUNAT_RUC')) define('SUNAT_RUC', '00000000000');

// ─── DESCARGAS XML / CDR (antes de imprimir HTML) ──────────────
if (in_array($action, ['xml', 'cdr'], true) && !empty($_GET['id'])) {
    $vid = (int)$_GET['id'];
    $st  = $db->prepare("SELECT serie, numero, tipo_comprobante, sunat_xml, sunat_cdr FROM ventas WHERE id=?");
    $st->execute([$vid]);
    $v = $st->fetch();
    if (!$v) { http_response_code(404); echo 'Venta no encontrada.'; exit; }

    $tipo = $v['tipo_comprobante'] === 'factura' ? '01' : '03';
    $base = SUNAT_RUC.'-'.$tipo.'-'.$v['serie'].'-'.str_pad($v['numero'], 8, '0', STR_PAD_LEFT);

    if ($action === 'xml') {
        if (empty($v['sunat_xml'])) { http_response_code(404); echo 'Esta venta no tiene XML generado.'; exit; }
        $download = isset($_GET['dl']);
        header('Content-Type: application/xml; charset=utf-8');
        if ($download) header('Content-Disposition: attachment; filename="'.$base.'.xml"');
        echo $v['sunat_xml'];
        exit;
    }

    // action === 'cdr' → viene en base64; lo decodificamos a ZIP
    if (empty($v['sunat_cdr'])) { http_response_code(404); echo 'Esta venta no tiene CDR (aún no fue aceptada por SUNAT).'; exit; }
    $bin = base64_decode($v['sunat_cdr'], true);
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="R-'.$base.'.zip"');
    echo $bin !== false ? $bin : $v['sunat_cdr'];
    exit;
}

// Ya pasamos los redirects → ahora sí podemos imprimir HTML.
require_once __DIR__ . '/../includes/header.php';

// ─── CARGAR VENTA PARA VER ──────────────────────────────────────
$venta_detalle = null;
$items_detalle = [];
if ($action === 'ver' && !empty($_GET['id'])) {
    $vid = (int)$_GET['id'];
    $st = $db->prepare("
        SELECT v.*, c.nombre as cliente, c.telefono, c.dni, c.ruc as cli_ruc, c.direccion as cli_dir,
               m.nombre as mascota, u.nombre as vendedor
        FROM ventas v
        JOIN clientes c ON c.id=v.cliente_id
        LEFT JOIN mascotas m ON m.id=v.mascota_id
        LEFT JOIN usuarios u ON u.id=v.usuario_id
        WHERE v.id=?");
    $st->execute([$vid]); $venta_detalle = $st->fetch();

    $st2 = $db->prepare("SELECT * FROM venta_items WHERE venta_id=? ORDER BY id ASC");
    $st2->execute([$vid]); $items_detalle = $st2->fetchAll();

    $pagos_detalle = $db->prepare("SELECT metodo_pago, monto FROM venta_pagos WHERE venta_id=? ORDER BY id ASC")->fetchAll();
}

// ─── DATOS PARA SELECTS ─────────────────────────────────────────
$_fac_sid = getSede();
$_fac_all = verTodasSedes();

// Filtros seguros — solo si la columna sede_id existe en cada tabla
$_fac_sw  = ""; // productos, clientes (sin alias)
$_fac_swm = ""; // mascotas m
$_fac_swp = ""; // petshop_productos p

if (!$_fac_all) {
    try { $r=$db->query("SHOW COLUMNS FROM `clientes` LIKE 'sede_id'")->fetchAll(); if(!empty($r)) $_fac_sw=" AND sede_id=$_fac_sid"; } catch(Exception $e){}
    try { $r=$db->query("SHOW COLUMNS FROM `mascotas` LIKE 'sede_id'")->fetchAll(); if(!empty($r)) $_fac_swm=" AND m.sede_id=$_fac_sid"; } catch(Exception $e){}
    try { $r=$db->query("SHOW COLUMNS FROM `productos` LIKE 'sede_id'")->fetchAll(); if(!empty($r)) $_fac_sw_prod=" AND sede_id=$_fac_sid"; } catch(Exception $e){ $_fac_sw_prod=""; }
    try { $r=$db->query("SHOW COLUMNS FROM `petshop_productos` LIKE 'sede_id'")->fetchAll(); if(!empty($r)) $_fac_swp=" AND p.sede_id=$_fac_sid"; } catch(Exception $e){}
} else {
    $_fac_sw_prod = "";
}

$clientes_sel  = $db->query("SELECT id,nombre,telefono,COALESCE(dni,'') as dni,COALESCE(ruc,'') as ruc,COALESCE(ce,'') as ce,COALESCE(pasaporte,'') as pasaporte FROM clientes WHERE activo=1$_fac_sw ORDER BY nombre")->fetchAll();
$mascotas_sel  = $db->query("SELECT m.id,CONCAT(m.nombre,' (',c.nombre,')') as label,m.cliente_id FROM mascotas m JOIN clientes c ON c.id=m.cliente_id WHERE m.estado='activo'$_fac_swm ORDER BY m.nombre")->fetchAll();
$servicios_sel = $db->query("SELECT id,nombre,precio FROM servicios WHERE activo=1 ORDER BY tipo,nombre")->fetchAll();
$productos_sel = $db->query("SELECT id,nombre,precio_venta as precio,COALESCE(codigo_barras,'') AS codigo FROM productos WHERE activo=1 AND stock>0".($_fac_sw_prod??" ")." ORDER BY nombre")->fetchAll();
try {
    $petshop_sel = $db->query("SELECT p.id, CONCAT(p.nombre, IFNULL(CONCAT(' (',p.contenido,')'), '')) as nombre, p.precio_venta as precio, COALESCE(p.codigo_barras,'') AS codigo FROM petshop_productos p WHERE p.activo=1 AND p.stock>0$_fac_swp ORDER BY p.nombre")->fetchAll();
} catch(Exception $e) { $petshop_sel = []; }

// ─── LISTA DE VENTAS ────────────────────────────────────────────
$search  = trim($_GET['q']  ?? '');
$estado_f = $_GET['estado'] ?? '';
$fecha_d  = $_GET['fecha_d'] ?? date('Y-m-01');
$fecha_h  = $_GET['fecha_h'] ?? date('Y-m-d');
$where  = "v.fecha BETWEEN ? AND ?";
$params = [$fecha_d.' 00:00:00', $fecha_h.' 23:59:59'];
if ($estado_f) { $where .= " AND v.estado=?"; $params[]=$estado_f; }
if ($search)   { $where .= " AND (c.nombre LIKE ? OR v.serie LIKE ? OR CAST(v.numero AS CHAR) LIKE ?)"; $like="%$search%"; $params=array_merge($params,[$like,$like,$like]); }
// Filtro por sede (sin afectar lógica SUNAT)
if (!verTodasSedes()) { $where .= " AND v.sede_id=" . getSede(); }
$ventas = $db->prepare("SELECT v.*,c.nombre as cliente,m.nombre as mascota FROM ventas v JOIN clientes c ON c.id=v.cliente_id LEFT JOIN mascotas m ON m.id=v.mascota_id WHERE $where ORDER BY v.fecha DESC LIMIT 100");
$ventas->execute($params); $ventas=$ventas->fetchAll();
$total_periodo = array_sum(array_column(array_filter($ventas,fn($v)=>$v['estado']==='pagado'),'total'));
?>

<?php if(($msg??'')==='error_items'): ?>
<div class="alert alert-warn mb-2">⚠️ Debes agregar al menos un servicio o producto con precio mayor a 0.</div>
<?php endif; ?>
<?php if(($msg??'')==='success' || ($_GET['msg']??'')==='nuevo'): ?>
<div class="alert alert-success mb-2">✅ Venta registrada exitosamente.</div>
<?php endif; ?>
<?php
$_sunat_flash = $_GET['sunat'] ?? '';
$_sunat_msg   = clean($_GET['sunat_msg'] ?? '');
?>
<?php if($_sunat_flash==='xml_ok'): ?>
<div class="alert alert-success mb-2">📄 XML generado: <?= $_sunat_msg ?: 'Listo para enviar a SUNAT.' ?></div>
<?php elseif($_sunat_flash==='xml_err'): ?>
<div class="alert alert-warn mb-2">⚠️ Error al generar XML: <?= $_sunat_msg ?></div>
<?php elseif($_sunat_flash==='env_ok'): ?>
<div class="alert alert-success mb-2">✅ SUNAT aceptó el comprobante. <?= $_sunat_msg ?></div>
<?php elseif($_sunat_flash==='env_err'): ?>
<div class="alert alert-warn mb-2">⚠️ SUNAT rechazó el envío: <?= $_sunat_msg ?></div>
<?php endif; ?>
<?php if(($msg??'')==='anulado'): ?><div class="alert alert-warn mb-2">⚠️ Venta anulada.</div><?php endif; ?>
<?php if(($msg??'')==='cobrado'): ?><div class="alert alert-success mb-2">✅ Pago registrado.</div><?php endif; ?>
<?php if(($msg??'')==='cli_factura_req'): ?><div class="alert alert-warn mb-2">⚠️ Para emitir una <strong>factura</strong> debes seleccionar un cliente con RUC válido.</div><?php endif; ?>
<?php if(($msg??'')==='cli_factura_ruc'): ?><div class="alert alert-warn mb-2">⚠️ El cliente seleccionado no tiene <strong>RUC válido</strong>. La factura requiere RUC de 11 dígitos. Edita el cliente o emite una boleta.</div><?php endif; ?>
<?php if(($msg??'')==='cli_boleta_ruc20'): ?><div class="alert alert-warn mb-2">⚠️ El cliente tiene <strong>RUC 20 (persona jurídica)</strong>. A una empresa le corresponde <strong>factura</strong>, no boleta.</div><?php endif; ?>
<?php if(($msg??'')==='error_items'): ?><div class="alert alert-warn mb-2">⚠️ Debes agregar al menos un ítem con precio mayor a 0.</div><?php endif; ?>

<?php if($action==='nueva'): ?>
<!-- ════════════════════════════ NUEVA VENTA ════════════════════════════ -->
<?php
$_sede_fac = getSede();
$_series_fac = [];
// Calcular series para TODAS las sedes (para que JS pueda cambiar sin reload)
try { $_todas_sedes = $db->query("SELECT id FROM sedes")->fetchAll(PDO::FETCH_COLUMN); } catch(Exception $e){ $_todas_sedes=[$_sede_fac]; }
foreach ($_todas_sedes as $_s) {
    foreach (['boleta','factura','ticket'] as $_tc) {
        $_serie = getSerieParaSede($db, $_tc, $_s);
        $_sig = siguienteNumeroSerie($db, $_serie);
        $_series_fac[$_s][$_tc] = ['serie'=>$_serie,'numero'=>str_pad($_sig,6,'0',STR_PAD_LEFT)];
    }
}
$_series_sede_actual = $_series_fac[$_sede_fac] ?? $_series_fac[1] ?? [];
// Datos JS clientes y mascotas
$_cli_js = array_map(fn($c)=>['id'=>$c['id'],'nombre'=>$c['nombre'],'dni'=>$c['dni']??'','ruc'=>$c['ruc']??'','ce'=>$c['ce']??'','pasaporte'=>$c['pasaporte']??''], $clientes_sel);
$_mas_js = array_map(fn($m)=>['id'=>$m['id'],'label'=>$m['label'],'cliente_id'=>$m['cliente_id']], $mascotas_sel);
if (isset($_SESSION['flash_error'])) {
    echo '<div class="alert alert-danger mb-3" style="padding:12px 16px;border-radius:8px;font-size:14px">❌ ' . htmlspecialchars($_SESSION['flash_error']) . '</div>';
    unset($_SESSION['flash_error']);
} elseif (isset($_SESSION['flash_ok'])) {
    echo '<div class="alert alert-success mb-3" style="padding:12px 16px;border-radius:8px;font-size:14px">✅ ' . htmlspecialchars($_SESSION['flash_ok']) . '</div>';
    unset($_SESSION['flash_ok']);
}
?>
<div class="card" style="max-width:1400px;width:100%">
  <div class="sec-header mb-3"><div class="sec-title">Nueva Venta</div><a href="?p=facturacion" class="btn btn-sm btn-ghost">← Volver</a></div>
 
<style>
@media (max-width: 600px){
  .cli-mas-grid{ grid-template-columns:1fr !important; }
}
</style>


 <form method="POST" id="venta-form">
    <input type="hidden" name="action" value="save">

    <div class="form-body" style="display:grid;grid-template-columns:1fr 340px;gap:16px;align-items:start">
      <div class="form-col-left"><!-- COLUMNA IZQUIERDA -->
        <!-- CLIENTE + MASCOTA -->
        <div class="cli-mas-grid" style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px">
          <div class="form-group" style="position:relative">
            <label class="form-label">Cliente <span style="color:var(--text3);font-weight:400">(opcional para boleta/nota)</span></label>
            <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
              <select id="sel-tipodoc" onchange="actualizarPlaceHolderDoc()" style="border:1.5px solid var(--border);border-radius:8px;padding:7px 10px;background:var(--bg2);color:var(--text);font-size:13px;flex-shrink:0;min-width:100px">
                <option value="dni">DNI</option>
                <option value="ruc">RUC</option>
                <option value="ce">Carné Ext.</option>
                <option value="pasaporte">Pasaporte</option>
              </select>
              <input type="text" id="cli-busq" class="form-input" placeholder="🔍 Ingresa el número..."
                     autocomplete="off" oninput="buscarCliente(this.value)" onfocus="buscarCliente(this.value)"
                     onblur="setTimeout(function(){document.getElementById('cli-drop').style.display='none'},200)" style="flex:1;min-width:120px">
              <button type="button" id="btnCliSearch" onclick="btnBuscarCliente()"
                      title="Consultar RENIEC o SUNAT"
                      style="background:var(--primary);border:none;border-radius:8px;cursor:pointer;font-size:14px;padding:7px 12px;color:white;flex-shrink:0">🔍</button>
            </div>
            <input type="hidden" name="cliente_id" id="cli-id">
            <div id="cli-drop" style="display:none;position:absolute;top:calc(100% + 2px);left:0;right:0;background:var(--bg2);border:1px solid var(--border);border-radius:8px;box-shadow:0 8px 24px rgba(0,0,0,.12);z-index:400;max-height:200px;overflow-y:auto"></div>
            <div id="cli-sel" style="display:none;margin-top:4px;padding:7px 10px;background:rgba(30,168,161,.1);border-radius:8px;font-size:13px;align-items:center;justify-content:space-between">
              <span id="cli-sel-nom" style="font-weight:600;color:var(--primary-d)"></span>
              <button type="button" onclick="limpiarCliente()" style="background:none;border:none;color:var(--text3);cursor:pointer">✕</button>
            </div>
          </div>
          <div class="form-group" style="position:relative">
            <label class="form-label">Mascota <span style="color:var(--text3);font-weight:400">(opcional)</span></label>
            <input type="text" id="mas-busq" class="form-input" placeholder="🐾 Selecciona primero un cliente"
                   autocomplete="off" oninput="buscarMascota(this.value)" onfocus="buscarMascota('')"
                   onblur="setTimeout(function(){document.getElementById('mas-drop').style.display='none'},200)" disabled>
            <input type="hidden" name="mascota_id" id="mas-id">
            <div id="mas-drop" style="display:none;position:absolute;top:calc(100% + 2px);left:0;right:0;background:var(--bg2);border:1px solid var(--border);border-radius:8px;box-shadow:0 8px 24px rgba(0,0,0,.12);z-index:400;max-height:200px;overflow-y:auto"></div>
            <div id="mas-sel" style="display:none;margin-top:4px;padding:7px 10px;background:var(--bg3);border-radius:8px;font-size:13px;align-items:center;justify-content:space-between">
              <span id="mas-sel-nom" style="font-weight:600"></span>
              <button type="button" onclick="limpiarMascota()" style="background:none;border:none;color:var(--text3);cursor:pointer">✕</button>
            </div>
          </div>
        </div>

        <!-- TIPO DOC + SERIE + N° + CONDICIÓN + FECHA -->
        <div style="display:grid;grid-template-columns:1fr 0.65fr 0.65fr 0.75fr 0.9fr;gap:8px;margin-bottom:10px">
          <div class="form-group">
            <label class="form-label">Tipo Doc.</label>
            <select class="form-input" name="tipo_comprobante" id="sel-tipo" onchange="actualizarSerieNum()" style="font-size:13px">
              <option value="boleta">BOLETA</option>
              <option value="factura">FACTURA</option>
              <option value="ticket">NOTA VENTA</option>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Serie</label>
            <input class="form-input" id="disp-serie" readonly style="background:var(--bg3);font-weight:800;letter-spacing:1px;color:var(--primary);text-align:center;font-size:13px;cursor:default">
          </div>
          <div class="form-group">
            <label class="form-label">N° Doc.</label>
            <input class="form-input" id="disp-numero" readonly style="background:var(--bg3);font-weight:800;font-size:13px;text-align:center;cursor:default">
          </div>
          <div class="form-group">
            <label class="form-label">Condición</label>
            <select class="form-input" name="condicion_pago" style="font-size:13px">
              <option value="contado">Contado</option>
              <option value="credito">Crédito</option>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">F. Emisión</label>
            <input class="form-input" type="date" name="fecha_emision" value="<?= date('Y-m-d') ?>" style="font-size:13px">
          </div>
        </div>

        <!-- ── ITEMS ── -->
        <div style="border:1px solid var(--border);border-radius:10px;padding:12px;margin-bottom:10px">
          <div class="flex items-center justify-between mb-2">
            <div class="sec-title">Servicios y Productos</div>
            <div class="flex gap-1">
              <button type="button" class="btn btn-sm btn-primary" onclick="addItem('servicio')">+ Servicio</button>
              <button type="button" class="btn btn-sm" onclick="addItem('producto')">+ Producto</button>
              <button type="button" class="btn btn-sm" style="background:#ede9fe;color:#6d28d9;border-color:#c4b5fd" onclick="addItem('petshop')">🛒 Pet Shop</button>
              <button type="button" class="btn btn-sm" onclick="addItemManual()">+ Manual</button>
            </div>
          </div>

          <!-- 📷 ESCANEO DE CÓDIGO DE BARRAS -->
          <div style="background:#f0fdfa;border:1px dashed #6ee7b7;border-radius:8px;padding:8px 12px;margin-bottom:10px">
            <div class="flex items-center gap-2">
              <span style="font-size:18px">📷</span>
              <input type="text" id="scan-input" class="form-input" placeholder="Escanear código de barras del producto (o escribir y Enter)..."
                     autocomplete="off" style="flex:1;font-size:13px"
                     onkeydown="if(event.key==='Enter'){event.preventDefault();procesarEscaneo(this.value);this.value='';}">
              <button type="button" class="btn btn-sm" onclick="document.getElementById('scan-input').focus()" title="Enfocar el campo para escanear">⚡ Listo para escanear</button>
            </div>
            <div id="scan-feedback" class="text-xs mt-1" style="min-height:14px;color:var(--text3)"></div>
          </div>

          <table style="width:100%;border-collapse:collapse;font-size:13px" id="items-table">
            <thead id="items-thead" style="display:none">
              <tr style="background:var(--bg3)">
                <th style="padding:6px 8px;text-align:left;font-size:11px;color:var(--text3);text-transform:uppercase;letter-spacing:.4px;border-bottom:1px solid var(--border)">Descripción</th>
                <th style="padding:6px 8px;text-align:center;width:65px;font-size:11px;color:var(--text3);text-transform:uppercase;letter-spacing:.4px;border-bottom:1px solid var(--border)">Cant.</th>
                <th style="padding:6px 8px;text-align:right;width:100px;font-size:11px;color:var(--text3);text-transform:uppercase;letter-spacing:.4px;border-bottom:1px solid var(--border)">Precio (S/.)</th>
                <th style="padding:6px 8px;text-align:right;width:85px;font-size:11px;color:var(--text3);text-transform:uppercase;letter-spacing:.4px;border-bottom:1px solid var(--border)">Subtotal</th>
                <th style="width:28px;border-bottom:1px solid var(--border)"></th>
              </tr>
            </thead>
            <tbody id="items-list"></tbody>
          </table>
          <div id="items-empty" class="text-center text-muted" style="padding:16px;font-size:13px">
            Haz clic en <strong>+ Servicio</strong> o <strong>+ Producto</strong> para agregar ítems
          </div>
        </div>

        <!-- DESC. + IGV + NOTAS -->
        <div class="form-group">
          <label class="form-label">Descuento (S/.)</label>
          <input class="form-input" type="number" step="0.01" name="descuento" id="inp-desc" value="0" min="0" oninput="calcTotal()">
          <label class="form-label mt-2">¿Aplica IGV?</label>
          <div class="igv-toggle">
            <label class="igv-opt">
              <input type="radio" name="aplica_igv" value="1" checked onchange="toggleIgv()">
              <span class="igv-pill"><span class="igv-ico">✓</span><span>Sí (gravado)</span></span>
            </label>
            <label class="igv-opt">
              <input type="radio" name="aplica_igv" value="0" onchange="toggleIgv()">
              <span class="igv-pill"><span class="igv-ico">○</span><span>No (exonerado)</span></span>
            </label>
          </div>
          <div id="igv-info" class="mt-2" style="font-size:11.5px;padding:9px 12px;border-radius:8px;background:#e8f8f7;border:1px solid #b8e6e3;color:#0f6b65">
            ℹ Los precios <strong>incluyen IGV (18%)</strong>. Se desglosa automáticamente en el comprobante.
          </div>
          <style>
            .igv-toggle { display:grid;grid-template-columns:1fr 1fr;gap:6px }
            .igv-opt { position:relative;cursor:pointer;user-select:none;margin:0 }
            .igv-opt input { position:absolute;opacity:0;width:0;height:0;pointer-events:none }
            .igv-pill { display:flex;align-items:center;justify-content:center;gap:6px; padding:7px 10px;border-radius:8px;font-size:12.5px;font-weight:600; background:#f5f7fa;color:#6b7280; border:1.5px solid #d1d5db; transition:all .15s ease;line-height:1 }
            .igv-ico { width:16px;height:16px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;background:#fff;color:transparent;border:1.5px solid #9ca3af;font-size:11px;font-weight:900;flex-shrink:0 }
            .igv-opt:hover .igv-pill { border-color:#1ea8a1;color:#0f6b65;background:#fff }
            .igv-opt input:checked ~ .igv-pill { background:#1ea8a1;color:#ffffff;border-color:#158a83;box-shadow:0 2px 6px rgba(30,168,161,.3) }
            .igv-opt input:checked ~ .igv-pill .igv-ico { background:#ffffff;color:#1ea8a1;border-color:#ffffff }
          </style>
          <div class="form-group mt-2"><label class="form-label">Notas</label><input class="form-input" name="notas" placeholder="Observaciones del comprobante"></div>
        </div>
      </div><!-- /form-col-left -->

      <div class="form-col-right"><!-- COLUMNA DERECHA -->
        <div style="background:var(--teal-l);border:1.5px solid var(--teal);border-radius:12px;padding:16px;margin-bottom:12px">
          <div class="flex justify-between text-sm mb-2"><span class="text-muted" id="lbl-tot-sub">Op. Gravadas:</span><span id="tot-sub">S/. 0.00</span></div>
          <div class="flex justify-between text-sm mb-2"><span class="text-muted">Descuento:</span><span id="tot-desc" style="color:var(--red)">-S/. 0.00</span></div>
          <div class="flex justify-between text-sm mb-2" id="row-tot-igv"><span class="text-muted">IGV (18%):</span><span id="tot-igv">S/. 0.00</span></div>
          <div style="border-top:1.5px solid var(--teal);padding-top:10px;margin-top:4px" class="flex justify-between"><span style="font-size:16px;font-weight:800;color:var(--teal-d)">Total:</span><span id="tot-total" style="font-size:20px;font-weight:800;color:var(--teal-d)">S/. 0.00</span></div>
        </div>

        <!-- ── MÉTODOS DE PAGO MÚLTIPLES ── -->
        <div style="border:1.5px solid var(--teal);border-radius:12px;padding:12px 14px;background:var(--teal-l)">
          <div class="flex items-center justify-between mb-2">
            <label class="form-label" style="margin:0;font-weight:700;color:var(--teal-d)">💰 Métodos de pago</label>
            <button type="button" onclick="abrirModalMetodoPago()" style="background:none;border:none;color:var(--teal-d);cursor:pointer;font-size:12px;text-decoration:underline">⚙️ Editar</button>
          </div>
          <div id="lista-metodos-pago-ui" style="margin-bottom:8px"></div>
          <div style="display:flex;gap:8px;align-items:center">
            <select id="sel-nuevo-metodo" class="form-input" style="flex:1">
              <?php foreach($metodos_pago_list as $m): ?>
                <option value="<?= strtolower(str_replace(' ','_',$m['nombre'])) ?>"><?= clean($m['nombre']) ?></option>
              <?php endforeach; ?>
            </select>
            <div style="position:relative;flex:none">
              <span style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:var(--text3);font-size:13px">S/.</span>
              <input type="number" id="inp-monto-metodo" class="form-input" placeholder="0.00" min="0" step="0.01" style="padding-left:32px;width:110px">
            </div>
            <button type="button" onclick="agregarFilaPago()" style="background:var(--primary);border:none;border-radius:8px;cursor:pointer;font-size:13px;padding:7px 14px;color:white">+</button>
          </div>
          <input type="hidden" name="pagos_json" id="pagos-json" value="[]">
          <div id="pago-total-msg" style="margin-top:6px;font-size:12px;font-weight:600"></div>
        </div>

        <div class="flex gap-1" style="margin-top:10px">
          <button type="submit" class="btn btn-primary" style="flex:1;justify-content:center" id="btn-emitir">🧾 Emitir comprobante</button>
          <a href="?p=facturacion" class="btn">Cancelar</a>
        </div>
      </div><!-- /form-col-right -->
    </div><!-- /form-body -->
  </form>
</div>
        <!-- ═══ MODAL NUEVO CLIENTE (desde RENIEC/SUNAT) ═══ -->
<div id="modalNuevoCliente" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:999;align-items:center;justify-content:center">
  <div style="background:var(--bg2);border:1px solid var(--border);border-radius:12px;width:460px;max-width:95vw">
    <div style="display:flex;align-items:center;justify-content:space-between;padding:14px 16px;border-bottom:1px solid var(--border)">
      <div style="font-size:15px;font-weight:700;color:var(--text)">➕ Nuevo cliente desde RENIEC/SUNAT</div>
      <button type="button" onclick="cerrarModalNuevoCliente()" style="background:none;border:none;color:var(--text3);cursor:pointer;font-size:18px">✕</button>
    </div>
    <div style="padding:16px">
      <div class="form-group"><label class="form-label">Nombre completo *</label>
        <input type="text" id="new-nombre" class="form-input" placeholder="Nombre obtenido de la consulta"></div>
      <div class="form-row">
        <div class="form-group"><label class="form-label">DNI</label>
          <input type="text" id="new-dni" class="form-input" maxlength="8" placeholder="8 dígitos"></div>
        <div class="form-group"><label class="form-label">RUC</label>
          <input type="text" id="new-ruc" class="form-input" maxlength="11" placeholder="11 dígitos"></div>
      </div>
      <div class="form-row">
        <div class="form-group"><label class="form-label">Carné Extranjería</label>
          <input type="text" id="new-ce" class="form-input" maxlength="15" placeholder="9+ dígitos"></div>
        <div class="form-group"><label class="form-label">Pasaporte</label>
          <input type="text" id="new-pasaporte" class="form-input" maxlength="20" placeholder="Número Pasaporte"></div>
      </div>
      <div class="form-group"><label class="form-label">Teléfono</label>
        <input type="text" id="new-telefono" class="form-input" placeholder="+51 987 654 321"></div>
      <div class="form-group"><label class="form-label">Email</label>
        <input type="email" id="new-email" class="form-input" placeholder="email@ejemplo.com"></div>
      <div class="form-group"><label class="form-label">Dirección</label>
        <input type="text" id="new-direccion" class="form-input" placeholder="Dirección del cliente"></div>
      <div style="margin-top:14px;display:flex;gap:8px">
        <button type="button" id="btnNewCliSave" onclick="guardarNuevoCliente()" class="btn btn-primary" style="flex:1">💾 Guardar cliente</button>
        <button type="button" onclick="cerrarModalNuevoCliente()" class="btn btn-ghost">Cancelar</button>
      </div>
    </div>
  </div>
</div>

<!-- ═══ MODAL MÉTODOS DE PAGO ═══ -->
<div id="modalMetodoPago" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:999;align-items:center;justify-content:center">
  <div style="background:var(--bg2);border:1px solid var(--border);border-radius:12px;width:460px;max-width:95vw">
    <div style="display:flex;align-items:center;justify-content:space-between;padding:14px 16px;border-bottom:1px solid var(--border)">
      <div style="font-size:15px;font-weight:700;color:var(--text)">⚙️ Métodos de pago</div>
      <button type="button" onclick="cerrarModalMetodoPago()" style="background:none;border:none;color:var(--text3);cursor:pointer;font-size:18px">✕</button>
    </div>
    <div style="padding:16px">
      <div id="lista-metodos-pago" style="margin-bottom:14px">
        <?php foreach($metodos_pago_list as $m): ?>
          <div style="display:flex;align-items:center;justify-content:space-between;padding:8px 12px;background:var(--bg3);border-radius:8px;margin-bottom:6px" id="mp-row-<?= $m['id'] ?>">
            <span style="font-weight:600"><?= clean($m['nombre']) ?></span>
            <button type="button" onclick="eliminarMetodoPago('<?= $m['id'] ?>')" style="background:none;border:none;color:var(--red);cursor:pointer;font-size:14px" title="Eliminar">✕</button>
          </div>
        <?php endforeach; ?>
      </div>
      <div style="display:flex;gap:8px">
        <input type="text" id="new-nombre-metodo" class="form-input" placeholder="Ej: Visa, Mastercard, Mercado Pago..." style="flex:1">
        <button type="button" onclick="agregarMetodoPago()" class="btn btn-primary">+ Agregar</button>
      </div>
    </div>
  </div>
</div>

<?php elseif($action==='ver' && $venta_detalle): ?>
<!-- ════════════════════════════ VER VENTA ════════════════════════════ -->
<div class="card" style="max-width:700px">
  <div class="sec-header">
    <div>
      <div class="sec-title"><?= strtoupper($venta_detalle['tipo_comprobante']) ?> <?= $venta_detalle['serie'] ?>-<?= str_pad($venta_detalle['numero'],5,'0',STR_PAD_LEFT) ?></div>
      <div class="sec-sub"><?= date('d/m/Y H:i',strtotime($venta_detalle['fecha'])) ?></div>
    </div>
    <div class="flex gap-1 flex-wrap">
      <span class="badge <?= $venta_detalle['estado']==='pagado'?'b-teal':($venta_detalle['estado']==='anulado'?'b-red':'b-amber') ?>">
        <span class="dot"></span><?= ucfirst($venta_detalle['estado']) ?>
      </span>
      <a href="?p=facturacion" class="btn btn-sm">← Volver</a>
    </div>
  </div>

  <!-- DATOS CLIENTE -->
  <div class="grid g2 mb-2" style="background:var(--bg3);border-radius:10px;padding:14px;gap:12px">
    <div>
      <div class="text-xs text-muted mb-1">CLIENTE</div>
      <div class="font-bold"><?= clean($venta_detalle['cliente']) ?></div>
      <div class="text-xs text-muted"><?= clean($venta_detalle['telefono']) ?></div>
      <?php if($venta_detalle['dni']): ?><div class="text-xs text-muted">DNI: <?= clean($venta_detalle['dni']) ?></div><?php endif; ?>
    </div>
    <?php if($venta_detalle['mascota']): ?>
    <div>
      <div class="text-xs text-muted mb-1">MASCOTA</div>
      <div class="font-bold">🐾 <?= clean($venta_detalle['mascota']) ?></div>
    </div>
    <?php endif; ?>
  </div>

  <!-- TABLA ÍTEMS -->
  <div class="table-wrap mb-2">
    <table class="vtable">
      <thead>
        <tr>
          <th>Descripción</th>
          <th style="text-align:center;width:60px">Cant.</th>
          <th style="text-align:right;width:100px">Precio</th>
          <th style="text-align:right;width:100px">Subtotal</th>
        </tr>
      </thead>
      <tbody>
        <?php if(empty($items_detalle)): ?>
        <tr><td colspan="4" class="text-center text-muted" style="padding:20px;font-style:italic">Sin ítems registrados.</td></tr>
        <?php else: ?>
        <?php foreach($items_detalle as $it): ?>
        <tr>
          <td class="td-main"><?= clean($it['descripcion']) ?></td>
          <td style="text-align:center"><?= $it['cantidad'] ?></td>
          <td style="text-align:right">S/. <?= number_format($it['precio_unitario'],2) ?></td>
          <td style="text-align:right" class="font-med">S/. <?= number_format($it['subtotal'],2) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <!-- TOTALES -->
  <div style="text-align:right;border-top:1px solid var(--border);padding-top:12px;margin-bottom:14px">
    <?php $_aplica = !isset($venta_detalle['aplica_igv']) || (int)$venta_detalle['aplica_igv']===1; ?>
    <div class="text-sm text-muted mb-1"><?= $_aplica ? 'Op. Gravadas:' : 'Op. Inafectas:' ?> S/. <?= number_format($venta_detalle['subtotal'],2) ?></div>
    <?php if(($venta_detalle['descuento']??0)>0): ?>
    <div class="text-sm text-muted mb-1" style="color:var(--red)">Descuento: -S/. <?= number_format($venta_detalle['descuento'],2) ?></div>
    <?php endif; ?>
    <?php if ($_aplica): ?><div class="text-sm text-muted mb-1">IGV (18%): S/. <?= number_format($venta_detalle['igv'],2) ?></div><?php endif; ?>
    <div style="font-size:22px;font-weight:800;color:var(--teal-d)">Total: S/. <?= number_format($venta_detalle['total'],2) ?></div>
    <div class="text-xs text-muted mt-1">
      <?php
      $pagosLinea = [];
      foreach ($pagos_detalle as $p) {
          $pagosLinea[] = ucfirst(str_replace('_',' ',$p['metodo_pago'])) . ': S/. ' . number_format($p['monto'], 2);
      }
      echo implode(' + ', $pagosLinea);
      ?>
    </div>
  </div>

  <!-- OPCIONES IMPRESIÓN -->
  <div style="background:var(--bg3);border:1px solid var(--border);border-radius:12px;padding:16px;margin-bottom:14px">
    <div class="flex items-center gap-2 mb-3"><span style="font-size:18px">🖨️</span><div class="font-bold text-sm">Opciones de impresión</div></div>
    <div class="flex gap-2" style="flex-wrap:wrap">
      <a href="<?= BASE_URL ?>/print/comprobante.php?id=<?= $venta_detalle['id'] ?>&fmt=a4" target="_blank" style="flex:1;min-width:130px;text-decoration:none">
        <div class="card card-sm text-center" style="cursor:pointer;transition:all .15s" onmouseover="this.style.borderColor='var(--teal)';this.style.background='var(--teal-l)'" onmouseout="this.style.borderColor='var(--border)';this.style.background='var(--bg2)'">
          <div style="font-size:26px;margin-bottom:4px">📄</div>
          <div class="font-bold text-sm">Formato A4</div>
          <div class="text-xs text-muted">Documento estándar</div>
        </div>
      </a>
      <a href="<?= BASE_URL ?>/print/comprobante.php?id=<?= $venta_detalle['id'] ?>&fmt=voucher" target="_blank" style="flex:1;min-width:130px;text-decoration:none">
        <div class="card card-sm text-center" style="cursor:pointer;transition:all .15s" onmouseover="this.style.borderColor='var(--teal)';this.style.background='var(--teal-l)'" onmouseout="this.style.borderColor='var(--border)';this.style.background='var(--bg2)'">
          <div style="font-size:26px;margin-bottom:4px">🖨️</div>
          <div class="font-bold text-sm">Voucher 80mm</div>
          <div class="text-xs text-muted">Ticket de impresora</div>
        </div>
      </a>
      <a href="<?= BASE_URL ?>/print/ver.php?serie=<?= urlencode($venta_detalle['serie']) ?>&num=<?= $venta_detalle['numero'] ?>" target="_blank" style="flex:1;min-width:130px;text-decoration:none">
        <div class="card card-sm text-center" style="cursor:pointer;transition:all .15s" onmouseover="this.style.borderColor='var(--blue)';this.style.background='var(--blue-l)'" onmouseout="this.style.borderColor='var(--border)';this.style.background='var(--bg2)'">
          <div style="font-size:26px;margin-bottom:4px">🌐</div>
          <div class="font-bold text-sm" style="color:var(--blue)">Ver en web</div>
          <div class="text-xs text-muted">Link para el cliente</div>
        </div>
      </a>
    </div>
    <div class="text-xs text-muted mt-2 text-center">Personaliza la cabecera en <a href="?p=plantillas" style="color:var(--blue)">Plantillas de Impresión</a></div>
  </div>

  <!-- ─── PANEL SUNAT ─── -->
  <?php if(in_array($venta_detalle['tipo_comprobante'], ['factura','boleta'], true)):
      $se = $venta_detalle['sunat_estado'] ?? null;
      $cls = $se==='aceptado' ? 'b-teal' : ($se==='rechazado' ? 'b-red' : ($se==='pendiente' ? 'b-amber' : 'b-gray'));
      $lab = $se ? ucfirst($se) : 'Sin emitir';
  ?>
  <div style="background:var(--bg3);border:1px solid var(--border);border-radius:12px;padding:16px;margin-bottom:14px">
    <div class="flex items-center justify-between mb-3">
      <div class="flex items-center gap-2"><span style="font-size:18px">🏛️</span><div class="font-bold text-sm">SUNAT</div></div>
      <span class="badge <?= $cls ?>"><span class="dot"></span> <?= $lab ?></span>
    </div>

    <?php if(!empty($venta_detalle['sunat_mensaje'])): ?>
      <div class="text-xs text-muted mb-2"><?= clean($venta_detalle['sunat_mensaje']) ?></div>
    <?php endif; ?>
    <?php if(!empty($venta_detalle['sunat_hash'])): ?>
      <div class="text-xs text-muted mb-2"><strong>Hash:</strong> <code style="font-size:11px"><?= clean($venta_detalle['sunat_hash']) ?></code></div>
    <?php endif; ?>

    <div class="flex gap-1 flex-wrap">
      <?php if(!empty($venta_detalle['sunat_xml'])): ?>
        <a href="?p=facturacion&action=xml&id=<?= $venta_detalle['id'] ?>" target="_blank" class="btn btn-sm">📄 Ver XML</a>
        <a href="?p=facturacion&action=xml&id=<?= $venta_detalle['id'] ?>&dl=1" class="btn btn-sm">⬇ Descargar XML</a>
      <?php endif; ?>

      <?php if(!empty($venta_detalle['sunat_xml']) && $se !== 'aceptado'): ?>
        <form method="POST" style="display:inline" onsubmit="return confirm('¿Enviar este comprobante a SUNAT?');">
          <input type="hidden" name="action" value="enviar_sunat">
          <input type="hidden" name="id" value="<?= $venta_detalle['id'] ?>">
          <button type="submit" class="btn btn-sm btn-primary">📤 Enviar a SUNAT</button>
        </form>
      <?php endif; ?>

      <?php if(!empty($venta_detalle['sunat_cdr'])): ?>
        <a href="?p=facturacion&action=cdr&id=<?= $venta_detalle['id'] ?>" class="btn btn-sm">⬇ Descargar CDR</a>
      <?php endif; ?>

      <?php if(empty($venta_detalle['sunat_xml'])): ?>
        <div class="text-xs text-muted">Esta venta no tiene XML aún. Vuelve a emitirla para regenerarlo.</div>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- COBRAR si pendiente -->
  <?php if($venta_detalle['estado']==='pendiente'): ?>
  <form method="POST" class="flex gap-1 mb-2">
    <input type="hidden" name="action" value="cobrar">
    <input type="hidden" name="id" value="<?= $venta_detalle['id'] ?>">
    <select class="form-input" name="metodo_pago" style="width:180px">
      <option value="efectivo">Efectivo</option><option value="yape">Yape</option>
      <option value="plin">Plin</option><option value="tarjeta_debito">Tarjeta débito</option>
    </select>
    <button type="submit" class="btn btn-primary">✅ Registrar pago</button>
  </form>
  <?php endif; ?>

  <!-- ANULAR -->
  <?php if($venta_detalle['estado']==='pagado'): ?>
  <?php if(!empty($venta_detalle['sunat_xml'])): ?>
    <!-- Comprobante electrónico: ante SUNAT solo se da de baja con nota de crédito. -->
    <a href="?p=notas_credito&action=nueva&venta_id=<?= $venta_detalle['id'] ?>"
       class="btn btn-xs mb-2" style="color:var(--red);display:inline-flex">✕ Anular (nota de crédito)</a>
  <?php else: ?>
  <form method="POST" class="mb-2" style="display:inline">
    <input type="hidden" name="action" value="anular">
    <input type="hidden" name="id" value="<?= $venta_detalle['id'] ?>">
    <button type="submit" class="btn btn-xs" style="color:var(--red)" onclick="return confirm('¿Anular este comprobante? Esta acción no se puede deshacer.')">✕ Anular comprobante</button>
  </form>
  <?php endif; ?>
  <?php endif; ?>

  <!-- WHATSAPP CON URL -->
  <?php
  $tel = preg_replace('/[^0-9]/','',ltrim($venta_detalle['telefono'],'+'));
  if(strlen($tel)<11) $tel='51'.$tel;
  $num_cmp = $venta_detalle['serie'].'-'.str_pad($venta_detalle['numero'],6,'0',STR_PAD_LEFT);
  $url_cmp = BASE_URL.'/print/ver.php?serie='.urlencode($venta_detalle['serie']).'&num='.$venta_detalle['numero'];
  $tipo_wa = ['boleta'=>'BOLETA','factura'=>'FACTURA','ticket'=>'NOTA DE VENTA'];
  $wa_msg  = "🧾 *".($tipo_wa[$venta_detalle['tipo_comprobante']]??'COMPROBANTE')." VetPro*\n";
  $wa_msg .= "N° $num_cmp\n\n";
  $wa_msg .= "👤 Cliente: {$venta_detalle['cliente']}\n";
  $wa_msg .= "📅 Fecha: ".date('d/m/Y',strtotime($venta_detalle['fecha']))."\n\n";
  // Detalle de ítems en el mensaje
  foreach($items_detalle as $it) {
    $wa_msg .= "• {$it['descripcion']} x{$it['cantidad']} → S/. ".number_format($it['subtotal'],2)."\n";
  }
  $wa_msg .= "\n💰 *Total: S/. ".number_format($venta_detalle['total'],2)."*\n";
  $wa_msg .= "💳 Pago: ".ucfirst(str_replace('_',' ',$venta_detalle['metodo_pago']))." ✅\n\n";
  $wa_msg .= "📄 Ver comprobante:\n$url_cmp\n\n";
  $wa_msg .= "¡Gracias por confiar en VetPro 🐾!";
  ?>
  <a href="https://wa.me/<?= $tel ?>?text=<?= rawurlencode($wa_msg) ?>" target="_blank"
     class="btn btn-wa" style="width:100%;justify-content:center;font-size:14px;padding:12px">
    💬 Enviar comprobante por WhatsApp
  </a>
</div>

<?php else: ?>
<!-- ════════════════════════════ LISTA ════════════════════════════ -->
<div class="grid g4 mb-2">
  <div class="stat-card"><div class="stat-icon si-teal">💰</div><div class="stat-value">S/. <?= number_format($total_periodo,0) ?></div><div class="stat-label">Ingresos del período</div></div>
  <div class="stat-card"><div class="stat-icon si-blue">🧾</div><div class="stat-value"><?= count($ventas) ?></div><div class="stat-label">Comprobantes</div></div>
  <div class="stat-card"><div class="stat-icon si-teal">✅</div><div class="stat-value"><?= count(array_filter($ventas,fn($v)=>$v['estado']==='pagado')) ?></div><div class="stat-label">Pagados</div></div>
  <div class="stat-card"><div class="stat-icon si-amber">⏳</div><div class="stat-value"><?= count(array_filter($ventas,fn($v)=>$v['estado']==='pendiente')) ?></div><div class="stat-label">Pendientes</div></div>
</div>
<div class="card mb-2" style="padding:14px 18px">
  <form method="GET" class="flex items-center gap-2" style="flex-wrap:wrap">
    <input type="hidden" name="p" value="facturacion">
    <input class="form-input" name="q" value="<?= clean($search) ?>" placeholder="Buscar cliente, serie..." style="width:220px">
    <input class="form-input" type="date" name="fecha_d" value="<?= $fecha_d ?>" style="width:150px">
    <input class="form-input" type="date" name="fecha_h" value="<?= $fecha_h ?>" style="width:150px">
    <select class="form-input" name="estado" style="width:140px">
      <option value="">Todos</option>
      <option value="pagado"   <?= $estado_f==='pagado'   ?'selected':'' ?>>Pagado</option>
      <option value="pendiente"<?= $estado_f==='pendiente'?'selected':'' ?>>Pendiente</option>
      <option value="anulado"  <?= $estado_f==='anulado'  ?'selected':'' ?>>Anulado</option>
    </select>
    <button type="submit" class="btn">Filtrar</button>
    <a href="?p=facturacion&action=nueva" class="btn btn-primary" style="margin-left:auto">+ Nueva Venta</a>
    <a href="?p=plantillas" class="btn" title="Plantillas de impresión">🖨️ Plantillas</a>
  </form>
</div>
<div class="card" style="padding:0">
  <div class="table-wrap">
    <table class="vtable">
      <thead>
        <tr><th>Comprobante</th><th>Fecha</th><th>Cliente</th><th>Mascota</th><th>Total</th><th>Método</th><th>Estado</th><th>Acciones</th></tr>
      </thead>
      <tbody>
        <?php foreach($ventas as $v):
          $se  = $v['sunat_estado'] ?? null;
          $sCl = $se==='aceptado' ? 'b-teal' : ($se==='rechazado' ? 'b-red' : ($se==='pendiente' ? 'b-amber' : 'b-gray'));
          $sLb = $se ? ucfirst($se) : '—';
        ?>
        <tr>
          <td class="td-main" style="color:var(--blue)"><?= $v['serie'] ?>-<?= str_pad($v['numero'],5,'0',STR_PAD_LEFT) ?></td>
          <td class="text-muted"><?= date('d/m/Y H:i',strtotime($v['fecha'])) ?></td>
          <td><?= clean($v['cliente']) ?></td>
          <td class="text-muted"><?= clean($v['mascota']??'—') ?></td>
          <td class="font-bold">S/. <?= number_format($v['total'],2) ?></td>
          <td><span class="badge b-gray"><?= ucfirst(str_replace('_',' ',$v['metodo_pago'])) ?></span></td>
          <td>
            <span class="badge <?= $v['estado']==='pagado'?'b-teal':($v['estado']==='anulado'?'b-red':'b-amber') ?>"><span class="dot"></span> <?= ucfirst($v['estado']) ?></span>
            <?php if(in_array($v['tipo_comprobante'], ['factura','boleta'], true)): ?>
              <span class="badge <?= $sCl ?>" style="margin-top:2px">SUNAT: <?= $sLb ?></span>
            <?php endif; ?>
          </td>
          <td>
            <div class="flex gap-1">
              <a href="?p=facturacion&action=ver&id=<?= $v['id'] ?>" class="btn btn-xs">Ver</a>
              <?php if(!empty($v['sunat_xml'])): ?>
                <a href="?p=facturacion&action=xml&id=<?= $v['id'] ?>" target="_blank" class="btn btn-xs" title="Ver XML">📄</a>
              <?php endif; ?>
              <?php if(!empty($v['sunat_xml']) && $se !== 'aceptado'): ?>
                <form method="POST" style="display:inline" onsubmit="return confirm('¿Enviar a SUNAT?');">
                  <input type="hidden" name="action" value="enviar_sunat">
                  <input type="hidden" name="id" value="<?= $v['id'] ?>">
                  <button type="submit" class="btn btn-xs btn-primary" title="Enviar a SUNAT">📤</button>
                </form>
              <?php endif; ?>
              <?php if(!empty($v['sunat_cdr'])): ?>
                <a href="?p=facturacion&action=cdr&id=<?= $v['id'] ?>" class="btn btn-xs" title="Descargar CDR">⬇</a>
              <?php endif; ?>
              <?php if($v['estado']==='pagado'): ?>
              <button type="button" class="btn btn-xs" onclick="togglePrintMenu(this, <?= $v['id'] ?>, '<?= urlencode($v['serie']) ?>', <?= (int)$v['numero'] ?>)">🖨️ ▾</button>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if(empty($ventas)): ?><tr><td colspan="8" class="text-center text-muted" style="padding:32px">No se encontraron ventas.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Menú flotante de impresión (compartido para todas las filas) -->
<div id="print-menu" style="display:none;position:fixed;background:#fff;border:1px solid #e2e5eb;border-radius:10px;box-shadow:0 12px 32px rgba(0,0,0,.18);z-index:9999;min-width:220px;padding:6px;font-family:inherit">
  <a id="pm-a4" href="#" target="_blank" class="pm-item">
    <span class="pm-ico">📄</span>
    <div><div style="font-weight:600">Formato A4</div><div class="pm-sub">Documento estándar</div></div>
  </a>
  <a id="pm-voucher" href="#" target="_blank" class="pm-item">
    <span class="pm-ico">🖨️</span>
    <div><div style="font-weight:600">Voucher 80mm</div><div class="pm-sub">Ticket impresora</div></div>
  </a>
  <a id="pm-web" href="#" target="_blank" class="pm-item">
    <span class="pm-ico">🌐</span>
    <div><div style="font-weight:600">Link web</div><div class="pm-sub">Compartir al cliente</div></div>
  </a>
</div>
<style>
  .pm-item { display:flex;align-items:center;gap:10px;padding:9px 12px;text-decoration:none;color:#1a1d23;font-size:12.5px;border-radius:7px;transition:background .12s }
  .pm-item:hover { background:#f0f2f5 }
  .pm-ico { font-size:18px;flex-shrink:0;width:24px;text-align:center }
  .pm-sub { font-size:10.5px;color:#9299a8;margin-top:1px }
</style>
<?php endif; ?>

<script>
// ─── Dropdown de impresión flotante (escapa overflow:hidden de la tabla) ───
(function(){
  const menu = document.getElementById('print-menu');
  if (!menu) return;
  let openBtn = null;

  window.togglePrintMenu = function(btn, ventaId, serie, numero) {
    if (openBtn === btn) { hidePrintMenu(); return; }
    openBtn = btn;

    const base = '<?= BASE_URL ?>';
    document.getElementById('pm-a4').href      = base + '/print/comprobante.php?id=' + ventaId + '&fmt=a4';
    document.getElementById('pm-voucher').href = base + '/print/comprobante.php?id=' + ventaId + '&fmt=voucher';
    document.getElementById('pm-web').href     = base + '/print/ver.php?serie=' + serie + '&num=' + numero;

    // Posicionar bajo el botón, alineado a la derecha
    menu.style.display = 'block';
    const r = btn.getBoundingClientRect();
    const mw = menu.offsetWidth;
    let left = r.right - mw;
    if (left < 8) left = 8;
    if (left + mw > window.innerWidth - 8) left = window.innerWidth - mw - 8;
    let top = r.bottom + 4;
    if (top + menu.offsetHeight > window.innerHeight - 8) top = r.top - menu.offsetHeight - 4;
    menu.style.left = left + 'px';
    menu.style.top  = top  + 'px';
  };

  function hidePrintMenu() { menu.style.display = 'none'; openBtn = null; }
  document.addEventListener('click', e => {
    if (openBtn && !openBtn.contains(e.target) && !menu.contains(e.target)) hidePrintMenu();
  });
  document.addEventListener('keydown', e => { if (e.key === 'Escape') hidePrintMenu(); });
  window.addEventListener('resize', hidePrintMenu);
  window.addEventListener('scroll', hidePrintMenu, true);
})();
</script>

<script>
var SERVICIOS = <?= json_encode(array_values(array_map(fn($s)=>['id'=>(int)$s['id'],'nombre'=>$s['nombre'],'precio'=>(float)$s['precio']], $servicios_sel))) ?>;
var PRODUCTOS = <?= json_encode(array_values(array_map(fn($p)=>['id'=>(int)$p['id'],'nombre'=>$p['nombre'],'precio'=>(float)$p['precio'],'codigo'=>$p['codigo']??''], $productos_sel))) ?>;
var PETSHOP   = <?= json_encode(array_values(array_map(fn($p)=>['id'=>(int)$p['id'],'nombre'=>$p['nombre'],'precio'=>(float)$p['precio'],'codigo'=>$p['codigo']??''], $petshop_sel))) ?>;
var CLIENTES  = <?= json_encode(array_values($_cli_js ?? [])) ?>;
var MASCOTAS  = <?= json_encode(array_values($_mas_js ?? [])) ?>;
var SERIES    = <?= json_encode($_series_sede_actual) ?>;
var itemIdx   = 0;
var _cliSel   = null;

// ── SERIE Y NÚMERO ──
function actualizarSerieNum() {
    var sel = document.getElementById('sel-tipo');
    if (!sel) return;  // No estamos en el form de "Nueva venta"
    var d = (typeof SERIES !== 'undefined') ? SERIES[sel.value] : null;
    if (!d) return;
    var serieEl  = document.getElementById('disp-serie');
    var numeroEl = document.getElementById('disp-numero');
    if (serieEl)  serieEl.value  = d.serie;
    if (numeroEl) numeroEl.value = d.numero;
}

// ── ACTUALIZAR PLACEHOLDER SEGÚN TIPO DE DOCUMENTO ──
function actualizarPlaceHolderDoc() {
    var tipo = document.getElementById('sel-tipodoc').value;
    var placeholder = '🔍 Ingresa el número de documento...';
    if (tipo === 'dni') placeholder = '🔍 Ingresa el DNI (8 dígitos)...';
    else if (tipo === 'ruc') placeholder = '🔍 Ingresa el RUC (11 dígitos)...';
    else if (tipo === 'ce') placeholder = '🔍 Ingresa el Carné de Extranjería (9+ dígitos)...';
    else if (tipo === 'pasaporte') placeholder = '🔍 Ingresa el número de Pasaporte...';
    document.getElementById('cli-busq').placeholder = placeholder;
}

// ── BOTÓN CONSULTAR (lupa) → buscar en RENIEC/SUNAT ──
async function btnBuscarCliente() {
    var q = (document.getElementById('cli-busq').value || '').trim();
    if (!q) { alert('Ingresa el número de documento.'); return; }

    var tipo = document.getElementById('sel-tipodoc').value;
    var isDni = tipo === 'dni' && /^\d{8}$/.test(q);
    var isRuc = tipo === 'ruc' && /^\d{11}$/.test(q);
    var isCe  = tipo === 'ce'  && q.length >= 9;
    var isPas = tipo === 'pasaporte' && q.length >= 5;

    if (!isDni && !isRuc && !isCe && !isPas) {
        var msg = tipo === 'dni'   ? 'El DNI debe tener 8 dígitos.'
                : tipo === 'ruc'   ? 'El RUC debe tener 11 dígitos.'
                : tipo === 'ce'    ? 'El Carné de Extranjería debe tener al menos 9 dígitos.'
                : 'El Pasaporte debe tener al menos 5 caracteres.';
        alert(msg); return;
    }

    // CE y Pasaporte no tienen consulta automática → pre-llenar modal con el número
    if (tipo === 'ce' || tipo === 'pasaporte') {
        abrirModalNuevoCliente('', '', '', '', tipo, q);
        return;
    }

    var btn = document.getElementById('btnCliSearch');
    btn.disabled = true; btn.textContent = '⏳';

    try {
        var r = await fetch('<?= BASE_URL ?>/api/consulta_documento.php?tipo=' + tipo + '&numero=' + q);
        var j = await r.json();

        if (!j.ok) {
            alert(j.error || 'No se encontró ese documento');
            btn.disabled = false; btn.textContent = '🔍';
            return;
        }

        // Encontrado → abrir modal pre-llenado
        abrirModalNuevoCliente(j.nombre || '', isDni ? q : '', isRuc ? q : '', j.direccion || '');

    } catch(e) {
        alert('Error de red: ' + e.message);
    } finally {
        btn.disabled = false; btn.textContent = '🔍';
    }
}

// ── MODAL NUEVO CLIENTE DESDE API (vanilla JS) ──
function abrirModalNuevoCliente(nombre, dni, ruc, direccion, tipo, numDoc) {
    var nomField = document.getElementById('new-nombre');
    nomField.value = nombre || '';
    // CE y Pasaporte no tienen consulta → placeholder es genérico
    if (tipo === 'ce' || tipo === 'pasaporte') {
        nomField.placeholder = 'Ingresa el nombre completo del cliente';
        nomField.focus();
    } else {
        nomField.placeholder = 'Nombre obtenido de la consulta';
    }
    document.getElementById('new-dni').value    = dni    || '';
    document.getElementById('new-ruc').value    = ruc    || '';
    document.getElementById('new-ce').value    = '';
    document.getElementById('new-pasaporte').value = '';
    document.getElementById('new-telefono').value = '';
    document.getElementById('new-email').value    = '';
    document.getElementById('new-direccion').value = direccion || '';
    if (tipo === 'ce') {
        document.getElementById('new-ce').value = numDoc || '';
    } else if (tipo === 'pasaporte') {
        document.getElementById('new-pasaporte').value = numDoc || '';
    }
    var modal = document.getElementById('modalNuevoCliente');
    modal.style.display = 'flex';
    document.body.insertAdjacentHTML('beforeend', '<div id="modalBackdrop" style="position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:998"></div>');
}

function cerrarModalNuevoCliente() {
    document.getElementById('modalNuevoCliente').style.display = 'none';
    var b = document.getElementById('modalBackdrop');
    if (b) b.remove();
}

function abrirModalMetodoPago() {
    document.getElementById('modalMetodoPago').style.display = 'flex';
}
function cerrarModalMetodoPago() {
    document.getElementById('modalMetodoPago').style.display = 'none';
}

async function agregarMetodoPago() {
    var nombre = document.getElementById('new-nombre-metodo').value.trim();
    if (!nombre) { alert('Escribe el nombre del método'); return; }
    try {
        var r = await fetch('?p=facturacion&action=nueva', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=add_metodo_pago&nombre=' + encodeURIComponent(nombre)
        });
        var j = await r.json();
        if (!j.ok) { alert('Error al guardar'); return; }
        var n = document.querySelectorAll('#lista-metodos-pago > div').length + 1;
        var html = '<div style="display:flex;align-items:center;justify-content:space-between;padding:8px 12px;background:var(--bg3);border-radius:8px;margin-bottom:6px" id="mp-row-' + n + '">'
                 + '<span style="font-weight:600">' + nombre + '</span>'
                 + '<button type="button" onclick="eliminarMetodoPago(' + n + ')" style="background:none;border:none;color:var(--red);cursor:pointer;font-size:14px" title="Eliminar">✕</button>'
                 + '</div>';
        document.getElementById('lista-metodos-pago').insertAdjacentHTML('beforeend', html);
        var opt = document.createElement('option');
        opt.value = nombre.toLowerCase().replace(/\s+/g,'_');
        opt.textContent = nombre;
        document.getElementById('sel-metodo-pago').appendChild(opt);
        document.getElementById('new-nombre-metodo').value = '';
        setTimeout(function(){ location.reload(); }, 500);
    } catch(e) { alert('Error: ' + e.message); }
}

async function eliminarMetodoPago(id) {
    if (!confirm('¿Eliminar este método de pago?')) return;
    try {
        var r = await fetch('?p=facturacion&action=nueva', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=del_metodo_pago&id=' + id
        });
        var el = document.getElementById('mp-row-' + id);
        if (el) el.remove();
        setTimeout(function(){ location.reload(); }, 300);
    } catch(e) { alert('Error: ' + e.message); }
}

async function guardarNuevoCliente() {
    var nombre    = document.getElementById('new-nombre').value.trim();
    var dni       = document.getElementById('new-dni').value.trim();
    var ruc       = document.getElementById('new-ruc').value.trim();
    var ce        = document.getElementById('new-ce').value.trim();
    var pasaporte = document.getElementById('new-pasaporte').value.trim();
    var telefono  = document.getElementById('new-telefono').value.trim();
    var email     = document.getElementById('new-email').value.trim();
    var direccion = document.getElementById('new-direccion').value.trim();

    if (!nombre) { alert('El nombre es obligatorio'); return; }

    var btn = document.getElementById('btnNewCliSave');
    btn.disabled = true; btn.textContent = 'Creando...';

    try {
        var r = await fetch('<?= BASE_URL ?>/api/cliente_crear.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ nombre, dni, ruc, ce, pasaporte, telefono, email, direccion })
        });
        var j = await r.json();
        if (!j.ok) { alert(j.error || 'No se pudo crear'); return; }

        var nuevo = { id: j.id, nombre: j.nombre, dni: j.dni || '', ruc: j.ruc || '', ce: j.ce || '', pasaporte: j.pasaporte || '' };
        CLIENTES.push(nuevo);
        seleccionarCliente(nuevo);
        cerrarModalNuevoCliente();

    } catch(e) {
        alert('Error: ' + e.message);
    } finally {
        btn.disabled = false; btn.textContent = '💾 Guardar cliente';
    }
}
function buscarCliente(val) {
    var drop = document.getElementById('cli-drop');
    var q = (val || '').trim();
    // Si parece un documento parcial (7+ dígitos o 10+ dígitos) ofrecer consulta remota
    var pareceDni = /^\d{7,8}$/.test(q);
    var pareceRuc = /^\d{10,11}$/.test(q);
    var isDni = /^\d{8}$/.test(q);
    var isRuc = /^\d{11}$/.test(q);
    var matches = q
      ? CLIENTES.filter(function(c) {
          return c.nombre.toLowerCase().indexOf(q)>=0 || (c.dni&&c.dni.indexOf(q)>=0) || (c.ruc&&c.ruc.indexOf(q)>=0);
        }).slice(0,8)
      : CLIENTES.slice(0,8);   // focus sin texto → muestra los primeros 8

    var html = '';

    // Si no hay matches locales y parece un documento (7+ dígitos), ofrecer opción remota
    if (matches.length === 0 && (pareceDni || pareceRuc)) {
        var tipo = pareceDni ? 'dni' : 'ruc';
        html += '<div class="cli-opt" style="padding:10px 14px;cursor:pointer;background:var(--teal-l);border-bottom:1px solid var(--border)"'
             +  ' onmouseover="this.style.background=\'rgba(30,168,161,.15)\'" onmouseout="this.style.background=\'var(--teal-l)\'"'
             +  ' onclick="consultarDocRemoto(\'' + tipo + '\',\'' + q + '\')">'
             +  '<div style="font-size:13px;font-weight:600;color:var(--teal-d)">🔍 No existe — Consultar en ' + (pareceDni ? 'RENIEC' : 'SUNAT') + '</div>'
             +  '<div style="font-size:11px;color:var(--text3)">' + (pareceDni ? 'DNI: ' : 'RUC: ') + q + ' — clic para buscar y crear</div>'
             +  '</div>'
             +  '<div id="cli-remote-result" style="display:none;padding:12px 14px;border-bottom:1px solid var(--border);background:var(--bg2)"></div>';
    } else if (!matches.length) {
        // Búsqueda por nombre sin resultados → no mostrar nada (el campo vacío = CLIENTES VARIOS)
        drop.innerHTML = '';
        drop.style.display = 'none';
        return;
    }

    matches.forEach(function(c) {
        html += '<div class="cli-opt" style="padding:10px 14px;cursor:pointer;border-bottom:1px solid var(--border)"'
             + ' onmouseover="this.style.background=\'var(--bg3)\'" onmouseout="this.style.background=\'\'">'
             + '<div style="font-size:13px;font-weight:600">' + c.nombre + '</div>'
             + (c.dni ? '<div style="font-size:11px;color:var(--text3)">DNI: ' + c.dni + '</div>' : '')
             + (c.ruc ? '<div style="font-size:11px;color:var(--text3)">RUC: ' + c.ruc + '</div>' : '')
             + '</div>';
    });
    drop.innerHTML = html;
    drop.style.display = 'block';
    drop.querySelectorAll('.cli-opt').forEach(function(el, i) {
        if (matches[i]) {
            el.addEventListener('mousedown', function(e) {
                e.preventDefault();
                seleccionarCliente(matches[i]);
            });
        }
    });
}

// ── CONSULTA DNI/RUC A API EXTERNA Y CREA CLIENTE ──
async function consultarDocRemoto(tipo, numero) {
    var drop = document.getElementById('cli-drop');
    var resultDiv = document.getElementById('cli-remote-result') || document.getElementById('cli-remote-result');
    if (!resultDiv) return;

    resultDiv.style.display = 'block';
    resultDiv.innerHTML = '<div style="font-size:12px;color:var(--text3)">Consultando ' + (tipo === 'dni' ? 'RENIEC' : 'SUNAT') + '...</div>';

    try {
        var r = await fetch('<?= BASE_URL ?>/api/consulta_documento.php?tipo=' + tipo + '&numero=' + numero);
        var j = await r.json();

        if (!j.ok) {
            resultDiv.innerHTML = '<div style="font-size:12px;color:var(--red)">❌ ' + (j.error || 'No se encontró') + '</div>';
            return;
        }

        var nombreRemoto = j.nombre || (tipo === 'dni' ? 'Cliente DNI ' + numero : 'Empresa RUC ' + numero);
        var dirRemoto = j.direccion || '';
        resultDiv.innerHTML =
            '<div style="font-size:12px">' +
            '<div style="font-weight:600;color:var(--success)">✅ Encontrado: ' + nombreRemoto + '</div>' +
            (dirRemoto ? '<div style="color:var(--text3)">' + dirRemoto + '</div>' : '') +
            '<button type="button" class="btn btn-sm btn-primary" style="margin-top:6px" onclick="crearClienteRemoto(\'' + tipo + '\',\'' + numero + '\',\'' + nombreRemoto.replace(/'/g,"\\'") + '\',\'' + dirRemoto.replace(/'/g,"\\'") + '\')">Crear cliente con estos datos</button>' +
            '</div>';
    } catch(e) {
        resultDiv.innerHTML = '<div style="font-size:12px;color:var(--red)">Error de red: ' + e.message + '</div>';
    }
}

async function crearClienteRemoto(tipo, numero, nombre, direccion) {
    var btn = event.target;
    btn.disabled = true; btn.textContent = 'Creando...';

    try {
        var payload = { nombre: nombre };
        if (tipo === 'dni') payload.dni = numero;
        else                payload.ruc  = numero;
        if (direccion) payload.direccion = direccion;

        var r = await fetch('<?= BASE_URL ?>/api/cliente_crear.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        var j = await r.json();

        if (!j.ok) {
            alert(j.error || 'No se pudo crear el cliente.');
            btn.disabled = false; btn.textContent = 'Crear cliente con estos datos';
            return;
        }

        // Agregar el nuevo cliente a la lista local y seleccionarlo
        var nuevoCliente = { id: j.id, nombre: j.nombre, dni: j.dni || '', ruc: j.ruc || '' };
        CLIENTES.push(nuevoCliente);
        seleccionarCliente(nuevoCliente);

        // Cerrar dropdown
        document.getElementById('cli-drop').style.display = 'none';

    } catch(e) {
        alert('Error de red: ' + e.message);
        btn.disabled = false; btn.textContent = 'Crear cliente con estos datos';
    }
}

function seleccionarCliente(c) {
    _cliSel = c;
    document.getElementById('cli-id').value   = c.id;
    document.getElementById('cli-busq').value = c.nombre;
    document.getElementById('cli-drop').style.display = 'none';
    document.getElementById('cli-sel').style.display  = 'flex';
    var label = c.nombre;
    if (c.dni) label += ' · DNI ' + c.dni;
    else if (c.ruc) label += ' · RUC ' + c.ruc;
    else if (c.ce) label += ' · CE ' + c.ce;
    else if (c.pasaporte) label += ' · Pas. ' + c.pasaporte;
    document.getElementById('cli-sel-nom').textContent = label;
    document.getElementById('cli-busq').style.display = 'none';

    // Habilitar campo mascota y precargar mascotas del cliente
    var masBusq = document.getElementById('mas-busq');
    masBusq.value = '';
    masBusq.disabled = false;
    var mascotasCli = MASCOTAS.filter(function(m){ return m.cliente_id == c.id; });
    if (mascotasCli.length === 1) {
        // Auto-selección si solo tiene una mascota
        seleccionarMascota(mascotasCli[0]);
    } else if (mascotasCli.length > 1) {
        masBusq.placeholder = '🐾 ' + mascotasCli.length + ' mascota(s) — clic para ver';
        document.getElementById('mas-id').value = '';
        limpiarMascota();
    } else {
        masBusq.placeholder = '🐾 Este cliente no tiene mascotas';
        document.getElementById('mas-id').value = '';
        limpiarMascota();
    }
}

function limpiarCliente() {
    _cliSel = null;
    document.getElementById('cli-id').value   = '';
    document.getElementById('cli-busq').value = '';
    document.getElementById('cli-busq').style.display = '';
    document.getElementById('cli-sel').style.display  = 'none';
    // Deshabilitar mascota de nuevo
    var masBusq = document.getElementById('mas-busq');
    masBusq.disabled = true;
    masBusq.placeholder = '🐾 Selecciona primero un cliente';
    limpiarMascota();
}

// ── BUSCADOR MASCOTAS ──
function buscarMascota(val) {
    var drop = document.getElementById('mas-drop');
    var cliId = document.getElementById('cli-id').value;
    if (!cliId) { drop.style.display='none'; return; }
    var q = (val || '').toLowerCase();
    var matches = MASCOTAS.filter(function(m) {
        var matchNom = !q || m.label.toLowerCase().indexOf(q) >= 0;
        var matchCli = m.cliente_id == cliId;
        return matchNom && matchCli;
    }).slice(0,8);
    if (!matches.length) { drop.style.display='none'; return; }
    var html = '';
    matches.forEach(function(m) {
        html += '<div class="mas-opt" style="padding:10px 14px;cursor:pointer;border-bottom:1px solid var(--border)"'
             + ' onmouseover="this.style.background=\'var(--bg3)\'" onmouseout="this.style.background=\'\'">'
             + '<div style="font-size:13px;font-weight:600">🐾 ' + m.label + '</div></div>';
    });
    drop.innerHTML = html;
    drop.style.display = 'block';
    drop.querySelectorAll('.mas-opt').forEach(function(el, i) {
        el.addEventListener('mousedown', function(e) {
            e.preventDefault();
            seleccionarMascota(matches[i]);
        });
    });
}

function seleccionarMascota(m) {
    document.getElementById('mas-id').value   = m.id;
    document.getElementById('mas-busq').value = m.label;
    document.getElementById('mas-drop').style.display = 'none';
    document.getElementById('mas-sel').style.display  = 'flex';
    document.getElementById('mas-sel-nom').textContent = m.label;
    document.getElementById('mas-busq').style.display = 'none';
}

function limpiarMascota() {
    document.getElementById('mas-id').value   = '';
    document.getElementById('mas-busq').value = '';
    document.getElementById('mas-busq').style.display = '';
    document.getElementById('mas-sel').style.display  = 'none';
}

// Inicializar
document.addEventListener('DOMContentLoaded', function() {
    actualizarSerieNum();
});

function showHeader() {
    var has = document.querySelector('#items-list tr');
    document.getElementById('items-thead').style.display = has ? '' : 'none';
    document.getElementById('items-empty').style.display = has ? 'none' : 'block';
}

function addItem(tipo) {
  var list, labelTipo, tipoInterno;
  if (tipo === 'servicio')  { list = SERVICIOS; labelTipo = 'servicio'; tipoInterno = 'servicio'; }
  else if (tipo === 'petshop') { list = PETSHOP; labelTipo = 'Pet Shop'; tipoInterno = 'petshop'; }
  else                      { list = PRODUCTOS; labelTipo = 'producto'; tipoInterno = 'producto'; }

  var opts = list.map(x =>
    `<option value="${x.id}" data-precio="${x.precio}" data-nombre="${x.nombre.replace(/"/g,'&quot;')}">${x.nombre} — S/. ${x.precio.toFixed(2)}</option>`
  ).join('');

  var idx  = itemIdx++;
  var row  = document.createElement('tr');
  row.className = 'item-row';

  // Color del label según tipo
  var tagStyle = tipo === 'petshop'
    ? 'background:#ede9fe;color:#6d28d9;border:1px solid #c4b5fd'
    : tipo === 'producto'
      ? 'background:#dbeafe;color:#1e3a8a;border:1px solid #93c5fd'
      : 'background:#d1fae5;color:#065f46;border:1px solid #6ee7b7';

  row.innerHTML = `
    <td style="padding:6px 4px">
      <input type="hidden" name="item_tipo[]" value="${tipoInterno}">
      <input type="hidden" name="item_ref[]"  id="ref_${idx}" value="">
      <div style="display:flex;align-items:center;gap:6px;margin-bottom:4px">
        <span style="font-size:10px;font-weight:700;padding:2px 7px;border-radius:999px;${tagStyle};white-space:nowrap">${tipo === 'petshop' ? '🛒' : tipo === 'producto' ? '📦' : '🏥'} ${labelTipo.charAt(0).toUpperCase()+labelTipo.slice(1)}</span>
        <div style="position:relative;flex:1">
          <input class="form-input ac-input" id="ac_${idx}" autocomplete="off" placeholder="Escribe para buscar ${labelTipo}…" style="font-size:12px;width:100%"
                 oninput="acFilter(${idx},'${tipoInterno}')" onfocus="acFilter(${idx},'${tipoInterno}')" onblur="acBlur(${idx})" onkeydown="acKey(event,${idx},'${tipoInterno}')">
          <div class="ac-drop" id="acd_${idx}" style="display:none;position:absolute;z-index:60;left:0;right:0;top:calc(100% + 2px);max-height:230px;overflow:auto;background:var(--bg2);border:1px solid var(--border);border-radius:8px;box-shadow:var(--shadow-lg,0 10px 30px rgba(0,0,0,.12))"></div>
        </div>
      </div>
      <input class="form-input" name="item_desc[]" id="desc_${idx}" value="" placeholder="Descripción" style="font-size:12px">
    </td>
    <td style="padding:6px 4px;text-align:center">
      <input class="form-input" type="number" name="item_qty[]" id="qty_${idx}" value="1" min="1" style="text-align:center;font-size:12px;width:60px" oninput="calcTotal()">
    </td>
    <td style="padding:6px 4px;text-align:right">
      <input class="form-input" type="number" step="0.01" name="item_precio[]" id="precio_${idx}" value="" placeholder="0.00" style="text-align:right;font-size:12px;width:100px" oninput="calcSubtotal(${idx});calcTotal()">
    </td>
    <td style="padding:6px 4px;text-align:right;font-weight:600;font-size:13px;color:var(--teal-d)" id="sub_${idx}">S/. 0.00</td>
    <td style="padding:6px 4px;text-align:center">
      <button type="button" onclick="removeItem(this)" class="btn btn-xs" style="color:var(--red);padding:3px 7px">✕</button>
    </td>
  `;
  document.getElementById('items-list').appendChild(row);
  showHeader();
  var _ac = row.querySelector('.ac-input'); if (_ac) _ac.focus();
}

// ═══════════════════════════════════════════════════════════════
// ESCANEO DE CÓDIGO DE BARRAS
// Busca el código en PRODUCTOS y PETSHOP. Si lo encuentra, agrega
// una línea ya rellena con descripción y precio. Si la línea ya
// existe (mismo código escaneado de nuevo), suma 1 a la cantidad.
// Si no lo encuentra, muestra aviso al usuario.
// ═══════════════════════════════════════════════════════════════
function procesarEscaneo(codigoRaw) {
  var codigo = (codigoRaw||'').trim();
  var fb = document.getElementById('scan-feedback');
  if (!codigo) { fb.textContent=''; return; }

  // Buscar en farmacia y petshop
  var prod = PRODUCTOS.find(function(p){ return p.codigo && p.codigo === codigo; });
  var tipo = 'producto';
  if (!prod) {
    prod = PETSHOP.find(function(p){ return p.codigo && p.codigo === codigo; });
    tipo = 'petshop';
  }

  if (!prod) {
    fb.innerHTML = '<span style="color:#ef4444">❌ Producto con código <b>'+codigo.replace(/</g,'&lt;')+'</b> no encontrado. Verifica que esté registrado y con stock.</span>';
    return;
  }

  // ¿Ya hay una fila con este producto? → sumar +1 a la cantidad
  var rows = document.querySelectorAll('#items-list tr.item-row');
  var encontrada = null;
  rows.forEach(function(row){
    var ref = row.querySelector('input[name="item_ref[]"]');
    var tipoH = row.querySelector('input[name="item_tipo[]"]');
    if (ref && tipoH && (+ref.value) === prod.id && tipoH.value === tipo) encontrada = row;
  });
  if (encontrada) {
    var qty = encontrada.querySelector('input[name="item_qty[]"]');
    qty.value = (parseInt(qty.value||'1',10) + 1);
    qty.dispatchEvent(new Event('input', {bubbles:true}));
    fb.innerHTML = '<span style="color:#10b981">✓ +1 a <b>'+prod.nombre.replace(/</g,'&lt;')+'</b> (cantidad: '+qty.value+')</span>';
  } else {
    // Agregar nueva línea ya rellenada
    addItem(tipo);
    // Tomar la última fila recién agregada y rellenarla
    var ultima = document.querySelector('#items-list tr.item-row:last-child');
    if (ultima) {
      var refI = ultima.querySelector('input[name="item_ref[]"]');
      var _ix  = refI ? parseInt((refI.id||'ref_0').split('_')[1],10) : NaN;
      if (!isNaN(_ix)) acApply(_ix, tipo, prod.id);
    }
    fb.innerHTML = '<span style="color:#10b981">✓ Agregado: <b>'+prod.nombre.replace(/</g,'&lt;')+'</b> — S/. '+prod.precio.toFixed(2)+'</span>';
  }

  // Mantener el foco en el campo de escaneo (para escanear el siguiente)
  setTimeout(function(){ document.getElementById('scan-input').focus(); }, 100);
}

function addItemManual() {
  var idx = itemIdx++;
  var row = document.createElement('tr');
  row.className = 'item-row';
  row.innerHTML = `
    <td style="padding:6px 4px">
      <input type="hidden" name="item_tipo[]" value="servicio">
      <input type="hidden" name="item_ref[]" value="0">
      <input class="form-input" name="item_desc[]" id="desc_${idx}" placeholder="Descripción del servicio/producto" style="font-size:12px" required>
    </td>
    <td style="padding:6px 4px;text-align:center">
      <input class="form-input" type="number" name="item_qty[]" id="qty_${idx}" value="1" min="1" style="text-align:center;font-size:12px;width:60px" oninput="calcTotal()">
    </td>
    <td style="padding:6px 4px;text-align:right">
      <input class="form-input" type="number" step="0.01" name="item_precio[]" id="precio_${idx}" value="" placeholder="0.00" style="text-align:right;font-size:12px;width:100px" oninput="calcSubtotal(${idx});calcTotal()">
    </td>
    <td style="padding:6px 4px;text-align:right;font-weight:600;font-size:13px;color:var(--teal-d)" id="sub_${idx}">S/. 0.00</td>
    <td style="padding:6px 4px;text-align:center">
      <button type="button" onclick="removeItem(this)" class="btn btn-xs" style="color:var(--red);padding:3px 7px">✕</button>
    </td>
  `;
  document.getElementById('items-list').appendChild(row);
  showHeader();
  document.getElementById('desc_'+idx).focus();
}

function fillItem(sel, idx) {
  var opt = sel.options[sel.selectedIndex];
  if (!opt.value) return;
  var precio  = parseFloat(opt.getAttribute('data-precio') || 0);
  var nombre  = opt.getAttribute('data-nombre') || opt.text.split(' — ')[0];
  var refEl   = document.getElementById('ref_'+idx);
  var descEl  = document.getElementById('desc_'+idx);
  var precioEl= document.getElementById('precio_'+idx);
  if (refEl)    refEl.value    = opt.value;
  if (descEl)   descEl.value   = nombre;
  if (precioEl){ precioEl.value = precio.toFixed(2); calcSubtotal(idx); }
  calcTotal();
}

// ═══════════════════════════════════════════════════════════════
// AUTOCOMPLETAR de ítems (servicio / producto / pet shop)
// Filtra por nombre y código mientras se escribe.
// ═══════════════════════════════════════════════════════════════
(function(){ var s=document.createElement('style');
  s.textContent='.ac-item:hover,.ac-item.active{background:var(--bg3,#f1f5f9)}';
  document.head.appendChild(s);
})();

function acData(tipo){ return tipo==='servicio'?SERVICIOS:(tipo==='petshop'?PETSHOP:PRODUCTOS); }
function _acEsc(s){ return (s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/"/g,'&quot;'); }

function acFilter(idx, tipo){
  var inp=document.getElementById('ac_'+idx), drop=document.getElementById('acd_'+idx);
  if(!inp||!drop) return;
  var qv=(inp.value||'').trim().toLowerCase();
  var list=acData(tipo), res=[];
  for(var i=0;i<list.length && res.length<25;i++){
    var x=list[i], n=(x.nombre||'').toLowerCase(), c=(x.codigo||'').toLowerCase();
    if(qv==='' || n.indexOf(qv)>=0 || (c && c.indexOf(qv)>=0)) res.push(x);
  }
  if(res.length===0){
    drop.innerHTML='<div style="padding:9px 10px;font-size:12px;color:var(--text3)">Sin resultados</div>';
    drop.removeAttribute('data-ids'); drop.style.display=''; return;
  }
  drop.innerHTML=res.map(function(x,i){
    return '<div class="ac-item'+(i===0?' active':'')+'" onmousedown="acPick('+idx+',\''+tipo+'\','+x.id+')" '
      +'style="padding:7px 10px;cursor:pointer;border-bottom:1px solid var(--border)">'
      +'<div style="font-weight:600;font-size:12px">'+_acEsc(x.nombre)+'</div>'
      +'<div style="font-size:11px;color:var(--text3)">S/ '+(x.precio||0).toFixed(2)+(x.codigo?' · '+_acEsc(x.codigo):'')+'</div></div>';
  }).join('');
  drop.setAttribute('data-ids', res.map(function(x){return x.id;}).join(','));
  drop.setAttribute('data-active','0');
  drop.style.display='';
}

function acApply(idx, tipo, id){
  var list=acData(tipo), it=null;
  for(var i=0;i<list.length;i++){ if(list[i].id===id){ it=list[i]; break; } }
  if(!it) return;
  var inp=document.getElementById('ac_'+idx), refEl=document.getElementById('ref_'+idx),
      descEl=document.getElementById('desc_'+idx), precioEl=document.getElementById('precio_'+idx);
  if(inp) inp.value=it.nombre;
  if(refEl) refEl.value=it.id;
  if(descEl) descEl.value=it.nombre;
  if(precioEl){ precioEl.value=(it.precio||0).toFixed(2); calcSubtotal(idx); }
  calcTotal();
}

function acPick(idx, tipo, id){
  acApply(idx, tipo, id);
  var drop=document.getElementById('acd_'+idx); if(drop) drop.style.display='none';
}

function acBlur(idx){
  var drop=document.getElementById('acd_'+idx);
  setTimeout(function(){ if(drop) drop.style.display='none'; }, 150);
}

function acKey(e, idx, tipo){
  var drop=document.getElementById('acd_'+idx);
  if(!drop || drop.style.display==='none'){ if(e.key==='ArrowDown') acFilter(idx,tipo); return; }
  var items=drop.querySelectorAll('.ac-item'); if(!items.length) return;
  var act=parseInt(drop.getAttribute('data-active')||'0',10);
  if(e.key==='ArrowDown'){ e.preventDefault(); act=Math.min(items.length-1,act+1); }
  else if(e.key==='ArrowUp'){ e.preventDefault(); act=Math.max(0,act-1); }
  else if(e.key==='Enter'){ e.preventDefault(); var ids=(drop.getAttribute('data-ids')||'').split(','); if(ids[act]!==undefined&&ids[act]!=='') acPick(idx,tipo,parseInt(ids[act],10)); return; }
  else if(e.key==='Escape'){ drop.style.display='none'; return; }
  else return;
  items.forEach(function(el,i){ el.classList.toggle('active', i===act); });
  drop.setAttribute('data-active', act);
  if(items[act]) items[act].scrollIntoView({block:'nearest'});
}

function calcSubtotal(idx) {
  var qty   = parseFloat(document.getElementById('qty_'+idx)?.value    || 1);
  var price = parseFloat(document.getElementById('precio_'+idx)?.value || 0);
  var sub   = qty * price;
  var el    = document.getElementById('sub_'+idx);
  if (el) el.textContent = 'S/. ' + sub.toFixed(2);
}

function calcTotal() {
  var sumaBruta = 0;
  document.querySelectorAll('.item-row').forEach(row => {
    var qty   = parseFloat(row.querySelector('[name="item_qty[]"]')?.value  || 1);
    var price = parseFloat(row.querySelector('[name="item_precio[]"]')?.value || 0);
    sumaBruta += qty * price;
  });
  var desc  = parseFloat(document.getElementById('inp-desc')?.value || 0);
  var total = Math.max(0, sumaBruta - desc);

  var aplicaIgv = document.querySelector('input[name="aplica_igv"]:checked')?.value === '1';
  var base, igv;
  if (aplicaIgv) { base = total / 1.18; igv = total - base; }
  else           { base = total;        igv = 0; }

  var lblSub = document.getElementById('lbl-tot-sub');
  if (lblSub) lblSub.textContent = aplicaIgv ? 'Op. Gravadas:' : 'Op. Inafectas:';
  var rowIgv = document.getElementById('row-tot-igv');
  if (rowIgv) rowIgv.style.display = aplicaIgv ? '' : 'none';

  document.getElementById('tot-sub').textContent   = 'S/. ' + base.toFixed(2);
  document.getElementById('tot-desc').textContent  = '-S/. ' + desc.toFixed(2);
  document.getElementById('tot-igv').textContent   = 'S/. ' + igv.toFixed(2);
  document.getElementById('tot-total').textContent = 'S/. ' + total.toFixed(2);

  updatePagoBtnState(total);
}

function updatePagoBtnState(totalVenta) {
  var sumPagos = pagosUI.reduce(function(s, p) { return s + p.monto; }, 0);
  var diff = totalVenta - sumPagos;
  var btnAdd = document.querySelector('button[onclick="agregarFilaPago()"]');
  if (!btnAdd) return;
  btnAdd.disabled = diff <= 0.01;
  btnAdd.style.opacity = diff <= 0.01 ? '0.4' : '1';
  btnAdd.style.cursor = diff <= 0.01 ? 'not-allowed' : 'pointer';
  var msg = totalVenta > 0 ? 'Total venta: S/. ' + totalVenta.toFixed(2) : '';
  if (pagosUI.length > 0) {
    msg += ' · Pagado: S/. ' + sumPagos.toFixed(2);
    if (diff > 0.01) msg += ' <span style="color:var(--red)">· Faltan: S/. ' + diff.toFixed(2) + '</span>';
    else if (diff < -0.01) msg += ' <span style="color:var(--orange)">· Exceso: S/. ' + Math.abs(diff).toFixed(2) + '</span>';
    else msg += ' <span style="color:var(--teal-d)">✓ Cuadrado</span>';
  }
  var msgEl = document.getElementById('pago-total-msg');
  if (msgEl) msgEl.innerHTML = msg;
}

function toggleIgv() {
  var aplicaIgv = document.querySelector('input[name="aplica_igv"]:checked')?.value === '1';
  var info = document.getElementById('igv-info');
  if (info) {
    info.innerHTML = aplicaIgv
      ? 'ℹ Los precios <strong>incluyen IGV (18%)</strong>. Se desglosa automáticamente en el comprobante.'
      : '🧾 Los precios <strong>NO incluyen IGV</strong>. Se emite como comprobante exonerado/inafecto.';
  }
  calcTotal();
}

function removeItem(btn) {
  btn.closest('tr').remove();
  calcTotal();
  showHeader();
}

function filterMascotas(cliId) {
  var sel = document.getElementById('sel-mas');
  if (!sel) return;
  Array.from(sel.options).forEach(opt => {
    if (!opt.value) return;
    opt.hidden = opt.getAttribute('data-cli') != cliId;
  });
  sel.value = '';
}

// Validar antes de enviar
document.getElementById('venta-form')?.addEventListener('submit', function(e) {
  var rows = document.querySelectorAll('.item-row');
  if (rows.length === 0) {
    e.preventDefault();
    alert('Debes agregar al menos un servicio o producto.');
    return;
  }
  var valid = false;
  rows.forEach(row => {
    var p = parseFloat(row.querySelector('[name="item_precio[]"]')?.value || 0);
    if (p > 0) valid = true;
  });
if (pagosUI.length === 0) {
        alert('Agrega al menos un método de pago.');
        return;
    }
    var sumPagos = pagosUI.reduce(function(s, p) { return s + p.monto; }, 0);
    var _txt = document.getElementById('tot-total').textContent;
    var totalVenta = parseFloat((_txt.match(/\d[\d.,-]*/) || ['0'])[0].replace(',', '.')) || 0;
    if (Math.abs(sumPagos - totalVenta) > 0.02) {
        alert('La suma de los pagos (S/. ' + sumPagos.toFixed(2) + ') no coincide con el total (S/. ' + totalVenta.toFixed(2) + ').');
        return;
    }
    if (!valid) {
        e.preventDefault();
        alert('Todos los ítems deben tener un precio mayor a 0.');
    }
});

// ── MULTI-PAYMENT METHODS ──
var pagosUI = [];

function agregarFilaPago() {
    var sel = document.getElementById('sel-nuevo-metodo');
    var inpMonto = document.getElementById('inp-monto-metodo');

    var metodo = sel.value;
    var monto = parseFloat(inpMonto.value);
    var _txt = document.getElementById('tot-total').textContent;
    var totalVenta = parseFloat((_txt.match(/\d[\d.,-]*/) || ['0'])[0].replace(',', '.')) || 0;
    var sumPagos = pagosUI.reduce(function(s, p) { return s + p.monto; }, 0);
    var diff = totalVenta - sumPagos;

    if (!metodo) { alert('Selecciona un método de pago.'); return; }
    if (isNaN(monto) || monto <= 0) { alert('Ingresa un monto mayor a 0.'); return; }
    if (diff <= 0.01) {
        alert('El total ya está cubierto. No puedes agregar más métodos de pago.');
        return;
    }

    if (monto > diff + 0.01) {
        alert('El monto excede lo que falta pagar (S/. ' + diff.toFixed(2) + '). Ajusta el monto o usa otro método.');
        return;
    }

    pagosUI.push({ metodo: metodo, monto: monto });
    inpMonto.value = '';
    renderPagosUI();
}

function eliminarFilaPago(idx) {
    pagosUI.splice(idx, 1);
    renderPagosUI();
}

function renderPagosUI() {
    var container = document.getElementById('lista-metodos-pago-ui');
    var _txt = document.getElementById('tot-total').textContent;
    var totalVenta = parseFloat((_txt.match(/\d[\d.,-]*/) || ['0'])[0].replace(',', '.')) || 0;
    var sumPagos = pagosUI.reduce(function(s, p) { return s + p.monto; }, 0);
    var diff = totalVenta - sumPagos;

    var html = '';
    pagosUI.forEach(function(p, i) {
        var label = p.metodo.charAt(0).toUpperCase() + p.metodo.slice(1).replace(/_/g, ' ');
        html += '<div style="display:flex;align-items:center;justify-content:space-between;padding:5px 10px;background:var(--bg3);border-radius:6px;margin-bottom:4px">'
              + '<span style="font-size:13px">' + label + '</span>'
              + '<div style="display:flex;align-items:center;gap:8px">'
              + '<span style="font-weight:600;font-size:13px">S/. ' + p.monto.toFixed(2) + '</span>'
              + '<button type="button" onclick="eliminarFilaPago(' + i + ')" style="background:none;border:none;color:var(--red);cursor:pointer;font-size:13px">✕</button>'
              + '</div></div>';
    });
    container.innerHTML = html;

    document.getElementById('pagos-json').value = JSON.stringify(pagosUI);
    updatePagoBtnState(totalVenta);
}

// Si no hay ningún pago cargado y hay total > 0, pre-cargar efectivo
window.addEventListener('DOMContentLoaded', function() {
    renderPagosUI();
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
