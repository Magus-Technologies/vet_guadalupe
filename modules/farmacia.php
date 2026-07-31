<?php
$page = 'farmacia'; $pageTitle = 'Farmacia / Inventario';

// ════════════════════════════════════════════════════════════════
// IMPORTACIÓN / EXPORTACIÓN masiva de productos de farmacia
// (mismo método robusto que Pet Shop: XML SpreadsheetML, abre en
//  columnas en cualquier Excel sin importar la config regional)
// ════════════════════════════════════════════════════════════════

// Lectores de archivos (XLSX real, XML SpreadsheetML Excel 2003, CSV/TSV)
if (!function_exists('fm_leer_excel_xml')) {
function fm_leer_excel_xml($raw) {
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
        if ($celdas) { ksort($celdas); $_max=max(array_keys($celdas)); $_fila=[]; for($_j=0;$_j<=$_max;$_j++) $_fila[$_j]=$celdas[$_j]??""; $filas[] = $_fila; }
    }
    return $filas;
}
}
if (!function_exists('fm_col_a_num')) {
function fm_col_a_num($letras) {
    $n = 0;
    for ($i=0; $i<strlen($letras); $i++) { $n = $n*26 + (ord($letras[$i]) - 64); }
    return $n - 1;
}
}
if (!function_exists('fm_leer_xlsx')) {
function fm_leer_xlsx($path) {
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
            if ($ref && preg_match('/^([A-Z]+)/', $ref, $m)) { $colIdx = fm_col_a_num($m[1]); }
            $tipo = (string)($c['t'] ?? '');
            $v = isset($c->v) ? (string)$c->v : '';
            if ($tipo === 's') { $v = $shared[(int)$v] ?? ''; }
            elseif ($tipo === 'inlineStr' && isset($c->is->t)) { $v = (string)$c->is->t; }
            $celdas[$colIdx] = $v; $colIdx++;
        }
        if ($celdas) { ksort($celdas); $_max=max(array_keys($celdas)); $_fila=[]; for($_j=0;$_j<=$_max;$_j++) $_fila[$_j]=$celdas[$_j]??""; $filas[] = $_fila; }
    }
    return $filas;
}
}

// ── EXPORTAR / PLANTILLA — XML SpreadsheetML (abre en columnas siempre) ──
if (($_GET['action'] ?? '') === 'exportar' || ($_GET['action'] ?? '') === 'plantilla') {
    require_once __DIR__ . '/../includes/config.php';
    $db = getDB();
    if (function_exists('requireLogin')) requireLogin();
    $es_plantilla = ($_GET['action'] === 'plantilla');

    $cols = ['Categoria','Nombre','Descripcion','Presentacion','Laboratorio','Codigo_Barras','Precio_Costo','Precio_Venta','Stock','Stock_Minimo','Lote','Fecha_Vencimiento'];
    $fname = $es_plantilla ? 'plantilla_farmacia.xls' : 'farmacia_'.date('Y-m-d').'.xls';

    $datos = [];
    if ($es_plantilla) {
        $datos[] = ['Antibióticos','Amoxicilina 500mg','Antibiótico de amplio espectro','Caja x 20 tabletas','Genfar','7501234560001','8.00','15.00','50','10','L2024A','2026-12-31'];
        $datos[] = ['Antiparasitarios','Ivermectina 1%','Antiparasitario inyectable','Frasco 50ml','Bayer','7501234560002','25.00','45.00','30','8','L2024B','2027-06-30'];
    } else {
        try {
            $where = 'p.activo=1';
            try { $r=$db->query("SHOW COLUMNS FROM productos LIKE 'sede_id'")->fetchAll(); if(!empty($r)&&!verTodasSedes()){$where.=' AND p.sede_id='.getSede();} } catch(Exception $e){}
            $datos = $db->query("SELECT COALESCE(c.nombre,'') as categoria, p.nombre, COALESCE(p.descripcion,''), COALESCE(p.presentacion,''), COALESCE(p.laboratorio,''), COALESCE(p.codigo_barras,''), p.precio_costo, p.precio_venta, p.stock, p.stock_minimo, COALESCE(p.lote,''), COALESCE(p.fecha_vencimiento,'') FROM productos p LEFT JOIN categorias_producto c ON c.id=p.categoria_id WHERE $where ORDER BY p.nombre")->fetchAll(PDO::FETCH_NUM);
        } catch(Exception $e) { $datos = []; }
    }

    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="'.$fname.'"');
    header('Cache-Control: max-age=0');

    $esc = function($v){ return htmlspecialchars((string)$v, ENT_QUOTES|ENT_XML1, 'UTF-8'); };
    $celda = function($v, $col) use ($esc) {
        $numericas = [6,7,8,9]; // precios y stock
        if (in_array($col, $numericas, true) && is_numeric(str_replace(',','.',$v)) && $v !== '') {
            return '<Cell><Data ss:Type="Number">'.$esc(str_replace(',','.',$v)).'</Data></Cell>';
        }
        return '<Cell><Data ss:Type="String">'.$esc($v).'</Data></Cell>';
    };

    echo '<?xml version="1.0" encoding="UTF-8"?>'."\n";
    echo '<?mso-application progid="Excel.Sheet"?>'."\n";
    echo '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">'."\n";
    echo '<Worksheet ss:Name="Farmacia"><Table>'."\n";
    echo '<Row>';
    foreach ($cols as $c) echo '<Cell><Data ss:Type="String">'.$esc($c).'</Data></Cell>';
    echo '</Row>'."\n";
    foreach ($datos as $fila) {
        echo '<Row>';
        foreach (array_values($fila) as $i => $v) echo $celda($v, $i);
        echo '</Row>'."\n";
    }
    echo '</Table></Worksheet></Workbook>';
    exit;
}

