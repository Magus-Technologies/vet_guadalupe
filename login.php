<?php
require_once __DIR__ . '/includes/config.php';
if (isLogged()) { header('Location: ' . BASE_URL . '/index.php'); exit; }

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $pass  = trim($_POST['password'] ?? '');
    if ($email && $pass) {
        $db = getDB();
        $st = $db->prepare("SELECT * FROM usuarios WHERE email = ? AND activo = 1 LIMIT 1");
        $st->execute([$email]);
        $u = $st->fetch();
        if ($u && password_verify($pass, $u['password'])) {
            $_SESSION['user'] = $u;
            $_SESSION['sede_id'] = $u['sede_id'] ?? 1;
            try {
                $us = $db->prepare("SELECT sede_id FROM usuario_sedes WHERE usuario_id=?");
                $us->execute([$u['id']]);
                $sedes_asignadas = array_column($us->fetchAll(), 'sede_id');
                $_SESSION['sedes_asignadas'] = !empty($sedes_asignadas) ? $sedes_asignadas : [$u['sede_id'] ?? 1];
            } catch(Exception $e) { $_SESSION['sedes_asignadas'] = [$u['sede_id'] ?? 1]; }
            if ($u['rol'] === 'admin') $_SESSION['ver_todas_sedes'] = true;
            if (!isset($_SESSION['migracion_sede_ok'])) {
                foreach (['ventas','citas','clientes','mascotas','consultas','productos','compras','petshop_productos'] as $tbl) {
                    try {
                        $cols = $db->query("SHOW COLUMNS FROM `$tbl` LIKE 'sede_id'")->fetchAll();
                        if (empty($cols)) $db->exec("ALTER TABLE `$tbl` ADD COLUMN sede_id INT DEFAULT 1");
                        else $db->exec("UPDATE `$tbl` SET sede_id=1 WHERE (sede_id IS NULL OR sede_id=0)");
                    } catch(Exception $e) {}
                }
                $_SESSION['migracion_sede_ok'] = true;
            }
            $db->prepare("UPDATE usuarios SET ultimo_login = NOW() WHERE id = ?")->execute([$u['id']]);
            header('Location: ' . BASE_URL . '/index.php'); exit;
        } else { $error = 'Correo o contraseña incorrectos.'; }
    } else { $error = 'Completa todos los campos.'; }
}

// Cargar logo y nombre desde configuración
$db_cfg = getDB();
$cfg_logo = ''; $cfg_nombre = 'VetMagus'; $cfg_ruc = '';
try {
    $rows = $db_cfg->query("SELECT clave,valor FROM configuracion WHERE clave IN ('logo_path','clinica_nombre','clinica_ruc')")->fetchAll();
    foreach ($rows as $r) {
        if ($r['clave']==='logo_path')     $cfg_logo   = $r['valor'];
        if ($r['clave']==='clinica_nombre') $cfg_nombre = $r['valor'];
        if ($r['clave']==='clinica_ruc')   $cfg_ruc    = $r['valor'];
    }
} catch(Exception $e) {}
?><!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($cfg_nombre) ?> — Iniciar Sesión</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<style>
*{margin:0;padding:0;box-sizing:border-box}
body {
    font-family:'Poppins',sans-serif;
    min-height:100vh;
    display:flex;
    background:#0E3B2E;
    overflow:hidden;
}

