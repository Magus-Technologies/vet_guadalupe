<?php
$page = 'permisos'; $pageTitle = 'Roles y Permisos';
require_once __DIR__ . '/../includes/header.php';
if (!hasRole(['admin'])) {
    echo '<div class="alert alert-danger">🔒 Acceso denegado.</div>';
    require_once __DIR__ . '/../includes/footer.php'; exit;
}
$db = getDB();

// Auto-instalar tablas
try {
    $db->exec("CREATE TABLE IF NOT EXISTS permisos (id INT AUTO_INCREMENT PRIMARY KEY, rol VARCHAR(30) NOT NULL, modulo VARCHAR(50) NOT NULL, puede_ver TINYINT(1) DEFAULT 1, puede_crear TINYINT(1) DEFAULT 1, puede_editar TINYINT(1) DEFAULT 1, puede_eliminar TINYINT(1) DEFAULT 0, puede_exportar TINYINT(1) DEFAULT 0, UNIQUE KEY uk_rol_modulo (rol, modulo))");
    $db->exec("CREATE TABLE IF NOT EXISTS auditoria (id INT AUTO_INCREMENT PRIMARY KEY, usuario_id INT, usuario_nombre VARCHAR(150), rol VARCHAR(30), accion VARCHAR(50), modulo VARCHAR(50), descripcion TEXT, ip VARCHAR(45), created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
} catch(Exception $e) {}

$modulos = [
    'dashboard'   => ['🏠','Dashboard'],
    'citas'       => ['📅','Citas'],
    'clientes'    => ['👥','Clientes'],
    'mascotas'    => ['🐾','Mascotas'],
    'historial'   => ['📋','Historia Clínica'],
    'recetas'     => ['💊','Recetas'],
    'examenes'    => ['🔬','Exámenes'],
    'vacunas'     => ['💉','Vacunación'],
    'cirugias'    => ['✂️','Cirugías'],
    'hospital'    => ['🚑','Hospital/UCI'],
    'grooming'    => ['✨','Grooming'],
    'petshop'     => ['🛒','Pet Shop'],
    'farmacia'    => ['💊','Farmacia'],
    'inventario'  => ['📦','Inventario'],
    'compras'     => ['🛒','Compras'],
    'facturacion' => ['🧾','Facturación'],
    'caja'        => ['💰','Caja'],
    'reporte_pagos'=>['💳','Reporte de Pagos'],
    'cuentas'     => ['📋','Cuentas por cobrar'],
    'movimientos' => ['📦','Movimientos'],
    'reportes'    => ['📊','Reportes'],
    'personal'    => ['👤','Personal'],
    'plantillas'  => ['🖨️','Plantillas'],
    'whatsapp'    => ['💬','WhatsApp'],
    'solicitudes' => ['📩','Solicitudes'],
    'servicios'   => ['🏷️','Servicios'],
    'ganado'      => ['🐄','Ganado Vacuno'],
];

$roles_list = ['veterinario','recepcionista','asistente'];
$acciones = ['ver'=>'Ver','crear'=>'Crear','editar'=>'Editar','eliminar'=>'Eliminar','exportar'=>'Exportar'];
$accion_col = ['ver'=>'puede_ver','crear'=>'puede_crear','editar'=>'puede_editar','eliminar'=>'puede_eliminar','exportar'=>'puede_exportar'];

$msg = '';

// POST: guardar permisos
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action']??'') === 'save_permisos') {
    $rol = $_POST['rol'] ?? '';
    if (in_array($rol, $roles_list)) {
        foreach ($modulos as $mod => $_) {
            $vals = [];
            foreach ($accion_col as $ak => $col) {
                $vals[$col] = isset($_POST["perm_{$mod}_{$ak}"]) ? 1 : 0;
            }
            $db->prepare("INSERT INTO permisos (rol,modulo,puede_ver,puede_crear,puede_editar,puede_eliminar,puede_exportar) VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE puede_ver=VALUES(puede_ver),puede_crear=VALUES(puede_crear),puede_editar=VALUES(puede_editar),puede_eliminar=VALUES(puede_eliminar),puede_exportar=VALUES(puede_exportar)")
               ->execute([$rol,$mod,$vals['puede_ver'],$vals['puede_crear'],$vals['puede_editar'],$vals['puede_eliminar'],$vals['puede_exportar']]);
        }
        // Limpiar caché de sesiones (próximo login usará nuevos permisos)
        $msg = 'Permisos de '.ucfirst($rol).' actualizados correctamente.';
        auditLog('update','permisos',"Actualización de permisos para rol: $rol");
    }
}

