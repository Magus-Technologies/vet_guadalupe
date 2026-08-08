<?php
$page = 'historial'; $pageTitle = 'Historia Clínica';

// ════════════════════════════════════════════════════════════════
// Número de Historia Clínica PERMANENTE
// Antes se calculaba según el orden del listado (cambiaba al agregar
// mascotas). Ahora se guarda en la columna `mascotas.hc_numero` y
// nunca cambia. La primera vez que entras, asigna el número a las
// mascotas que no lo tienen (respeta el orden por id).
// ════════════════════════════════════════════════════════════════
require_once __DIR__ . '/../includes/config.php';
$_hc_db = getDB();
try {
    $col = $_hc_db->query("SHOW COLUMNS FROM mascotas LIKE 'hc_numero'")->fetchAll();
    if (empty($col)) {
        $_hc_db->exec("ALTER TABLE mascotas ADD COLUMN hc_numero VARCHAR(15) NULL");
        $_hc_db->exec("CREATE INDEX idx_hc_numero ON mascotas (hc_numero)");
    }
    // Asignar HC a mascotas que aún no lo tienen (respeta orden por id = orden de creación)
    $sinHC = $_hc_db->query("SELECT id FROM mascotas WHERE hc_numero IS NULL OR hc_numero='' ORDER BY id ASC")->fetchAll();
    if ($sinHC) {
        // Determinar el próximo número a partir del MAX existente
        $maxRow = $_hc_db->query("SELECT MAX(CAST(SUBSTRING(hc_numero,4) AS UNSIGNED)) AS m FROM mascotas WHERE hc_numero LIKE 'HC-%'")->fetch();
        $next = ((int)($maxRow['m'] ?? 0)) + 1;
        $upd = $_hc_db->prepare("UPDATE mascotas SET hc_numero=? WHERE id=?");
        foreach ($sinHC as $m) {
            $upd->execute([sprintf('HC-%04d', $next), $m['id']]);
            $next++;
        }
    }
} catch(Exception $e) { /* no bloquear si falla */ }

// Helper para reservar el siguiente HC al crear una mascota (lo usa también mascotas.php)
if (!function_exists('hc_siguiente_numero')) {
    function hc_siguiente_numero($db) {
        try {
            $r = $db->query("SELECT MAX(CAST(SUBSTRING(hc_numero,4) AS UNSIGNED)) AS m FROM mascotas WHERE hc_numero LIKE 'HC-%'")->fetch();
            $n = ((int)($r['m'] ?? 0)) + 1;
            return sprintf('HC-%04d', $n);
        } catch(Exception $e) { return null; }
    }
}