require_once __DIR__ . '/../includes/header.php';
$db = getDB();
$_sid = getSede(); $_all = verTodasSedes();
try {
    $r = $db->query("SHOW COLUMNS FROM productos LIKE 'sede_id'")->fetchAll();
    $_prod_sede = !empty($r);
    if (empty($r)) { $db->exec("ALTER TABLE productos ADD COLUMN sede_id INT DEFAULT 1"); $_prod_sede=true; }
} catch(Exception $e) { $_prod_sede = false; }
$action = $_GET['action'] ?? 'list';
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pa = $_POST['action'] ?? '';

    // ── IMPORTAR productos de farmacia masivamente ──
    if ($pa === 'importar_farmacia') {
        $importados = 0; $omitidos = 0; $err_imp = ''; $errores_imp = []; $actualizados = 0;
        try {
            if (empty($_FILES['archivo']['tmp_name'])) throw new Exception('No se recibió ningún archivo.');
            $tmp = $_FILES['archivo']['tmp_name'];
            $raw = file_get_contents($tmp);
            $filas = [];
            if (substr($raw,0,2) === 'PK') {
                $filas = fm_leer_xlsx($tmp);
            } elseif (substr($raw,0,4) === "\xD0\xCF\x11\xE0") {
                throw new Exception('El archivo es un Excel binario antiguo (.xls 97-2003), que no es compatible. Ábrelo en Excel y usa «Guardar como» → «Libro de Excel (.xlsx)» o «CSV (delimitado por comas)», o usa la plantilla que descargas del sistema. Luego vuelve a importarlo.');
            } elseif (stripos($raw,'<?xml') !== false || stripos($raw,'spreadsheet') !== false) {
                $filas = fm_leer_excel_xml($raw);
            } else {
                $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
                $sep = (substr_count($raw, "\t") > substr_count($raw, ',')) ? "\t" : ',';
                foreach (preg_split('/\r\n|\r|\n/', $raw) as $linea) {
                    if (trim($linea) === '') continue;
                    $filas[] = str_getcsv($linea, $sep);
                }
            }
            if (empty($filas)) throw new Exception('No se pudieron leer filas del archivo. Revisa el formato.');

            $sede_destino = function_exists('getSede') ? getSede() : 1;

            // Cache de categorías (nombre→id), para resolver/crear sin repetir queries
            $cat_cache = [];
            foreach ($db->query("SELECT id,nombre FROM categorias_producto")->fetchAll() as $c) {
                $cat_cache[mb_strtolower(trim($c['nombre']))] = (int)$c['id'];
            }
            $get_cat_id = function($nombre) use (&$cat_cache, $db) {
                $nombre = trim($nombre);
                if ($nombre === '') return null;
                $k = mb_strtolower($nombre);
                if (isset($cat_cache[$k])) return $cat_cache[$k];
                // Crear la categoría si no existe
                $db->prepare("INSERT INTO categorias_producto (nombre) VALUES (?)")->execute([$nombre]);
                $id = (int)$db->lastInsertId();
                $cat_cache[$k] = $id;
                return $id;
            };

            $ins = $db->prepare(
                "INSERT INTO productos
                 (categoria_id,nombre,descripcion,presentacion,laboratorio,codigo_barras,precio_costo,precio_venta,stock,stock_minimo,lote,fecha_vencimiento,sede_id)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)"
            );
            // UPSERT: si el producto ya existe (por código de barras o nombre) se ACTUALIZA en vez de duplicar.
            $upd = $db->prepare(
                "UPDATE productos SET categoria_id=?, nombre=?, descripcion=?, presentacion=?, laboratorio=?,
                    codigo_barras = COALESCE(NULLIF(?,''), codigo_barras),
                    precio_costo=?, precio_venta=?, stock=?, stock_minimo=?, lote=?, fecha_vencimiento=?
                 WHERE id=?"
            );
            $find_cb  = $db->prepare("SELECT id FROM productos WHERE codigo_barras=? AND codigo_barras<>'' AND sede_id=? LIMIT 1");
            $find_nom = $db->prepare("SELECT id FROM productos WHERE nombre=? AND sede_id=? LIMIT 1");

            // Parseo robusto de precios: soporta "1,500.00", "1.500,00", "S/ 1500", "1500", etc.
            $fm_num = function($s) {
                $s = trim((string)$s);
                $s = preg_replace('/[^\d.,\-]/', '', $s); // quita S/, espacios, letras
                if ($s === '' || $s === '-') return 0.0;
                $lc = strrpos($s, ','); $ld = strrpos($s, '.');
                if ($lc !== false && $ld !== false) {
                    if ($lc > $ld) { $s = str_replace('.', '', $s); $s = str_replace(',', '.', $s); } // 1.500,00
                    else           { $s = str_replace(',', '', $s); }                                  // 1,500.00
                } elseif ($lc !== false) {
                    if (preg_match('/,\d{1,2}$/', $s)) $s = str_replace(',', '.', $s); // 1,50 -> 1.50 (decimal)
                    else                               $s = str_replace(',', '', $s);  // 1,500 -> 1500 (miles)
                }
                return (float)$s;
            };
            $errores_imp = [];       // detalle de filas saltadas
            $MAX_DECIMAL = 99999999.99; // tope de DECIMAL(10,2)

            foreach ($filas as $f) {
                $f = array_map(fn($v)=>trim((string)$v), $f);
                $c0 = mb_strtolower($f[0] ?? '');
                if ($c0==='' && empty(array_filter($f))) continue;
                // Saltar encabezado/título
                if (in_array($c0, ['categoria','categoría','plantilla','producto','productos','nombre']) && (stripos(($f[1]??''),'nombre')!==false || $c0==='categoria' || $c0==='categoría')) continue;

                $nombre = $f[1] ?? '';
                if ($nombre === '') { $omitidos++; continue; }

                $cat_id      = $get_cat_id($f[0] ?? '');
                $descripcion = $f[2] ?? '';
                $present     = $f[3] ?? '';
                $laboratorio = $f[4] ?? '';
                $cod_barras  = $f[5] ?? '';
                $p_costo     = $fm_num($f[6] ?? '0');
                $p_venta     = $fm_num($f[7] ?? '0');
                $stock       = (int)($f[8] ?? 0);
                $stock_min   = (int)($f[9] ?? 5);
                $lote        = $f[10] ?? '';
                // Normalizar fecha (acepta dd/mm/yyyy o yyyy-mm-dd)
                $fv_raw = trim($f[11] ?? '');
                $fecha_venc = null;
                if ($fv_raw !== '') {
                    if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $fv_raw, $m)) {
                        $fecha_venc = sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
                    } elseif (preg_match('#^\d{4}-\d{1,2}-\d{1,2}#', $fv_raw)) {
                        $fecha_venc = substr($fv_raw,0,10);
                    }
                }

                // Validar rango de precios (DECIMAL(10,2)). Evita el error 1264.
                if ($p_costo < 0) $p_costo = 0;
                if ($p_venta < 0) $p_venta = 0;
                if ($p_costo > $MAX_DECIMAL || $p_venta > $MAX_DECIMAL) {
                    $omitidos++;
                    $errores_imp[] = "«{$nombre}»: precio fuera de rango (costo ".number_format($p_costo,2).", venta ".number_format($p_venta,2)."). Revisa que las columnas del archivo estén en orden y que no haya un código de barras en la columna de precio.";
                    continue;
                }

                try {
                    // ¿ya existe? -> por código de barras; si no tiene, por nombre (misma sede)
                    $pid = 0;
                    if ($cod_barras !== '') { $find_cb->execute([$cod_barras, $sede_destino]); $pid = (int)($find_cb->fetchColumn() ?: 0); }
                    if (!$pid)              { $find_nom->execute([$nombre, $sede_destino]);     $pid = (int)($find_nom->fetchColumn() ?: 0); }

                    if ($pid) {
                        $upd->execute([$cat_id,$nombre,$descripcion,$present,$laboratorio,$cod_barras,$p_costo,$p_venta,$stock,$stock_min,$lote ?: null,$fecha_venc,$pid]);
                        $actualizados++;
                    } else {
                        $ins->execute([$cat_id,$nombre,$descripcion,$present,$laboratorio,$cod_barras,$p_costo,$p_venta,$stock,$stock_min,$lote ?: null,$fecha_venc,$sede_destino]);
                        $importados++;
                    }
                } catch (Exception $eRow) {
                    $omitidos++;
                    $errores_imp[] = "«{$nombre}»: ".$eRow->getMessage();
                }
            }
        } catch(Exception $e) {
            $err_imp = $e->getMessage();
        }
        $qs = 'p=farmacia&imp='.$importados.'&act='.$actualizados.'&om='.$omitidos;
        if ($err_imp) $qs .= '&imperr='.urlencode(substr($err_imp,0,200));
        if (!empty($errores_imp)) $qs .= '&impdet='.urlencode(substr(implode(' • ', array_slice($errores_imp,0,4)),0,500));
        if (!headers_sent()) { header('Location: '.BASE_URL.'/index.php?'.$qs); exit; }
        echo '<script>location.href='.json_encode(BASE_URL.'/index.php?'.$qs).';</script>'; exit;
    }

    if ($pa === 'save') {
        $id = (int)($_POST['id']??0);
        $fields = ['categoria_id','nombre','descripcion','presentacion','laboratorio','codigo_barras','stock','stock_minimo','precio_costo','precio_venta','lote','fecha_vencimiento'];
        $data=[]; foreach($fields as $f) $data[$f] = trim($_POST[$f]??'') ?: null;
        $data['sede_id'] = getSede();
        $ok_guardar = true;

        // Validar que el código de barras no esté duplicado
        if (!empty($data['codigo_barras'])) {
            $sq = "SELECT nombre FROM productos WHERE codigo_barras = ? AND activo=1" . ($id ? " AND id <> ".(int)$id : "");
            $cb = $db->prepare($sq); $cb->execute([$data['codigo_barras']]); $dup = $cb->fetchColumn();
            if ($dup) {
                $msg = 'dup_codigo:' . $dup;
                $action = $id ? 'editar' : 'nuevo';
                $editing = array_merge((array)($editing ?? []), $data, ['id'=>$id]);
                $ok_guardar = false;
            }
            // También revisar contra petshop_productos (códigos únicos en todo el sistema)
            if ($ok_guardar) try {
                $sq2 = "SELECT nombre FROM petshop_productos WHERE codigo_barras = ? AND activo=1";
                $cb2 = $db->prepare($sq2); $cb2->execute([$data['codigo_barras']]); $dup2 = $cb2->fetchColumn();
                if ($dup2) {
                    $msg = 'dup_codigo_ps:' . $dup2;
                    $action = $id ? 'editar' : 'nuevo';
                    $editing = array_merge((array)($editing ?? []), $data, ['id'=>$id]);
                    $ok_guardar = false;
                }
            } catch(Exception $e){}
        }

        if ($ok_guardar) {
            if ($id) {
                $sets = implode(',', array_map(fn($f)=>"$f=:$f", $fields));
                $st = $db->prepare("UPDATE productos SET $sets WHERE id=:id"); $data['id']=$id;
            } else {
                $cols = implode(',', array_merge($fields,['sede_id']));
                $pls  = implode(',', array_map(fn($f)=>":$f", array_merge($fields,['sede_id'])));
                $st = $db->prepare("INSERT INTO productos ($cols) VALUES ($pls)");
            }
            $st->execute($data); $msg='success'; $action='list';
        }
    }
    if ($pa === 'movimiento') {
        $prod_id = (int)$_POST['producto_id'];
        $tipo    = $_POST['tipo'];
        $qty     = (int)$_POST['cantidad'];
        $notas   = trim($_POST['notas']??'');
        $st = $db->prepare("SELECT stock FROM productos WHERE id=?"); $st->execute([$prod_id]);
        $stock_ant = (int)$st->fetchColumn();
        $stock_nuevo = $tipo==='entrada' ? $stock_ant+$qty : max(0,$stock_ant-$qty);
        $db->prepare("UPDATE productos SET stock=? WHERE id=?")->execute([$stock_nuevo,$prod_id]);
        $db->prepare("INSERT INTO kardex (producto_id,usuario_id,tipo,cantidad,stock_anterior,stock_nuevo,notas) VALUES (?,?,?,?,?,?,?)")->execute([$prod_id,$user['id'],$tipo,$qty,$stock_ant,$stock_nuevo,$notas]);
        $msg='success'; $action='list';
    }
}
if ($action==='delete' && isset($_GET['id'])) {
    $db->prepare("UPDATE productos SET activo=0 WHERE id=?")->execute([(int)$_GET['id']]); $action='list';
}