// Cargar permisos actuales
$permisos_db = [];
try {
    $rows = $db->query("SELECT rol,modulo,puede_ver,puede_crear,puede_editar,puede_eliminar,puede_exportar FROM permisos")->fetchAll();
    foreach ($rows as $r) $permisos_db[$r['rol']][$r['modulo']] = $r;
} catch(Exception $e) {}

// Tab activo
$tab_rol = $_GET['rol'] ?? 'veterinario';
if (!in_array($tab_rol, $roles_list)) $tab_rol = 'veterinario';

// Auditoría
$audit_log = [];
try {
    $audit_log = $db->query("SELECT * FROM auditoria ORDER BY created_at DESC LIMIT 50")->fetchAll();
} catch(Exception $e) {}

// Estadísticas de usuarios por rol
$users_by_rol = [];
try {
    $rows = $db->query("SELECT rol, COUNT(*) as n FROM usuarios WHERE activo=1 GROUP BY rol")->fetchAll();
    foreach ($rows as $r) $users_by_rol[$r['rol']] = $r['n'];
} catch(Exception $e) {}
?>

<div class="page">
<?php if($msg): ?>
<div class="alert alert-success mb-3"><span class="alert-icon">✅</span><?= clean($msg) ?></div>
<?php endif; ?>

<!-- Header -->
<div class="sec-header mb-3">
  <div>
    <div class="page-title">🔐 Roles y Permisos</div>
    <div class="page-desc">Controla qué puede ver y hacer cada rol en el sistema</div>
  </div>
</div>

<!-- Cards de roles -->
<div class="grid g4 mb-4">
  <?php
  $rol_info = [
    'admin'        => ['🔴','Administrador','Acceso total al sistema','#fee2e2','#7f1d1d'],
    'veterinario'  => ['🟢','Veterinario','Clínica y atención médica','#d1fae5','#065f46'],
    'recepcionista'=> ['🔵','Recepcionista','Citas, clientes y facturación','#dbeafe','#1e3a8a'],
    'asistente'    => ['🟡','Asistente','Soporte clínico básico','#fef3c7','#78350f'],
  ];
  foreach ($rol_info as $rol => [$ic, $lbl, $desc, $bg, $tc]):
    $n = $users_by_rol[$rol] ?? 0;
  ?>
  <div class="card" style="border-top:3px solid <?= $bg==='#fee2e2'?'#ef4444':($bg==='#d1fae5'?'#10b981':($bg==='#dbeafe'?'#3b82f6':'#f59e0b')) ?>;text-align:center">
    <div style="font-size:32px;margin-bottom:8px"><?= $ic ?></div>
    <div style="font-size:14px;font-weight:700;color:var(--text)"><?= $lbl ?></div>
    <div style="font-size:11px;color:var(--text3);margin-bottom:10px"><?= $desc ?></div>
    <div style="display:inline-flex;align-items:center;gap:5px;padding:4px 12px;background:<?= $bg ?>;color:<?= $tc ?>;border-radius:999px;font-size:12px;font-weight:700">
      👤 <?= $n ?> usuario<?= $n!=1?'s':'' ?>
    </div>
    <?php if($rol !== 'admin'): ?>
    <div style="margin-top:10px">
      <a href="?p=permisos&rol=<?= $rol ?>" class="btn btn-sm <?= $tab_rol===$rol?'btn-primary':'btn-ghost' ?>">
        <?= $tab_rol===$rol?'✏️ Editando':'Configurar' ?>
      </a>
    </div>
    <?php else: ?>
    <div style="margin-top:10px;font-size:11px;color:var(--text3);font-style:italic">Acceso total (no editable)</div>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
</div>

