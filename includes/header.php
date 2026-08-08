<?php
requireLogin();
$user      = getUser();
$page      = $page      ?? 'dashboard';
$pageTitle = $pageTitle ?? 'Dashboard';
$db        = getDB();

// ── Alertas inteligentes ──────────────────────────────────────
$alertas_data = [];

try {
  $citas_hoy  = (int)$db->query("SELECT COUNT(*) FROM citas WHERE fecha=CURDATE() AND estado IN ('pendiente','confirmada')")->fetchColumn();
  if ($citas_hoy > 0) $alertas_data[] = ['tipo'=>'info','msg'=>"$citas_hoy cita".($citas_hoy>1?'s':'')." hoy",'link'=>'calendario','icon'=>'📅'];
} catch(Exception $e) { $citas_hoy = 0; }

try {
  try {
    $vac_venc = (int)$db->query("SELECT COUNT(*) FROM vacunas WHERE estado='aplicada' AND proxima_dosis < CURDATE()")->fetchColumn();
  } catch(Exception $e) {
    $vac_venc = (int)$db->query("SELECT COUNT(*) FROM vacunas WHERE proxima_dosis < CURDATE()")->fetchColumn();
  }
  if ($vac_venc > 0) $alertas_data[] = ['tipo'=>'danger','msg'=>"$vac_venc vacuna".($vac_venc>1?'s':'')." vencida".($vac_venc>1?'s':''),'link'=>'vacunas','icon'=>'⚠️'];
} catch(Exception $e) { $vac_venc = 0; }

try {
  try {
    $vac_prox = (int)$db->query("SELECT COUNT(*) FROM vacunas WHERE estado='aplicada' AND proxima_dosis BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 7 DAY)")->fetchColumn();
  } catch(Exception $e) {
    $vac_prox = (int)$db->query("SELECT COUNT(*) FROM vacunas WHERE proxima_dosis BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 7 DAY)")->fetchColumn();
  }
  if ($vac_prox > 0) $alertas_data[] = ['tipo'=>'warn','msg'=>"$vac_prox vacuna".($vac_prox>1?'s':'')." por vencer",'link'=>'vacunas','icon'=>'💉'];
} catch(Exception $e) { $vac_prox = 0; }

try {
  $stock_crit = (int)$db->query("SELECT COUNT(*) FROM productos WHERE stock < stock_minimo/2 AND activo=1")->fetchColumn();
  if ($stock_crit > 0) $alertas_data[] = ['tipo'=>'danger','msg'=>"$stock_crit producto".($stock_crit>1?'s':'')." stock crítico",'link'=>'farmacia','icon'=>'🚨'];
} catch(Exception $e) { $stock_crit = 0; }

try {
  $prod_venc = (int)$db->query("SELECT COUNT(*) FROM productos WHERE fecha_vencimiento BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 30 DAY) AND activo=1")->fetchColumn();
  if ($prod_venc > 0) $alertas_data[] = ['tipo'=>'warn','msg'=>"$prod_venc producto".($prod_venc>1?'s':'')." por vencer",'link'=>'farmacia','icon'=>'📦'];
} catch(Exception $e) { $prod_venc = 0; }

try {
  $hosp_emer = (int)$db->query("SELECT COUNT(*) FROM hospitalizacion WHERE estado='emergencia' AND activo=1")->fetchColumn();
  if ($hosp_emer > 0) $alertas_data[] = ['tipo'=>'danger','msg'=>"$hosp_emer emergencia".($hosp_emer>1?'s':''). " activa".($hosp_emer>1?'s':''),'link'=>'hospital','icon'=>'🚑'];
} catch(Exception $e) { $hosp_emer = 0; }

try {
  $cir_hoy = (int)$db->query("SELECT COUNT(*) FROM cirugias WHERE DATE(fecha_programada)=CURDATE() AND estado='programada'")->fetchColumn();
  if ($cir_hoy > 0) $alertas_data[] = ['tipo'=>'warn','msg'=>"$cir_hoy cirug".($cir_hoy>1?'ías':'ía')." hoy",'link'=>'cirugias','icon'=>'🔬'];
} catch(Exception $e) { $cir_hoy = 0; }