$editing=null;
if (in_array($action,['editar']) && isset($_GET['id'])) {
    $st=$db->prepare("SELECT * FROM productos WHERE id=?"); $st->execute([(int)$_GET['id']]); $editing=$st->fetch();
}

$categorias = $db->query("SELECT * FROM categorias_producto ORDER BY nombre")->fetchAll();
$cat_map = [];foreach($categorias as $c) $cat_map[$c['id']]=$c['nombre'];

// Filtros
$cat_f = (int)($_GET['cat']??0);
$alerta_f = $_GET['alerta']??'';
$search = trim($_GET['q']??'');
$where = "activo=1"; $params=[];
if ($cat_f) { $where .= " AND categoria_id=?"; $params[]=$cat_f; }
if ($search) { $where .= " AND (nombre LIKE ? OR laboratorio LIKE ? OR lote LIKE ?)"; $like="%$search%"; $params=array_merge($params,[$like,$like,$like]); }
if ($alerta_f==='critico')    $where .= " AND stock < stock_minimo/2";
elseif($alerta_f==='bajo')    $where .= " AND stock <= stock_minimo";
elseif($alerta_f==='por_vencer') $where .= " AND fecha_vencimiento BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 30 DAY)";
// Filtro sede seguro
if ($_prod_sede && !$_all) { $where .= " AND sede_id=$_sid"; }
$_swf = ($_prod_sede && !$_all) ? " AND sede_id=$_sid" : "";

