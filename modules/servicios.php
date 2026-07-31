<?php
$page = 'servicios'; $pageTitle = 'Servicios';
require_once __DIR__ . '/../includes/config.php';
$db = getDB();
$action = $_GET['action'] ?? 'list';

// ════════════════════════════════════════════════════════════════
// Migraciones idempotentes
//  - tabla tipos_servicio (tipos gestionables)
//  - servicios.tipo -> VARCHAR (para permitir tipos personalizados)
// ════════════════════════════════════════════════════════════════
try {
    $db->exec("CREATE TABLE IF NOT EXISTS tipos_servicio (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nombre VARCHAR(40) NOT NULL,
        activo TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $col = $db->query("SHOW COLUMNS FROM servicios LIKE 'tipo'")->fetch();
    if ($col && stripos($col['Type'], 'enum') !== false) {
        $db->exec("ALTER TABLE servicios MODIFY COLUMN tipo VARCHAR(40) DEFAULT 'Consulta'");
        // Mapear los valores viejos (enum en minúscula) a etiquetas legibles
        $map = ['consulta'=>'Consulta','cirugia'=>'Cirugía','vacuna'=>'Vacuna','bano'=>'Baño',
                'grooming'=>'Grooming','hospitalizacion'=>'Hospitalización','laboratorio'=>'Laboratorio','otro'=>'Otro'];
        $u = $db->prepare("UPDATE servicios SET tipo=? WHERE tipo=?");
        foreach ($map as $old=>$new) $u->execute([$new, $old]);
    }
    // Semilla de tipos si está vacía
    if ((int)$db->query("SELECT COUNT(*) FROM tipos_servicio")->fetchColumn() === 0) {
        $seed = ['Consulta','Cirugía','Vacuna','Baño','Grooming','Hospitalización','Laboratorio','Otro'];
        $ins = $db->prepare("INSERT INTO tipos_servicio (nombre) VALUES (?)");
        foreach ($seed as $s) $ins->execute([$s]);
    }
} catch (Exception $e) {}

// Lista de tipos activos (para formulario y filtros)
$tipos = $db->query("SELECT id,nombre FROM tipos_servicio WHERE activo=1 ORDER BY nombre")->fetchAll();
$tipos_nombres = array_column($tipos, 'nombre');

// ════════════════════════════════════════════════════════════════
// Acciones que redirigen (ANTES del header · PRG con flash)
// ════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    $id     = (int)($_POST['id'] ?? 0);
    $nombre = trim($_POST['nombre'] ?? '');
    $tipo   = trim($_POST['tipo'] ?? '');
    if ($tipo === '' || ($tipos_nombres && !in_array($tipo, $tipos_nombres, true))) $tipo = $tipos_nombres[0] ?? 'Otro';
    $precio = (float)str_replace(',', '.', $_POST['precio'] ?? '0');
    $dur    = max(0, (int)($_POST['duracion_minutos'] ?? 30));
    $desc   = trim($_POST['descripcion'] ?? '');
    $activo = isset($_POST['activo']) ? 1 : 0;

    if ($nombre === '' || $precio < 0) {
        $_SESSION['flash'] = 'error:Falta el nombre o el precio no es válido.';
    } else {
        try {
            if ($id) {
                $db->prepare("UPDATE servicios SET nombre=?, descripcion=?, tipo=?, precio=?, duracion_minutos=?, activo=? WHERE id=?")
                   ->execute([$nombre,$desc,$tipo,$precio,$dur,$activo,$id]);
                if (function_exists('auditLog')) auditLog('editar','servicios',"Servicio #$id: $nombre");
                $_SESSION['flash'] = 'ok:Servicio actualizado correctamente.';
            } else {
                $sede = getSede() ?: 1;
                $db->prepare("INSERT INTO servicios (sede_id,nombre,descripcion,tipo,precio,duracion_minutos,activo) VALUES (?,?,?,?,?,?,?)")
                   ->execute([$sede,$nombre,$desc,$tipo,$precio,$dur,$activo]);
                if (function_exists('auditLog')) auditLog('crear','servicios',"Servicio: $nombre");
                $_SESSION['flash'] = 'ok:Servicio creado correctamente.';
            }
        } catch (Exception $e) { $_SESSION['flash'] = 'error:No se pudo guardar el servicio.'; }
    }
    header('Location: ?p=servicios'); exit;
}

