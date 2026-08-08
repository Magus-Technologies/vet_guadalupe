<?php
/* ============================================================================
 * VetPro — Biblioteca del Triaje / Motor de formularios dinámicos
 * Compartida por: modules/triaje.php (recepción), reservar.php (portal),
 * modules/solicitudes.php (prioridad de solicitudes).
 * Todo va protegido con function_exists para poder incluirse varias veces.
 * ==========================================================================*/

if (!function_exists('triaje_niveles')) {

/** Mapa de niveles: clave => [etiqueta, color, texto] */
function triaje_niveles(): array {
    return [
        'rojo'     => ['Emergencia','#ef4444','Atención inmediata'],
        'naranja'  => ['Urgente','#f59e0b','Atender < 30 min'],
        'amarillo' => ['Prioritario','#eab308','Puede esperar'],
        'verde'    => ['Rutina','#22c55e','Sin urgencia'],
    ];
}

/** Crea/asegura las tablas del motor + enganche + siembra la plantilla base. Idempotente. */
function triaje_bootstrap(PDO $db): void {
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS form_plantillas (
            id INT AUTO_INCREMENT PRIMARY KEY, clave VARCHAR(50) NOT NULL, nombre VARCHAR(150) NOT NULL,
            contexto ENUM('triaje','consentimiento','encuesta','ficha') NOT NULL DEFAULT 'triaje',
            especie VARCHAR(50) NOT NULL DEFAULT 'todos', version INT NOT NULL DEFAULT 1,
            umbral_amarillo INT DEFAULT 3, umbral_naranja INT DEFAULT 7, umbral_rojo INT DEFAULT 12,
            activo TINYINT(1) NOT NULL DEFAULT 1, sede_id INT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $db->exec("CREATE TABLE IF NOT EXISTS form_campos (
            id INT AUTO_INCREMENT PRIMARY KEY, plantilla_id INT NOT NULL, orden INT NOT NULL DEFAULT 0,
            seccion VARCHAR(80) DEFAULT NULL, etiqueta VARCHAR(200) NOT NULL, clave VARCHAR(50) NOT NULL,
            tipo ENUM('texto','textarea','numero','select','multiselect','boolean','escala','fecha') NOT NULL DEFAULT 'select',
            opciones LONGTEXT DEFAULT NULL, peso_base INT DEFAULT 0, requerido TINYINT(1) DEFAULT 0,
            config LONGTEXT DEFAULT NULL, condicion LONGTEXT DEFAULT NULL, en_portal TINYINT(1) DEFAULT 0, activo TINYINT(1) DEFAULT 1,
            KEY idx_form_campos_pl (plantilla_id,orden)
        ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $db->exec("CREATE TABLE IF NOT EXISTS triaje (
            id INT AUTO_INCREMENT PRIMARY KEY, plantilla_id INT NOT NULL, plantilla_version INT NOT NULL DEFAULT 1,
            sede_id INT DEFAULT 1, mascota_id INT DEFAULT NULL, cliente_id INT DEFAULT NULL,
            cita_id INT DEFAULT NULL, consulta_id INT DEFAULT NULL, solicitud_id INT DEFAULT NULL,
            canal ENUM('recepcion','portal','whatsapp') NOT NULL DEFAULT 'recepcion', motivo VARCHAR(255) DEFAULT NULL,
            respuestas LONGTEXT NOT NULL, puntaje INT NOT NULL DEFAULT 0,
            nivel ENUM('rojo','naranja','amarillo','verde') NOT NULL DEFAULT 'verde',
            banderas LONGTEXT DEFAULT NULL, realizado_por INT DEFAULT NULL,
            estado ENUM('abierto','atendido','anulado') NOT NULL DEFAULT 'abierto',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_triaje_mascota (mascota_id), KEY idx_triaje_nivel (nivel,estado)
        ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        // Columnas de enganche (idempotente)
        foreach ([['citas','triaje_nivel',"ENUM('rojo','naranja','amarillo','verde') DEFAULT NULL"],
                  ['citas','triaje_id','INT DEFAULT NULL'],
                  ['consultas','triaje_id','INT DEFAULT NULL'],
                  ['solicitudes_cita','triaje_id','INT DEFAULT NULL'],
                  ['solicitudes_cita','triaje_nivel',"ENUM('rojo','naranja','amarillo','verde') DEFAULT NULL"],
                  ['form_campos','en_portal','TINYINT(1) DEFAULT 0']] as $c) {
            try {
                $ex = $db->query("SHOW COLUMNS FROM `{$c[0]}` LIKE '{$c[1]}'")->fetchAll();
                if (empty($ex)) $db->exec("ALTER TABLE `{$c[0]}` ADD COLUMN `{$c[1]}` {$c[2]}");
            } catch (Exception $e) {}
        }
        // Sembrar plantilla base la primera vez
        $cnt = (int)$db->query("SELECT COUNT(*) FROM form_plantillas WHERE contexto='triaje'")->fetchColumn();
        if ($cnt === 0) {
            $db->prepare("INSERT INTO form_plantillas (clave,nombre,contexto,especie) VALUES ('triaje_general','Triaje general (perro/gato)','triaje','todos')")->execute();
            $pid = (int)$db->lastInsertId();
            // [clave, etiqueta, tipo, opciones, requerido, config, en_portal]
            $seed = [
              ['motivo','Motivo principal / síntomas','textarea',null,0,'{"mapea":"sintomas"}',0],
              ['dificultad_resp','¿Dificultad para respirar?','select','[{"valor":"no","etiqueta":"No","peso":0},{"valor":"leve","etiqueta":"Leve","peso":4},{"valor":"severa","etiqueta":"Severa","peso":0,"critico":1}]',1,null,1],
              ['encias','Color de encías','select','[{"valor":"rosadas","etiqueta":"Rosadas","peso":0},{"valor":"palidas","etiqueta":"Pálidas","peso":0,"critico":1},{"valor":"azuladas","etiqueta":"Azuladas","peso":0,"critico":1}]',1,null,1],
              ['convulsion','¿Convulsiones ahora?','select','[{"valor":"no","etiqueta":"No","peso":0},{"valor":"si","etiqueta":"Sí","peso":0,"critico":1}]',1,null,1],
              ['trauma','¿Trauma / atropello reciente?','select','[{"valor":"no","etiqueta":"No","peso":0},{"valor":"si","etiqueta":"Sí","peso":0,"critico":1}]',1,null,1],
              ['vomito_diarrea','Vómitos / diarrea','select','[{"valor":"no","etiqueta":"No","peso":0},{"valor":"leve","etiqueta":"1-2 veces","peso":2},{"valor":"frecuente","etiqueta":"Frecuente","peso":5},{"valor":"sangre","etiqueta":"Con sangre","peso":8}]',0,null,1],
              ['animo','Estado de ánimo','select','[{"valor":"normal","etiqueta":"Normal","peso":0},{"valor":"decaido","etiqueta":"Decaído","peso":3},{"valor":"noresp","etiqueta":"No responde","peso":8}]',1,null,1],
              ['tiempo','Tiempo con el síntoma','select','[{"valor":"semanas","etiqueta":"Semanas","peso":1},{"valor":"dias","etiqueta":"Días","peso":2},{"valor":"horas","etiqueta":"Horas","peso":4}]',0,null,1],
              ['temperatura','Temperatura (°C)','numero',null,0,'{"mapea":"temperatura","rangos":[{"max":37.5,"peso":5},{"min":40,"peso":5},{"min":39.3,"max":40,"peso":2}]}',0],
              ['peso','Peso actual (kg)','numero',null,0,'{"mapea":"peso"}',0],
            ];
            $ins = $db->prepare("INSERT INTO form_campos (plantilla_id,orden,etiqueta,clave,tipo,opciones,requerido,config,en_portal) VALUES (?,?,?,?,?,?,?,?,?)");
            foreach ($seed as $i=>$s) $ins->execute([$pid,$i,$s[1],$s[0],$s[2],$s[3],$s[4],$s[5],$s[6]]);
        }
        // Self-heal: si la plantilla ya existía (sembrada en Fase 1 sin en_portal),
        // marcar las preguntas que van en el portal cuando aún no hay ninguna.
        try {
            $hay = (int)$db->query("SELECT COUNT(*) FROM form_campos WHERE en_portal=1")->fetchColumn();
            if ($hay === 0) {
                $db->exec("UPDATE form_campos SET en_portal=1 WHERE clave IN ('dificultad_resp','encias','convulsion','trauma','vomito_diarrea','animo')");
            }
        } catch (Exception $e) {}
    } catch (Exception $e) {}
}

/** Plantilla de triaje activa (o null). */
function triaje_plantilla_activa(PDO $db): ?array {
    try { return $db->query("SELECT * FROM form_plantillas WHERE contexto='triaje' AND activo=1 ORDER BY id LIMIT 1")->fetch() ?: null; }
    catch (Exception $e) { return null; }
}

/** Campos activos de una plantilla. $solo_portal=true → solo los marcados para el portal. */
function triaje_campos(PDO $db, int $pid, bool $solo_portal = false): array {
    try {
        $sql = "SELECT * FROM form_campos WHERE plantilla_id=? AND activo=1" . ($solo_portal ? " AND en_portal=1" : "") . " ORDER BY orden";
        $st = $db->prepare($sql); $st->execute([$pid]); return $st->fetchAll();
    } catch (Exception $e) { return []; }
}

/**
 * Calcula la prioridad. Devuelve ['puntaje','nivel','banderas','snapshot'].
 * Reglas por peso + banderas rojas (una bandera fuerza 'rojo').
 */
function triaje_calcular(array $campos, array $resp, array $umbrales): array {
    $puntaje = 0; $banderas = []; $snapshot = [];
    foreach ($campos as $c) {
        $val = $resp[$c['clave']] ?? null;
        $opts = $c['opciones'] ? (json_decode($c['opciones'], true) ?: []) : [];
        $cfg  = $c['config']   ? (json_decode($c['config'],   true) ?: []) : [];
        $val_txt = '';
        if ($val !== null && $val !== '') {
            if (in_array($c['tipo'], ['select','boolean','multiselect'])) {
                foreach ((array)$val as $vv) {
                    foreach ($opts as $o) if ((string)$o['valor'] === (string)$vv) {
                        $puntaje += (int)($o['peso'] ?? 0);
                        if (!empty($o['critico'])) $banderas[] = $c['etiqueta'];
                        $val_txt .= ($val_txt ? ', ' : '') . ($o['etiqueta'] ?? $vv);
                    }
                }
            } elseif (in_array($c['tipo'], ['numero','escala'])) {
                $num = (float)str_replace(',', '.', $val);
                foreach (($cfg['rangos'] ?? []) as $r) {
                    $okMin = !isset($r['min']) || $num >= $r['min'];
                    $okMax = !isset($r['max']) || $num <= $r['max'];
                    if ($okMin && $okMax) $puntaje += (int)($r['peso'] ?? 0);
                }
                $val_txt = (string)$val;
            } else {
                $val_txt = (string)$val;
            }
        }
        $snapshot[] = ['clave'=>$c['clave'],'etiqueta'=>$c['etiqueta'],'valor'=>$val,'valor_txt'=>$val_txt];
    }
    if (!empty($banderas))                     $nivel = 'rojo';
    elseif ($puntaje >= ($umbrales['rojo'] ?? 12))     $nivel = 'rojo';
    elseif ($puntaje >= ($umbrales['naranja'] ?? 7))   $nivel = 'naranja';
    elseif ($puntaje >= ($umbrales['amarillo'] ?? 3))  $nivel = 'amarillo';
    else                                       $nivel = 'verde';
    return ['puntaje'=>$puntaje,'nivel'=>$nivel,'banderas'=>$banderas,'snapshot'=>$snapshot];
}

} // function_exists