$st = $db->prepare("SELECT * FROM productos WHERE $where ORDER BY nombre ASC"); $st->execute($params); $productos=$st->fetchAll();
$criticos = $db->query("SELECT COUNT(*) FROM productos WHERE stock < stock_minimo/2 AND activo=1$_swf")->fetchColumn();
$bajos    = $db->query("SELECT COUNT(*) FROM productos WHERE stock <= stock_minimo AND activo=1$_swf")->fetchColumn();
$total_val= $db->query("SELECT COALESCE(SUM(stock*precio_costo),0) FROM productos WHERE activo=1$_swf")->fetchColumn();
?>
<?php if($msg==='success'): ?><div class="alert alert-success mb-2">✅ Operación realizada correctamente.</div><?php endif; ?>
<?php if(is_string($msg) && strpos($msg,'dup_codigo:')===0): ?>
<div class="alert alert-danger mb-2">⚠️ Ese código de barras ya está en uso por el producto de farmacia: <strong><?= clean(substr($msg,11)) ?></strong>. Cada código debe ser único.</div>
<?php endif; ?>
<?php if(is_string($msg) && strpos($msg,'dup_codigo_ps:')===0): ?>
<div class="alert alert-danger mb-2">⚠️ Ese código de barras ya está en uso por el producto de Pet Shop: <strong><?= clean(substr($msg,14)) ?></strong>. Cada código debe ser único.</div>
<?php endif; ?>