// Agregar un tipo de servicio
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'tipo_add') {
    $nom = trim($_POST['nombre_tipo'] ?? '');
    if ($nom === '') {
        $_SESSION['flash'] = 'error:Escribe el nombre del tipo.';
    } else {
        try {
            $dup = $db->prepare("SELECT id,activo FROM tipos_servicio WHERE LOWER(nombre)=LOWER(?) LIMIT 1");
            $dup->execute([$nom]); $ex = $dup->fetch();
            if ($ex) {
                // Si existía desactivado, reactivar
                if (empty($ex['activo'])) $db->prepare("UPDATE tipos_servicio SET activo=1 WHERE id=?")->execute([$ex['id']]);
                $_SESSION['flash'] = 'ok:Ese tipo ya existía; quedó disponible.';
            } else {
                $db->prepare("INSERT INTO tipos_servicio (nombre,activo) VALUES (?,1)")->execute([$nom]);
                if (function_exists('auditLog')) auditLog('crear','servicios',"Tipo de servicio: $nom");
                $_SESSION['flash'] = 'ok:Tipo agregado.';
            }
        } catch (Exception $e) { $_SESSION['flash'] = 'error:No se pudo agregar el tipo.'; }
    }
    header('Location: ?p=servicios'); exit;
}

// Eliminar un tipo de servicio
if ($action === 'tipo_del' && isset($_GET['id'])) {
    $tid = (int)$_GET['id'];
    try {
        $t = $db->prepare("SELECT nombre FROM tipos_servicio WHERE id=?"); $t->execute([$tid]); $tnom = $t->fetchColumn();
        if ($tnom !== false) {
            $en_uso = (int)$db->query("SELECT COUNT(*) FROM servicios WHERE tipo=".$db->quote($tnom))->fetchColumn();
            if ($en_uso > 0) {
                // En uso → se desactiva (no se borra) para no dejar servicios sin referencia
                $db->prepare("UPDATE tipos_servicio SET activo=0 WHERE id=?")->execute([$tid]);
                $_SESSION['flash'] = "ok:El tipo «{$tnom}» está en uso por {$en_uso} servicio(s); se desactivó (ya no aparece para nuevos).";
            } else {
                $db->prepare("DELETE FROM tipos_servicio WHERE id=?")->execute([$tid]);
                $_SESSION['flash'] = "ok:Tipo «{$tnom}» eliminado.";
            }
        }
    } catch (Exception $e) { $_SESSION['flash'] = 'error:No se pudo eliminar el tipo.'; }
    header('Location: ?p=servicios'); exit;
}

// Activar / desactivar servicio
if ($action === 'toggle' && isset($_GET['id'])) {
    try { $db->prepare("UPDATE servicios SET activo = 1 - activo WHERE id=?")->execute([(int)$_GET['id']]); } catch (Exception $e) {}
    header('Location: ?p=servicios'); exit;
}

require_once __DIR__ . '/../includes/header.php';

$msg=''; $msg_tipo='success';
if (!empty($_SESSION['flash'])) { [$ft,$fm]=array_pad(explode(':',$_SESSION['flash'],2),2,''); $msg=$fm; $msg_tipo=($ft==='ok'?'success':'danger'); unset($_SESSION['flash']); }

// Servicio a editar
$editing = null;
if ($action === 'editar' && isset($_GET['id'])) {
    $st = $db->prepare("SELECT * FROM servicios WHERE id=?"); $st->execute([(int)$_GET['id']]); $editing = $st->fetch();
    if (!$editing) $action = 'list';
}

// Filtros
$q = trim($_GET['q'] ?? '');
$tipo_f = trim($_GET['tipo'] ?? '');
$where = "WHERE 1=1"; $params = [];
if ($q !== '')       { $where .= " AND nombre LIKE ?"; $params[] = "%$q%"; }
if ($tipo_f !== '' && in_array($tipo_f, $tipos_nombres, true)) { $where .= " AND tipo = ?"; $params[] = $tipo_f; }

