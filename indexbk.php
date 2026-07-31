<?php
require_once __DIR__ . '/includes/config.php';
requireLogin();

$p = preg_replace('/[^a-z_]/', '', strtolower($_GET['p'] ?? 'dashboard'));
$allowed = [
  'dashboard','citas','clientes','mascotas',
  'historial','recetas','evolucion','examenes','vacunas',
  'cirugias','hospital',
  'grooming','petshop','servicios','solicitudes',
  'farmacia','inventario',
  'facturacion','plantillas','caja','personal','reportes',
  'whatsapp','portal','buscar',
  'ganado','permisos','calendario','sedes','compras','configuracion',
  'whatsapp_conexion','reporte_pagos','cuentas','movimientos'
];
if (!in_array($p, $allowed)) $p = 'dashboard';

// Verificar permiso de acceso al módulo
$modulos_sin_permiso = ['portal','buscar','evolucion','whatsapp_conexion']; // módulos sin restricción específica
if (!in_array($p, $modulos_sin_permiso)) {
    if (!canView($p)) {
        // Si no puede ver el dashboard tampoco, mandarlo al primer módulo permitido (evita bucle)
        if ($p === 'dashboard') {
            $destino = '';
            foreach ($allowed as $cand) {
                if (in_array($cand, $modulos_sin_permiso)) continue;
                if ($cand !== 'dashboard' && canView($cand)) { $destino = $cand; break; }
            }
            if ($destino !== '') { header('Location: '.BASE_URL.'/index.php?p='.$destino); exit; }
        }
        // Mostrar página de acceso denegado
        $pageTitle = 'Acceso denegado';
        require_once __DIR__ . '/includes/header.php';
        echo '
        <div style="display:flex;flex-direction:column;align-items:center;justify-content:center;min-height:50vh;text-align:center;padding:40px">
          <div style="font-size:64px;margin-bottom:16px">🔒</div>
          <div style="font-size:22px;font-weight:800;color:var(--text);margin-bottom:8px">Acceso restringido</div>
          <div style="font-size:14px;color:var(--text3);margin-bottom:24px;max-width:400px">
            Tu rol <strong>' . htmlspecialchars(getUser()['rol'] ?? '') . '</strong> no tiene permiso para acceder a este módulo.<br>
            Contacta al administrador si necesitas acceso.
          </div>
          <a href="' . BASE_URL . '/index.php?p=dashboard" class="btn btn-primary">← Ir al Dashboard</a>
        </div>';
        require_once __DIR__ . '/includes/footer.php';
        exit;
    }
}

// Manejar cambio rápido de sede desde topbar
if (isset($_GET['cambiar_sede_rapido'])) {
    $sid = (int)($_GET['sede_sel'] ?? 0);
    if ($sid === 0 && hasRole(['admin'])) {
        // Ver todas
        $_SESSION['ver_todas_sedes'] = true;
    } else {
        // Verificar que el usuario tenga acceso a esa sede
        $sedes_ok = $_SESSION['sedes_asignadas'] ?? [];
        if (hasRole(['admin']) || in_array($sid, $sedes_ok)) {
            $_SESSION['sede_id'] = $sid;
            $_SESSION['ver_todas_sedes'] = false;
            try { getDB()->prepare("UPDATE usuarios SET sede_id=? WHERE id=?")->execute([$sid,$user['id']]); }catch(Exception $e){}
            // Actualizar sesión de usuario
            $_SESSION['user']['sede_id'] = $sid;
        }
    }
    header('Location: '.BASE_URL.'/index.php?p='.$p); exit;
}

// Manejar toggle "ver todas las sedes" para admin
if (isset($_GET['toggle_sedes']) && hasRole(['admin'])) {
    $_SESSION['ver_todas_sedes'] = !($_SESSION['ver_todas_sedes'] ?? true);
    header('Location: '.BASE_URL.'/index.php?p='.($p??'dashboard')); exit;
}

$module = __DIR__ . '/modules/' . $p . '.php';
if (!file_exists($module)) $module = __DIR__ . '/modules/dashboard.php';
require $module;