<?php if(in_array($action,['nueva','editar'])): ?>
<div class="card" style="max-width:680px">
  <div class="sec-header"><div class="sec-title"><?= $action==='editar'?'Editar':'Nuevo'?> Producto</div><a href="?p=farmacia" class="btn btn-sm">← Volver</a></div>
  <form method="POST">
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?= $editing['id']??'' ?>">
    <div class="form-row">
      <div class="form-group"><label class="form-label">Nombre *</label><input class="form-input" name="nombre" value="<?= clean($editing['nombre']??'') ?>" required></div>
      <div class="form-group"><label class="form-label">Categoría</label>
        <select class="form-input" name="categoria_id">
          <option value="">— Sin categoría —</option>
          <?php foreach($categorias as $c): ?><option value="<?= $c['id'] ?>" <?= ($editing['categoria_id']??'')==$c['id']?'selected':'' ?>><?= clean($c['nombre']) ?></option><?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="form-row">
      <div class="form-group"><label class="form-label">Presentación</label><input class="form-input" name="presentacion" value="<?= clean($editing['presentacion']??'') ?>" placeholder="Ej: Tabletas x100"></div>
      <div class="form-group"><label class="form-label">Laboratorio</label><input class="form-input" name="laboratorio" value="<?= clean($editing['laboratorio']??'') ?>"></div>
    </div>

    <!-- 📷 CÓDIGO DE BARRAS con escaneo y autogenerar -->
    <div class="form-group">
      <label class="form-label">Código de barras</label>
      <div class="flex gap-1" style="align-items:stretch">
        <input class="form-input" id="cb-input" name="codigo_barras" value="<?= clean($editing['codigo_barras']??'') ?>"
               placeholder="Acerca el lector USB y escanea aquí, o escribe el código" autocomplete="off" style="flex:1">
        <button type="button" class="btn" onclick="document.getElementById('cb-input').focus()" title="Hacer clic, luego acercar el lector USB al producto">📷 Escanear</button>
        <button type="button" class="btn" onclick="cbAutogenerar()" title="Generar código interno automático (VET-F-0001)">⚡ Autogenerar</button>
      </div>
      <div class="text-xs text-muted mt-1">
        Si el producto trae código del fabricante, escanéalo. Si no (preparados, genéricos), usa "Autogenerar" para crear un código interno tipo <code>VET-F-0001</code>.
      </div>
    </div>

    <div class="form-row">
      <div class="form-group"><label class="form-label">Stock actual *</label><input class="form-input" type="number" name="stock" value="<?= clean($editing['stock']??0) ?>" required></div>
      <div class="form-group"><label class="form-label">Stock mínimo</label><input class="form-input" type="number" name="stock_minimo" value="<?= clean($editing['stock_minimo']??5) ?>"></div>
    </div>
    <div class="form-row">
      <div class="form-group"><label class="form-label">Precio de costo (S/.)</label><input class="form-input" type="number" step="0.01" name="precio_costo" value="<?= clean($editing['precio_costo']??0) ?>"></div>
      <div class="form-group"><label class="form-label">Precio de venta (S/.)</label><input class="form-input" type="number" step="0.01" name="precio_venta" value="<?= clean($editing['precio_venta']??0) ?>"></div>
    </div>
    <div class="form-row">
      <div class="form-group"><label class="form-label">N° de lote</label><input class="form-input" name="lote" value="<?= clean($editing['lote']??'') ?>"></div>
      <div class="form-group"><label class="form-label">Fecha de vencimiento</label><input class="form-input" type="date" name="fecha_vencimiento" value="<?= clean($editing['fecha_vencimiento']??'') ?>"></div>
    </div>
    <div class="form-group"><label class="form-label">Descripción</label><textarea class="form-input" name="descripcion" style="min-height:60px"><?= clean($editing['descripcion']??'') ?></textarea></div>
    <div class="flex gap-1"><button type="submit" class="btn btn-primary">💾 Guardar producto</button><a href="?p=farmacia" class="btn">Cancelar</a></div>
  </form>