<!-- Editor de permisos -->
<div class="card" style="padding:0;margin-bottom:20px">
  <!-- Tabs de roles -->
  <div style="display:flex;border-bottom:1px solid var(--border);overflow-x:auto">
    <?php foreach($roles_list as $r):
      $ri = $rol_info[$r];
    ?>
    <a href="?p=permisos&rol=<?= $r ?>"
       style="padding:14px 24px;font-size:13px;font-weight:600;text-decoration:none;white-space:nowrap;
              <?= $tab_rol===$r?'color:var(--primary);border-bottom:2px solid var(--primary);background:var(--primary-l)':'color:var(--text3);border-bottom:2px solid transparent' ?>">
      <?= $ri[0] ?> <?= $ri[1] ?>
    </a>
    <?php endforeach; ?>
  </div>

  <form method="POST">
    <input type="hidden" name="action" value="save_permisos">
    <input type="hidden" name="rol" value="<?= $tab_rol ?>">

    <div style="padding:16px 20px;background:var(--bg3);border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px">
      <div>
        <div style="font-size:14px;font-weight:700"><?= $rol_info[$tab_rol][0] ?> Permisos de <?= $rol_info[$tab_rol][1] ?></div>
        <div style="font-size:12px;color:var(--text3)"><?= $rol_info[$tab_rol][2] ?></div>
        <div style="font-size:11px;color:var(--text3);margin-top:4px">
          💡 <strong>Caja</strong> controla los montos acumulados: sin “Ver” en Caja, el rol no ve
          ingresos ni egresos en el Dashboard y en Facturación solo ve los comprobantes que emitió.
        </div>
      </div>
      <div style="display:flex;gap:8px;align-items:center">
        <button type="button" onclick="marcarTodo(true)" class="btn btn-sm btn-ghost">✅ Todo ON</button>
        <button type="button" onclick="marcarTodo(false)" class="btn btn-sm btn-ghost">❌ Todo OFF</button>
        <button type="submit" class="btn btn-primary btn-sm">💾 Guardar cambios</button>
      </div>
    </div>

    <!-- Tabla de permisos -->
    <div style="overflow-x:auto">
      <table style="width:100%;border-collapse:collapse;font-size:13px">
        <thead>
          <tr style="background:var(--bg3)">
            <th style="padding:11px 16px;text-align:left;font-weight:700;color:var(--text2);border-bottom:1px solid var(--border)">Módulo</th>
            <?php foreach($acciones as $ak => $albl): ?>
            <th style="padding:11px 12px;text-align:center;font-weight:700;color:var(--text2);border-bottom:1px solid var(--border);white-space:nowrap">
              <?= ['ver'=>'👁️','crear'=>'➕','editar'=>'✏️','eliminar'=>'🗑️','exportar'=>'📤'][$ak] ?>
              <div style="font-size:10px;font-weight:600;color:var(--text3)"><?= $albl ?></div>
            </th>
            <?php endforeach; ?>
          </tr>
        </thead>
        <tbody>
          <?php
          $section_map = [
            'PRINCIPAL'  => ['dashboard','citas','solicitudes','clientes'],
            'CLÍNICA'    => ['mascotas','historial','recetas','examenes','vacunas','cirugias','hospital'],
            'SERVICIOS'  => ['grooming','petshop','servicios'],
            'INVENTARIO' => ['farmacia','inventario','compras','movimientos'],
            'GESTIÓN'    => ['facturacion','caja','reporte_pagos','cuentas','reportes','personal','plantillas','whatsapp'],
            'GANADERÍA'  => ['ganado'],
          ];
          $current_section = '';
          foreach ($section_map as $sec => $mods):
          ?>
          <tr>
            <td colspan="6" style="padding:8px 16px 4px;background:var(--bg);font-size:10px;font-weight:700;color:var(--text3);text-transform:uppercase;letter-spacing:1px;border-bottom:1px solid var(--border)"><?= $sec ?></td>
          </tr>
          <?php foreach($mods as $mod):
            $info = $modulos[$mod] ?? ['•',$mod];
            $p    = $permisos_db[$tab_rol][$mod] ?? [];
          ?>
          <tr style="border-bottom:1px solid var(--border)" onmouseover="this.style.background='var(--bg3)'" onmouseout="this.style.background=''">
            <td style="padding:11px 16px">
              <div style="display:flex;align-items:center;gap:8px">
                <span style="font-size:16px"><?= $info[0] ?></span>
                <span style="font-weight:600;color:var(--text)"><?= $info[1] ?></span>
              </div>
            </td>
            <?php foreach($accion_col as $ak => $col):
              $checked = isset($p[$col]) ? (bool)$p[$col] : ($ak==='ver'?true:false);
              // Eliminar y exportar más restringidos por defecto
              if (!isset($p[$col]) && in_array($ak,['eliminar','exportar'])) $checked = false;
            ?>
            <td style="padding:11px 12px;text-align:center">
              <label style="display:inline-flex;align-items:center;justify-content:center;cursor:pointer;width:36px;height:36px">
                <input type="checkbox" name="perm_<?= $mod ?>_<?= $ak ?>"
                       class="perm-check" data-modulo="<?= $mod ?>"
                       style="width:18px;height:18px;accent-color:var(--primary);cursor:pointer"
                       <?= $checked?'checked':'' ?>>
              </label>
            </td>
            <?php endforeach; ?>
          </tr>
          <?php endforeach; endforeach; ?>
        </tbody>
      </table>
    </div>

    <div style="padding:14px 20px;border-top:1px solid var(--border);display:flex;justify-content:flex-end">
      <button type="submit" class="btn btn-primary">💾 Guardar permisos de <?= $rol_info[$tab_rol][1] ?></button>
    </div>
  </form>