$stL = $db->prepare("SELECT * FROM servicios $where ORDER BY activo DESC, tipo, nombre");
$stL->execute($params); $servicios = $stL->fetchAll();
$total_act = (int)$db->query("SELECT COUNT(*) FROM servicios WHERE activo=1")->fetchColumn();
$total_ina = (int)$db->query("SELECT COUNT(*) FROM servicios WHERE activo=0")->fetchColumn();
// Todos los tipos (activos e inactivos) para el gestor
$tipos_all = $db->query("SELECT t.id,t.nombre,t.activo,(SELECT COUNT(*) FROM servicios s WHERE s.tipo=t.nombre) as usos FROM tipos_servicio t ORDER BY t.activo DESC, t.nombre")->fetchAll();
?>

<div class="page">
  <?php if ($msg): ?><div class="alert alert-<?= $msg_tipo ?>"><span class="alert-icon"><?= $msg_tipo==='success'?'✅':'⚠️' ?></span><?= clean($msg) ?></div><?php endif; ?>

<?php if ($action === 'nuevo' || $action === 'editar'): ?>
  <!-- ═══════════ FORMULARIO ═══════════ -->
  <div class="page-title"><?= $editing ? '✏️ Editar servicio' : '➕ Nuevo servicio' ?></div>
  <div class="page-desc">Estos servicios aparecen en el buscador de <strong>Ventas / Facturación</strong>.</div>
  <div class="card" style="max-width:640px;padding:22px;margin-top:14px">
    <form method="post">
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" value="<?= (int)($editing['id'] ?? 0) ?>">
      <div class="form-group"><label class="form-label">Nombre del servicio *</label>
        <input class="form-input" name="nombre" required maxlength="200" value="<?= clean($editing['nombre'] ?? '') ?>" placeholder="Ej: Consulta general"></div>
      <div class="form-row">
        <div class="form-group"><label class="form-label">Tipo</label>
          <select class="form-input" name="tipo">
            <?php foreach ($tipos as $t): $sel = (($editing['tipo'] ?? '') === $t['nombre']); ?>
              <option value="<?= clean($t['nombre']) ?>" <?= $sel?'selected':'' ?>><?= clean($t['nombre']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group"><label class="form-label">Precio (S/) *</label>
          <input class="form-input" name="precio" type="number" step="0.01" min="0" required value="<?= isset($editing['precio'])?number_format($editing['precio'],2,'.',''):'' ?>" placeholder="0.00"></div>
        <div class="form-group"><label class="form-label">Duración (min)</label>
          <input class="form-input" name="duracion_minutos" type="number" min="0" value="<?= (int)($editing['duracion_minutos'] ?? 30) ?>"></div>
      </div>
      <div class="form-group"><label class="form-label">Descripción (opcional)</label>
        <input class="form-input" name="descripcion" maxlength="500" value="<?= clean($editing['descripcion'] ?? '') ?>" placeholder="Detalle breve del servicio"></div>
      <div class="form-group"><label class="flex items-center gap-1" style="cursor:pointer;font-weight:600;margin:0">
        <input type="checkbox" name="activo" value="1" <?= (!$editing || !empty($editing['activo']))?'checked':'' ?> style="width:auto;margin:0"> Activo (visible en Ventas)</label></div>
      <div class="flex gap-1" style="margin-top:8px">
        <button type="submit" class="btn btn-primary">💾 Guardar servicio</button>
        <a href="?p=servicios" class="btn btn-ghost">Cancelar</a>
      </div>
    </form>
  </div>

<?php else: ?>
  <!-- ═══════════ LISTA ═══════════ -->
  <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">
    <div>
      <div class="page-title">🏷️ Servicios</div>
      <div class="page-desc">Catálogo que aparece en Ventas / Facturación. <strong><?= $total_act ?></strong> activos · <?= $total_ina ?> inactivos.</div>
    </div>
    <a href="?p=servicios&action=nuevo" class="btn btn-primary">➕ Nuevo servicio</a>
  </div>

  <!-- ── GESTOR DE TIPOS ── -->
  <div class="card" style="padding:16px;margin:14px 0">
    <div style="font-weight:700;margin-bottom:4px">🏷️ Tipos de servicio</div>
    <div class="text-xs text-muted" style="margin-bottom:10px">Categorías que puedes asignar a cada servicio. Agrega o elimina las que necesites.</div>
    <div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:12px">
      <?php foreach ($tipos_all as $t): ?>
        <span style="display:inline-flex;align-items:center;gap:6px;padding:5px 10px;border-radius:999px;font-size:12.5px;font-weight:600;
                     background:<?= $t['activo']?'var(--primary-l)':'#f1f5f9' ?>;color:<?= $t['activo']?'var(--primary-d)':'#94a3b8' ?>">
          <?= clean($t['nombre']) ?><?= $t['usos']>0?' <span style="opacity:.6;font-weight:400">('.$t['usos'].')</span>':'' ?>
          <a href="?p=servicios&action=tipo_del&id=<?= (int)$t['id'] ?>"
             onclick="return confirm('¿Eliminar el tipo «<?= clean($t['nombre']) ?>»?<?= $t['usos']>0?' Está en uso por '.$t['usos'].' servicio(s); se desactivará.':'' ?>')"
             style="color:inherit;text-decoration:none;opacity:.7" title="Eliminar tipo">✕</a>
        </span>
      <?php endforeach; ?>
    </div>
    <form method="post" class="flex gap-1" style="max-width:420px">
      <input type="hidden" name="action" value="tipo_add">
      <input class="form-input" name="nombre_tipo" maxlength="40" placeholder="Nuevo tipo (ej: Peluquería, Ecografía…)" required>
      <button class="btn btn-primary btn-sm" type="submit">➕ Agregar tipo</button>
    </form>
  </div>

  <form method="get" class="flex gap-1" style="margin:14px 0">
    <input type="hidden" name="p" value="servicios">
    <input class="form-input" name="q" value="<?= clean($q) ?>" placeholder="🔍 Buscar servicio..." style="max-width:280px">
    <select class="form-input" name="tipo" style="max-width:180px" onchange="this.form.submit()">
      <option value="">Todos los tipos</option>
      <?php foreach ($tipos as $t): ?><option value="<?= clean($t['nombre']) ?>" <?= $tipo_f===$t['nombre']?'selected':'' ?>><?= clean($t['nombre']) ?></option><?php endforeach; ?>
    </select>
    <button class="btn btn-primary btn-sm">Filtrar</button>
    <?php if ($q!=='' || $tipo_f!==''): ?><a href="?p=servicios" class="btn btn-ghost btn-sm">Limpiar</a><?php endif; ?>
  </form>

  <div class="card table-wrap">
    <table class="vtable">
      <thead><tr><th>Servicio</th><th>Tipo</th><th style="text-align:right">Precio</th><th style="text-align:center">Duración</th><th style="text-align:center">Estado</th><th style="text-align:right">Acciones</th></tr></thead>
      <tbody>
        <?php foreach ($servicios as $s): ?>
        <tr<?= empty($s['activo'])?' style="opacity:.55"':'' ?>>
          <td><div class="td-main"><?= clean($s['nombre']) ?></div><?php if(!empty($s['descripcion'])): ?><div class="text-xs text-muted"><?= clean($s['descripcion']) ?></div><?php endif; ?></td>
          <td><span class="badge"><?= clean($s['tipo']) ?></span></td>
          <td style="text-align:right;font-weight:700">S/ <?= number_format($s['precio'],2) ?></td>
          <td style="text-align:center;color:var(--text3)"><?= (int)$s['duracion_minutos'] ?> min</td>
          <td style="text-align:center"><?php if(!empty($s['activo'])): ?><span class="badge" style="background:var(--success-l);color:var(--success-d)">Activo</span><?php else: ?><span class="badge" style="background:#f1f5f9;color:#64748b">Inactivo</span><?php endif; ?></td>
          <td style="text-align:right;white-space:nowrap">
            <a href="?p=servicios&action=editar&id=<?= (int)$s['id'] ?>" class="btn btn-xs btn-primary">✏️ Editar</a>
            <a href="?p=servicios&action=toggle&id=<?= (int)$s['id'] ?>" class="btn btn-xs btn-ghost"><?= !empty($s['activo'])?'⏸️ Desactivar':'▶️ Activar' ?></a>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($servicios)): ?><tr><td colspan="6" style="text-align:center;padding:34px;color:var(--text3)">No hay servicios<?= ($q!==''||$tipo_f!=='')?' con ese filtro':'' ?>. <a href="?p=servicios&action=nuevo" style="color:var(--primary);font-weight:700">Crea el primero</a>.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