</div>

<?php
// Calcular el siguiente código interno disponible para autogenerar (VET-F-####)
$_cb_next = 1;
try {
    $r = $db->query("SELECT MAX(CAST(SUBSTRING(codigo_barras,7) AS UNSIGNED)) AS m FROM productos WHERE codigo_barras LIKE 'VET-F-%'")->fetch();
    $_cb_next = ((int)($r['m'] ?? 0)) + 1;
} catch(Exception $e){}
?>
<script>
function cbAutogenerar() {
    var inp = document.getElementById('cb-input');
    if (inp.value.trim() !== '' && !confirm('El campo ya tiene un código (' + inp.value + '). ¿Reemplazar con uno autogenerado?')) return;
    var n = <?= (int)$_cb_next ?>;
    inp.value = 'VET-F-' + String(n).padStart(4, '0');
    inp.focus();
}
// Si el usuario abre el form de nuevo producto y empieza a "escanear" sin hacer clic en el campo,
// algunos lectores envían el código tan rápido que el primer carácter puede perderse. Por eso,
// si el campo está vacío al abrir, lo enfocamos automáticamente.
document.addEventListener('DOMContentLoaded', function(){
    var inp = document.getElementById('cb-input');
    if (inp && !inp.value.trim() && '<?= $action ?>' === 'nuevo') {
        // Enfocar después de un breve delay (para que otros listeners se monten primero)
        setTimeout(function(){ inp.focus(); }, 100);
    }
});
</script>

<?php elseif($action==='movimiento' && isset($_GET['id'])): ?>
<?php $prod_mov=$db->prepare("SELECT * FROM productos WHERE id=?"); $prod_mov->execute([(int)$_GET['id']]); $pm=$prod_mov->fetch(); ?>
<div class="card" style="max-width:480px">
  <div class="sec-header"><div class="sec-title">Movimiento de inventario</div><a href="?p=farmacia" class="btn btn-sm">← Volver</a></div>
  <div class="alert alert-info mb-2"><span>📦</span><div><strong><?= clean($pm['nombre']) ?></strong> · Stock actual: <strong><?= $pm['stock'] ?> unidades</strong></div></div>
  <form method="POST">
    <input type="hidden" name="action" value="movimiento">
    <input type="hidden" name="producto_id" value="<?= $pm['id'] ?>">
    <div class="form-row">
      <div class="form-group"><label class="form-label">Tipo de movimiento</label>
        <select class="form-input" name="tipo"><option value="entrada">Entrada (compra/recepción)</option><option value="salida">Salida (uso/venta)</option><option value="ajuste">Ajuste de inventario</option></select>
      </div>
      <div class="form-group"><label class="form-label">Cantidad</label><input class="form-input" type="number" name="cantidad" min="1" value="1" required></div>
    </div>
    <div class="form-group"><label class="form-label">Notas / Referencia</label><textarea class="form-input" name="notas" style="min-height:60px"></textarea></div>
    <div class="flex gap-1"><button type="submit" class="btn btn-primary">✅ Registrar movimiento</button><a href="?p=farmacia" class="btn">Cancelar</a></div>
  </form>
</div>

