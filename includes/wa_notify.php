<?php
/* ============================================================================
 * VetPro — Helper de notificaciones WhatsApp
 * ----------------------------------------------------------------------------
 * Envía un mensaje al microservicio Baileys local (whatsapp-server.js).
 * No bloquea ni rompe el flujo si el micro está caído: simplemente devuelve
 * false y la cita se guarda igual.
 * ==========================================================================*/

if (!defined('WA_MICRO_URL')) {
    /* ── AJUSTA ESTOS 2 VALORES EN CADA COPIA (/vet1, /vet2, /vet3) ──
     * El puerto y el token deben coincidir con los del .service de esa clínica:
     *   /vet1 → puerto 3031, token de vet1
     *   /vet2 → puerto 3032, token de vet2
     *   /vet3 → puerto 3033, token de vet3
     */
    define('WA_MICRO_URL',   getenv('WA_MICRO_URL')   ?: 'http://127.0.0.1:3035');
    define('WA_MICRO_TOKEN', getenv('WA_MICRO_TOKEN') ?: 'magus123technologies460');
}

/**
 * Envía un mensaje de WhatsApp por el microservicio local.
 * @param string $telefono  Teléfono del destinatario (con o sin prefijo 51).
 * @param string $mensaje   Texto del mensaje (admite emojis y *negritas* de WhatsApp).
 * @return bool  true si el micro confirmó el envío, false en cualquier fallo.
 */
function wa_enviar($telefono, $mensaje) {
    $telefono = trim((string)$telefono);
    $mensaje  = (string)$mensaje;
    if ($telefono === '' || $mensaje === '') return false;

    $payload = json_encode(['telefono' => $telefono, 'mensaje' => $mensaje], JSON_UNESCAPED_UNICODE);

    $ch = curl_init(WA_MICRO_URL . '/send');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-token: ' . WA_MICRO_TOKEN,
        ],
        CURLOPT_TIMEOUT        => 8,   // no esperar demasiado
        CURLOPT_CONNECTTIMEOUT => 3,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200) return false;
    $data = json_decode($resp, true);
    return is_array($data) && !empty($data['ok']);
}

/**
 * Construye el mensaje de confirmación de cita.
 */
function wa_msg_confirmacion($clinica, $dueno, $mascota, $fecha, $hora, $vet) {
    $f = date('d/m/Y', strtotime($fecha));
    $h = substr($hora, 0, 5);
    return "🐾 *{$clinica}*\n\n"
         . "Hola {$dueno} 👋\n\n"
         . "Tu cita quedó agendada:\n\n"
         . "📅 *Fecha:* {$f}\n"
         . "🕐 *Hora:* {$h}\n"
         . "🐶 *Paciente:* {$mascota}\n"
         . "👨‍⚕️ *Veterinario:* {$vet}\n\n"
         . "Por favor llega 10 min antes.\n"
         . "_Responde si necesitas reprogramar._\n\n"
         . "✅ {$clinica} — Cuidamos a tus mascotas";
}

/**
 * Construye el mensaje de recordatorio de cita.
 */
function wa_msg_recordatorio($clinica, $dueno, $mascota, $fecha, $hora, $vet) {
    $f = date('d/m/Y', strtotime($fecha));
    $h = substr($hora, 0, 5);
    return "⏰ *Recordatorio {$clinica}*\n\n"
         . "Hola {$dueno} 👋\n\n"
         . "Te recordamos la cita de *{$mascota}*:\n"
         . "📅 {$f} a las {$h}\n"
         . "👨‍⚕️ {$vet}\n\n"
         . "¿Confirmas tu asistencia?\n"
         . "Responde *SÍ* o *NO*\n\n"
         . "{$clinica} 🐾";
}
