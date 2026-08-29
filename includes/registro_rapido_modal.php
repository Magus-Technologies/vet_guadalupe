<?php
/* ============================================================================
 * VetPro — Modal reutilizable: Registro rápido de cliente + mascota
 * ----------------------------------------------------------------------------
 * Uso en cualquier módulo con buscador de mascota:
 *   $RR_HID = 'hid-mas-cita';   // id del <input type=hidden name=mascota_id>
 *   $RR_INP = 'inp-mas-cita';   // id del <input type=text> de búsqueda
 *   include __DIR__ . '/../includes/registro_rapido_modal.php';
 * Y en la etiqueta del campo, un enlace: onclick="rrAbrir()"
 * Usa la API /api/registro_rapido.php (cliente + mascota) y /api/consulta_documento.php (RENIEC).
 * ==========================================================================*/
if (!defined('RR_MODAL_INCLUDED')) {
    define('RR_MODAL_INCLUDED', 1);
    $RR_HID = $RR_HID ?? 'hid-mas-cita';
    $RR_INP = $RR_INP ?? 'inp-mas-cita';
?>
<div id="rrModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:1000;align-items:center;justify-content:center;padding:16px">
  <div style="background:var(--bg2);border-radius:16px;max-width:470px;width:100%;box-shadow:0 20px 60px rgba(0,0,0,.35);overflow:hidden">
    <div style="display:flex;align-items:center;justify-content:space-between;padding:14px 18px;border-bottom:1px solid var(--border)">
      <div style="font-size:15px;font-weight:700;color:var(--text)">➕ Registrar cliente y mascota</div>
      <button type="button" onclick="rrCerrar()" style="background:none;border:none;color:var(--text3);cursor:pointer;font-size:18px">✕</button>
    </div>
    <div style="padding:16px 18px">
      <div style="font-size:11px;font-weight:700;color:var(--text2);text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px">👤 Dueño</div>
      <div class="form-row" style="margin-bottom:10px">
        <div class="form-group" style="margin:0"><label class="form-label">DNI</label>
          <div style="display:flex;gap:6px">
            <input class="form-input" id="rr-dni" maxlength="8" inputmode="numeric" placeholder="8 dígitos">
            <button type="button" class="btn btn-sm" onclick="rrBuscarDni()" title="Buscar en RENIEC">🔍</button>
          </div>
        </div>
        <div class="form-group" style="margin:0"><label class="form-label">Teléfono</label>
          <input class="form-input" id="rr-tel" inputmode="tel" placeholder="Ej: 999 888 777">
        </div>
      </div>
      <div class="form-group" style="margin-bottom:14px"><label class="form-label required">Nombre del dueño</label>
        <input class="form-input" id="rr-dueno" placeholder="Nombre y apellido">
      </div>
      <div style="font-size:11px;font-weight:700;color:var(--text2);text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px">🐾 Mascota</div>
      <div class="form-row" style="margin-bottom:4px">
        <div class="form-group" style="margin:0"><label class="form-label required">Nombre</label>
          <input class="form-input" id="rr-mnom" placeholder="Ej: Firulais">
        </div>
        <div class="form-group" style="margin:0"><label class="form-label">Especie</label>
          <select class="form-input" id="rr-mesp">
            <option value="perro">Perro</option><option value="gato">Gato</option><option value="conejo">Conejo</option>
            <option value="ave">Ave</option><option value="reptil">Reptil</option><option value="roedor">Roedor</option><option value="otro">Otro</option>
          </select>
        </div>
      </div>
      <div id="rr-msg" style="font-size:12px;min-height:16px;margin:8px 2px 0;color:var(--danger)"></div>
    </div>
    <div style="display:flex;gap:8px;justify-content:flex-end;padding:12px 18px;border-top:1px solid var(--border)">
      <button type="button" onclick="rrCerrar()" class="btn btn-ghost">Cancelar</button>
      <button type="button" id="rr-guardar" onclick="rrGuardar()" class="btn btn-primary">Registrar y usar</button>
    </div>
  </div>
</div>
<script>
// Campos destino del buscador de mascota en este módulo
var RR_HID = <?= json_encode($RR_HID) ?>;
var RR_INP = <?= json_encode($RR_INP) ?>;
function rrAbrir(){ var m=document.getElementById('rrModal'); if(m){ m.style.display='flex'; document.getElementById('rr-msg').textContent=''; setTimeout(function(){var e=document.getElementById('rr-dni');if(e)e.focus();},50); } }
function rrCerrar(){ var m=document.getElementById('rrModal'); if(m) m.style.display='none'; }
async function rrBuscarDni(){
  var msg=document.getElementById('rr-msg');
  var dni=(document.getElementById('rr-dni').value||'').replace(/\D/g,'');
  if(dni.length!==8){ msg.style.color='var(--danger)'; msg.textContent='El DNI debe tener 8 dígitos.'; return; }
  msg.style.color='var(--text3)'; msg.textContent='Consultando RENIEC...';
  try{
    var r=await fetch('<?= BASE_URL ?>/api/consulta_documento.php?tipo=dni&numero='+dni);
    var d=await r.json();
    if(d && d.ok && d.nombre){ document.getElementById('rr-dueno').value=d.nombre; msg.textContent=''; }
    else { msg.style.color='var(--text3)'; msg.textContent='No se encontró en RENIEC. Escribe el nombre a mano.'; }
  }catch(e){ msg.style.color='var(--text3)'; msg.textContent='No se pudo consultar. Escribe el nombre a mano.'; }
}
async function rrGuardar(){
  var msg=document.getElementById('rr-msg'); msg.style.color='var(--danger)';
  var payload={ dni:document.getElementById('rr-dni').value, dueno_nombre:document.getElementById('rr-dueno').value,
    dueno_telefono:document.getElementById('rr-tel').value, mascota_nombre:document.getElementById('rr-mnom').value,
    mascota_especie:document.getElementById('rr-mesp').value };
  if(!payload.mascota_nombre.trim()){ msg.textContent='Escribe el nombre de la mascota.'; return; }
  if(!payload.dueno_nombre.trim()){ msg.textContent='Escribe el nombre del dueño (o búscalo por DNI).'; return; }
  var btn=document.getElementById('rr-guardar'); btn.disabled=true; var t=btn.textContent; btn.textContent='Guardando...';
  try{
    var r=await fetch('<?= BASE_URL ?>/api/registro_rapido.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)});
    var d=await r.json().catch(function(){return null;});
    if(d && d.ok){
      var hid=document.getElementById(RR_HID); if(hid) hid.value=d.mascota_id;
      var inp=document.getElementById(RR_INP); if(inp) inp.value=d.label;
      if(typeof window.rrOnCreated==='function'){ try{ window.rrOnCreated(d); }catch(e){} }
      rrCerrar();
    } else { msg.textContent=(d&&d.error)?d.error:'No se pudo registrar. Inténtalo de nuevo.'; }
  }catch(e){ msg.textContent='Error de red. Inténtalo de nuevo.'; }
  btn.disabled=false; btn.textContent=t;
}
</script>
<?php } ?>