<?php else: ?>
<div class="grid g4 mb-2">
  <div class="stat-card"><div class="stat-icon si-teal">📦</div><div class="stat-value"><?= count($productos) ?></div><div class="stat-label">Productos activos</div></div>
  <div class="stat-card" style="<?= $criticos>0?'border-color:var(--red)':'' ?>"><div class="stat-icon <?= $criticos>0?'si-red':'si-teal' ?>">🚨</div><div class="stat-value"><?= $criticos ?></div><div class="stat-label">Stock crítico</div></div>
  <div class="stat-card" style="<?= $bajos>0?'border-color:var(--amber)':'' ?>"><div class="stat-icon <?= $bajos>0?'si-amber':'si-teal' ?>">⚠️</div><div class="stat-value"><?= $bajos ?></div><div class="stat-label">Bajo el mínimo</div></div>
  <div class="stat-card"><div class="stat-icon si-blue">💰</div><div class="stat-value">S/. <?= number_format($total_val,0) ?></div><div class="stat-label">Valor del inventario</div></div>
</div>

<?php if($criticos > 0): ?>
<div class="alert alert-warn mb-2"><span>⚠️</span><div><strong><?= $criticos ?> producto(s) en stock crítico.</strong> Realiza una compra/reposición pronto.</div></div>
<?php endif; ?>

<div class="card mb-2" style="padding:14px 18px">
  <form method="GET" class="flex items-center gap-2" style="flex-wrap:wrap"><input type="hidden" name="p" value="farmacia">
    <input class="form-input" name="q" value="<?= clean($search) ?>" placeholder="Buscar producto..." style="width:220px">
    <select class="form-input" name="cat" style="width:160px"><option value="">Todas las categorías</option><?php foreach($categorias as $c): ?><option value="<?= $c['id'] ?>" <?= $cat_f==$c['id']?'selected':'' ?>><?= clean($c['nombre']) ?></option><?php endforeach; ?></select>
    <select class="form-input" name="alerta" style="width:160px"><option value="">Todos</option><option value="critico" <?= $alerta_f==='critico'?'selected':'' ?>>Stock crítico</option><option value="bajo" <?= $alerta_f==='bajo'?'selected':'' ?>>Bajo mínimo</option><option value="por_vencer" <?= $alerta_f==='por_vencer'?'selected':'' ?>>Por vencer (30d)</option></select>
    <button type="submit" class="btn">Filtrar</button>
    <button type="button" class="btn btn-ghost" style="margin-left:auto" onclick="document.getElementById('modal-import-fm').style.display='flex'">📥 Importar</button>
    <a href="?p=farmacia&action=exportar" class="btn btn-ghost">📤 Exportar</a>
    <a href="?p=farmacia&action=nueva" class="btn btn-primary">+ Nuevo Producto</a>
  </form>
</div>

<?php if (isset($_GET['imp'])): ?>
<div class="card mb-2" style="padding:13px 16px;background:#f0fdf4;border-left:3px solid #10b981">
  <div style="font-size:13px;color:#065f46">
    ✅ Importación completada: <strong><?= (int)$_GET['imp'] ?></strong> agregado(s)<?= (int)($_GET['act']??0) ? ', <strong>'.(int)$_GET['act'].'</strong> actualizado(s)' : '' ?><?= (int)($_GET['om']??0) ? ', '.(int)$_GET['om'].' omitido(s)' : '' ?>.
    <?php if(!empty($_GET['imperr'])): ?><br><span style="color:#b91c1c">⚠️ <?= clean($_GET['imperr']) ?></span><?php endif; ?>
    <?php if(!empty($_GET['impdet'])): ?><br><span style="color:#b45309;font-size:12px">Filas no importadas → <?= clean($_GET['impdet']) ?></span><?php endif; ?>
  </div>
</div>
<?php endif; ?>