try {
  $partos_prox = (int)$db->query("SELECT COUNT(*) FROM gv_prenez WHERE resultado='prenada' AND fecha_probable_parto BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 7 DAY)")->fetchColumn();
  if ($partos_prox > 0) $alertas_data[] = ['tipo'=>'warn','msg'=>"$partos_prox parto".($partos_prox>1?'s':'')." bovino próximo",'link'=>'ganado','icon'=>'🐄'];
} catch(Exception $e) { $partos_prox = 0; }

// ── Menú lateral ─────────────────────────────────────────────
$nav = [
  ['icon'=>'⊞', 'label'=>'Dashboard',       'page'=>'dashboard',   'section'=>'PRINCIPAL'],
  ['icon'=>'🗓️', 'label'=>'Citas / Agenda',   'page'=>'calendario',  'section'=>''],
  ['icon'=>'📩', 'label'=>'Solicitudes', 'page'=>'solicitudes', 'section'=>''],
  ['icon'=>'👥', 'label'=>'Clientes',         'page'=>'clientes',    'section'=>''],
  ['icon'=>'🏥', 'label'=>'Veterinaria',      'page'=>'_vet_toggle', 'section'=>''],
  ['icon'=>'🐾', 'label'=>'Mascotas',         'page'=>'mascotas',    'section'=>'', 'sub'=>true],
  ['icon'=>'🐄', 'label'=>'Ganado Vacuno',    'page'=>'ganado',      'section'=>'', 'sub'=>true],
  ['icon'=>'🚦', 'label'=>'Triaje',           'page'=>'triaje',      'section'=>'CLÍNICA'],
  ['icon'=>'📋', 'label'=>'Historia Clínica', 'page'=>'historial',   'section'=>''],
  ['icon'=>'📋', 'label'=>'Recetas',          'page'=>'recetas',     'section'=>''],
  ['icon'=>'📈', 'label'=>'Evolución',        'page'=>'evolucion',   'section'=>''],
  ['icon'=>'🔬', 'label'=>'Exámenes',         'page'=>'examenes',    'section'=>''],
  ['icon'=>'💉', 'label'=>'Vacunación',       'page'=>'vacunas',     'section'=>''],
  ['icon'=>'✂️', 'label'=>'Cirugías',         'page'=>'cirugias',    'section'=>''],
  ['icon'=>'🚑', 'label'=>'Hospital/UCI',     'page'=>'hospital',    'section'=>''],
  ['icon'=>'✨', 'label'=>'Grooming',         'page'=>'grooming',    'section'=>'SERVICIOS'],
  ['icon'=>'🛒', 'label'=>'Pet Shop',         'page'=>'petshop',     'section'=>''],
  ['icon'=>'🏷️', 'label'=>'Servicios',         'page'=>'servicios',   'section'=>''],
  ['icon'=>'💊', 'label'=>'Farmacia',         'page'=>'farmacia',    'section'=>'INVENTARIO'],
  ['icon'=>'📦', 'label'=>'Inventario',       'page'=>'inventario',  'section'=>''],
  ['icon'=>'🛒', 'label'=>'Compras',           'page'=>'compras',     'section'=>''],
  ['icon'=>'🧾', 'label'=>'Facturación',      'page'=>'facturacion', 'section'=>'GESTIÓN'],
  ['icon'=>'🧮', 'label'=>'Notas Créd./Déb.', 'page'=>'notas_credito','section'=>'', 'perm'=>'facturacion'],
  ['icon'=>'💰', 'label'=>'Caja',             'page'=>'caja',        'section'=>''],
  ['icon'=>'💳', 'label'=>'Reporte de Pagos', 'page'=>'reporte_pagos','section'=>''],
  ['icon'=>'📋', 'label'=>'Cuentas por cobrar', 'page'=>'cuentas','section'=>''],
  ['icon'=>'📦', 'label'=>'Movimientos',         'page'=>'movimientos','section'=>''],
  ['icon'=>'📊', 'label'=>'Reportes',         'page'=>'reportes',    'section'=>''],
  ['icon'=>'👤', 'label'=>'Personal',         'page'=>'personal',    'section'=>''],
  ['icon'=>'💬', 'label'=>'WhatsApp',         'page'=>'whatsapp',    'section'=>'COMUNICACIÓN'],
  ['icon'=>'🔗', 'label'=>'Conexión WhatsApp','page'=>'whatsapp_conexion', 'section'=>''],
  ['icon'=>'🌐', 'label'=>'Portal Cliente',   'page'=>'portal',      'section'=>''],
  ['icon'=>'🔐', 'label'=>'Roles y Permisos', 'page'=>'permisos',       'section'=>''],
  ['icon'=>'🏢', 'label'=>'Multi-Sede',        'page'=>'sedes',          'section'=>''],
  ['icon'=>'⚙️', 'label'=>'Configuración',     'page'=>'configuracion',  'section'=>''],
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>VetPro — <?= clean($pageTitle) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@500;600;700;800&family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<?php
/* Cache-busting de CSS: agrega ?v=<fecha de modificación del archivo> para que
   el navegador descargue la versión nueva cada vez que editas el CSS, en lugar
   de servir una copia cacheada. Busca la carpeta public/css esté donde esté
   header.php; si no la encuentra, usa una versión manual de respaldo (súbela
   cuando edites un CSS). */
$__cssDir = null;
foreach ([dirname(__DIR__).'/public/css', __DIR__.'/public/css', $_SERVER['DOCUMENT_ROOT'].'/public/css'] as $__d) {
    if (is_dir($__d)) { $__cssDir = $__d; break; }
}
$__vMain = ($__cssDir && @filemtime("$__cssDir/main.css"))   ? filemtime("$__cssDir/main.css")   : '20260606';
$__vMob  = ($__cssDir && @filemtime("$__cssDir/mobile.css")) ? filemtime("$__cssDir/mobile.css") : '20260606';
?>
<link rel="stylesheet" href="<?= BASE_URL ?>/public/css/main.css?v=<?= $__vMain ?>">
<link rel="stylesheet" href="<?= BASE_URL ?>/public/css/mobile.css?v=<?= $__vMob ?>" media="screen and (max-width:768px)">
<style>
/* ── MOBILE OVERRIDE INLINE — sidebar drawer ──
   Sobreescribe cualquier regla vieja de main.css (@media 900px)
   que oculte textos o centre los items del sidebar en móvil */
@media (max-width:768px) {
  /* SIDEBAR: drawer fuera de pantalla */
  .sidebar {
    position:fixed !important; top:0 !important; left:0 !important;
    width:80vw !important; max-width:300px !important;
    height:100% !important; z-index:1200 !important;
    transform:translateX(-100%) !important;
    transition:transform .28s ease !important;
    overflow-y:auto !important; overflow-x:hidden !important;
    background:#0f1729 !important;
  }
  .sidebar.mob-open { transform:translateX(0) !important; box-shadow:12px 0 40px rgba(0,0,0,.5) !important; }
  /* MAIN: sin margen izquierdo, ocupa todo */
  .main { margin-left:0 !important; width:100% !important; padding-bottom:60px !important; }
  /* NAV ITEMS: alineados izquierda, texto visible */
  .nav-item {
    display:flex !important; align-items:center !important;
    justify-content:flex-start !important; gap:10px !important;
    padding:11px 14px !important; margin:3px 10px !important;
    border-radius:10px !important; font-size:13px !important;
    font-weight:500 !important; color:rgba(255,255,255,.65) !important;
    text-decoration:none !important; border:1px solid transparent !important;
    background:transparent !important; width:calc(100% - 20px) !important;
    box-sizing:border-box !important; text-align:left !important;
    text-indent:0 !important; overflow:visible !important;
  }
  .nav-item.active {
    background:rgba(30,168,161,.18) !important;
    border-color:rgba(30,168,161,.35) !important;
    color:#fff !important;
  }
  /* TEXTOS del sidebar: forzar visibles */
  .logo-text, .logo-sub, .sidebar-section,
  .nav-item span, .user-name, .user-role {
    display:block !important; visibility:visible !important;
    opacity:1 !important; width:auto !important;
    overflow:visible !important; clip:auto !important;
  }
  .nav-item span.ni {
    display:inline-flex !important; width:22px !important;
    font-size:17px !important; flex-shrink:0 !important;
    text-align:center !important; align-items:center !important;
    justify-content:center !important;
  }
  .sidebar-section {
    padding:14px 16px 4px !important; font-size:9px !important;
    letter-spacing:1.5px !important; font-weight:700 !important;
    color:rgba(255,255,255,.25) !important; text-transform:uppercase !important;
  }
  .sidebar-logo { padding:18px 16px 14px !important; display:flex !important; align-items:center !important; gap:12px !important; }
  .logo-icon { width:36px !important; height:36px !important; font-size:18px !important; flex-shrink:0 !important; }
  .logo-text { font-size:18px !important; font-weight:700 !important; color:#fff !important; }
  .logo-sub  { font-size:10px !important; color:rgba(255,255,255,.4) !important; }
  /* Overlay */
  .mob-overlay { position:fixed !important; inset:0 !important; background:rgba(0,0,0,.6) !important; z-index:1190 !important; display:none !important; }
  .mob-overlay.active { display:block !important; }
  /* Hamburger */
  .mob-menu-btn { display:flex !important; align-items:center !important; justify-content:center !important; width:38px !important; height:38px !important; border-radius:10px !important; background:var(--bg3) !important; border:1.5px solid var(--border) !important; font-size:20px !important; cursor:pointer !important; flex-shrink:0 !important; }
  /* Content — padding-bottom amplio para que el último contenido no quede tapado por la barra inferior fija (60px) ni la zona de gestos del navegador */
  .content { flex:1 !important; overflow-y:auto !important; padding:12px 12px 90px !important; -webkit-overflow-scrolling:touch !important; }
  /* Bottom nav */
  .mob-bottom-nav { display:flex !important; position:fixed !important; bottom:0 !important; left:0 !important; right:0 !important; height:60px !important; background:var(--bg2) !important; border-top:1px solid var(--border) !important; z-index:1000 !important; align-items:stretch !important; }
  .mob-nav-item { flex:1 !important; display:flex !important; flex-direction:column !important; align-items:center !important; justify-content:center !important; gap:2px !important; text-decoration:none !important; color:var(--text3) !important; font-size:9px !important; font-weight:600 !important; border:none !important; background:none !important; cursor:pointer !important; position:relative !important; -webkit-tap-highlight-color:transparent !important; }
  .mob-nav-item span { display:block !important; visibility:visible !important; opacity:1 !important; }
  .mob-nav-icon { font-size:22px !important; line-height:1 !important; }
  .mob-nav-item.active { color:var(--primary) !important; }
  /* Grids */
  .g2,.g3,.g4,.g5,.g6 { grid-template-columns:1fr !important; }
  .form-row,.form-row-3 { grid-template-columns:1fr !important; }
  input, select, textarea { font-size:16px !important; }
}

/* Tablet y escritorio (≥769px): garantizar suficiente espacio inferior en el
   área de contenido para que el final de los formularios largos (botones
   Guardar/Cancelar) y de las fichas largas (ej. ficha de la mascota) sea
   siempre alcanzable al hacer scroll. Antes la tablet tenía solo 40px y se
   cortaba el último bloque. */
@media (min-width:769px) {
  .content { padding-bottom:110px !important; }
}
</style>
</head>
<body>

<!-- OVERLAY para cerrar drawer en móvil -->
<div class="mob-overlay" id="mobOverlay" onclick="closeMobMenu()"></div>

<nav class="sidebar" id="mainSidebar">

 <?php
    $__cfg_logo = '';
    try { $__cfg_logo = trim((string) getDB()->query("SELECT valor FROM configuracion WHERE clave='logo_path' LIMIT 1")->fetchColumn()); } catch (Exception $e) {}
  ?>
  <div class="sidebar-logo">
    <div class="logo-icon" style="overflow:hidden">
      <?php if ($__cfg_logo !== ''): ?>
        <img src="<?= UPLOADS_URL.'/'.htmlspecialchars($__cfg_logo) ?>" alt="VetPro"
             style="width:100%;height:100%;object-fit:contain;background:#fff;border-radius:inherit;padding:2px"
             onerror="this.style.display='none';this.parentNode.textContent='🐾'">
      <?php else: ?>🐾<?php endif; ?>
    </div>
    <div>
      <div class="logo-text">VetPro</div>
      <div class="logo-sub">Sistema Veterinario</div>
    </div>
  </div>

  <?php
  $lastSec    = '';
  $_vet_open  = in_array($page, ['mascotas','ganado']);
  $in_vet_sub = false;

  foreach ($nav as $item) {
    // Ocultar del menú los módulos que el rol no puede ver (admin ve todo).
    $pg = $item['page'] ?? '';
    // 'perm' permite que un módulo se apoye en el permiso de otro (ej. notas de crédito → facturación).
    $pg_perm = $item['perm'] ?? $pg;
    $_sin_check = ['_vet_toggle','portal','buscar','evolucion','whatsapp_conexion','triaje'];
    if ($pg !== '' && !in_array($pg, $_sin_check, true) && function_exists('canView') && !canView($pg_perm)) {
        continue;
    }
    // Sección
    if (!empty($item['section']) && $item['section'] !== $lastSec) {
      if ($in_vet_sub) { echo '</div>'; $in_vet_sub = false; }
      $lastSec = $item['section'];
      echo '<div class="sidebar-section">' . htmlspecialchars($item['section']) . '</div>';
    }

    // Toggle Veterinaria
    if ($item['page'] === '_vet_toggle') {
      $bg = $_vet_open ? 'background:rgba(255,255,255,.08);' : '';
      echo '<div class="nav-item" onclick="toggleVetMenu()" style="cursor:pointer;' . $bg . '">';
      echo '<span class="ni">' . $item['icon'] . '</span>';
      echo $item['label'];
      echo '<span id="vet-caret" style="margin-left:auto;font-size:10px">' . ($_vet_open ? '∧' : '∨') . '</span>';
      echo '</div>';
      echo '<div id="vet-submenu" style="display:' . ($_vet_open ? 'block' : 'none') . '">';
      $in_vet_sub = true;
      continue;
    }

    // Sub-items
    if (!empty($item['sub'])) {
      $active = $page === $item['page'] ? 'active' : '';
      echo '<a href="' . BASE_URL . '/index.php?p=' . $item['page'] . '" class="nav-item ' . $active . '" ';
      echo 'style="margin-left:18px;padding-left:12px;border-left:2px solid rgba(255,255,255,.1)">';
      echo '<span class="ni">' . $item['icon'] . '</span>';
      echo htmlspecialchars($item['label']);
      echo '</a>';
      continue;
    }

    // Cerrar submenu antes de item normal
    if ($in_vet_sub) { echo '</div>'; $in_vet_sub = false; }

    // Badge
    $badge = 0;
    if ($item['page'] === 'calendario') $badge = $citas_hoy;
    if ($item['page'] === 'farmacia') $badge = $stock_crit;
    if ($item['page'] === 'hospital') $badge = $hosp_emer;

    $active = $page === $item['page'] ? 'active' : '';
    echo '<a href="' . BASE_URL . '/index.php?p=' . $item['page'] . '" class="nav-item ' . $active . '">';
    echo '<span class="ni">' . $item['icon'] . '</span>';
    echo htmlspecialchars($item['label']);
    if ($badge > 0) echo '<span class="nav-badge">' . $badge . '</span>';
    echo '</a>';
  }
  if ($in_vet_sub) echo '</div>';
  ?>

  <div class="sidebar-footer">
    <div class="user-info-wrap">
      <?php
      $n   = $user['nombre'] ?? 'U';
      $ini = strtoupper(substr($n, 0, 1));
      $pos = strpos($n, ' ');
      if ($pos !== false) $ini .= strtoupper(substr($n, $pos + 1, 1));
      ?>
      <div class="avatar"><?= $ini ?></div>
      <div>
        <div class="user-name"><?= clean(explode(' ', $user['nombre'])[0]) ?></div>
        <div class="user-role"><?= ucfirst($user['rol']) ?></div>
      </div>
      <a href="<?= BASE_URL ?>/logout.php" class="logout-btn" title="Salir">⏻</a>
    </div>
  </div>
</nav>

<div class="main">
  <div class="topbar">
    <!-- Hamburger (solo móvil, mostrado via CSS media query) -->
    <button class="mob-menu-btn" id="mobMenuBtn" onclick="openMobMenu()" aria-label="Menú" style="display:none">
      ☰
    </button>
    <div>
      <?php
      $sede_nombre_top = '';
      try {
        $sn = $db->prepare("SELECT nombre,color FROM sedes WHERE id=?");
        $sn->execute([getSede()]);
        $sn_row = $sn->fetch();
        $sede_nombre_top = $sn_row['nombre'] ?? '';
        $sede_color_top  = $sn_row['color'] ?? '#1ea8a1';
      } catch(Exception $e){ $sede_color_top='#1ea8a1'; }
      $es_admin = hasRole(['admin']);
      $ver_todas = $es_admin && ($_SESSION['ver_todas_sedes'] ?? true);
      $sedes_usuario = $_SESSION['sedes_asignadas'] ?? [];
      $tiene_multi_sede = count($sedes_usuario) > 1;
      ?>
      <div style="display:flex;align-items:center;gap:6px">
        <?php if($tiene_multi_sede || $es_admin): ?>
        <!-- Selector de sede para usuarios con acceso a múltiples sedes -->
        <form method="GET" action="<?= BASE_URL ?>/index.php" style="display:flex;gap:4px;align-items:center">
          <input type="hidden" name="p" value="<?= $page ?>">
          <input type="hidden" name="cambiar_sede_rapido" value="1">
          <select name="sede_sel" onchange="this.form.submit()"
            style="padding:4px 8px;border-radius:8px;font-size:11px;font-weight:700;border:1.5px solid <?= $sede_color_top ?>;background:<?= $sede_color_top ?>15;color:<?= $sede_color_top ?>;cursor:pointer;outline:none;max-width:160px">
            <?php
            $todas_sedes_list=[];
            try { $todas_sedes_list=$db->query("SELECT id,nombre FROM sedes ORDER BY nombre")->fetchAll(); }catch(Exception $e){}
            foreach($todas_sedes_list as $sl):
              $visible = $es_admin || in_array($sl['id'],$sedes_usuario);
              if(!$visible) continue;
            ?>
            <option value="<?= $sl['id'] ?>" <?= $sl['id']==getSede()?'selected':'' ?>>🏢 <?= clean($sl['nombre']) ?></option>
            <?php endforeach; ?>
            <?php if($es_admin): ?>
            <option value="0" <?= $ver_todas?'selected':'' ?>>🌐 Todas las sedes</option>
            <?php endif; ?>
          </select>
        </form>
        <?php else: ?>
        <div style="display:flex;align-items:center;gap:5px;padding:4px 10px;border-radius:8px;font-size:11px;font-weight:700;background:<?= $sede_color_top ?>15;color:<?= $sede_color_top ?>;border:1.5px solid <?= $sede_color_top ?>40">
          🏢 <?= clean($sede_nombre_top) ?>
        </div>
        <?php endif; ?>
      </div>
      <div class="topbar-title"><?= clean($pageTitle) ?></div>
    </div>

    <!-- Buscador global -->
    <div class="search-box" id="globalSearchWrap" style="position:relative">
      <span class="search-icon" style="pointer-events:none">🔍</span>
      <input type="text" id="globalSearchInput"
             placeholder="Buscar paciente, cliente..."
             value="<?= clean($_GET['q'] ?? '') ?>"
             autocomplete="off"
             oninput="gsAutoComplete(this.value)"
             onkeydown="gsKeyDown(event)"
             onfocus="if(this.value.length>=2) gsAutoComplete(this.value)">
      <div id="gsDropdown" style="display:none;position:absolute;top:calc(100% + 6px);left:0;right:0;
        background:var(--bg2);border:1px solid var(--border);border-radius:12px;
        box-shadow:0 8px 32px rgba(0,0,0,.12);z-index:2000;max-height:380px;
        overflow-y:auto;min-width:340px;"></div>
    </div>

    <div class="topbar-actions">
      <?php
      $alert_styles = [
        'danger' => ['bg'=>'#fef2f2','border'=>'#fca5a5','color'=>'#991b1b','dot'=>'#ef4444'],
        'warn'   => ['bg'=>'#fffbeb','border'=>'#fcd34d','color'=>'#92400e','dot'=>'#f59e0b'],
        'info'   => ['bg'=>'#eff6ff','border'=>'#93c5fd','color'=>'#1e40af','dot'=>'#3b82f6'],
      ];
      foreach (array_slice($alertas_data, 0, 3) as $al):
        $st2 = $alert_styles[$al['tipo']] ?? $alert_styles['info'];
      ?>
      <a href="<?= BASE_URL ?>/index.php?p=<?= $al['link'] ?>" title="Ver detalles" class="mob-hide"
         style="display:inline-flex;align-items:center;gap:6px;padding:5px 11px;border-radius:999px;
                text-decoration:none;background:<?= $st2['bg'] ?>;border:1.5px solid <?= $st2['border'] ?>;
                color:<?= $st2['color'] ?>;font-size:12px;font-weight:600;white-space:nowrap"
         onmouseover="this.style.opacity='.8'" onmouseout="this.style.opacity='1'">
        <span style="width:7px;height:7px;border-radius:50%;background:<?= $st2['dot'] ?>;flex-shrink:0"></span>
        <?= $al['icon'] ?> <?= clean($al['msg']) ?>
      </a>
      <?php endforeach; ?>

      <!-- 🔔 CAMPANA DE NOTIFICACIONES -->
      <div style="position:relative" id="notif-wrap">
        <button id="notif-btn" onclick="toggleNotifPanel()"
          style="width:38px;height:38px;border-radius:10px;background:var(--bg3);border:1.5px solid var(--border);
                 display:flex;align-items:center;justify-content:center;font-size:18px;cursor:pointer;
                 position:relative;transition:all .15s;color:var(--text2)"
          onmouseover="this.style.borderColor='var(--primary)';this.style.color='var(--primary)'"
          onmouseout="this.style.borderColor='var(--border)';this.style.color='var(--text2)'"
          title="Notificaciones">
          🔔
          <span id="notif-badge" style="display:none;position:absolute;top:-4px;right:-4px;
                background:#ef4444;color:#fff;font-size:10px;font-weight:700;padding:1px 5px;
                border-radius:999px;min-width:18px;text-align:center;border:2px solid var(--bg2)">0</span>
        </button>

        <!-- Panel de notificaciones -->
        <div id="notif-panel" style="display:none;position:absolute;top:calc(100% + 8px);right:0;
             width:360px;max-width:95vw;background:var(--bg2);border:1px solid var(--border);
             border-radius:14px;box-shadow:0 8px 40px rgba(0,0,0,.15);z-index:2000;overflow:hidden">
          <!-- Header del panel -->
          <div style="padding:14px 16px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;background:var(--bg3)">
            <div style="font-size:14px;font-weight:700;color:var(--text)">🔔 Notificaciones</div>
            <div style="display:flex;gap:6px">
              <button onclick="marcarTodasLeidas()" style="font-size:11px;padding:4px 10px;border-radius:6px;background:var(--primary-l);color:var(--primary-d);border:1px solid var(--primary);cursor:pointer;font-weight:600">✓ Marcar todas</button>
              <button onclick="toggleNotifPanel()" style="font-size:16px;background:none;border:none;cursor:pointer;color:var(--text3);line-height:1">✕</button>
            </div>
          </div>
          <!-- Lista notificaciones -->
          <div id="notif-list" style="max-height:400px;overflow-y:auto">
            <div style="padding:32px;text-align:center;color:var(--text3)">
              <div style="font-size:28px;margin-bottom:8px">🔔</div>
              <div style="font-size:13px">Cargando notificaciones...</div>
            </div>
          </div>
          <!-- Footer -->
          <div style="padding:10px 16px;border-top:1px solid var(--border);background:var(--bg3);text-align:center">
            <a href="<?= BASE_URL ?>/index.php?p=dashboard" style="font-size:12px;color:var(--primary);text-decoration:none;font-weight:600">Ver todas las alertas →</a>
          </div>
        </div>
      </div>

      <a href="<?= BASE_URL ?>/index.php?p=citas&action=nueva" class="btn btn-primary btn-sm">＋ Nueva Atención</a>
    </div>
  </div>

<script>
/* Submenú "Veterinaria" (Mascotas / Ganado vacuno):
   El onclick inline llama a toggleVetMenu(), que vive en un JS externo y en
   móvil no responde. Aquí enganchamos el clic directamente sobre el item, sin
   depender de esa función, para que el desplegable funcione siempre. */
(function () {
  function initVetToggle() {
    var caret = document.getElementById('vet-caret');
    var toggle = caret ? caret.closest('.nav-item') : null;
    var submenu = document.getElementById('vet-submenu');
    if (!toggle || !submenu) return;
    if (toggle.dataset.vetBound === '1') return;   // evitar doble enganche
    toggle.dataset.vetBound = '1';
    toggle.removeAttribute('onclick');             // quitar el handler roto/ausente
    toggle.style.cursor = 'pointer';
    toggle.addEventListener('click', function (e) {
      e.preventDefault();
      e.stopPropagation();
      var oculto = getComputedStyle(submenu).display === 'none';
      submenu.style.display = oculto ? 'block' : 'none';
      if (caret) caret.textContent = oculto ? '∧' : '∨';
    });
  }
  // Define también el global por si algún onclick inline lo invoca
  window.toggleVetMenu = function () {
    var submenu = document.getElementById('vet-submenu');
    var caret = document.getElementById('vet-caret');
    if (!submenu) return;
    var oculto = getComputedStyle(submenu).display === 'none';
    submenu.style.display = oculto ? 'block' : 'none';
    if (caret) caret.textContent = oculto ? '∧' : '∨';
  };
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initVetToggle);
  } else {
    initVetToggle();
  }
})();
</script>

  <div class="content">
