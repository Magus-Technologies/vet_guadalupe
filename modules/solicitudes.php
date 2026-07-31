<?php
$page = 'solicitudes'; $pageTitle = 'Solicitudes de cita';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/wa_notify.php';
$db = getDB();
$action = $_GET['action'] ?? 'list';

// ── Migración idempotente (por si el módulo se abre antes que reservar.php) ──
try {
    $db->exec("CREATE TABLE IF NOT EXISTS solicitudes_cita (
        id INT AUTO_INCREMENT PRIMARY KEY, sede_id INT DEFAULT 1, dni VARCHAR(8) NULL, servicio_id INT NULL, tipo VARCHAR(40) DEFAULT 'consulta',
        dueno_nombre VARCHAR(150) NOT NULL, dueno_telefono VARCHAR(30) NOT NULL, dueno_email VARCHAR(150) NULL,
        mascota_nombre VARCHAR(100) NOT NULL, mascota_especie VARCHAR(30) DEFAULT 'perro',
        fecha_preferida DATE NOT NULL, hora_preferida TIME NULL, motivo TEXT NULL,
        estado ENUM('pendiente','aceptada','rechazada') NOT NULL DEFAULT 'pendiente', respuesta VARCHAR(255) NULL,
        cliente_id INT NULL, mascota_id INT NULL, veterinario_id INT NULL, cita_id INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NULL DEFAULT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $c = $db->query("SHOW COLUMNS FROM solicitudes_cita LIKE 'dni'")->fetchAll();
    if (empty($c)) $db->exec("ALTER TABLE solicitudes_cita ADD COLUMN dni VARCHAR(8) NULL AFTER sede_id");
    // origen en citas: distinguir reservas web de las agendadas en clínica
    $co = $db->query("SHOW COLUMNS FROM citas LIKE 'origen'")->fetchAll();
    if (empty($co)) $db->exec("ALTER TABLE citas ADD COLUMN origen ENUM('interno','web') NOT NULL DEFAULT 'interno'");
} catch (Exception $e) {}

$cfg     = $db->query("SELECT clave,valor FROM configuracion")->fetchAll(PDO::FETCH_KEY_PAIR);
$clinica = trim($cfg['nombre_clinica'] ?? $cfg['clinica_nombre'] ?? 'VetPro') ?: 'VetPro';

// ════════════════════════════════════════════════════════════════
// Acciones que redirigen: ANTES de imprimir el header (PRG + flash)
// ════════════════════════════════════════════════════════════════
// ¿El veterinario ya tiene una cita que se solapa a esa hora?
function _vetOcupado(PDO $db, int $vet, string $fecha, string $hora, int $dur): bool {
    try {
        $ini = substr($hora,0,5).':00';
        $st = $db->prepare("SELECT COUNT(*) FROM citas
            WHERE veterinario_id=? AND fecha=? AND estado IN ('pendiente','confirmada')
              AND ? < ADDTIME(hora, SEC_TO_TIME(duracion_minutos*60))
              AND hora < ADDTIME(?, SEC_TO_TIME(?*60))");
        $st->execute([$vet,$fecha,$ini,$ini,$dur]);
        return (int)$st->fetchColumn() > 0;
    } catch (Exception $e) { return false; }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'aceptar') {
    $sid   = (int)($_POST['id'] ?? 0);
    $vetid = (int)($_POST['veterinario_id'] ?? 0);
    $fecha = trim($_POST['fecha'] ?? '');
    $hora  = trim($_POST['hora'] ?? '') ?: '09:00';
    $dur   = max(10, (int)($_POST['duracion'] ?? 30));

    $sol = $db->prepare("SELECT * FROM solicitudes_cita WHERE id=? AND estado='pendiente'");
    $sol->execute([$sid]); $sol = $sol->fetch();

    if (!$sol)            { $_SESSION['flash']='error:La solicitud no existe o ya fue procesada.'; }
    elseif (!$vetid)      { $_SESSION['flash']='error:Debes asignar un veterinario para aceptar.'; }
    elseif ($fecha==='')  { $_SESSION['flash']='error:Confirma la fecha de la cita.'; }
    elseif (_vetOcupado($db, $vetid, $fecha, $hora, $dur)) { $_SESSION['flash']='error:Ese veterinario ya tiene una cita en ese horario ('.date('d/m/Y',strtotime($fecha)).' '.substr($hora,0,5).'). Elige otra hora u otro veterinario.'; }
    else {
        try {
            $db->beginTransaction();
            // 1) Cliente: buscar por DNI (llave real), luego por teléfono; si no, crear
            $dni     = preg_replace('/\D/','', $sol['dni'] ?? '');
            $telnorm = preg_replace('/[^0-9]/','',$sol['dueno_telefono']);
            $cliente_id = 0;
            if (strlen($dni) === 8) {
                $c = $db->prepare("SELECT id FROM clientes WHERE dni=? AND activo=1 LIMIT 1");
                $c->execute([$dni]); $cliente_id = (int)($c->fetchColumn() ?: 0);
            }
            if (!$cliente_id && $telnorm !== '') {
                $c = $db->prepare("SELECT id FROM clientes WHERE REPLACE(REPLACE(REPLACE(telefono,' ',''),'-',''),'+','')=? AND activo=1 LIMIT 1");
                $c->execute([$telnorm]); $cliente_id = (int)($c->fetchColumn() ?: 0);
            }
            if (!$cliente_id) {
                $db->prepare("INSERT INTO clientes (sede_id,nombre,dni,telefono,email,como_conocio,activo) VALUES (?,?,?,?,?, 'otro',1)")
                   ->execute([$sol['sede_id'],$sol['dueno_nombre'],($dni?:null),$sol['dueno_telefono'],($sol['dueno_email']?:null)]);
                $cliente_id = (int)$db->lastInsertId();
            } elseif (strlen($dni) === 8) {
                // Si el cliente existía (por teléfono) pero sin DNI, lo completamos
                try { $db->prepare("UPDATE clientes SET dni=? WHERE id=? AND (dni IS NULL OR dni='')")->execute([$dni,$cliente_id]); } catch(Exception $e){}
            }
            // 2) Mascota: misma del cliente por nombre (no duplica); si no, crear
            $mas = $db->prepare("SELECT id FROM mascotas WHERE cliente_id=? AND TRIM(LOWER(nombre))=TRIM(LOWER(?)) LIMIT 1");
            $mas->execute([$cliente_id,$sol['mascota_nombre']]); $mascota_id = (int)($mas->fetchColumn() ?: 0);
            if (!$mascota_id) {
                $db->prepare("INSERT INTO mascotas (cliente_id,sede_id,nombre,especie,estado) VALUES (?,?,?,?, 'activo')")
                   ->execute([$cliente_id,$sol['sede_id'],$sol['mascota_nombre'],$sol['mascota_especie']]);
                $mascota_id = (int)$db->lastInsertId();
            }
            // 3) Crear la cita CONFIRMADA (normalizar tipo al ENUM de citas)
            $tipos_cita = ['consulta','vacuna','control','cirugia','bano','grooming','emergencia','hospitalizacion'];
            $tipo_cita = strtr(strtolower(trim($sol['tipo'] ?? 'consulta')), ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ñ'=>'n']);
            if (!in_array($tipo_cita, $tipos_cita, true)) $tipo_cita = 'consulta';
            $db->prepare("INSERT INTO citas (sede_id,mascota_id,veterinario_id,tipo,fecha,hora,duracion_minutos,estado,motivo,origen)
                          VALUES (?,?,?,?,?,?,?, 'confirmada', ?, 'web')")
               ->execute([$sol['sede_id'],$mascota_id,$vetid,$tipo_cita,$fecha,$hora,$dur,($sol['motivo']?:'Cita reservada por la web')]);
            $cita_id = (int)$db->lastInsertId();
            // 4) Marcar la solicitud como aceptada
            $db->prepare("UPDATE solicitudes_cita SET estado='aceptada', cliente_id=?, mascota_id=?, veterinario_id=?, cita_id=?, updated_at=NOW() WHERE id=?")
               ->execute([$cliente_id,$mascota_id,$vetid,$cita_id,$sid]);
            $db->commit();

            // 5) WhatsApp de confirmación (best-effort)
            try {
                $vet = $db->prepare("SELECT nombre FROM usuarios WHERE id=?"); $vet->execute([$vetid]); $vetn = $vet->fetchColumn() ?: 'nuestro equipo';
                $texto = wa_msg_confirmacion($clinica, $sol['dueno_nombre'], $sol['mascota_nombre'], $fecha, $hora, $vetn);
                @wa_enviar(preg_replace('/[^0-9]/','',$sol['dueno_telefono']), $texto);
            } catch (Exception $e) {}

            if (function_exists('auditLog')) auditLog('aceptar','solicitudes',"Solicitud #$sid aceptada → cita #$cita_id");
            $_SESSION['flash']='ok:Cita confirmada y creada. Se notificó al cliente por WhatsApp.';
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            $_SESSION['flash']='error:No se pudo aceptar la solicitud. Revisa los datos e inténtalo de nuevo.';
        }
    }
    header('Location: ?p=solicitudes'); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'rechazar') {
    $sid    = (int)($_POST['id'] ?? 0);
    $motivo = trim($_POST['motivo'] ?? '');
    $sol = $db->prepare("SELECT * FROM solicitudes_cita WHERE id=? AND estado='pendiente'");
    $sol->execute([$sid]); $sol = $sol->fetch();
    if ($sol) {
        try {
            $db->prepare("UPDATE solicitudes_cita SET estado='rechazada', respuesta=?, updated_at=NOW() WHERE id=?")
               ->execute([($motivo?:null),$sid]);
            try {
                $f = date('d/m/Y', strtotime($sol['fecha_preferida']));
                $extra = $motivo ? "\n\nMotivo: {$motivo}" : '';
                $texto = "🐾 *{$clinica}*\n\nHola {$sol['dueno_nombre']} 👋\n\nLamentamos informarte que no pudimos confirmar tu cita para *{$sol['mascota_nombre']}* del {$f}.{$extra}\n\nPor favor escríbenos para reprogramar. ¡Gracias!";
                @wa_enviar(preg_replace('/[^0-9]/','',$sol['dueno_telefono']), $texto);
            } catch (Exception $e) {}
            if (function_exists('auditLog')) auditLog('rechazar','solicitudes',"Solicitud #$sid rechazada");
            $_SESSION['flash']='ok:Solicitud rechazada. Se notificó al cliente por WhatsApp.';
        } catch (Exception $e) { $_SESSION['flash']='error:No se pudo rechazar la solicitud.'; }
    } else { $_SESSION['flash']='error:La solicitud no existe o ya fue procesada.'; }
    header('Location: ?p=solicitudes'); exit;
}

require_once __DIR__ . '/../includes/header.php';

$msg=''; $msg_tipo='success';
if (!empty($_SESSION['flash'])) { [$ft,$fm]=array_pad(explode(':',$_SESSION['flash'],2),2,''); $msg=$fm; $msg_tipo=($ft==='ok'?'success':'danger'); unset($_SESSION['flash']); }

$filtro = $_GET['estado'] ?? 'pendiente';
$filtro = in_array($filtro,['pendiente','aceptada','rechazada','todas'],true) ? $filtro : 'pendiente';
$wsql = $filtro==='todas' ? '' : "WHERE estado=".$db->quote($filtro);
$sols = $db->query("SELECT * FROM solicitudes_cita $wsql ORDER BY (estado='pendiente') DESC, created_at DESC LIMIT 200")->fetchAll();
$n_pend = (int)$db->query("SELECT COUNT(*) FROM solicitudes_cita WHERE estado='pendiente'")->fetchColumn();

$vets = $db->query("SELECT id,nombre FROM usuarios WHERE rol IN ('veterinario','admin') AND activo=1 ORDER BY nombre")->fetchAll();
$ei=['perro'=>'🐕','gato'=>'🐈','conejo'=>'🐰','ave'=>'🐦','reptil'=>'🦎','roedor'=>'🐭','otro'=>'🐾'];
$badge=['pendiente'=>['#fef3c7','#b45309','Pendiente'],'aceptada'=>['#dcfce7','#15803d','Aceptada'],'rechazada'=>['#fee2e2','#b91c1c','Rechazada']];
?>
<div class="page">
  <?php if ($msg): ?><div class="alert alert-<?= $msg_tipo ?>"><span class="alert-icon"><?= $msg_tipo==='success'?'✅':'⚠️' ?></span><?= clean($msg) ?></div><?php endif; ?>

  <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">
    <div>
      <div class="page-title">📩 Solicitudes de cita</div>
      <div class="page-desc">Reservas hechas por los clientes desde la web. <strong><?= $n_pend ?></strong> pendiente(s) por revisar.</div>
    </div>
  </div>

  <div class="flex gap-1" style="margin:14px 0">
    <?php foreach (['pendiente'=>'Pendientes','aceptada'=>'Aceptadas','rechazada'=>'Rechazadas','todas'=>'Todas'] as $k=>$lbl): ?>
      <a href="?p=solicitudes&estado=<?= $k ?>" class="btn btn-sm <?= $filtro===$k?'btn-primary':'btn-ghost' ?>"><?= $lbl ?></a>
    <?php endforeach; ?>
  </div>

  <?php if (empty($sols)): ?>
    <div class="card" style="padding:34px;text-align:center;color:var(--text3)">No hay solicitudes <?= $filtro!=='todas'?clean($filtro).'s':'' ?>.</div>
  <?php else: foreach ($sols as $s): $bg=$badge[$s['estado']]??['#f1f5f9','#64748b',$s['estado']]; ?>
    <div class="card" style="padding:16px;margin-bottom:12px">
      <div style="display:flex;align-items:flex-start;gap:12px;flex-wrap:wrap">
        <div style="font-size:26px"><?= $ei[$s['mascota_especie']]??'🐾' ?></div>
        <div style="flex:1;min-width:220px">
          <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
            <span style="font-weight:700;font-size:15px"><?= clean($s['mascota_nombre']) ?></span>
            <span class="badge" style="background:<?= $bg[0] ?>;color:<?= $bg[1] ?>"><?= $bg[2] ?></span>
          </div>
          <div class="text-xs text-muted" style="margin-top:3px">
            👤 <?= clean($s['dueno_nombre']) ?><?= !empty($s['dni'])?' · <b>DNI</b> '.clean($s['dni']):'' ?> · 📱 <?= clean($s['dueno_telefono']) ?><?= $s['dueno_email']?' · '.clean($s['dueno_email']):'' ?>
          </div>
          <div style="font-size:13px;margin-top:6px">
            📅 <strong><?= date('d/m/Y',strtotime($s['fecha_preferida'])) ?></strong><?= $s['hora_preferida']?(' · '.substr($s['hora_preferida'],0,5)):'' ?>
            <span class="text-muted"> · solicitado el <?= date('d/m/Y H:i',strtotime($s['created_at'])) ?></span>
          </div>
          <?php if (!empty($s['motivo'])): ?><div class="text-xs" style="margin-top:5px;color:var(--text2)">📝 <?= clean($s['motivo']) ?></div><?php endif; ?>
          <?php if ($s['estado']==='rechazada' && !empty($s['respuesta'])): ?><div class="text-xs" style="margin-top:5px;color:var(--danger)">Motivo del rechazo: <?= clean($s['respuesta']) ?></div><?php endif; ?>
        </div>
      </div>

      <?php if ($s['estado']==='pendiente'): ?>
      <div style="border-top:1px solid var(--border);margin-top:12px;padding-top:12px">
        <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end">
          <input type="hidden" name="action" value="aceptar">
          <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
          <div><label class="text-xs text-muted" style="display:block;margin-bottom:3px">Veterinario</label>
            <select class="form-input" name="veterinario_id" style="min-width:160px" required>
              <option value="">— Asignar —</option>
              <?php foreach($vets as $v): ?><option value="<?= (int)$v['id'] ?>"><?= clean($v['nombre']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div><label class="text-xs text-muted" style="display:block;margin-bottom:3px">Fecha</label>
            <input class="form-input" type="date" name="fecha" value="<?= clean($s['fecha_preferida']) ?>" required></div>
          <div><label class="text-xs text-muted" style="display:block;margin-bottom:3px">Hora</label>
            <input class="form-input" type="time" name="hora" value="<?= $s['hora_preferida']?substr($s['hora_preferida'],0,5):'09:00' ?>"></div>
          <button class="btn btn-primary btn-sm" type="submit" style="background:#15803d">✅ Aceptar y agendar</button>
        </form>
        <form method="post" style="display:flex;gap:8px;align-items:flex-end;margin-top:8px" onsubmit="return confirm('¿Rechazar esta solicitud? Se notificará al cliente por WhatsApp.')">
          <input type="hidden" name="action" value="rechazar">
          <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
          <div style="flex:1"><input class="form-input" name="motivo" placeholder="Motivo del rechazo (opcional)" maxlength="255"></div>
          <button class="btn btn-sm" type="submit" style="color:var(--danger);border:1px solid #fecaca">✕ Rechazar</button>
        </form>
      </div>
      <?php elseif ($s['estado']==='aceptada'): ?>
        <div class="text-xs" style="border-top:1px solid var(--border);margin-top:10px;padding-top:10px;color:var(--success-d)">✅ Cita agendada (#<?= (int)$s['cita_id'] ?>). Cliente y mascota registrados.</div>
      <?php endif; ?>
    </div>
  <?php endforeach; endif; ?>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