<div class="card" style="padding:0">
  <div class="table-wrap">
    <table class="vtable">
      <thead><tr><th>Producto</th><th>Código</th><th>Categoría</th><th>Stock</th><th>Mín.</th><th>Precio venta</th><th>Lote</th><th>Vencimiento</th><th>Estado</th><th>Acciones</th></tr></thead>
      <tbody>
        <?php foreach($productos as $p):
          $critico = $p['stock'] < $p['stock_minimo']/2;
          $bajo    = $p['stock'] <= $p['stock_minimo'];
          $estado  = $critico ? 'Crítico' : ($bajo ? 'Bajo' : 'OK');
          $badge   = $critico ? 'b-red' : ($bajo ? 'b-amber' : 'b-teal');
          $venc_warn = $p['fecha_vencimiento'] && strtotime($p['fecha_vencimiento']) < strtotime('+30 days');
          $pct = min(100, $p['stock_minimo'] > 0 ? round($p['stock']/$p['stock_minimo']*50) : 100);
        ?>
        <tr>
          <td><div class="td-main"><?= clean($p['nombre']) ?></div><div class="text-xs text-muted"><?= clean($p['presentacion']??'') ?></div></td>
          <td><?php if(!empty($p['codigo_barras'])): ?><code class="text-xs" style="background:#f0fdfa;color:#065f46;padding:2px 6px;border-radius:4px"><?= clean($p['codigo_barras']) ?></code><?php else: ?><span class="text-xs text-muted">—</span><?php endif; ?></td>
          <td><span class="badge b-gray"><?= clean($cat_map[$p['categoria_id']]??'—') ?></span></td>
          <td>
            <div class="font-bold" style="color:<?= $critico?'var(--red)':($bajo?'var(--amber)':'var(--text)') ?>"><?= $p['stock'] ?></div>
            <div class="progress-bar mt-1" style="width:70px"><div class="progress-fill" style="width:<?= $pct ?>%;background:<?= $critico?'var(--red)':($bajo?'var(--amber)':'var(--teal)') ?>"></div></div>
          </td>
          <td class="text-muted"><?= $p['stock_minimo'] ?></td>
          <td class="font-med">S/. <?= number_format($p['precio_venta'],2) ?></td>
          <td class="text-xs text-muted"><?= clean($p['lote']??'—') ?></td>
          <td class="<?= $venc_warn?'font-bold':'text-muted' ?>" style="<?= $venc_warn?'color:var(--amber)':'' ?>"><?= $p['fecha_vencimiento'] ? date('d/m/Y',strtotime($p['fecha_vencimiento'])) : '—' ?></td>
          <td><span class="badge <?= $badge ?>"><?= $estado ?></span></td>
          <td><div class="flex gap-1">
            <a href="?p=farmacia&action=movimiento&id=<?= $p['id'] ?>" class="btn btn-xs">📦 Mov.</a>
            <a href="?p=farmacia&action=editar&id=<?= $p['id'] ?>" class="btn btn-xs">✏️</a>
            <a href="?p=farmacia&action=delete&id=<?= $p['id'] ?>" class="btn btn-xs" style="color:var(--red)" onclick="return confirm('¿Dar de baja este producto?')">✕</a>
          </div></td>
        </tr>
        <?php endforeach; ?>
        <?php if(empty($productos)): ?><tr><td colspan="9" class="text-center text-muted" style="padding:32px">No se encontraron productos.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>
<!-- ══ Modal Importar productos de farmacia ══ -->
<div id="modal-import-fm" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center;padding:20px">
  <div style="background:var(--bg2);border-radius:16px;max-width:540px;width:100%;padding:24px;max-height:90vh;overflow-y:auto">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px">
      <div style="font-size:17px;font-weight:800;color:var(--text)">📥 Importar productos de farmacia</div>
      <button onclick="document.getElementById('modal-import-fm').style.display='none'" style="background:none;border:none;font-size:22px;cursor:pointer;color:var(--text3)">×</button>
    </div>
    <div style="font-size:13px;color:var(--text3);margin-bottom:16px;line-height:1.6">
      Sube un archivo <strong>Excel (.xlsx)</strong> o <strong>CSV</strong> con tus medicamentos. Acepta archivos exportados de otros sistemas.
    </div>
    <div style="background:var(--bg3);border-radius:10px;padding:12px 14px;margin-bottom:16px">
      <div style="font-size:12px;font-weight:700;color:var(--text2);margin-bottom:6px">📋 Columnas esperadas (en este orden):</div>
      <div style="font-size:11px;color:var(--text3);line-height:1.7">
        Categoría · Nombre · Descripción · Presentación · Laboratorio · Código de Barras · Precio Costo · Precio Venta · Stock · Stock Mínimo · Lote · Fecha Vencimiento
      </div>
      <div style="margin-top:10px">
        <a href="?p=farmacia&action=plantilla" class="btn btn-ghost btn-xs">⬇️ Descargar plantilla de ejemplo</a>
      </div>
      <div style="font-size:11px;color:var(--text3);margin-top:8px">Solo <strong>Nombre</strong> es obligatorio. La <strong>categoría</strong> se crea sola si no existe. La fecha admite formato dd/mm/aaaa o aaaa-mm-dd.</div>
    </div>
    <form method="POST" enctype="multipart/form-data" onsubmit="var b=this.querySelector('button[type=submit]');b.disabled=true;b.textContent='Importando...';">
      <input type="hidden" name="action" value="importar_farmacia">
      <input type="file" name="archivo" accept=".xlsx,.xls,.csv,.tsv,.xml" required
             style="width:100%;padding:10px;border:2px dashed var(--border);border-radius:10px;font-size:13px;margin-bottom:16px;background:var(--bg3)">
      <div style="display:flex;gap:8px;justify-content:flex-end">
        <button type="button" class="btn btn-ghost" onclick="document.getElementById('modal-import-fm').style.display='none'">Cancelar</button>
        <button type="submit" class="btn btn-primary">📥 Importar productos</button>
      </div>
    </form>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