/* ── Panel izquierdo: foto de fondo ── */
.left-panel {
    flex:1;
    position:relative;
    overflow:hidden;
    min-height:100vh;
}
.left-panel::before {
    content:'';
    position:absolute;inset:0;
    background: linear-gradient(135deg, rgba(14,59,46,.85) 0%, rgba(26,250,99,.08) 100%);
    z-index:1;
}
.left-bg {
    width:100%;height:100%;
    object-fit:cover;
    filter:brightness(.85);
}
.left-content {
    position:absolute;inset:0;z-index:2;
    display:flex;flex-direction:column;
    align-items:center;justify-content:center;
    padding:40px;
    text-align:center;
}
.brand-logo {
    width:100px;height:100px;
    object-fit:contain;
    border-radius:20px;
    background:#fff;
    padding:8px;
    box-shadow:0 8px 32px rgba(0,0,0,.3);
    margin-bottom:20px;
}
.brand-logo-placeholder {
    width:100px;height:100px;
    background:linear-gradient(135deg,#1FA463,#0E3B2E);
    border-radius:20px;
    display:flex;align-items:center;justify-content:center;
    font-size:44px;
    box-shadow:0 8px 32px rgba(0,0,0,.3);
    margin-bottom:20px;
}
.brand-name {
    font-size:42px;font-weight:800;
    color:#fff;
    letter-spacing:-1px;
    text-shadow:0 2px 16px rgba(0,0,0,.3);
    line-height:1;
}
.brand-name span { color:#34C759; }
.brand-sub {
    font-size:14px;font-weight:400;
    color:rgba(255,255,255,.8);
    margin-top:8px;
    letter-spacing:1px;
}
.brand-divider {
    display:flex;align-items:center;gap:10px;
    margin:20px 0;
    color:rgba(255,255,255,.6);
    font-size:11px;font-weight:600;letter-spacing:2px;
}
.brand-divider::before,.brand-divider::after {
    content:'';flex:1;height:1px;background:rgba(255,255,255,.3);
}
.brand-tag {
    background:rgba(52,199,89,.9);
    color:#fff;
    font-size:12px;font-weight:700;
    padding:6px 20px;border-radius:999px;
    letter-spacing:1px;
}
.stats-row {
    display:flex;gap:24px;margin-top:40px;
}
.stat-item {
    text-align:center;
}
.stat-icon { font-size:28px;margin-bottom:4px; }
.stat-label { font-size:11px;color:rgba(255,255,255,.7);font-weight:500; }
.stat-val { font-size:18px;font-weight:700;color:#34C759; }

/* ── Panel derecho: formulario ── */
.right-panel {
    width:480px;min-width:380px;
    background:#fff;
    display:flex;flex-direction:column;
    align-items:center;justify-content:center;
    padding:48px 40px;
    position:relative;
    overflow-y:auto;
}
.login-title {
    font-size:28px;font-weight:700;
    color:#1A1F2C;
    margin-bottom:6px;text-align:center;
}
.login-sub {
    font-size:13px;color:#657176;
    text-align:center;margin-bottom:32px;
}
.input-group {
    width:100%;margin-bottom:16px;position:relative;
}
.input-icon {
    position:absolute;left:14px;top:50%;transform:translateY(-50%);
    width:36px;height:36px;
    background:linear-gradient(135deg,#1FA463,#0E3B2E);
    border-radius:8px;
    display:flex;align-items:center;justify-content:center;
    font-size:16px;
}
.input-group input {
    width:100%;
    padding:14px 44px 14px 58px;
    border:1.5px solid #E8F5EE;
    border-radius:10px;
    font-size:14px;
    font-family:'Poppins',sans-serif;
    color:#1A1F2C;
    background:#f8fdfb;
    outline:none;
    transition:all .15s;
}
.input-group input:focus {
    border-color:#1FA463;
    background:#fff;
    box-shadow:0 0 0 3px rgba(31,164,99,.1);
}
.input-group input::placeholder { color:#aab8b4; }
.toggle-pass {
    position:absolute;right:14px;top:50%;transform:translateY(-50%);
    background:none;border:none;cursor:pointer;
    font-size:18px;color:#657176;
    padding:4px;
}
.remember-row {
    width:100%;display:flex;align-items:center;justify-content:space-between;
    margin-bottom:20px;
}
.remember-row label {
    display:flex;align-items:center;gap:8px;
    font-size:13px;color:#657176;cursor:pointer;
}
.remember-row label input[type=checkbox] {
    width:16px;height:16px;
    accent-color:#1FA463;
    cursor:pointer;
}
.forgot-link { font-size:13px;color:#1FA463;text-decoration:none;font-weight:500; }
.forgot-link:hover { text-decoration:underline; }
.btn-login {
    width:100%;
    padding:14px;
    background:linear-gradient(135deg,#1FA463,#34C759);
    color:#fff;border:none;border-radius:10px;
    font-size:14px;font-weight:700;
    font-family:'Poppins',sans-serif;
    cursor:pointer;
    display:flex;align-items:center;justify-content:center;gap:10px;
    letter-spacing:.5px;text-transform:uppercase;
    transition:all .2s;
    box-shadow:0 4px 16px rgba(31,164,99,.35);
}
.btn-login:hover { transform:translateY(-1px);box-shadow:0 6px 20px rgba(31,164,99,.45); }
.btn-arrow {
    width:32px;height:32px;border-radius:50%;
    background:rgba(255,255,255,.25);
    display:flex;align-items:center;justify-content:center;
    font-size:16px;
}
.error-box {
    width:100%;
    background:#fef2f2;border:1px solid #fca5a5;
    color:#b91c1c;border-radius:8px;
    padding:10px 14px;font-size:13px;
    margin-bottom:16px;
}
.footer-copy {
    position:absolute;bottom:24px;
    text-align:center;
    font-size:12px;color:#9aadaa;
}
.footer-copy a {
    color:#1FA463;font-weight:600;text-decoration:none;
}
.footer-copy a:hover { text-decoration:underline; }
.paw-deco {
    position:absolute;bottom:20px;left:20px;
    font-size:60px;opacity:.05;transform:rotate(-20deg);
    pointer-events:none;
}

@media(max-width:768px){
    .left-panel{display:none}
    .right-panel{width:100%;min-width:unset;padding:32px 24px}
    .mobile-brand{display:flex!important}
}
</style>
</head>
<body>

<!-- Panel izquierdo con imagen -->
<div class="left-panel">
    <!-- Imagen de fondo usando Unsplash (veterinaria) -->
    <img class="left-bg"
         src="https://magus-ecommerce.com/vet_guadalupe/public/img/banner.jpg"
         alt="Veterinaria"
         onerror="this.style.display='none'">
    <div class="left-content">
        <!-- Logo desde configuración -->
        <?php if($cfg_logo): ?>
        <img class="brand-logo"
             src="<?= UPLOADS_URL . '/' . htmlspecialchars($cfg_logo) ?>"
             alt="<?= htmlspecialchars($cfg_nombre) ?>"
             onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
        <div class="brand-logo-placeholder" style="display:none">🐾</div>
        <?php else: ?>
        <div class="brand-logo-placeholder">🐾</div>
        <?php endif; ?>

        <div class="brand-name">
            <?php
            // Dividir nombre en dos partes para colorear la segunda
            $parts = explode(' ', $cfg_nombre, 2);
            echo htmlspecialchars($parts[0]);
            if (isset($parts[1])) echo '<span> '.htmlspecialchars($parts[1]).'</span>';
            ?>
        </div>
        <div class="brand-sub">Sistema de Gestión Veterinaria</div>

        <div class="brand-divider">VACUNO</div>
        <div class="brand-tag"><?= htmlspecialchars($cfg_nombre) ?></div>

        <div class="stats-row">
            <div class="stat-item">
                <div class="stat-icon">🐾</div>
                <div class="stat-val">70%</div>
                <div class="stat-label">Mascotas<br>Perros y Gatos</div>
            </div>
            <div class="stat-item">
                <div class="stat-icon">🐄</div>
                <div class="stat-val">30%</div>
                <div class="stat-label">Ganado<br>Vacuno</div>
            </div>
            <div class="stat-item">
                <div class="stat-icon">📊</div>
                <div class="stat-val">100%</div>
                <div class="stat-label">Control y<br>Reportes</div>
            </div>
        </div>
    </div>
    <div class="paw-deco">🐾</div>
</div>

<!-- Panel derecho con formulario -->
<div class="right-panel">
    <div style="width:100%;max-width:360px">

        <!-- Logo para móvil (oculto en desktop) -->
        <div class="mobile-brand" style="display:none;flex-direction:column;align-items:center;margin-bottom:28px">
            <?php if($cfg_logo): ?>
            <img src="<?= UPLOADS_URL . '/' . htmlspecialchars($cfg_logo) ?>"
                 alt="<?= htmlspecialchars($cfg_nombre) ?>"
                 style="width:72px;height:72px;object-fit:contain;border-radius:16px;background:linear-gradient(135deg,#1FA463,#0E3B2E);padding:6px;box-shadow:0 4px 16px rgba(31,164,99,.3);margin-bottom:12px"
                 onerror="this.style.display='none';document.getElementById('mob-icon').style.display='flex'">
            <div id="mob-icon" style="display:none;width:72px;height:72px;background:linear-gradient(135deg,#1FA463,#0E3B2E);border-radius:16px;align-items:center;justify-content:center;font-size:36px;margin-bottom:12px;box-shadow:0 4px 16px rgba(31,164,99,.3)">🐾</div>
            <?php else: ?>
            <div style="width:72px;height:72px;background:linear-gradient(135deg,#1FA463,#0E3B2E);border-radius:16px;display:flex;align-items:center;justify-content:center;font-size:36px;margin-bottom:12px;box-shadow:0 4px 16px rgba(31,164,99,.3)">🐾</div>
            <?php endif; ?>
            <div style="font-size:26px;font-weight:800;color:#1A1F2C;letter-spacing:-0.5px">
                <?= htmlspecialchars($parts[0]??$cfg_nombre) ?><span style="color:#1FA463"><?= isset($parts[1])?' '.htmlspecialchars($parts[1]):'' ?></span>
            </div>
            <div style="font-size:12px;color:#657176;margin-top:2px;letter-spacing:1px">Sistema de Gestión Veterinaria</div>
        </div>

        <div class="login-title">Inicia sesión</div>
        <div class="login-sub">Accede a tu cuenta</div>

        <?php if($error): ?>
        <div class="error-box">⚠️ <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST">
            <!-- Usuario / Email -->
            <div class="input-group">
                <div class="input-icon">👤</div>
                <input type="email" name="email"
                       value="<?= htmlspecialchars($_POST['email']??'') ?>"
                       placeholder="Usuario" required autocomplete="email" autofocus>
            </div>
            <!-- Contraseña -->
            <div class="input-group">
                <div class="input-icon">🔒</div>
                <input type="password" name="password" id="pwd-inp"
                       placeholder="Contraseña" required autocomplete="current-password">
                <button type="button" class="toggle-pass" onclick="togglePwd()" title="Ver contraseña">
                    <span id="pwd-eye">👁️</span>
                </button>
            </div>

            <div class="remember-row">
                <label>
                    <input type="checkbox" name="remember"> Recordarme
                </label>
                <a href="#" class="forgot-link">¿Olvidaste tu contraseña?</a>
            </div>

            <button type="submit" class="btn-login">
                INGRESAR
                <div class="btn-arrow">→</div>
            </button>
        </form>
    </div>

    <!-- Footer -->
    <div class="footer-copy">
        © <?= date('Y') ?> Todos los derechos reservados<br>
        Elaborado por <a href="https://magustechnologies.com" target="_blank" rel="noopener">MagusTechnologies</a> ↗
    </div>
</div>

<script>
function togglePwd() {
    var inp = document.getElementById('pwd-inp');
    var eye = document.getElementById('pwd-eye');
    if (inp.type === 'password') {
        inp.type = 'text';
        eye.textContent = '🙈';
    } else {
        inp.type = 'password';
        eye.textContent = '👁️';
    }
}
</script>
</body>
</html>