</div>

<!-- Log de auditoría -->
<?php if(!empty($audit_log)): ?>
<div class="card" style="padding:0">
  <div style="padding:14px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between">
    <div style="font-size:14px;font-weight:700">📋 Log de Auditoría</div>
    <div style="font-size:12px;color:var(--text3)">Últimas 50 acciones</div>
  </div>
  <table class="vtable">
    <thead><tr>
      <th>Fecha</th><th>Usuario</th><th>Rol</th><th>Acción</th><th>Módulo</th><th>Descripción</th><th>IP</th>
    </tr></thead>
    <tbody>
      <?php foreach($audit_log as $al): ?>
      <tr>
        <td style="white-space:nowrap;font-size:11px;color:var(--text3)"><?= date('d/m/Y H:i',strtotime($al['created_at'])) ?></td>
        <td class="td-main"><?= clean($al['usuario_nombre']??'—') ?></td>
        <td><span class="badge b-info"><?= clean($al['rol']??'—') ?></span></td>
        <td><span class="badge <?= $al['accion']==='delete'?'b-danger':($al['accion']==='create'?'b-success':'b-info') ?>"><?= clean($al['accion']??'—') ?></span></td>
        <td class="text-muted"><?= clean($al['modulo']??'—') ?></td>
        <td style="font-size:11px;color:var(--text2);max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= clean($al['descripcion']??'—') ?></td>
        <td style="font-size:11px;color:var(--text3);font-family:monospace"><?= clean($al['ip']??'—') ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

</div><!-- .page -->

<script>
function marcarTodo(val) {
  document.querySelectorAll('.perm-check').forEach(function(cb) {
    cb.checked = val;
  });
}
/* "Ver" es la puerta de entrada al módulo: el menú y el router lo consultan
   antes que nada. Sin él, crear/editar/eliminar/exportar quedan huérfanos —el
   rol nunca llega a la pantalla donde usarlos—. Por eso apagar "Ver" apaga
   todo, y prender cualquier otra acción prende "Ver". */
document.querySelectorAll('input[data-modulo]').forEach(function(cb) {
  cb.addEventListener('change', function() {
    var mod  = this.dataset.modulo;
    var caja = document.querySelectorAll('input[data-modulo="'+mod+'"]');
    if (this.name.endsWith('_ver')) {
      if (!this.checked) caja.forEach(function(c) { c.checked = false; });
      return;
    }
    if (this.checked) {
      caja.forEach(function(c) { if (c.name.endsWith('_ver')) c.checked = true; });
    }
  });
});
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