// ── GUARDAR CONSULTA (ANTES del header para que el redirect funcione) ──
// Si va después del header, header('Location') falla con "headers already sent"
// → guarda pero deja la pantalla en blanco.
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='save_consulta') {
    require_once __DIR__ . '/../includes/config.php';
    $db = getDB();
    if (function_exists('requireLogin')) requireLogin();
    $user = function_exists('getUser') ? getUser() : ($GLOBALS['user'] ?? []);
    $cita_id = (int)($_GET['cita_id'] ?? 0);
    $fields=['mascota_id','veterinario_id','tipo','fecha','peso_actual','temperatura',
             'frecuencia_cardiaca','frecuencia_respiratoria',
             'deshidratacion','mucosas','reflejo_tusigeno','linfonodos','palpacion_abdominal',
             'sintomas','diagnostico',
             'tratamiento','observaciones','proximo_control'];
    $data=[]; foreach($fields as $f) $data[$f]=trim($_POST[$f]??'')?:null;
    $data['cita_id']=$cita_id?:null;
    $data['sede_id']=$user['sede_id']??1;
    // ¿Es edición de una consulta existente?
    $consulta_id_edit = (int)($_POST['consulta_id'] ?? 0);
    $is_update = $consulta_id_edit > 0;
    // Normalizar decimales (aceptar coma y varios decimales: "4,56" o "4.560")
    foreach (['peso_actual','temperatura'] as $_dec) {
        if (!empty($data[$_dec])) { $data[$_dec]=str_replace(',','.',$data[$_dec]); if(!is_numeric($data[$_dec])) $data[$_dec]=null; }
    }

    // ── Normalizar datos para que no choquen con la BD ──
    // fecha: datetime-local manda "2026-07-17T10:30" -> "2026-07-17 10:30"
    if (!empty($data['fecha'])) $data['fecha'] = str_replace('T',' ',$data['fecha']);
    if (empty($data['fecha']))  $data['fecha'] = date('Y-m-d H:i:s');
    // tipo: debe existir en el ENUM de consultas
    $_tipos_hc = ['consulta','control','emergencia','cirugia','vacuna','hospitalizacion'];
    $_t = strtr(strtolower(trim((string)($data['tipo'] ?? 'consulta'))), ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ñ'=>'n']);
    $data['tipo'] = in_array($_t, $_tipos_hc, true) ? $_t : 'consulta';

    // ── Validar obligatorios (columnas NOT NULL) para no reventar en blanco ──
    $_mid = (int)($data['mascota_id'] ?? 0);
    $_vid = (int)($data['veterinario_id'] ?? 0);
    if (!$_mid || !$_vid) {
        $_falta = !$_mid ? 'mascota' : 'veterinario';
        header('Location: '.BASE_URL.'/index.php?p=historial&mascota_id='.$_mid.'&msg=err&e='.urlencode('Falta seleccionar el '.$_falta.'. Vuelve a elegirlo en el buscador y guarda de nuevo.'));
        exit;
    }

    // Firma digital y extras
    $firma = trim($_POST['firma_veterinario']??'');
    $nota_voz = trim($_POST['nota_voz_texto']??'');
    $plantilla = trim($_POST['plantilla_usada']??'');
    $extra_cols = ''; $extra_pls = ''; $extra_data = [];
    if ($firma)    { $extra_cols.=',firma_veterinario'; $extra_pls.=',:firma_veterinario'; $extra_data['firma_veterinario']=$firma; }
    if ($nota_voz) { $extra_cols.=',nota_voz_texto';   $extra_pls.=',:nota_voz_texto';    $extra_data['nota_voz_texto']=$nota_voz; }
    if ($plantilla){ $extra_cols.=',plantilla_usada';  $extra_pls.=',:plantilla_usada';   $extra_data['plantilla_usada']=$plantilla; }
    try { $r=$db->query("SHOW COLUMNS FROM consultas LIKE 'firma_veterinario'")->fetchAll(); if(empty($r)) $db->exec("ALTER TABLE consultas ADD COLUMN firma_veterinario MEDIUMTEXT"); } catch(Exception $e){}
    try { $r=$db->query("SHOW COLUMNS FROM consultas LIKE 'nota_voz_texto'")->fetchAll(); if(empty($r)) $db->exec("ALTER TABLE consultas ADD COLUMN nota_voz_texto TEXT"); } catch(Exception $e){}
    try { $r=$db->query("SHOW COLUMNS FROM consultas LIKE 'plantilla_usada'")->fetchAll(); if(empty($r)) $db->exec("ALTER TABLE consultas ADD COLUMN plantilla_usada VARCHAR(100)"); } catch(Exception $e){}
    // Nuevos signos vitales (idempotente): %DH, mucosas, reflejo tusígeno, linfonodos, palpación abdominal
    foreach ([
        'deshidratacion'      => 'VARCHAR(30)',
        'mucosas'             => 'VARCHAR(120)',
        'reflejo_tusigeno'    => 'VARCHAR(60)',
        'linfonodos'          => 'VARCHAR(150)',
        'palpacion_abdominal' => 'VARCHAR(255)',
    ] as $_col => $_type) {
        try { $r=$db->query("SHOW COLUMNS FROM consultas LIKE '$_col'")->fetchAll(); if(empty($r)) $db->exec("ALTER TABLE consultas ADD COLUMN $_col $_type DEFAULT NULL"); } catch(Exception $e){}
    }
    if ($is_update) {
        // ── EDITAR consulta existente ──
        // No se cambia cita_id ni sede en la edición; se actualizan los datos clínicos.
        $upd_fields = ['tipo','fecha','peso_actual','temperatura','frecuencia_cardiaca',
                       'frecuencia_respiratoria','deshidratacion','mucosas','reflejo_tusigeno',
                       'linfonodos','palpacion_abdominal','sintomas','diagnostico','tratamiento',
                       'observaciones','proximo_control'];
        $set = implode(',', array_map(fn($f)=>"$f=:$f", $upd_fields));
        $set_extra = '';
        $upd_data = [];
        foreach ($upd_fields as $f) $upd_data[$f] = $data[$f];
        if ($firma)     { $set_extra.=',firma_veterinario=:firma_veterinario'; $upd_data['firma_veterinario']=$firma; }
        if ($nota_voz)  { $set_extra.=',nota_voz_texto=:nota_voz_texto';       $upd_data['nota_voz_texto']=$nota_voz; }
        if ($plantilla) { $set_extra.=',plantilla_usada=:plantilla_usada';     $upd_data['plantilla_usada']=$plantilla; }
        $upd_data['id'] = $consulta_id_edit;
        try {
            $st=$db->prepare("UPDATE consultas SET $set$set_extra WHERE id=:id");
            $st->execute($upd_data);
        } catch (Exception $e) {
            header('Location: '.BASE_URL.'/index.php?p=historial&mascota_id='.$_mid.'&msg=err&e='.urlencode('No se pudo actualizar la consulta: '.$e->getMessage()));
            exit;
        }
        $nueva_cid = $consulta_id_edit;
    } else {
        $cols=implode(',',array_merge($fields,['cita_id','sede_id']));
        $pls=implode(',',array_map(fn($f)=>":$f",array_merge($fields,['cita_id','sede_id'])));
        try {
            $st=$db->prepare("INSERT INTO consultas ($cols$extra_cols) VALUES ($pls$extra_pls)");
            $st->execute(array_merge($data,$extra_data));
        } catch (Exception $e) {
            // Nunca dejar pantalla en blanco: volver con el motivo exacto
            header('Location: '.BASE_URL.'/index.php?p=historial&mascota_id='.$_mid.'&msg=err&e='.urlencode('No se pudo guardar la consulta: '.$e->getMessage()));
            exit;
        }
        $nueva_cid=(int)$db->lastInsertId();
        if($data['cita_id']) $db->prepare("UPDATE citas SET estado='atendida' WHERE id=?")->execute([$data['cita_id']]);
    }

    // ── Enlazar con TRIAJE (si la consulta se creó desde "Atender") ──
    $triaje_id_link = (int)($_POST['triaje_id'] ?? 0);
    if ($triaje_id_link) {
        try {
            $tc = $db->query("SHOW COLUMNS FROM consultas LIKE 'triaje_id'")->fetchAll();
            if (empty($tc)) $db->exec("ALTER TABLE consultas ADD COLUMN triaje_id INT DEFAULT NULL");
            $db->prepare("UPDATE consultas SET triaje_id=? WHERE id=?")->execute([$triaje_id_link, $nueva_cid]);
            $db->prepare("UPDATE triaje SET estado='atendido', consulta_id=? WHERE id=?")->execute([$nueva_cid, $triaje_id_link]);
        } catch(Exception $e) { /* no bloquea el guardado */ }
    }

    // ── Sincronizar VACUNACIÓN ──
    // TODA consulta de tipo "vacuna" genera (o actualiza) un registro en el
    // módulo de Vacunación, enlazado por consulta_id. El nombre se toma del
    // desplegable "Tipo de vacuna"; si se dejó vacío, se usa el diagnóstico o
    // los síntomas escritos y, en último caso, "Vacuna".
    $vac_tipo = trim($_POST['vac_tipo_vacuna'] ?? '');
    if ($data['tipo']==='vacuna' && $vac_tipo==='') {
        $vac_tipo = trim((string)($data['diagnostico'] ?? '')) ?: trim((string)($data['sintomas'] ?? '')) ?: 'Vacuna';
        $vac_tipo = mb_substr($vac_tipo, 0, 100);
    }
    if ($data['tipo']==='vacuna' && $vac_tipo!=='') {
        try {
            // Asegurar columna de enlace
            $vc = $db->query("SHOW COLUMNS FROM vacunas LIKE 'consulta_id'")->fetchAll();
            if (empty($vc)) $db->exec("ALTER TABLE vacunas ADD COLUMN consulta_id INT DEFAULT NULL");
            $vac = [
                'tipo_vacuna'      => $vac_tipo,
                'laboratorio'      => trim($_POST['vac_laboratorio'] ?? '') ?: null,
                'lote'             => trim($_POST['vac_lote'] ?? '') ?: null,
                'fecha_aplicacion' => (!empty($data['fecha']) ? substr($data['fecha'],0,10) : date('Y-m-d')),
                'fecha_vencimiento'=> trim($_POST['vac_fecha_vencimiento'] ?? '') ?: null,
                'proxima_dosis'    => trim($_POST['vac_proxima_dosis'] ?? '') ?: null,
                'notas'            => trim($_POST['vac_notas'] ?? '') ?: null,
            ];
            // ¿Ya existe una vacuna enlazada a esta consulta? (evita duplicar al editar)
            $ex = $db->prepare("SELECT id FROM vacunas WHERE consulta_id=? LIMIT 1");
            $ex->execute([$nueva_cid]);
            $ex_id = $ex->fetchColumn();
            if ($ex_id) {
                $db->prepare("UPDATE vacunas SET tipo_vacuna=?,laboratorio=?,lote=?,fecha_aplicacion=?,fecha_vencimiento=?,proxima_dosis=?,notas=? WHERE id=?")
                   ->execute([$vac['tipo_vacuna'],$vac['laboratorio'],$vac['lote'],$vac['fecha_aplicacion'],$vac['fecha_vencimiento'],$vac['proxima_dosis'],$vac['notas'],(int)$ex_id]);
            } else {
                $db->prepare("INSERT INTO vacunas (mascota_id,veterinario_id,consulta_id,tipo_vacuna,laboratorio,lote,fecha_aplicacion,fecha_vencimiento,proxima_dosis,notas) VALUES (?,?,?,?,?,?,?,?,?,?)")
                   ->execute([$data['mascota_id'],$data['veterinario_id'],$nueva_cid,$vac['tipo_vacuna'],$vac['laboratorio'],$vac['lote'],$vac['fecha_aplicacion'],$vac['fecha_vencimiento'],$vac['proxima_dosis'],$vac['notas']]);
            }
        } catch(Exception $e) { /* la consulta ya se guardó; no bloquear por la vacuna */ }
    }

    // ── Próximo control → crear cita(s) automática(s) en la agenda ──
    // Por defecto crea UN control en la fecha indicada. Si el vet marcó
    // "Controles recurrentes", crea toda la serie agrupada.
    // (Solo al crear la consulta; al editar no se regeneran citas.)
    if (!$is_update && !empty($data['proximo_control'])) {
        try {
            // Asegurar columnas de recurrencia (idempotente)
            $_c = $db->query("SHOW COLUMNS FROM `citas` LIKE 'grupo_recurrencia'")->fetchAll();
            if (empty($_c)) {
                $db->exec("ALTER TABLE `citas`
                    ADD COLUMN `grupo_recurrencia` VARCHAR(36) NULL,
                    ADD COLUMN `sesion_numero` SMALLINT NULL,
                    ADD COLUMN `total_sesiones` SMALLINT NULL");
                $db->exec("CREATE INDEX idx_citas_grupo ON `citas` (`grupo_recurrencia`)");
            }

            $base = $data['proximo_control'];
            $recur = !empty($_POST['pc_recurrente']);
            $n = $recur ? max(2, min(52, (int)($_POST['pc_total_sesiones'] ?? 0))) : 1;

            // UUID de grupo solo si es serie
            $grupo = null;
            if ($recur) {
                $b = random_bytes(16);
                $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
                $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
                $grupo = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
            }

            // Calcular fechas
            $frec = $_POST['pc_frecuencia'] ?? 'semanal';
            $cada = max(1, (int)($_POST['pc_cada_dias'] ?? 1));
            $fechas = [];
            for ($i = 0; $i < $n; $i++) {
                if (!$recur) { $fechas[] = $base; break; }
                if ($frec === 'mensual') {
                    if (!function_exists('_vp_add_months')) {
                        function _vp_add_months($base, $i) {
                            $ts = strtotime($base);
                            $y=(int)date('Y',$ts); $m=(int)date('n',$ts); $d=(int)date('j',$ts);
                            $m += $i; $y += intdiv($m-1,12); $m = (($m-1)%12)+1;
                            $maxd = (int)date('t', strtotime(sprintf('%04d-%02d-01',$y,$m)));
                            return sprintf('%04d-%02d-%02d', $y, $m, min($d,$maxd));
                        }
                    }
                    $fechas[] = _vp_add_months($base, $i);
                } else {
                    $step = $frec==='diaria' ? 1 : ($frec==='semanal' ? 7 : ($frec==='quincenal' ? 15 : $cada));
                    $fechas[] = date('Y-m-d', strtotime($base." +".($i*$step)." days"));
                }
            }

            $dup = $db->prepare("SELECT COUNT(*) FROM citas WHERE mascota_id=? AND fecha=? AND tipo='control'");
            $ins = $db->prepare(
                "INSERT INTO citas (sede_id,mascota_id,veterinario_id,tipo,fecha,hora,duracion_minutos,estado,motivo,grupo_recurrencia,sesion_numero,total_sesiones)
                 VALUES (?,?,?,'control',?,?,?, 'pendiente', ?,?,?,?)"
            );
            foreach ($fechas as $k => $fch) {
                // Evitar duplicar si ya hay un control ese día para esta mascota
                $dup->execute([$data['mascota_id'], $fch]);
                if ((int)$dup->fetchColumn() !== 0) continue;
                $motivo = $recur
                    ? 'Control recurrente '.($k+1).'/'.$n.' (generado desde la consulta del '.date('d/m/Y').')'
                    : 'Control de seguimiento (generado desde la consulta del '.date('d/m/Y').')';
                $ins->execute([
                    $data['sede_id'], $data['mascota_id'], $data['veterinario_id'],
                    $fch, '09:00:00', 30, $motivo,
                    $grupo, ($recur ? $k+1 : null), ($recur ? $n : null)
                ]);
            }
        } catch(Exception $e) { /* si falla, no bloquea el guardado de la consulta */ }
    }
    if(!$is_update && !empty($_POST['med_nombre'][0])){
      try {
        $st2=$db->prepare("INSERT INTO recetas (consulta_id,mascota_id,veterinario_id,fecha,indicaciones) VALUES (?,?,?,CURDATE(),?)");
        $st2->execute([$nueva_cid,$data['mascota_id'],$data['veterinario_id'],trim($_POST['indicaciones']??'')]);
        $rid=(int)$db->lastInsertId();
        $st3=$db->prepare("INSERT INTO receta_items (receta_id,medicamento,dosis,frecuencia,duracion,via) VALUES (?,?,?,?,?,?)");
        foreach($_POST['med_nombre'] as $i=>$med) if(trim($med)) $st3->execute([$rid,trim($med),trim($_POST['med_dosis'][$i]??''),trim($_POST['med_frecuencia'][$i]??''),trim($_POST['med_duracion'][$i]??''),trim($_POST['med_via'][$i]??'')]);
      } catch(Exception $e) { /* la consulta ya se guardó; no bloquear por la receta */ }
    }
    // Subir archivos adjuntos
    if(!empty($_FILES['archivos']['name'][0])){
        try {
            $db->exec("CREATE TABLE IF NOT EXISTS archivos_clinicos (id INT AUTO_INCREMENT PRIMARY KEY,mascota_id INT,consulta_id INT,nombre VARCHAR(300),ruta VARCHAR(500),tipo VARCHAR(80),mime_type VARCHAR(100),created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
            foreach($_FILES['archivos']['tmp_name'] as $i=>$tmp){
                if($_FILES['archivos']['error'][$i]===0){
                    $oname=basename($_FILES['archivos']['name'][$i]);
                    $mime=mime_content_type($tmp);
                    $ext=pathinfo($oname,PATHINFO_EXTENSION);
                    $fname='arch_'.$nueva_cid.'_'.uniqid().'.'.$ext;
                    $dir=UPLOADS_PATH.'/examenes/';
                    if(!is_dir($dir))mkdir($dir,0755,true);
                    if(move_uploaded_file($tmp,$dir.$fname)){
                        $db->prepare("INSERT INTO archivos_clinicos (mascota_id,consulta_id,nombre,ruta,tipo,mime_type) VALUES (?,?,?,?,?,?)")
                           ->execute([$data['mascota_id'],$nueva_cid,$oname,'examenes/'.$fname,'clinico',$mime]);
                    }
                }
            }
        } catch(Exception $e){}
    }
    $mascota_id=$data['mascota_id'];
    header('Location: '.BASE_URL.'/index.php?p=historial&mascota_id='.$mascota_id.'&cid='.$nueva_cid.'&msg=ok');
    exit;
}

require_once __DIR__ . '/../includes/header.php';
$db = getDB();

$action      = $_GET['action'] ?? 'list';
$mascota_id  = (int)($_GET['mascota_id'] ?? 0);
$cita_id     = (int)($_GET['cita_id']    ?? 0);
$consulta_id = (int)($_GET['cid']        ?? 0);
$msg = $_GET['msg'] ?? '';

// Ampliar peso_actual para admitir 3 decimales (ej. 4.560) — una sola vez
try {
    $cpa = $db->query("SHOW COLUMNS FROM consultas LIKE 'peso_actual'")->fetch();
    if ($cpa && stripos($cpa['Type'] ?? '', '(7,3)') === false) {
        $db->exec("ALTER TABLE consultas MODIFY COLUMN peso_actual DECIMAL(7,3) DEFAULT NULL");
    }
} catch (Exception $e) {}

$vets_sel=$db->query("SELECT id,nombre FROM usuarios WHERE rol IN ('veterinario','admin') AND activo=1")->fetchAll();
$mascotas_sel=$db->query("SELECT m.id,CONCAT(m.nombre,' (',c.nombre,')') as label FROM mascotas m JOIN clientes c ON c.id=m.cliente_id WHERE m.estado='activo' ORDER BY m.nombre")->fetchAll();
$ei=['perro'=>'🐕','gato'=>'🐈','conejo'=>'🐰','ave'=>'🐦','reptil'=>'🦎','roedor'=>'🐭','otro'=>'🐾'];

// ── FORMULARIO NUEVA CONSULTA ──
if ($action==='nueva'): ?>

<style>
.hc-form{max-width:800px}
.med-row{display:grid;grid-template-columns:2fr 1fr 1fr 1fr 1fr auto;gap:8px;align-items:center;margin-bottom:6px}
.sec-box{background:var(--bg3);border:1px solid var(--border);border-radius:var(--r-sm);padding:16px;margin-bottom:14px}
.sec-box-title{font-size:12px;font-weight:700;color:var(--text2);text-transform:uppercase;letter-spacing:.5px;margin-bottom:12px;display:flex;align-items:center;gap:6px}
</style>

<div class="hc-form">
  <?php
  // ── Modo edición: cargar consulta existente por ?cid= ──
  $editing_c = null;
  $edit_cid = (int)($_GET['cid'] ?? 0);
  if ($edit_cid) {
      $st=$db->prepare("SELECT * FROM consultas WHERE id=?"); $st->execute([$edit_cid]);
      $editing_c=$st->fetch();
      if ($editing_c) { $mascota_id = (int)$editing_c['mascota_id']; }
  }
  // Precarga desde Triaje (cuando se crea la consulta con "Atender")
  $pf_sintomas = $editing_c ? '' : trim((string)($_GET['pf_sintomas'] ?? ''));
  $pf_peso     = $editing_c ? '' : trim((string)($_GET['pf_peso'] ?? ''));
  $pf_temp     = $editing_c ? '' : trim((string)($_GET['pf_temp'] ?? ''));
  $triaje_id_pf = (int)($_GET['triaje_id'] ?? 0);
  // Helpers de valor para precargar el formulario en edición
  $cv  = function($k) use ($editing_c){ return $editing_c ? clean((string)($editing_c[$k]??'')) : ''; };
  $csel= function($k,$opt) use ($editing_c){ return ($editing_c && (string)($editing_c[$k]??'')===$opt) ? 'selected' : ''; };
  $vet_pre_id = $editing_c ? (int)$editing_c['veterinario_id'] : 0;
  // Vacuna enlazada a esta consulta (si existe) para precargar la sección de vacunación
  $vac_pre = null;
  if ($editing_c) { try { $vp=$db->prepare("SELECT * FROM vacunas WHERE consulta_id=? LIMIT 1"); $vp->execute([$edit_cid]); $vac_pre=$vp->fetch(); } catch(Exception $e){} }
  // Tipos de vacuna activos para el desplegable (crea/siembra la tabla si aún no existe)
  $tipos_vac_hc = [];
  try {
      $db->exec("CREATE TABLE IF NOT EXISTS tipos_vacuna (id INT AUTO_INCREMENT PRIMARY KEY, nombre VARCHAR(100) NOT NULL, estado ENUM('activo','suspendido') NOT NULL DEFAULT 'activo', created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
      if ((int)$db->query("SELECT COUNT(*) FROM tipos_vacuna")->fetchColumn() === 0) {
          foreach (['Antirrábica','Óctuple','Séxtuple','Triple Felina','Leucemia Felina','Parvovirus','Mixomatosis','Enfermedad de Newcastle','Otra'] as $s)
              $db->prepare("INSERT INTO tipos_vacuna (nombre) VALUES (?)")->execute([$s]);
      }
      $tipos_vac_hc = $db->query("SELECT nombre FROM tipos_vacuna WHERE estado='activo' ORDER BY nombre")->fetchAll(PDO::FETCH_COLUMN);
  } catch(Exception $e){}

  $mascota_pre=null;
  if($mascota_id){$st=$db->prepare("SELECT m.*,c.nombre as dueno FROM mascotas m JOIN clientes c ON c.id=m.cliente_id WHERE m.id=?");$st->execute([$mascota_id]);$mascota_pre=$st->fetch();}
  if($cita_id&&!$mascota_pre){$st=$db->prepare("SELECT m.*,c.nombre as dueno FROM mascotas m JOIN clientes c ON c.id=m.cliente_id JOIN citas ci ON ci.mascota_id=m.id WHERE ci.id=?");$st->execute([$cita_id]);$mascota_pre=$st->fetch();if($mascota_pre)$mascota_id=$mascota_pre['id'];}
  ?>
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;flex-wrap:wrap;gap:10px">
    <div>
      <div class="page-title">🩺 <?= $editing_c ? 'Editar Consulta Médica' : 'Nueva Consulta Médica' ?></div>
      <?php if($mascota_pre): ?><div class="page-desc">Paciente: <strong><?= clean($mascota_pre['nombre']) ?></strong> · <?= clean($mascota_pre['dueno']) ?></div><?php endif; ?>
    </div>
    <div style="display:flex;gap:8px;align-items:center">
      <!-- Plantillas rápidas -->
      <div style="position:relative">
        <button type="button" onclick="document.getElementById('sel-plantilla').style.display=document.getElementById('sel-plantilla').style.display==='none'?'block':'none'"
          class="btn btn-sm btn-ghost" style="border-color:var(--accent);color:var(--accent)">📋 Plantillas</button>
        <div id="sel-plantilla" style="display:none;position:absolute;top:calc(100% + 6px);right:0;background:var(--bg2);border:1px solid var(--border);border-radius:12px;box-shadow:0 8px 30px rgba(0,0,0,.12);z-index:300;min-width:270px;overflow:hidden;max-height:320px;overflow-y:auto">
          <div style="padding:8px 12px;font-size:11px;font-weight:700;color:var(--text3);border-bottom:1px solid var(--border);background:var(--bg3);position:sticky;top:0">📋 Plantillas de diagnóstico</div>
          <?php
          $pls_menu=[]; try{
            $db->exec("CREATE TABLE IF NOT EXISTS hc_plantillas (id INT AUTO_INCREMENT PRIMARY KEY, nombre VARCHAR(150), especie VARCHAR(50) DEFAULT 'todos', tipo_consulta VARCHAR(50) DEFAULT 'consulta', motivo_template TEXT, diagnostico_template TEXT, tratamiento_template TEXT, activo TINYINT(1) DEFAULT 1, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
            $pls_menu=$db->query("SELECT * FROM hc_plantillas WHERE activo=1 ORDER BY especie,nombre")->fetchAll();
          }catch(Exception $e){}
          $especie_act=$mascota_pre['especie']??'';
          foreach($pls_menu as $pl):
            $match = $pl['especie']==='todos' || $pl['especie']===$especie_act;
          ?>
          <div onclick="aplicarPlantilla(<?= $pl['id'] ?>)"
            style="padding:10px 14px;cursor:pointer;border-bottom:1px solid var(--border);<?= !$match?'opacity:.5':'' ?>"
            onmouseover="this.style.background='var(--primary-l)'" onmouseout="this.style.background=''">
            <div style="font-size:12px;font-weight:600;color:var(--text)"><?= $match?'':'' ?><?= clean($pl['nombre']) ?></div>
            <div style="font-size:10px;color:var(--text3)"><?= ucfirst($pl['especie']) ?> · <?= ucfirst($pl['tipo_consulta']??'consulta') ?></div>
          </div>
          <?php endforeach; ?>
          <?php if(empty($pls_menu)): ?><div style="padding:16px;text-align:center;font-size:12px;color:var(--text3)">Ejecuta database_upgrade_v6.sql para cargar plantillas</div><?php endif; ?>
        </div>
      </div>
      <input type="hidden" name="plantilla_usada" id="plantilla_usada">
      <a href="?p=historial<?= $mascota_id?"&mascota_id=$mascota_id":'' ?>" class="btn btn-ghost btn-sm">← Volver</a>
    </div>
  </div>

  <form method="POST" enctype="multipart/form-data">
    <input type="hidden" name="action" value="save_consulta">
    <?php if($editing_c): ?><input type="hidden" name="consulta_id" value="<?= (int)$edit_cid ?>"><?php endif; ?>
    <?php if($triaje_id_pf): ?><input type="hidden" name="triaje_id" value="<?= $triaje_id_pf ?>"><?php endif; ?>
    <input type="hidden" name="cita_id" value="<?= $cita_id ?>">

    <div class="card">
      <div class="sec-box-title">👤 Paciente y datos generales</div>
      <?php
      $_hc_mas_js  = array_map(fn($m)=>['id'=>$m['id'],'label'=>$m['label']], $mascotas_sel);
      $_hc_vet_js  = array_map(fn($v)=>['id'=>$v['id'],'label'=>$v['nombre']], $vets_sel);
      $_hc_mas_pre = $mascota_pre ? $mascotas_sel[array_search($mascota_id, array_column($mascotas_sel,'id'))] ?? null : null;
      $_hc_vet_target = $vet_pre_id ?: ($user['id']??0); // en edición, el vet de la consulta
      $_hc_vet_pre = array_filter($vets_sel, fn($v)=>$v['id']==$_hc_vet_target); $_hc_vet_pre = $_hc_vet_pre ? reset($_hc_vet_pre) : ($vets_sel[0]??null);
      ?>
      <div class="form-row">
        <div class="form-group" style="position:relative">
          <label class="form-label required">Paciente</label>
          <input type="text" id="inp-mas-hc" class="form-input" placeholder="🐾 Buscar mascota..."
                 value="<?= $mascota_pre?clean($mascota_pre['nombre'].' ('.$mascota_pre['dueno'].')'):'' ?>"
                 autocomplete="off">
          <input type="hidden" name="mascota_id" id="hid-mas-hc" value="<?= $mascota_id ?>" required>
          <div id="drop-mas-hc" style="display:none;position:absolute;top:100%;left:0;right:0;background:var(--bg2);border:1px solid var(--border);border-radius:8px;box-shadow:0 8px 24px rgba(0,0,0,.12);z-index:300;max-height:220px;overflow-y:auto"></div>
        </div>
        <div class="form-group" style="position:relative">
          <label class="form-label required">Veterinario</label>
          <input type="text" id="inp-vet-hc" class="form-input" placeholder="👨‍⚕️ Buscar veterinario..."
                 value="<?= ($editing_c && $_hc_vet_pre)?clean($_hc_vet_pre['nombre']):'' ?>"
                 autocomplete="off">
          <input type="hidden" name="veterinario_id" id="hid-vet-hc" value="<?= $editing_c && $_hc_vet_pre?(int)$_hc_vet_pre['id']:'' ?>" required>
          <div id="drop-vet-hc" style="display:none;position:absolute;top:100%;left:0;right:0;background:var(--bg2);border:1px solid var(--border);border-radius:8px;box-shadow:0 8px 24px rgba(0,0,0,.12);z-index:300;max-height:200px;overflow-y:auto"></div>
        </div>
      </div>
      <div class="form-row">
        <div class="form-group"><label class="form-label">Tipo de consulta</label>
          <select class="form-input" name="tipo" id="hc-tipo" onchange="hcToggleVacuna()">
            <option value="consulta" <?= $csel('tipo','consulta') ?>>Consulta general</option><option value="control" <?= $csel('tipo','control') ?>>Control</option>
            <option value="emergencia" <?= $csel('tipo','emergencia') ?>>Emergencia</option><option value="cirugia" <?= $csel('tipo','cirugia') ?>>Post-cirugía</option>
            <option value="vacuna" <?= $csel('tipo','vacuna') ?>>Vacunación</option><option value="hospitalizacion" <?= $csel('tipo','hospitalizacion') ?>>Hospitalización</option>
          </select>
        </div>
        <div class="form-group"><label class="form-label">Fecha y hora</label>
          <input class="form-input" type="datetime-local" name="fecha" value="<?= $editing_c ? date('Y-m-d\TH:i', strtotime($editing_c['fecha'])) : date('Y-m-d\TH:i') ?>">
        </div>
      </div>
    </div>

    <!-- Signos vitales -->
    <div class="card">
      <div class="sec-box-title">📊 Signos vitales</div>
      <div class="form-row-3">
        <div class="form-group"><label class="form-label">Peso actual (kg)</label><input class="form-input" type="number" step="0.001" min="0" name="peso_actual" value="<?= $editing_c?$cv('peso_actual'):clean($pf_peso) ?>" placeholder="Ej: 4.560"></div>
        <div class="form-group"><label class="form-label">Temperatura (°C)</label><input class="form-input" type="number" step="0.1" name="temperatura" value="<?= $editing_c?$cv('temperatura'):clean($pf_temp) ?>" placeholder="Ej: 38.5"></div>
        <div class="form-group"><label class="form-label">F. Cardíaca (rpm)</label><input class="form-input" type="number" name="frecuencia_cardiaca" value="<?= $cv('frecuencia_cardiaca') ?>" placeholder="Ej: 80"></div>
      </div>
      <div class="form-row-3">
        <div class="form-group"><label class="form-label">F. Respiratoria (rpm)</label><input class="form-input" type="number" name="frecuencia_respiratoria" value="<?= $cv('frecuencia_respiratoria') ?>" placeholder="Ej: 20"></div>
        <div class="form-group"><label class="form-label">% Deshidratación</label><input class="form-input" name="deshidratacion" value="<?= $cv('deshidratacion') ?>" placeholder="Ej: 5%"></div>
        <div class="form-group"><label class="form-label">Mucosas</label><input class="form-input" name="mucosas" value="<?= $cv('mucosas') ?>" placeholder="Ej: rosadas, húmedas"></div>
      </div>
      <div class="form-row-3">
        <div class="form-group"><label class="form-label">Reflejo Tusígeno</label><input class="form-input" name="reflejo_tusigeno" value="<?= $cv('reflejo_tusigeno') ?>" placeholder="Ej: negativo"></div>
        <div class="form-group"><label class="form-label">Linfonodos</label><input class="form-input" name="linfonodos" value="<?= $cv('linfonodos') ?>" placeholder="Ej: no reactivos"></div>
        <div class="form-group"><label class="form-label">Palpación Abdominal</label><input class="form-input" name="palpacion_abdominal" value="<?= $cv('palpacion_abdominal') ?>" placeholder="Ej: sin dolor, blando"></div>
      </div>
      <div class="form-row-3">
        <div class="form-group"><label class="form-label">Próximo control</label><input class="form-input" type="date" name="proximo_control" id="pc-fecha" value="<?= $cv('proximo_control') ?>" onchange="pcPreview()"></div>
        <div class="form-group"><label class="form-label" style="display:block">&nbsp;</label>
          <label class="flex items-center gap-1" style="cursor:pointer;font-size:13px;height:38px">
            <input type="checkbox" name="pc_recurrente" value="1" id="pc-recur" onchange="pcToggle()" style="width:auto;margin:0"> 🔁 Controles recurrentes
          </label>
        </div>
        <div class="form-group"></div>
      </div>
      <div class="form-row-3" id="pc-recur-box" style="display:none">
        <div class="form-group"><label class="form-label">Frecuencia</label>
          <select class="form-input" name="pc_frecuencia" id="pc-frec" onchange="pcToggleCada();pcPreview()">
            <option value="diaria">Diaria</option>
            <option value="semanal" selected>Semanal (cada 7 días)</option>
            <option value="quincenal">Quincenal (cada 15 días)</option>
            <option value="mensual">Mensual</option>
            <option value="personalizado">Personalizado (cada N días)</option>
          </select>
        </div>
        <div class="form-group" id="pc-cada-box" style="display:none"><label class="form-label">Cada cuántos días</label>
          <input class="form-input" type="number" name="pc_cada_dias" id="pc-cada" min="1" value="3" onchange="pcPreview()">
        </div>
        <div class="form-group"><label class="form-label">N° de controles</label>
          <input class="form-input" type="number" name="pc_total_sesiones" id="pc-n" min="2" max="52" value="4" onchange="pcPreview()">
        </div>
      </div>
      <div id="pc-preview" class="text-xs text-muted" style="margin:-4px 2px 4px"></div>
    </div>

    <!-- Clínica -->
    <div class="card">
      <div class="sec-box-title">🔬 Datos clínicos</div>
      <div class="form-group"><label class="form-label">Síntomas / Motivo de consulta</label>
        <textarea class="form-input" name="sintomas" style="min-height:70px" placeholder="Describe los síntomas presentados..."><?= $editing_c?$cv('sintomas'):clean($pf_sintomas) ?></textarea>
      </div>
      <div class="form-group"><label class="form-label">Diagnóstico</label>
        <textarea class="form-input" name="diagnostico" style="min-height:70px" placeholder="Diagnóstico clínico..."><?= $cv('diagnostico') ?></textarea>
      </div>
      <div class="form-group"><label class="form-label">Tratamiento indicado</label>
        <textarea class="form-input" name="tratamiento" style="min-height:70px" placeholder="Tratamiento, procedimientos, indicaciones..."><?= $cv('tratamiento') ?></textarea>
      </div>
      <div class="form-group"><label class="form-label">Notas del veterinario</label>
        <textarea class="form-input" name="observaciones" style="min-height:55px" placeholder="Observaciones adicionales..."><?= $cv('observaciones') ?></textarea>
      </div>
    </div>

    <!-- 💉 Vacunación (aparece cuando el tipo es "Vacunación") -->
    <div class="card" id="hc-vacuna-box" style="display:none">
      <div class="sec-box-title">💉 Datos de vacunación <span style="font-size:10px;font-weight:400;color:var(--text3)">(se registra también en el módulo Vacunación)</span></div>
      <div class="form-row">
        <div class="form-group"><label class="form-label">Tipo de vacuna</label>
          <select class="form-input" name="vac_tipo_vacuna">
            <option value="">— Seleccionar —</option>
            <?php foreach($tipos_vac_hc as $tv): ?>
              <option value="<?= clean($tv) ?>" <?= ($vac_pre && $vac_pre['tipo_vacuna']===$tv)?'selected':'' ?>><?= clean($tv) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group"><label class="form-label">Laboratorio</label>
          <input class="form-input" name="vac_laboratorio" value="<?= $vac_pre?clean($vac_pre['laboratorio']??''):'' ?>" placeholder="Ej: Zoetis, Nobivac"></div>
      </div>
      <div class="form-row">
        <div class="form-group"><label class="form-label">N° de lote</label>
          <input class="form-input" name="vac_lote" value="<?= $vac_pre?clean($vac_pre['lote']??''):'' ?>" placeholder="Ej: L2025-01"></div>
        <div class="form-group"><label class="form-label">Próxima dosis</label>
          <input class="form-input" type="date" name="vac_proxima_dosis" value="<?= $vac_pre?clean($vac_pre['proxima_dosis']??''):'' ?>"></div>
      </div>
      <div class="form-row">
        <div class="form-group"><label class="form-label">Vencimiento (vacuna)</label>
          <input class="form-input" type="date" name="vac_fecha_vencimiento" value="<?= $vac_pre?clean($vac_pre['fecha_vencimiento']??''):'' ?>"></div>
        <div class="form-group"><label class="form-label">Notas de la vacuna</label>
          <input class="form-input" name="vac_notas" value="<?= $vac_pre?clean($vac_pre['notas']??''):'' ?>" placeholder="Opcional"></div>
      </div>
      <div style="font-size:11px;color:var(--text3)">💡 Elige el tipo de vacuna para que quede registrada en el módulo <strong>Vacunación</strong>. Si lo dejas vacío, solo se guarda como consulta.</div>
    </div>

    <!-- Receta -->
    <div class="card">
      <div class="sec-box-title">💊 Medicamentos recetados <span style="font-size:10px;font-weight:400;color:var(--text3)">(opcional)</span></div>
      <div style="overflow-x:auto">
        <div class="med-row" style="font-size:10px;font-weight:700;color:var(--text3);text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px">
          <span>Medicamento</span><span>Dosis</span><span>Frecuencia</span><span>Duración</span><span>Vía</span><span></span>
        </div>
        <div id="meds-list">
          <div class="med-row" id="med-0">
            <input class="form-input" type="text" name="med_nombre[]" placeholder="Ej: Amoxicilina 250mg">
            <input class="form-input" type="text" name="med_dosis[]" placeholder="Ej: 1 tab">
            <input class="form-input" type="text" name="med_frecuencia[]" placeholder="Ej: 12h">
            <input class="form-input" type="text" name="med_duracion[]" placeholder="Ej: 7 días">
            <input class="form-input" type="text" name="med_via[]" placeholder="Oral">
            <button type="button" onclick="this.closest('.med-row').remove()" class="btn btn-xs btn-ghost" style="color:var(--danger);flex-shrink:0">✕</button>
          </div>
        </div>
        <button type="button" onclick="addMed()" class="btn btn-ghost btn-xs mt-1">＋ Agregar medicamento</button>
      </div>
      <div class="form-group mt-2"><label class="form-label">Indicaciones generales</label>
        <textarea class="form-input" name="indicaciones" style="min-height:55px" placeholder="Indicaciones adicionales para el dueño..."></textarea>
      </div>
    </div>

    <!-- 📸 FOTOS DESDE CÁMARA DEL MÓVIL -->
    <div class="card">
      <div class="sec-box-title">📸 Fotos de la consulta <span style="font-size:10px;font-weight:400;color:var(--text3)">(desde cámara o galería)</span></div>
      <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
        <label style="display:flex;flex-direction:column;align-items:center;justify-content:center;width:80px;height:80px;border:2px dashed var(--border);border-radius:12px;cursor:pointer;transition:all .15s;background:var(--bg3)"
               onmouseover="this.style.borderColor='var(--primary)'" onmouseout="this.style.borderColor='var(--border)'">
          <span style="font-size:24px">📷</span>
          <span style="font-size:10px;color:var(--text3);margin-top:4px">Cámara</span>
          <input type="file" name="archivos[]" accept="image/*" capture="environment" style="display:none" onchange="previewFotos(this)">
        </label>
        <label style="display:flex;flex-direction:column;align-items:center;justify-content:center;width:80px;height:80px;border:2px dashed var(--border);border-radius:12px;cursor:pointer;transition:all .15s;background:var(--bg3)"
               onmouseover="this.style.borderColor='var(--primary)'" onmouseout="this.style.borderColor='var(--border)'">
          <span style="font-size:24px">🖼️</span>
          <span style="font-size:10px;color:var(--text3);margin-top:4px">Galería</span>
          <input type="file" name="archivos[]" accept="image/*,.pdf" multiple style="display:none" onchange="previewFotos(this)">
        </label>
        <div id="fotos-preview" style="display:flex;gap:8px;flex-wrap:wrap"></div>
      </div>
    </div>

    <!-- 🎤 NOTA DE VOZ -->
    <div class="card">
      <div class="sec-box-title" style="display:flex;align-items:center;justify-content:space-between">
        <span>🎤 Nota de voz <span style="font-size:10px;font-weight:400;color:var(--text3)">(transcripción automática)</span></span>
        <button type="button" id="btn-voz" onclick="toggleVoz()" class="btn btn-sm btn-ghost" style="border-color:var(--primary);color:var(--primary)">🎙️ Dictar</button>
      </div>
      <div id="voz-status" style="display:none;font-size:11px;color:var(--primary);margin-bottom:8px;display:flex;align-items:center;gap:6px">
        <span style="width:8px;height:8px;border-radius:50%;background:var(--danger);animation:pulse 1s infinite"></span> Escuchando...
      </div>
      <textarea id="nota-voz-area" name="nota_voz_texto" class="form-input" style="min-height:60px" placeholder="Las notas de voz se transcribirán aquí automáticamente, o puedes escribir directamente..."></textarea>
    </div>

    <!-- ✍️ FIRMA DIGITAL DEL VETERINARIO -->
    <div class="card">
      <div class="sec-box-title" style="display:flex;align-items:center;justify-content:space-between">
        <span>✍️ Firma del veterinario</span>
        <button type="button" onclick="limpiarFirma()" class="btn btn-xs btn-ghost">Limpiar</button>
      </div>
      <canvas id="firma-canvas" width="500" height="120"
        style="width:100%;height:120px;border:2px solid var(--border);border-radius:10px;background:#fff;cursor:crosshair;touch-action:none"></canvas>
      <input type="hidden" name="firma_veterinario" id="firma-data">
      <div style="font-size:11px;color:var(--text3);margin-top:4px">Dibuja tu firma con el mouse o dedo en móvil</div>
    </div>

    <div class="flex gap-2">
      <button type="submit" class="btn btn-primary btn-lg" onclick="guardarFirma()">💾 Guardar consulta</button>
      <a href="?p=historial<?= $mascota_id?"&mascota_id=$mascota_id":'' ?>" class="btn btn-ghost">Cancelar</a>
    </div>
  </form>
</div>

<script>
let medIdx=1;
// ── Controles recurrentes (Próximo control) ──
function pcToggle(){
  var on = document.getElementById('pc-recur').checked;
  document.getElementById('pc-recur-box').style.display = on ? '' : 'none';
  pcPreview();
}
function pcToggleCada(){
  document.getElementById('pc-cada-box').style.display =
    document.getElementById('pc-frec').value === 'personalizado' ? '' : 'none';
}
function pcPreview(){
  var box = document.getElementById('pc-preview'); if(!box) return;
  var chk = document.getElementById('pc-recur');
  var f = (document.getElementById('pc-fecha')||{}).value || '';
  if(!chk || !chk.checked){ box.textContent=''; return; }
  if(!f){ box.textContent='Indica la fecha del 1er control arriba.'; return; }
  var n = Math.max(2, Math.min(52, parseInt(document.getElementById('pc-n').value||'0')));
  var frec = document.getElementById('pc-frec').value;
  var cada = Math.max(1, parseInt(document.getElementById('pc-cada').value||'1'));
  var d0 = new Date(f+'T00:00:00'), fechas=[];
  for(var i=0;i<n;i++){
    var d = new Date(d0);
    if(frec==='mensual'){ var _m=d0.getMonth()+i, _y=d0.getFullYear()+Math.floor(_m/12); _m=((_m%12)+12)%12; var _maxd=new Date(_y,_m+1,0).getDate(); d=new Date(_y,_m,Math.min(d0.getDate(),_maxd)); }
    else { var step = frec==='diaria'?1:(frec==='semanal'?7:(frec==='quincenal'?15:cada)); d.setDate(d.getDate()+i*step); }
    fechas.push(d);
  }
  var fmt = function(x){ return ('0'+x.getDate()).slice(-2)+'/'+('0'+(x.getMonth()+1)).slice(-2)+'/'+x.getFullYear(); };
  box.innerHTML = '📅 <strong>'+n+' controles</strong> a las 09:00 · de '+fmt(fechas[0])+' a '+fmt(fechas[n-1])+'<br>'+fechas.slice(0,4).map(fmt).join(' · ')+(n>4?' …':'');
}
function addMed(){
  const d=document.createElement('div');d.className='med-row';d.innerHTML=`
    <input class="form-input" type="text" name="med_nombre[]" placeholder="Medicamento">
    <input class="form-input" type="text" name="med_dosis[]" placeholder="Dosis">
    <input class="form-input" type="text" name="med_frecuencia[]" placeholder="Frecuencia">
    <input class="form-input" type="text" name="med_duracion[]" placeholder="Duración">
    <input class="form-input" type="text" name="med_via[]" placeholder="Vía">
    <button type="button" onclick="this.closest('.med-row').remove()" class="btn btn-xs btn-ghost" style="color:var(--danger)">✕</button>`;
  document.getElementById('meds-list').appendChild(d);
}

// ── Preview fotos antes de subir ──
function previewFotos(input) {
  const preview = document.getElementById('fotos-preview');
  Array.from(input.files).forEach(function(file) {
    if (!file.type.startsWith('image/')) return;
    const reader = new FileReader();
    reader.onload = function(e) {
      const img = document.createElement('img');
      img.src = e.target.result;
      img.style.cssText = 'width:72px;height:72px;object-fit:cover;border-radius:10px;border:2px solid var(--border)';
      preview.appendChild(img);
    };
    reader.readAsDataURL(file);
  });
}

// ── Nota de voz (Web Speech API) ──
var _recognition = null;
var _vozActiva = false;
function toggleVoz() {
  var SpeechRec = window.SpeechRecognition || window.webkitSpeechRecognition;
  if (!SpeechRec) { alert('Tu navegador no soporta reconocimiento de voz. Usa Chrome.'); return; }
  if (_vozActiva) {
    _recognition && _recognition.stop();
    _vozActiva = false;
    document.getElementById('btn-voz').textContent = '🎙️ Dictar';
    document.getElementById('voz-status').style.display = 'none';
    return;
  }
  _recognition = new SpeechRec();
  _recognition.lang = 'es-PE';
  _recognition.continuous = true;
  _recognition.interimResults = true;
  _recognition.onresult = function(e) {
    var txt = '';
    for (var i=0; i<e.results.length; i++) txt += e.results[i][0].transcript;
    document.getElementById('nota-voz-area').value = txt;
  };
  _recognition.onend = function() {
    _vozActiva = false;
    document.getElementById('btn-voz').textContent = '🎙️ Dictar';
    document.getElementById('voz-status').style.display = 'none';
  };
  _recognition.start();
  _vozActiva = true;
  document.getElementById('btn-voz').textContent = '⏹ Detener';
  document.getElementById('voz-status').style.display = 'flex';
}

// ── Firma digital ──
var _firmaCanvas = document.getElementById('firma-canvas');
var _firmaCtx = _firmaCanvas ? _firmaCanvas.getContext('2d') : null;
var _firmaDibujando = false;
var _firmaTiene = false;
if (_firmaCtx) {
  _firmaCtx.strokeStyle = '#1e293b';
  _firmaCtx.lineWidth = 2;
  _firmaCtx.lineCap = 'round';
  function getFirmaPos(e) {
    var rect = _firmaCanvas.getBoundingClientRect();
    var scaleX = _firmaCanvas.width / rect.width;
    var scaleY = _firmaCanvas.height / rect.height;
    var clientX = e.touches ? e.touches[0].clientX : e.clientX;
    var clientY = e.touches ? e.touches[0].clientY : e.clientY;
    return { x: (clientX - rect.left) * scaleX, y: (clientY - rect.top) * scaleY };
  }
  _firmaCanvas.addEventListener('mousedown',  function(e){_firmaDibujando=true;var p=getFirmaPos(e);_firmaCtx.beginPath();_firmaCtx.moveTo(p.x,p.y);});
  _firmaCanvas.addEventListener('mousemove',  function(e){if(!_firmaDibujando)return;var p=getFirmaPos(e);_firmaCtx.lineTo(p.x,p.y);_firmaCtx.stroke();_firmaTiene=true;});
  _firmaCanvas.addEventListener('mouseup',    function(){_firmaDibujando=false;});
  _firmaCanvas.addEventListener('touchstart', function(e){e.preventDefault();_firmaDibujando=true;var p=getFirmaPos(e);_firmaCtx.beginPath();_firmaCtx.moveTo(p.x,p.y);},{passive:false});
  _firmaCanvas.addEventListener('touchmove',  function(e){e.preventDefault();if(!_firmaDibujando)return;var p=getFirmaPos(e);_firmaCtx.lineTo(p.x,p.y);_firmaCtx.stroke();_firmaTiene=true;},{passive:false});
  _firmaCanvas.addEventListener('touchend',   function(){_firmaDibujando=false;});
}
function limpiarFirma() {
  if(_firmaCtx){_firmaCtx.clearRect(0,0,_firmaCanvas.width,_firmaCanvas.height);_firmaTiene=false;}
  document.getElementById('firma-data').value='';
}
function guardarFirma() {
  if(_firmaTiene && _firmaCanvas) {
    document.getElementById('firma-data').value = _firmaCanvas.toDataURL('image/png');
  }
}

// ── Plantillas de diagnóstico ──
var _plantillas = <?php
$pls = []; try { $pls=$db->query("CREATE TABLE IF NOT EXISTS hc_plantillas (id INT AUTO_INCREMENT PRIMARY KEY, nombre VARCHAR(150), especie VARCHAR(50) DEFAULT 'todos', motivo_template TEXT, diagnostico_template TEXT, tratamiento_template TEXT, activo TINYINT(1) DEFAULT 1, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)")->execute(); } catch(Exception $e){}
try { $pls=$db->query("SELECT * FROM hc_plantillas WHERE activo=1 ORDER BY especie,nombre")->fetchAll(); } catch(Exception $e){}
echo json_encode($pls);
?>;

function aplicarPlantilla(id) {
  var pl = _plantillas.find(function(p){return p.id==id;});
  if (!pl) return;
  if (pl.motivo_template) {
    var f = document.querySelector('[name="sintomas"]');
    if (f && !f.value.trim()) f.value = pl.motivo_template;
  }
  if (pl.diagnostico_template) {
    var f = document.querySelector('[name="diagnostico"]');
    if (f && !f.value.trim()) f.value = pl.diagnostico_template;
  }
  if (pl.tratamiento_template) {
    var f = document.querySelector('[name="tratamiento"]');
    if (f && !f.value.trim()) f.value = pl.tratamiento_template;
  }
  document.getElementById('plantilla_usada').value = pl.nombre;
  document.getElementById('sel-plantilla').style.display = 'none';
}

// Inicializar buscadores de paciente y veterinario
var _HC_MAS = <?= json_encode(array_values($_hc_mas_js??[])) ?>;
var _HC_VET = <?= json_encode(array_values($_hc_vet_js??[])) ?>;
document.addEventListener('DOMContentLoaded', function() {
    vetSearchSelect('inp-mas-hc','drop-mas-hc','hid-mas-hc', _HC_MAS, 'label');
    vetSearchSelect('inp-vet-hc','drop-vet-hc','hid-vet-hc', _HC_VET, 'label');
    hcToggleVacuna();
});
// Mostrar la sección de vacunación solo cuando el tipo es "Vacunación"
function hcToggleVacuna(){
    var sel=document.getElementById('hc-tipo');
    var box=document.getElementById('hc-vacuna-box');
    if(!sel||!box) return;
    box.style.display = (sel.value==='vacuna') ? 'block' : 'none';
}
</script>

<?php
    require_once __DIR__ . '/../includes/footer.php';
    return;
endif;

// ── VISTA PRINCIPAL: Layout 3 columnas (imagen 2) ──
$mascota=null;
if ($mascota_id) {
    $st=$db->prepare("SELECT m.*,c.nombre as dueno,c.telefono,c.email FROM mascotas m JOIN clientes c ON c.id=m.cliente_id WHERE m.id=?");
    $st->execute([$mascota_id]); $mascota=$st->fetch();
}
// Consultas de esta mascota o busqueda global
$search=trim($_GET['q']??'');
$tab_act=$_GET['tab']??'consultas';
$where="1=1"; $params=[];
if($mascota_id){$where.=" AND con.mascota_id=?";$params[]=$mascota_id;}
elseif($search){$where.=" AND (m.nombre LIKE ? OR cl.nombre LIKE ? OR con.diagnostico LIKE ?)";$like="%$search%";$params=[$like,$like,$like];}
// Filtro sede
try {
    $_r=$db->query("SHOW COLUMNS FROM `mascotas` LIKE 'sede_id'")->fetchAll();
    if(!empty($_r)) {
        if(!verTodasSedes()) { $where.=" AND m.sede_id=".getSede(); }
    }
} catch(Exception $e) {}
$consultas=$db->prepare("SELECT con.*,m.nombre as mascota,m.especie,m.foto,u.nombre as veterinario,cl.nombre as dueno,
    (SELECT COUNT(*) FROM recetas r WHERE r.consulta_id=con.id) as tiene_receta
    FROM consultas con JOIN mascotas m ON m.id=con.mascota_id JOIN usuarios u ON u.id=con.veterinario_id
    JOIN clientes cl ON cl.id=m.cliente_id WHERE $where ORDER BY con.fecha DESC LIMIT 60");
$consultas->execute($params); $consultas=$consultas->fetchAll();
// ── Vacunas de la mascota (para la pestaña "Vacunas" del historial) ──
// Muestra TODAS las vacunas registradas de la mascota, sin importar si se
// crearon desde el módulo Vacunación o desde una consulta.
$vacunas_hc = [];
if ($mascota_id) {
    try {
        $vq=$db->prepare("SELECT v.*,u.nombre as veterinario FROM vacunas v LEFT JOIN usuarios u ON u.id=v.veterinario_id WHERE v.mascota_id=? ORDER BY v.fecha_aplicacion DESC, v.id DESC");
        $vq->execute([$mascota_id]); $vacunas_hc=$vq->fetchAll();
    } catch(Exception $e){ $vacunas_hc=[]; }
}
// Consulta seleccionada para el panel de detalle
$consulta_sel=null; $receta_sel=[]; $archivos_sel=[];
$sel_id = $consulta_id ?: (($consultas[0]['id']??0));
if($sel_id){
    $st=$db->prepare("SELECT con.*,m.nombre as mascota,m.especie,m.foto,m.peso,m.fecha_nacimiento,u.nombre as veterinario,cl.nombre as dueno,cl.telefono FROM consultas con JOIN mascotas m ON m.id=con.mascota_id JOIN usuarios u ON u.id=con.veterinario_id JOIN clientes cl ON cl.id=m.cliente_id WHERE con.id=?");
    $st->execute([$sel_id]);$consulta_sel=$st->fetch();
    if($consulta_sel){
        $st2=$db->prepare("SELECT ri.*,r.indicaciones FROM recetas r JOIN receta_items ri ON ri.receta_id=r.id WHERE r.consulta_id=?");
        $st2->execute([$sel_id]);$receta_sel=$st2->fetchAll();
        try{$st3=$db->prepare("SELECT * FROM archivos_clinicos WHERE consulta_id=? ORDER BY id ASC");$st3->execute([$sel_id]);$archivos_sel=$st3->fetchAll();}catch(Exception $e){}
    }
}
// Alertas de este paciente
$alertas_pac=[];
if($mascota){
    $vv=$db->prepare("SELECT COUNT(*) FROM vacunas WHERE mascota_id=? AND proxima_dosis<CURDATE()");$vv->execute([$mascota_id]);$nv=(int)$vv->fetchColumn();
    if($nv>0) $alertas_pac[]=['tipo'=>'danger','msg'=>"$nv vacuna".($nv>1?'s':''). " vencida".($nv>1?'s':''),'icon'=>'⚠️'];
    // Control pendiente
    $lc=$db->prepare("SELECT proximo_control FROM consultas WHERE mascota_id=? AND proximo_control IS NOT NULL ORDER BY fecha DESC LIMIT 1");$lc->execute([$mascota_id]);$lc=$lc->fetchColumn();
    if($lc&&strtotime($lc)<time()) $alertas_pac[]=['tipo'=>'warn','msg'=>'Control dental pendiente','icon'=>'⚠️'];
}
// Resumen rápido
$ultima_consulta=null;$diag_recurrente='—';$alergias_pac='Ninguna';$meds_activos=0;
if($mascota){
    $lcon=$db->prepare("SELECT fecha,diagnostico,tratamiento FROM consultas WHERE mascota_id=? ORDER BY fecha DESC LIMIT 1");$lcon->execute([$mascota_id]);$ultima_consulta=$lcon->fetch();
    $diag_f=$db->prepare("SELECT diagnostico,COUNT(*) as n FROM consultas WHERE mascota_id=? GROUP BY diagnostico ORDER BY n DESC LIMIT 1");$diag_f->execute([$mascota_id]);$df=$diag_f->fetch();if($df) $diag_recurrente=clean(substr($df['diagnostico'],0,20));
    if($mascota['alergias']) $alergias_pac=clean($mascota['alergias']);
    $meds_q=$db->prepare("SELECT COUNT(DISTINCT ri.medicamento) FROM consultas con JOIN recetas r ON r.consulta_id=con.id JOIN receta_items ri ON ri.receta_id=r.id WHERE con.mascota_id=? AND con.fecha>=DATE_SUB(CURDATE(),INTERVAL 30 DAY)");$meds_q->execute([$mascota_id]);$meds_activos=(int)$meds_q->fetchColumn();
}
$tel_pac=preg_replace('/[^0-9]/','',ltrim($mascota['telefono']??'','+'));if(strlen($tel_pac)<11)$tel_pac='51'.$tel_pac;
$foto_url_pac=!empty($mascota['foto'])&&file_exists(UPLOADS_PATH.'/'.$mascota['foto'])?BASE_URL.'/public/uploads/'.$mascota['foto']:null;
$edad_pac='';if($mascota&&$mascota['fecha_nacimiento']){$diff=(new DateTime())->diff(new DateTime($mascota['fecha_nacimiento']));$edad_pac=($diff->y>0?$diff->y.' año'.($diff->y>1?'s':'').' ':''). ($diff->m>0?$diff->m.' mes'.($diff->m>1?'es':''):'');$edad_pac=trim($edad_pac);}
$tipo_badge=['consulta'=>['b','#dbeafe','#1e3a8a'],'control'=>['b','#ede9fe','#4c1d95'],'emergencia'=>['b','#fee2e2','#7f1d1d'],'cirugia'=>['b','#fee2e2','#7f1d1d'],'vacuna'=>['b','#d1fae5','#065f46'],'hospitalizacion'=>['b','#fef3c7','#78350f']];
$tipo_labels=['consulta'=>'Consulta general','control'=>'Control','emergencia'=>'Emergencia','cirugia'=>'Post-cirugía','vacuna'=>'Vacunación','hospitalizacion'=>'Hospitalización'];

// ── LISTADO DE PACIENTES (puerta de entrada cuando no hay paciente elegido) ──
$lista_pacientes = [];
if (!$mascota_id) {
    $search_pac = trim($_GET['q'] ?? '');
    $wp = "m.estado='activo'"; $pp = [];
    if ($search_pac !== '') {
        $wp .= " AND (m.nombre LIKE ? OR cl.nombre LIKE ? OR m.especie LIKE ?)";
        $lk = "%$search_pac%"; $pp = [$lk,$lk,$lk];
    }
    try {
        $_rs = $db->query("SHOW COLUMNS FROM `mascotas` LIKE 'sede_id'")->fetchAll();
        if (!empty($_rs) && !verTodasSedes()) { $wp .= " AND m.sede_id=".getSede(); }
    } catch (Exception $e) {}
    // Orden por antigüedad de la PRIMERA consulta (criterio para presentar)
    $sqlp = "SELECT m.id, m.nombre, m.especie, m.foto, m.hc_numero,
                    cl.nombre AS dueno,
                    (SELECT COUNT(*) FROM consultas c2 WHERE c2.mascota_id=m.id) AS n_consultas,
                    (SELECT MAX(c3.fecha) FROM consultas c3 WHERE c3.mascota_id=m.id) AS ultima_visita,
                    (SELECT MIN(c4.fecha) FROM consultas c4 WHERE c4.mascota_id=m.id) AS primera_visita
             FROM mascotas m
             JOIN clientes cl ON cl.id=m.cliente_id
             WHERE $wp
             ORDER BY m.hc_numero ASC, m.id ASC";
    $stp = $db->prepare($sqlp); $stp->execute($pp);
    $lista_pacientes = $stp->fetchAll();
    // El HC viene PERMANENTE desde la columna mascotas.hc_numero (no se recalcula nunca)
    foreach ($lista_pacientes as &$_p) {
        $_p['hc_num'] = !empty($_p['hc_numero']) ? $_p['hc_numero'] : sprintf('HC-%04d', $_p['id']);
    }
    unset($_p);
}

// Número HC del paciente abierto: leer la columna (permanente)
$hc_num_actual = '';
if ($mascota_id) {
    try {
        $st_hc = $db->prepare("SELECT hc_numero FROM mascotas WHERE id=?");
        $st_hc->execute([$mascota_id]);
        $hc_num_actual = (string)$st_hc->fetchColumn();
    } catch (Exception $e) {}
}
?>

<style>
/* ── HISTORIA CLÍNICA LAYOUT ── */
/* Con mascota: 3 cols. Sin mascota: solo 2 cols (centro+derecho) */
.hc-layout{display:grid;gap:0;height:calc(100vh - 130px);overflow:hidden;background:var(--bg2);border:1px solid var(--border);border-radius:16px}
.hc-layout.with-mascota{grid-template-columns:230px 268px 1fr}
.hc-layout.no-mascota{grid-template-columns:300px 1fr}
/* Panel izquierdo */
.hc-left{border-right:1px solid var(--border);overflow-y:auto;display:flex;flex-direction:column}
.hc-pat-photo{width:96px;height:96px;border-radius:50%;object-fit:cover;border:3px solid var(--bg2);box-shadow:0 2px 12px rgba(0,0,0,.1);margin:0 auto 10px}
.hc-pat-emoji{width:96px;height:96px;border-radius:50%;background:var(--primary-l);display:flex;align-items:center;justify-content:center;font-size:44px;margin:0 auto 10px;border:3px solid var(--bg2);box-shadow:0 2px 12px rgba(0,0,0,.08)}
.hc-pat-id{display:inline-block;background:var(--primary-l);color:var(--primary-d);font-size:11px;font-weight:700;padding:3px 10px;border-radius:999px;margin-top:6px;font-family:monospace}
.hc-meta-row{display:flex;justify-content:space-between;align-items:flex-start;padding:7px 14px;font-size:12px;border-bottom:1px solid var(--border)}
.hc-meta-row:last-child{border-bottom:none}
.hc-meta-label{color:var(--text3);font-weight:500;flex-shrink:0}
.hc-meta-val{color:var(--text);font-weight:600;text-align:right;max-width:120px}
/* Panel centro */
.hc-center{border-right:1px solid var(--border);display:flex;flex-direction:column;overflow:hidden}
/* TABS como pills — sin scroll horizontal */
.hc-tabs-wrap{padding:8px 10px;border-bottom:1px solid var(--border);background:var(--bg3);flex-shrink:0}
.hc-tabs-pills{display:flex;gap:4px;flex-wrap:wrap}
.hc-tab-pill{
  padding:6px 14px;font-size:12px;font-weight:600;
  color:var(--text2);border:1.5px solid var(--border);
  background:var(--bg2);border-radius:999px;cursor:pointer;
  white-space:nowrap;transition:all .18s;
}
.hc-tab-pill.active{
  background:#0f766e;   /* verde oscuro — texto blanco siempre visible */
  color:#ffffff;
  border-color:#0f766e;
  box-shadow:0 2px 10px rgba(15,118,110,.4);
  font-weight:700;
}
.hc-tab-pill:hover:not(.active){
  color:var(--primary);
  border-color:var(--primary);
  background:var(--primary-l);
}
/* Buscador con autocomplete */
.hc-search-wrap{padding:8px;border-bottom:1px solid var(--border);flex-shrink:0;position:relative}
.hc-search-input{width:100%;padding:7px 34px 7px 10px;border:1.5px solid var(--border);border-radius:8px;font-size:12px;color:var(--text);background:var(--bg2);outline:none;font-family:var(--font)}
.hc-search-input:focus{border-color:var(--primary);background:#fff;box-shadow:0 0 0 3px var(--primary-glow)}
.hc-search-btn{position:absolute;right:14px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;font-size:14px;color:var(--text3)}
.hc-autocomplete{position:absolute;top:100%;left:8px;right:8px;background:var(--bg2);border:1px solid var(--border);border-radius:10px;box-shadow:0 8px 30px rgba(0,0,0,.12);z-index:200;max-height:220px;overflow-y:auto;display:none}
.hc-ac-item{display:flex;align-items:center;gap:10px;padding:9px 13px;cursor:pointer;transition:background .12s;border-bottom:1px solid var(--border)}
.hc-ac-item:last-child{border-bottom:none}
.hc-ac-item:hover,.hc-ac-item.focused{background:var(--primary-l)}
.hc-ac-photo{width:30px;height:30px;border-radius:8px;object-fit:cover;flex-shrink:0;border:1px solid var(--border)}
.hc-ac-emoji{width:30px;height:30px;border-radius:8px;background:var(--bg3);display:flex;align-items:center;justify-content:center;font-size:14px;flex-shrink:0}
.hc-timeline{flex:1;overflow-y:auto;padding:6px}
/* Timeline items */
.hc-item{display:flex;gap:10px;padding:9px 10px;border-radius:10px;cursor:pointer;transition:all .15s;margin-bottom:2px;align-items:flex-start}
.hc-item:hover{background:var(--bg3)}
.hc-item.selected{background:var(--primary-l);border:1px solid rgba(30,168,161,.25)}
.hc-dot{width:10px;height:10px;border-radius:50%;border:2px solid var(--border);background:var(--bg2);flex-shrink:0;margin-top:5px;transition:all .15s}
.hc-item.selected .hc-dot,.hc-item:hover .hc-dot{border-color:var(--primary);background:var(--primary)}
.hc-fecha-col{text-align:center;flex-shrink:0;width:32px}
.hc-dia{font-size:15px;font-weight:800;color:var(--text);line-height:1}
.hc-mes{font-size:9px;font-weight:700;color:var(--text3);text-transform:uppercase;letter-spacing:.4px;margin-top:1px}
.hc-anio{font-size:9px;color:var(--text4)}
.hc-item-body{flex:1;min-width:0}
.hc-item-tipo{font-size:12px;font-weight:700;color:var(--text);margin-bottom:2px;display:flex;align-items:center;gap:5px;flex-wrap:wrap}
.hc-item-diag{font-size:11px;color:var(--text3);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.hc-item-vet{font-size:10px;color:var(--text3);margin-top:1px}
.hc-receta-pill{font-size:10px;font-weight:600;background:#d1fae5;color:#065f46;padding:1px 7px;border-radius:999px}
/* Panel derecho */
.hc-right{overflow-y:auto;display:flex;flex-direction:column}
.hc-det-head{padding:14px 18px;border-bottom:1px solid var(--border);flex-shrink:0}
.hc-det-fecha{font-size:15px;font-weight:700;color:var(--text)}
.hc-det-meta{font-size:12px;color:var(--text3);margin-top:2px}
.hc-vitales{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;padding:12px 18px;border-bottom:1px solid var(--border);flex-shrink:0}
.vital-card{background:var(--bg3);border:1px solid var(--border);border-radius:10px;padding:10px 12px}
.vital-icon{font-size:18px;margin-bottom:5px}
.vital-label{font-size:10px;color:var(--text3);font-weight:500;margin-bottom:1px}
.vital-val{font-size:17px;font-weight:800;color:var(--text);font-family:var(--font-display);line-height:1}
.vital-status{font-size:10px;font-weight:600;margin-top:3px;padding:2px 6px;border-radius:999px;display:inline-block}
.hc-det-body{flex:1;overflow-y:auto;padding:14px 18px}
.hc-det-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.hc-sec-box{background:var(--bg3);border:1px solid var(--border);border-radius:10px;padding:12px 13px}
.hc-sec-box-title{font-size:11px;font-weight:700;color:var(--text2);display:flex;align-items:center;gap:5px;margin-bottom:8px}
.med-table{width:100%;border-collapse:collapse;font-size:12px}
.med-table th{padding:5px 7px;text-align:left;font-size:10px;font-weight:700;color:var(--text3);text-transform:uppercase;letter-spacing:.4px;border-bottom:1px solid var(--border);background:var(--bg3)}
.med-table td{padding:7px 7px;border-bottom:1px solid var(--border);color:var(--text2);vertical-align:middle}
.med-table tr:last-child td{border-bottom:none}
.arch-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:7px}
.arch-item{border-radius:8px;overflow:hidden;border:1px solid var(--border);aspect-ratio:1;background:var(--bg3);display:flex;align-items:center;justify-content:center;cursor:pointer;transition:all .15s;text-decoration:none}
.arch-item:hover{border-color:var(--primary);transform:scale(1.03)}
.arch-item img{width:100%;height:100%;object-fit:cover}
.arch-add{flex-direction:column;gap:3px;color:var(--text3);font-size:11px;font-weight:600}
.hc-bottom-bar{padding:10px 18px;border-top:1px solid var(--border);background:var(--bg2);display:flex;gap:7px;flex-shrink:0;flex-wrap:wrap}
.hc-resumen{padding:12px 14px;border-top:1px solid var(--border)}
.hc-resumen-title{font-size:11px;font-weight:700;color:var(--text2);text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px}
.hc-res-row{display:flex;justify-content:space-between;align-items:center;padding:5px 0;border-bottom:1px solid var(--border);font-size:11px}
.hc-res-row:last-child{border-bottom:none}
.hc-empty-small{text-align:center;padding:32px 16px;color:var(--text3)}

/* ── MÓVIL: Historia Clínica ── */
@media(max-width:768px) {
  /* Layout en columna única */
  .hc-layout {
    grid-template-columns:1fr !important;
    height:auto !important;
    overflow:visible !important;
    border-radius:12px !important;
  }
  .hc-layout.with-mascota,
  .hc-layout.no-mascota {
    grid-template-columns:1fr !important;
  }

  /* Ocultar panel izquierdo de paciente en móvil */
  .hc-left { display:none !important; }

  /* Panel centro: lista de consultas */
  .hc-center {
    border-right:none !important;
    border-bottom:1px solid var(--border);
    max-height:420px;
  }

  /* Panel derecho: detalle */
  .hc-right {
    max-height:none !important;
    border-left:none !important;
  }

  /* Vitales en 2 cols en móvil */
  .hc-vitales { grid-template-columns:1fr 1fr !important; }

  /* Detalle grid en 1 col */
  .hc-det-grid { grid-template-columns:1fr !important; }

  /* Archivo grid en 3 cols */
  .arch-grid { grid-template-columns:repeat(3,1fr) !important; }

  /* Tabs en wrap */
  .hc-tabs-pills { gap:4px; }
  .hc-tab-pill { font-size:11px !important; padding:5px 10px !important; }

  /* Barra bottom en columna */
  .hc-bottom-bar { flex-direction:column; gap:6px; }
  .hc-bottom-bar .btn { width:100%; justify-content:center; }

  /* Header de nueva atención */
  .hc-nueva-header { flex-direction:column !important; gap:8px; }
}
</style>

<?php if(($_GET['msg']??'')==='err'): ?>
  <div class="alert alert-danger" style="margin-bottom:12px"><span class="alert-icon">⚠️</span>
    <?= clean($_GET['e'] ?? 'No se pudo guardar la consulta.') ?>
  </div>
<?php endif; ?>

<?php if($msg==='ok'||($_GET['msg']??'')==='ok'): ?>
<div class="alert alert-success mb-2"><span class="alert-icon">✅</span>Consulta registrada correctamente.</div>
<?php endif; ?>

<!-- Barra superior cuando NO hay mascota seleccionada -->
<?php if(!$mascota_id): ?>
<div class="flex items-center justify-between mb-3">
  <div><div class="page-title">📋 Historia Clínica</div><div class="page-desc"><?= count($lista_pacientes) ?> paciente<?= count($lista_pacientes)!=1?'s':'' ?></div></div>
  <a href="?p=historial&action=nueva" class="btn btn-primary">＋ Nueva Atención</a>
</div>

<!-- ══ LISTADO DE PACIENTES (puerta de entrada) ══ -->
<style>
.hcp-search{width:100%;height:42px;border:1px solid var(--border);border-radius:12px;padding:0 14px 0 40px;font-size:14px;background:var(--bg2) url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" stroke="%2394a3b8" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>') no-repeat 14px center;}
.hcp-list{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-top:14px}
@media(max-width:1280px){.hcp-list{grid-template-columns:repeat(2,1fr)}}
@media(max-width:768px){.hcp-list{grid-template-columns:1fr}}
.hcp-row{display:flex;align-items:center;gap:11px;background:var(--bg2);border:1.5px solid var(--border);border-radius:14px;padding:11px 13px;text-decoration:none;color:inherit;transition:all .15s;min-width:0}
.hcp-row:hover{box-shadow:0 3px 14px rgba(0,0,0,.1);transform:translateY(-1px)}
.hcp-num{font-family:monospace;font-size:11px;font-weight:700;color:var(--primary-d);background:var(--primary-l);border:1px solid var(--primary);border-radius:8px;padding:4px 7px;min-width:56px;text-align:center;flex-shrink:0}
.hcp-avatar{width:42px;height:42px;border-radius:11px;object-fit:cover;flex-shrink:0;background:var(--bg3);display:flex;align-items:center;justify-content:center;font-size:20px}
.hcp-info{flex:1;min-width:0;overflow:hidden}
.hcp-info > div{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.hcp-name{font-size:13px;font-weight:700;color:var(--text)}
.hcp-sub{font-size:11px;color:var(--text3);margin-top:1px}
.hcp-visit{text-align:right;flex-shrink:0;font-size:11px}
.hcp-visit .l{font-size:9px;color:var(--text3)}
.hcp-visit .v{font-weight:600;color:var(--text2)}
.hcp-chev{color:var(--text3);flex-shrink:0;font-size:18px}
.hcp-empty{grid-column:1/-1;text-align:center;padding:48px 20px;color:var(--text3)}
@media(max-width:768px){.hcp-num{min-width:54px;font-size:11px}}
</style>

<form method="GET" style="margin-bottom:2px">
  <input type="hidden" name="p" value="historial">
  <input class="hcp-search" type="text" name="q" value="<?= clean($_GET['q']??'') ?>" placeholder="Buscar por mascota, dueño o especie..." autofocus>
</form>

<?php $ei_pac=['perro'=>'🐕','gato'=>'🐈','conejo'=>'🐰','ave'=>'🐦','reptil'=>'🦎','roedor'=>'🐭','otro'=>'🐾'];
$espcol_pac=['perro'=>'#10b981','gato'=>'#6366f1','conejo'=>'#f59e0b','ave'=>'#3b82f6','reptil'=>'#84cc16','roedor'=>'#f97316','otro'=>'#8b5cf6']; ?>
<div class="hcp-list">
  <?php if(empty($lista_pacientes)): ?>
    <div class="hcp-empty">
      <div style="font-size:40px;opacity:.3;margin-bottom:10px">📋</div>
      <div style="font-size:14px"><?= !empty($_GET['q'])?'No se encontraron pacientes con esa búsqueda.':'Aún no hay pacientes registrados.' ?></div>
    </div>
  <?php else: foreach($lista_pacientes as $p):
    $foto_p = (!empty($p['foto']) && file_exists(UPLOADS_PATH.'/'.$p['foto'])) ? BASE_URL.'/public/uploads/'.$p['foto'] : null;
    $uv = $p['ultima_visita'] ? date('d/m/Y', strtotime($p['ultima_visita'])) : '—';
    $ecp = $espcol_pac[$p['especie']] ?? '#10b981';
  ?>
  <a class="hcp-row" href="?p=historial&mascota_id=<?= (int)$p['id'] ?>" style="border-color:<?= $ecp ?>">
    <span class="hcp-num"><?= $p['hc_num'] ?></span>
    <?php if($foto_p): ?>
      <img class="hcp-avatar" src="<?= $foto_p ?>" alt="">
    <?php else: ?>
      <span class="hcp-avatar" style="background:<?= $ecp ?>18"><?= $ei_pac[$p['especie']] ?? '🐾' ?></span>
    <?php endif; ?>
    <div class="hcp-info">
      <div class="hcp-name"><?= clean($p['nombre']) ?> <span style="font-size:11px;color:var(--text3);font-weight:400">· <?= ucfirst(clean($p['especie'])) ?></span></div>
      <div class="hcp-sub"><?= clean($p['dueno']) ?></div>
      <div class="hcp-sub"><?= (int)$p['n_consultas'] ?> consulta<?= $p['n_consultas']!=1?'s':'' ?> · Últ: <?= $uv ?></div>
    </div>
    <span class="hcp-chev">›</span>
  </a>
  <?php endforeach; endif; ?>
</div>

<?php else: ?>
<!-- LAYOUT PRINCIPAL (detalle del paciente) -->
<div class="hc-layout with-mascota">

  <!-- ══ COL 1: PANEL PACIENTE (solo si hay mascota) ══ -->
  <?php if($mascota): ?>
  <div class="hc-left">
    <div style="padding:18px 14px;text-align:center;border-bottom:1px solid var(--border)">
      <div style="position:relative;width:96px;margin:0 auto 10px;cursor:pointer" onclick="document.getElementById('inp-foto-hc').click()">
        <?php if($foto_url_pac): ?>
        <img src="<?= $foto_url_pac ?>" class="hc-pat-photo" style="width:96px;height:96px" alt="">
        <?php else: ?>
        <div class="hc-pat-emoji"><?= $ei[$mascota['especie']]??'🐾' ?></div>
        <?php endif; ?>
        <div style="position:absolute;bottom:3px;right:3px;background:rgba(0,0,0,.5);color:#fff;border-radius:50%;width:22px;height:22px;display:flex;align-items:center;justify-content:center;font-size:10px">📷</div>
      </div>
      <input type="file" id="inp-foto-hc" accept="image/*" style="display:none" onchange="uploadFotoHC(this,<?= $mascota['id'] ?>)">
      <div style="font-size:16px;font-weight:800;color:var(--text);display:flex;align-items:center;justify-content:center;gap:5px">
        <?= clean($mascota['nombre']) ?>
        <span style="color:<?= $mascota['sexo']==='macho'?'#3b82f6':'#ec4899' ?>"><?= $mascota['sexo']==='macho'?'♂':'♀' ?></span>
      </div>
      <div style="font-size:11px;color:var(--text3);margin-top:2px"><?= clean($mascota['raza']??ucfirst($mascota['especie'])) ?></div>
      <?php if($edad_pac): ?><div style="font-size:11px;color:var(--text3)"><?= $edad_pac ?></div><?php endif; ?>
      <div class="hc-pat-id"><?= $hc_num_actual ?: ('ID: MASC-'.str_pad($mascota['id'],6,'0',STR_PAD_LEFT)) ?></div>
    </div>
    <div style="padding:0 0 8px">
      <div class="hc-meta-row"><span class="hc-meta-label">⚖️ Peso</span><span class="hc-meta-val"><?= $mascota['peso']?clean($mascota['peso']).' kg':'—' ?></span></div>
      <div class="hc-meta-row"><span class="hc-meta-label">📅 Nacimiento</span><span class="hc-meta-val"><?= $mascota['fecha_nacimiento']?date('d/m/Y',strtotime($mascota['fecha_nacimiento'])):'—' ?></span></div>
      <div class="hc-meta-row"><span class="hc-meta-label">✂️ Esterilizado</span><span class="hc-meta-val" style="color:<?= ($mascota['esterilizado']??0)?'var(--success)':'var(--danger)' ?>"><?= ($mascota['esterilizado']??0)?'Sí':'No' ?></span></div>
      <div class="hc-meta-row"><span class="hc-meta-label">👤 Propietario</span><span class="hc-meta-val"><a href="https://wa.me/<?= $tel_pac ?>" target="_blank" style="color:var(--primary);text-decoration:none"><?= clean($mascota['dueno']) ?></a></span></div>
    </div>
    <?php if(!empty($alertas_pac)): ?>
    <div style="padding:10px 14px;border-top:1px solid var(--border);background:#fff9f9">
      <div style="font-size:11px;font-weight:700;color:var(--danger);margin-bottom:6px">⚠️ Alertas</div>
      <?php foreach($alertas_pac as $al): ?>
      <div style="font-size:11px;color:<?= $al['tipo']==='danger'?'var(--danger)':'var(--warning-d)' ?>;margin-bottom:3px"><?= $al['icon'] ?> <?= clean($al['msg']) ?></div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <div class="hc-resumen">
      <div class="hc-resumen-title">Resumen rápido</div>
      <div class="hc-res-row"><span style="color:var(--text3)">Última consulta</span><span style="font-weight:600"><?= $ultima_consulta?date('d/m/Y',strtotime($ultima_consulta['fecha'])):'—' ?></span></div>
      <div class="hc-res-row"><span style="color:var(--text3)">Diagnóstico recurrente</span><span style="font-weight:600;text-align:right;max-width:90px;font-size:11px"><?= $diag_recurrente ?></span></div>
      <div class="hc-res-row"><span style="color:var(--text3)">Alergias</span><span style="font-weight:600"><?= $alergias_pac ?></span></div>
      <div class="hc-res-row"><span style="color:var(--text3)">Meds. activos</span><span style="font-weight:600"><?= $meds_activos ?> trat.</span></div>
    </div>
    <div style="padding:10px 14px;border-top:1px solid var(--border);margin-top:auto">
      <a href="?p=mascotas&action=ver&id=<?= $mascota_id ?>" class="btn btn-ghost btn-sm btn-block" style="justify-content:center;margin-bottom:6px">Ver ficha →</a>
      <a href="?p=historial&action=nueva&mascota_id=<?= $mascota_id ?>" class="btn btn-primary btn-sm btn-block" style="justify-content:center">＋ Nueva atención</a>
    </div>
  </div>
  <?php endif; ?>

  <!-- ══ COL 2: TIMELINE ══ -->
  <div class="hc-center">
    <!-- TABS como pills — sin slider horizontal -->
    <div class="hc-tabs-wrap">
      <div class="hc-tabs-pills">
        <?php
        $tabs=['consultas'=>'Consultas','vacunas'=>'Vacunas','examenes'=>'Exámenes','cirugias'=>'Cirugías','recetas'=>'Recetas'];
        foreach($tabs as $tk=>$tv):
        ?>
        <button class="hc-tab-pill <?= $tab_act===$tk?'active':'' ?>"
                onclick="switchHCTab('<?= $tk ?>')"><?= $tv ?></button>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- BUSCADOR con autocomplete -->
    <div class="hc-search-wrap" id="hcSearchWrap">
      <input class="hc-search-input" id="hcSearchInput"
             placeholder="Buscar paciente, diagnóstico, medicamento..."
             value="<?= clean($search) ?>"
             autocomplete="off"
             oninput="hcAutoComplete(this.value)"
             onkeydown="hcKeyDown(event)">
      <button class="hc-search-btn" onclick="hcSubmitSearch()">🔍</button>
      <div class="hc-autocomplete" id="hcDropdown"></div>
    </div>

    <!-- Timeline -->
    <div class="hc-timeline">
      <?php if($tab_act==='vacunas'): ?>
        <?php if(empty($vacunas_hc)): ?>
        <div class="hc-empty-small"><div style="font-size:32px;margin-bottom:8px;opacity:.3">💉</div><div style="font-size:12px">Sin vacunas registradas</div>
          <?php if($mascota_id): ?><a href="?p=vacunas&action=nueva" class="btn btn-primary btn-xs" style="margin-top:10px">+ Registrar vacuna</a><?php endif; ?>
        </div>
        <?php else:
          $mes_abr=['01'=>'ENE','02'=>'FEB','03'=>'MAR','04'=>'ABR','05'=>'MAY','06'=>'JUN','07'=>'JUL','08'=>'AGO','09'=>'SEP','10'=>'OCT','11'=>'NOV','12'=>'DIC'];
          foreach($vacunas_hc as $vh):
            $fap=strtotime($vh['fecha_aplicacion']);
            $prox = !empty($vh['proxima_dosis']) ? strtotime($vh['proxima_dosis']) : null;
            $estv = $vh['estado']??'aplicada';
            if($estv==='anulada'){$vb='background:#f1f5f9;color:#64748b';$vt='Anulada';}
            elseif($estv==='completada'){$vb='background:#dcfce7;color:#15803d';$vt='Completada';}
            elseif($prox && $prox<time()){$vb='background:#fee2e2;color:#b91c1c';$vt='Vencida';}
            elseif($prox && $prox<=time()+7*86400){$vb='background:#fef3c7;color:#b45309';$vt='Por vencer';}
            else{$vb='background:#ccfbf1;color:#0f766e';$vt='Vigente';}
        ?>
        <div class="hc-item" style="cursor:default">
          <div class="hc-dot" style="background:#0ea5a4"></div>
          <div class="hc-fecha-col">
            <div class="hc-dia"><?= date('d',$fap) ?></div>
            <div class="hc-mes"><?= $mes_abr[date('m',$fap)]??date('M',$fap) ?></div>
            <div class="hc-anio"><?= date('Y',$fap) ?></div>
          </div>
          <div class="hc-item-body">
            <div class="hc-item-tipo">💉 <?= clean($vh['tipo_vacuna']) ?> <span class="badge" style="<?= $vb ?>;font-size:9px;padding:1px 7px;border-radius:999px;margin-left:4px"><?= $vt ?></span></div>
            <div class="hc-item-diag"><?= $vh['proxima_dosis']?'Próxima dosis: '.date('d/m/Y',$prox):($vh['laboratorio']?clean($vh['laboratorio']):'—') ?></div>
            <div class="hc-item-vet"><?= $vh['veterinario']?'Dr/a. '.clean($vh['veterinario']):'' ?><?= $vh['lote']?' · Lote '.clean($vh['lote']):'' ?></div>
          </div>
        </div>
        <?php endforeach; endif; ?>
        <div style="text-align:center;padding:12px">
          <a href="?p=vacunas<?= $mascota_id?'&mascota_id='.$mascota_id:'' ?>" style="font-size:11px;color:var(--primary);font-weight:600;text-decoration:none">Ir al módulo Vacunación →</a>
        </div>
      <?php elseif(empty($consultas)): ?>
      <div class="hc-empty-small"><div style="font-size:32px;margin-bottom:8px;opacity:.3">📋</div><div style="font-size:12px">Sin registros encontrados</div>
        <?php if($mascota_id): ?><a href="?p=historial&action=nueva&mascota_id=<?= $mascota_id ?>" class="btn btn-primary btn-xs" style="margin-top:10px">+ Nueva atención</a><?php endif; ?>
      </div>
      <?php else: foreach($consultas as $con):
        $is_sel = $consulta_id && $con['id']==$sel_id; // solo seleccionar si cid explícito en URL
        $mes_abr=['01'=>'ENE','02'=>'FEB','03'=>'MAR','04'=>'ABR','05'=>'MAY','06'=>'JUN','07'=>'JUL','08'=>'AGO','09'=>'SEP','10'=>'OCT','11'=>'NOV','12'=>'DIC'];
        $fcon=strtotime($con['fecha']);
        $fecha_con=date('d',$fcon);$mes_con=$mes_abr[date('m',$fcon)]??date('M',$fcon);$anio_con=date('Y',$fcon);
      ?>
      <div class="hc-item <?= $is_sel?'selected':'' ?>"
           onclick="selectConsulta(<?= $con['id'] ?>)"
           data-id="<?= $con['id'] ?>">
        <div class="hc-dot"></div>
        <div class="hc-fecha-col">
          <div class="hc-dia"><?= $fecha_con ?></div>
          <div class="hc-mes"><?= $mes_con ?></div>
          <div class="hc-anio"><?= $anio_con ?></div>
        </div>
        <div class="hc-item-body">
          <div class="hc-item-tipo">
            <?= $tipo_labels[$con['tipo']]??ucfirst($con['tipo']) ?>
            <?php if($con['tiene_receta']): ?><span class="hc-receta-pill">Con receta</span><?php endif; ?>
          </div>
          <div class="hc-item-diag"><?= clean(substr($con['diagnostico'],0,42)) ?></div>
          <div class="hc-item-vet">Dr/a. <?= clean($con['veterinario']) ?></div>
        </div>
        <span style="font-size:13px;flex-shrink:0;color:var(--text3)">›</span>
      </div>
      <?php endforeach; ?>
      <?php if(count($consultas)>=10): ?>
      <div style="text-align:center;padding:12px">
        <a href="?p=historial&mascota_id=<?= $mascota_id ?>" style="font-size:11px;color:var(--primary);font-weight:600;text-decoration:none">Ver más consultas ↓</a>
      </div>
      <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>

  <!-- ══ COL 3: DETALLE CONSULTA ══ -->
  <div class="hc-right" id="hc-detalle">
    <?php if($consulta_sel): ?>
    <!-- Header detalle -->
    <div class="hc-det-head">
      <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px">
        <div>
          <div class="hc-det-fecha">Consulta del <?= date('d \d\e F \d\e Y',strtotime($consulta_sel['fecha'])) ?></div>
          <div class="hc-det-meta">
            <?= date('H:i',strtotime($consulta_sel['fecha'])) ?> hs · Dr/a. <?= clean($consulta_sel['veterinario']) ?>
            <?php if(!empty($receta_sel)): ?>&nbsp;<span style="background:#d1fae5;color:#065f46;font-size:10px;font-weight:700;padding:2px 8px;border-radius:999px">Con receta</span><?php endif; ?>
          </div>
        </div>
        <div style="display:flex;align-items:center;gap:8px;flex-shrink:0">
          <a href="?p=historial&action=nueva&cid=<?= (int)$consulta_sel['id'] ?>" class="btn btn-ghost btn-sm" style="border-color:var(--primary);color:var(--primary)" title="Editar esta consulta">✏️ Editar</a>
          <?php $tbl=$tipo_badge[$consulta_sel['tipo']]??['b','#f1f5f9','#475569']; ?>
          <span style="background:<?= $tbl[1] ?>;color:<?= $tbl[2] ?>;font-size:11px;font-weight:700;padding:4px 12px;border-radius:999px;white-space:nowrap"><?= $tipo_labels[$consulta_sel['tipo']]??ucfirst($consulta_sel['tipo']) ?></span>
        </div>
      </div>
    </div>

    <!-- Signos vitales -->
    <?php if($consulta_sel['peso_actual']||$consulta_sel['temperatura']||$consulta_sel['frecuencia_cardiaca']||$consulta_sel['frecuencia_respiratoria']): ?>
    <div class="hc-vitales">
      <?php if($consulta_sel['peso_actual']): ?>
      <div class="vital-card">
        <div class="vital-icon">⚖️</div>
        <div class="vital-label">Peso</div>
        <div class="vital-val"><?= clean($consulta_sel['peso_actual']) ?> <span style="font-size:12px;font-weight:500">kg</span></div>
        <?php $dp=$consulta_sel['peso_actual']-($mascota['peso']??$consulta_sel['peso_actual']); if($dp!=0): ?>
        <div class="vital-status" style="background:<?= $dp>0?'#d1fae5':'#fee2e2' ?>;color:<?= $dp>0?'#065f46':'#7f1d1d' ?>"><?= ($dp>0?'+':'').number_format($dp,2) ?> kg</div>
        <?php endif; ?>
      </div>
      <?php endif; ?>
      <?php if($consulta_sel['temperatura']): $temp=(float)$consulta_sel['temperatura']; $tst=$temp<38?['Baja','#dbeafe','#1e3a8a']:($temp>39.2?['Elevada','#fee2e2','#7f1d1d']:['Normal','#d1fae5','#065f46']); ?>
      <div class="vital-card">
        <div class="vital-icon">🌡️</div>
        <div class="vital-label">Temperatura</div>
        <div class="vital-val"><?= $temp ?> <span style="font-size:12px;font-weight:500">°C</span></div>
        <div class="vital-status" style="background:<?= $tst[1] ?>;color:<?= $tst[2] ?>"><?= $tst[0] ?></div>
      </div>
      <?php endif; ?>
      <?php if($consulta_sel['frecuencia_cardiaca']): $fc=(int)$consulta_sel['frecuencia_cardiaca'];$fst=$fc<60||$fc>160?['Anormal','#fee2e2','#7f1d1d']:['Normal','#d1fae5','#065f46']; ?>
      <div class="vital-card">
        <div class="vital-icon">❤️</div>
        <div class="vital-label">Frecuencia cardíaca</div>
        <div class="vital-val"><?= $fc ?> <span style="font-size:12px;font-weight:500">rpm</span></div>
        <div class="vital-status" style="background:<?= $fst[1] ?>;color:<?= $fst[2] ?>"><?= $fst[0] ?></div>
      </div>
      <?php endif; ?>
      <?php if($consulta_sel['frecuencia_respiratoria']): $fr=(int)$consulta_sel['frecuencia_respiratoria'];$frst=$fr<15||$fr>40?['Anormal','#fee2e2','#7f1d1d']:['Normal','#d1fae5','#065f46']; ?>
      <div class="vital-card">
        <div class="vital-icon">🫁</div>
        <div class="vital-label">Frecuencia respiratoria</div>
        <div class="vital-val"><?= $fr ?> <span style="font-size:12px;font-weight:500">rpm</span></div>
        <div class="vital-status" style="background:<?= $frst[1] ?>;color:<?= $frst[2] ?>"><?= $frst[0] ?></div>
      </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Otros signos / examen físico -->
    <?php
    $_ex = array_filter([
      '💧 % Deshidratación'    => $consulta_sel['deshidratacion']??'',
      '👄 Mucosas'             => $consulta_sel['mucosas']??'',
      '😮‍💨 Reflejo tusígeno'  => $consulta_sel['reflejo_tusigeno']??'',
      '🔗 Linfonodos'          => $consulta_sel['linfonodos']??'',
      '✋ Palpación abdominal'  => $consulta_sel['palpacion_abdominal']??'',
    ], fn($v)=>trim((string)$v)!=='');
    if($_ex): ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:8px;margin:0 14px 12px">
      <?php foreach($_ex as $lbl=>$val): ?>
      <div style="background:var(--bg3);border:1px solid var(--border);border-radius:10px;padding:8px 12px">
        <div style="font-size:10px;color:var(--text3);text-transform:uppercase;letter-spacing:.4px;font-weight:700"><?= $lbl ?></div>
        <div style="font-size:13px;color:var(--text);font-weight:600;margin-top:2px"><?= clean($val) ?></div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Cuerpo del detalle -->
    <div class="hc-det-body">
      <div class="hc-det-grid">
        <!-- Síntomas -->
        <?php if($consulta_sel['sintomas']): ?>
        <div class="hc-sec-box">
          <div class="hc-sec-box-title"><span>🔶</span> <span style="color:var(--warning-d)">Síntomas / Motivo</span></div>
          <div style="font-size:12px;color:var(--text);font-weight:600;margin-bottom:4px"><?= clean(strtok($consulta_sel['sintomas'],"\n")) ?></div>
          <div style="font-size:12px;color:var(--text2);line-height:1.6"><?= nl2br(clean($consulta_sel['sintomas'])) ?></div>
        </div>
        <?php endif; ?>
        <!-- Diagnóstico -->
        <?php if($consulta_sel['diagnostico']): ?>
        <div class="hc-sec-box">
          <div class="hc-sec-box-title"><span style="color:var(--danger)">🔴</span> <span style="color:var(--danger-d)">Diagnóstico</span></div>
          <div style="font-size:12px;color:var(--text);font-weight:600;margin-bottom:4px"><?= clean(strtok($consulta_sel['diagnostico'],"\n")) ?></div>
          <div style="font-size:12px;color:var(--text2);line-height:1.6"><?= nl2br(clean($consulta_sel['diagnostico'])) ?></div>
        </div>
        <?php endif; ?>
        <!-- Tratamiento -->
        <?php if($consulta_sel['tratamiento']): ?>
        <div class="hc-sec-box">
          <div class="hc-sec-box-title"><span>📋</span> <span style="color:var(--info-d)">Tratamiento</span></div>
          <div style="font-size:12px;color:var(--text2);line-height:1.8">
            <?php foreach(explode("\n",clean($consulta_sel['tratamiento'])) as $line): if(trim($line)): ?>
            <div style="display:flex;align-items:flex-start;gap:6px">• <?= trim($line) ?></div>
            <?php endif; endforeach; ?>
          </div>
        </div>
        <?php endif; ?>
        <!-- Medicamentos recetados -->
        <?php if(!empty($receta_sel)): ?>
        <div class="hc-sec-box">
          <div class="hc-sec-box-title"><span>💊</span> <span style="color:var(--purple-d)">Medicamentos recetados</span></div>
          <table class="med-table">
            <thead><tr><th>Medicamento</th><th>Dosis</th><th>Frecuencia</th><th>Duración</th><th>Vía</th></tr></thead>
            <tbody>
              <?php foreach($receta_sel as $ri): ?>
              <tr>
                <td style="font-weight:600;color:var(--text)"><?= clean($ri['medicamento']) ?></td>
                <td><?= clean($ri['dosis']) ?></td>
                <td><?= clean($ri['frecuencia']) ?></td>
                <td><?= clean($ri['duracion']) ?></td>
                <td><?= clean($ri['via']) ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <?php if($receta_sel[0]['indicaciones']??''): ?>
          <div style="font-size:11px;color:var(--text3);margin-top:8px;line-height:1.6"><?= nl2br(clean($receta_sel[0]['indicaciones'])) ?></div>
          <?php endif; ?>
        </div>
        <?php endif; ?>
      </div>

      <!-- Notas veterinario -->
      <?php if($consulta_sel['observaciones']): ?>
      <div class="hc-sec" style="margin-top:14px">
        <div class="hc-sec-box" style="background:#fffbeb;border-color:#fde68a">
          <div class="hc-sec-box-title"><span>📝</span> <span style="color:#78350f">Notas del veterinario</span></div>
          <div style="font-size:12px;color:#78350f;line-height:1.7"><?= nl2br(clean($consulta_sel['observaciones'])) ?></div>
        </div>
      </div>
      <?php endif; ?>

      <!-- Archivos adjuntos -->
      <?php if(!empty($archivos_sel)): ?>
      <div class="hc-sec" style="margin-top:14px">
        <div class="hc-sec-box">
          <div class="hc-sec-box-title"><span>📎</span> <span style="color:var(--success-d)">Archivos adjuntos</span></div>
          <div class="arch-grid">
            <?php foreach($archivos_sel as $arch):
              $arch_url=BASE_URL.'/public/uploads/'.$arch['ruta'];
              $is_img=strpos($arch['mime_type']??'','image')===0;
            ?>
            <a href="<?= $arch_url ?>" target="_blank" class="arch-item" title="<?= clean($arch['nombre']) ?>">
              <?php if($is_img): ?><img src="<?= $arch_url ?>" alt=""><?php else: ?><span style="font-size:22px">📄</span><?php endif; ?>
            </a>
            <?php endforeach; ?>
            <a href="?p=historial&action=nueva&mascota_id=<?= $mascota_id ?>" class="arch-item arch-add">
              <span style="font-size:22px">➕</span><span>Agregar</span>
            </a>
          </div>
        </div>
      </div>
      <?php endif; ?>

      <?php if($consulta_sel['proximo_control']): ?>
      <div style="background:var(--info-l);border:1px solid var(--info);border-radius:10px;padding:10px 14px;margin-top:14px;font-size:12px;color:var(--info-d)">
        📅 <strong>Próximo control:</strong> <?= date('d/m/Y',strtotime($consulta_sel['proximo_control'])) ?>
      </div>
      <?php endif; ?>
    </div>

    <!-- Barra de acciones inferior -->
    <div class="hc-bottom-bar">
      <a href="?p=historial&action=nueva&mascota_id=<?= $mascota_id ?>" class="btn btn-primary btn-sm">＋ Nueva atención</a>
      <?php if(!empty($receta_sel)): ?>
      <a href="?p=historial&action=nueva&mascota_id=<?= $mascota_id ?>" class="btn btn-ghost btn-sm">🔄 Repetir receta</a>
      <a href="?p=recetas&action=imprimir&id=<?= $receta_sel[0]['receta_id']??0 ?>" target="_blank" class="btn btn-ghost btn-sm">🖨️ Imprimir</a>
      <a href="?p=recetas&action=imprimir&id=<?= $receta_sel[0]['receta_id']??0 ?>" target="_blank" class="btn btn-ghost btn-sm">📄 PDF</a>
      <?php endif; ?>
      <?php
      $tel_wa=preg_replace('/[^0-9]/','',ltrim($consulta_sel['telefono']??'','+'));
      if(strlen($tel_wa)<11)$tel_wa='51'.$tel_wa;
      $wa="🏥 *VetPro — Consulta registrada*\n\nPaciente: *{$consulta_sel['mascota']}*\nFecha: ".date('d/m/Y H:i',strtotime($consulta_sel['fecha']))."\nDiagnóstico: {$consulta_sel['diagnostico']}\n\nSi tiene alguna consulta, no dude en comunicarse. 🐾";
      ?>
      <a href="https://wa.me/<?= $tel_wa ?>?text=<?= rawurlencode($wa) ?>" target="_blank" class="btn btn-wa btn-sm" style="margin-left:auto">💬 WhatsApp</a>
    </div>

    <?php else: ?>
    <div class="hc-empty">
      <div style="font-size:48px;margin-bottom:14px;opacity:.2">📋</div>
      <div style="font-size:14px;font-weight:600;margin-bottom:6px">Selecciona una consulta</div>
      <div style="font-size:12px;margin-bottom:20px">Haz clic en cualquier consulta del historial para ver los detalles</div>
      <a href="?p=historial&action=nueva<?= $mascota_id?"&mascota_id=$mascota_id":'' ?>" class="btn btn-primary btn-sm">＋ Nueva atención</a>
    </div>
    <?php endif; ?>
  </div>

</div><!-- fin hc-layout -->
<?php endif; /* fin: listado de pacientes vs detalle */ ?>


<script>
// ── Seleccionar consulta (navega a cid sin auto-select por defecto) ──
function selectConsulta(id) {
    document.querySelectorAll('.hc-item').forEach(el=>el.classList.remove('selected'));
    const item=document.querySelector(`.hc-item[data-id="${id}"]`);
    if(item) item.classList.add('selected');
    const url=new URL(window.location.href);
    url.searchParams.set('cid',id);
    window.location.href=url.toString();
}

// ── Cambiar tab ──
function switchHCTab(tab){
    const url=new URL(window.location.href);
    url.searchParams.set('tab',tab);
    url.searchParams.delete('cid'); // resetear detalle al cambiar tab
    window.location.href=url.toString();
}

// ── Autocomplete buscador ──
let hcAcTimer=null;
let hcAcFocus=-1;
const hcInput=document.getElementById('hcSearchInput');
const hcDrop=document.getElementById('hcDropdown');

function hcAutoComplete(val){
    clearTimeout(hcAcTimer);
    if(val.trim().length<2){hcDrop.style.display='none';hcAcFocus=-1;return;}
    hcAcTimer=setTimeout(()=>{
        fetch('<?= BASE_URL ?>/index.php?p=buscar&fmt=json&q='+encodeURIComponent(val))
        .then(r=>r.ok?r.json():null)
        .then(data=>{
            if(!data||(!data.mascotas?.length&&!data.clientes?.length)){
                // Fallback: buscar en consultas directamente
                hcFallbackSearch(val);return;
            }
            renderHcAc(data,val);
        }).catch(()=>hcFallbackSearch(val));
    },280);
}

function hcFallbackSearch(val){
    // Busca directo en el PHP via GET y filtra el listado local
    const items=document.querySelectorAll('.hc-item');
    items.forEach(item=>{
        const txt=item.textContent.toLowerCase();
        item.style.display=txt.includes(val.toLowerCase())?'':'none';
    });
    hcDrop.style.display='none';
}

function renderHcAc(data,val){
    const ei={perro:'🐕',gato:'🐈',conejo:'🐰',ave:'🐦',reptil:'🦎',roedor:'🐭',otro:'🐾'};
    let html='';
    // Mascotas
    (data.mascotas||[]).slice(0,5).forEach(m=>{
        const foto=m.foto?`<img src="<?= BASE_URL ?>/public/uploads/${m.foto}" class="hc-ac-photo" onerror="this.outerHTML='<div class=hc-ac-emoji>${ei[m.especie]||'🐾'}</div>'">`
                        :`<div class="hc-ac-emoji">${ei[m.especie]||'🐾'}</div>`;
        html+=`<div class="hc-ac-item" onclick="hcGoMascota(${m.id})">
            ${foto}
            <div><div style="font-size:12px;font-weight:600;color:var(--text)">${m.nombre}</div>
            <div style="font-size:10px;color:var(--text3)">${m.dueno||''} · ${m.raza||ucEsp(m.especie)}</div></div>
        </div>`;
    });
    // Clientes
    (data.clientes||[]).slice(0,3).forEach(c=>{
        html+=`<div class="hc-ac-item" onclick="hcGoCliente(${c.id})">
            <div class="hc-ac-emoji">👤</div>
            <div><div style="font-size:12px;font-weight:600;color:var(--text)">${c.nombre}</div>
            <div style="font-size:10px;color:var(--text3)">${c.telefono||''}</div></div>
        </div>`;
    });
    if(!html){hcDrop.style.display='none';return;}
    html+=`<div class="hc-ac-item" onclick="hcSubmitSearch()" style="background:var(--bg3)">
        <div class="hc-ac-emoji">🔍</div>
        <div style="font-size:12px;font-weight:600;color:var(--primary)">Buscar "<strong>${val}</strong>" en historial</div>
    </div>`;
    hcDrop.innerHTML=html;
    hcDrop.style.display='block';
    hcAcFocus=-1;
}

function ucEsp(e){return e?e.charAt(0).toUpperCase()+e.slice(1):'';}
function hcGoMascota(id){window.location.href='<?= BASE_URL ?>/index.php?p=historial&mascota_id='+id;}
function hcGoCliente(id){window.location.href='<?= BASE_URL ?>/index.php?p=historial&cliente_id='+id;}

function hcKeyDown(e){
    const items=hcDrop.querySelectorAll('.hc-ac-item');
    if(e.key==='ArrowDown'){hcAcFocus=Math.min(hcAcFocus+1,items.length-1);items.forEach((it,i)=>it.classList.toggle('focused',i===hcAcFocus));}
    else if(e.key==='ArrowUp'){hcAcFocus=Math.max(hcAcFocus-1,0);items.forEach((it,i)=>it.classList.toggle('focused',i===hcAcFocus));}
    else if(e.key==='Enter'){if(hcAcFocus>=0&&items[hcAcFocus]){items[hcAcFocus].click();}else hcSubmitSearch();}
    else if(e.key==='Escape'){hcDrop.style.display='none';}
}

function hcSubmitSearch(){
    const q=hcInput.value.trim();
    if(!q) return;
    const url=new URL(window.location.href);
    url.searchParams.set('q',q);
    url.searchParams.delete('cid');
    window.location.href=url.toString();
}

// Cerrar autocomplete al hacer click fuera
document.addEventListener('click',e=>{
    if(!document.getElementById('hcSearchWrap')?.contains(e.target))
        hcDrop.style.display='none';
});

// ── Upload foto ──
async function uploadFotoHC(input,id){
    if(!input.files[0])return;
    const fd=new FormData();
    fd.append('action','save');fd.append('id',id);fd.append('foto',input.files[0]);fd.append('mascota_id',id);
    await fetch('<?= BASE_URL ?>/index.php?p=mascotas',{method:'POST',body:fd});
    location.reload();
}

// NO auto-scroll automático — solo scroll si hay item seleccionado explícito (cid en URL)
document.addEventListener('DOMContentLoaded',()=>{
    const hasCid = new URLSearchParams(window.location.search).has('cid');
    if(hasCid){
        const sel=document.querySelector('.hc-item.selected');
        if(sel) sel.scrollIntoView({block:'nearest',behavior:'smooth'});
    }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
