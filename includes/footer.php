  </div><!-- .content -->
</div><!-- .main -->

<!-- ══ BOTTOM NAV MÓVIL ══ -->
<nav class="mob-bottom-nav" id="mobBottomNav" style="display:none" aria-label="Navegación móvil">
<?php
$_bnav = [
  ['icon'=>'⊞', 'label'=>'Inicio',     'page'=>'dashboard'],
  ['icon'=>'🗓️','label'=>'Agenda',     'page'=>'calendario'],
  ['icon'=>'🐾', 'label'=>'Mascotas',   'page'=>'mascotas'],
  ['icon'=>'📋', 'label'=>'Historia',   'page'=>'historial'],
  ['icon'=>'☰',  'label'=>'Más',        'page'=>'_menu'],
];
foreach ($_bnav as $_bn):
  $_active  = ($_bn['page'] !== '_menu') && ($page === $_bn['page']);
  $_href    = $_bn['page'] === '_menu' ? 'javascript:void(0)' : BASE_URL.'/index.php?p='.$_bn['page'];
  $_onclick = $_bn['page'] === '_menu' ? ' onclick="openMobMenu()"' : '';
  $_badge   = ($_bn['page']==='citas') ? ($citas_hoy ?? 0) : 0;
?>
<a href="<?= $_href ?>" class="mob-nav-item <?= $_active?'active':'' ?>"<?= $_onclick ?>>
  <?php if ($_badge > 0): ?><span class="mob-nav-badge"><?= $_badge ?></span><?php endif; ?>
  <span class="mob-nav-icon"><?= $_bn['icon'] ?></span>
  <span><?= $_bn['label'] ?></span>
</a>
<?php endforeach; ?>
</nav>

<script>
/* ── NOTIFICACIONES ── */
/* ── HELPER: vetSearchSelect ── */
function vetSearchSelect(inputId, dropId, hiddenId, data, labelKey, extraFn) {
  var inp  = document.getElementById(inputId);
  var drop = document.getElementById(dropId);
  var hid  = document.getElementById(hiddenId);
  if (!inp || !drop || !hid) return;

  function renderDrop(matches) {
    if (!matches.length) {
      drop.innerHTML = '<div style="padding:10px 14px;font-size:12px;color:var(--text3)">Sin resultados</div>';
      drop.style.display = 'block'; return;
    }
    var html = '';
    matches.forEach(function(d, i) {
      var lbl = d[labelKey] || d.nombre || d.label || '';
      var sub = d.especie ? ' · <span style="color:var(--text3)">' + d.especie + '</span>'
              : d.rol     ? ' · <span style="color:var(--text3)">' + d.rol + '</span>' : '';
      html += '<div class="vss-opt" style="padding:10px 14px;cursor:pointer;border-bottom:1px solid var(--border)"'
            + ' onmouseover="this.style.background=\'var(--bg3)\'" onmouseout="this.style.background=\'\'">'
            + '<div style="font-size:13px;font-weight:600">' + lbl + sub + '</div></div>';
    });
    drop.innerHTML = html;
    drop.style.display = 'block';
    drop.querySelectorAll('.vss-opt').forEach(function(el, i) {
      el.addEventListener('mousedown', function(e) {
        e.preventDefault();
        var d   = matches[i];
        var lbl = d[labelKey] || d.nombre || d.label || '';
        inp.value = lbl;
        hid.value = d.id;
        drop.style.display = 'none';
        if (typeof extraFn === 'function') extraFn(d);
      });
    });
  }

  inp.addEventListener('input', function() {
    hid.value = '';
    var val = inp.value.toLowerCase().trim();
    if (!val) { drop.style.display = 'none'; return; }
    renderDrop(data.filter(function(d) {
      return (d[labelKey] || d.nombre || d.label || '').toLowerCase().indexOf(val) >= 0;
    }).slice(0, 10));
  });

  // Mostrar todos al hacer foco (para selección fácil)
  inp.addEventListener('focus', function() {
    var val = inp.value.toLowerCase().trim();
    if (val) inp.dispatchEvent(new Event('input'));
    else renderDrop(data.slice(0, 8));
  });

  inp.addEventListener('blur', function() {
    setTimeout(function() { drop.style.display = 'none'; }, 200);
  });

  // Si se borra todo el texto, limpiar hidden
  inp.addEventListener('keyup', function() {
    if (!inp.value.trim()) hid.value = '';
  });
}

var _notifOpen = false;
var _notifApi  = '<?= BASE_URL ?>/api/notificaciones.php';
var _notifInterval = null;

function toggleNotifPanel() {
  _notifOpen = !_notifOpen;
  var panel = document.getElementById('notif-panel');
  if (!panel) return;
  panel.style.display = _notifOpen ? 'block' : 'none';
  if (_notifOpen) cargarNotificaciones();
}

function cargarNotificaciones() {
  fetch(_notifApi + '?action=list&limit=20')
    .then(function(r){ return r.ok ? r.json() : null; })
    .then(function(d){
      if (!d || !d.ok) return;
      actualizarBadge(d.sin_leer);
      renderNotifList(d.notifs);
    }).catch(function(){});
}

function actualizarBadge(n) {
  var badge = document.getElementById('notif-badge');
  if (!badge) return;
  badge.style.display = n > 0 ? 'inline-block' : 'none';
  badge.textContent   = n > 99 ? '99+' : n;
}

function renderNotifList(notifs) {
  var list = document.getElementById('notif-list');
  if (!list) return;
  if (!notifs || !notifs.length) {
    list.innerHTML = '<div style="padding:32px;text-align:center;color:var(--text3)"><div style="font-size:28px;margin-bottom:8px">✅</div><div style="font-size:13px;font-weight:600">Todo al día</div><div style="font-size:12px;margin-top:4px">No hay notificaciones pendientes</div></div>';
    return;
  }
  // Mapeo de iconos por si vienen como texto
  var iconMap = {'bell':'🔔','cita':'📅','vacuna':'💉','stock':'📦','parto':'🐄','sistema':'⚙️','custom':'📌'};
  var tiempoRelativo = function(ts) {
    var diff = Math.floor((Date.now() - new Date(ts).getTime()) / 1000);
    if (diff < 60) return 'Ahora';
    if (diff < 3600) return Math.floor(diff/60) + ' min';
    if (diff < 86400) return Math.floor(diff/3600) + 'h';
    return Math.floor(diff/86400) + 'd';
  };
  var html = '';
  notifs.forEach(function(n) {
    var leida = n.leida == 1;
    var icono = n.icono || 'bell';
    // Si el icono es una palabra clave, convertir a emoji
    if (iconMap[icono]) icono = iconMap[icono];
    html += '<div onclick="abrirNotif(' + n.id + ',\'' + (n.link||'').replace(/'/g,"\\'") + '\')" '
      + 'style="display:flex;gap:12px;padding:12px 16px;cursor:pointer;border-bottom:1px solid var(--border);'
      + 'background:' + (leida ? 'transparent' : 'rgba(30,168,161,.04)') + ';transition:background .1s"'
      + ' onmouseover="this.style.background=\'var(--bg3)\'" onmouseout="this.style.background=\'' + (leida?'transparent':'rgba(30,168,161,.04)') + '\'">'
      + '<div style="width:36px;height:36px;border-radius:10px;background:' + (n.color||'#3b82f6') + '22;'
      + 'display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0">' + icono + '</div>'
      + '<div style="flex:1;min-width:0">'
      + '<div style="display:flex;align-items:flex-start;justify-content:space-between;gap:6px">'
      + '<div style="font-size:13px;font-weight:' + (leida?'500':'700') + ';color:var(--text);line-height:1.3">' + (n.titulo||'') + '</div>'
      + '<div style="font-size:10px;color:var(--text3);white-space:nowrap;flex-shrink:0">' + tiempoRelativo(n.created_at) + '</div></div>'
      + (n.mensaje ? '<div style="font-size:12px;color:var(--text3);margin-top:3px;line-height:1.4">' + n.mensaje + '</div>' : '')
      + '</div>'
      + (!leida ? '<div style="width:8px;height:8px;border-radius:50%;background:' + (n.color||'#3b82f6') + ';flex-shrink:0;margin-top:4px"></div>' : '')
      + '</div>';
  });
  list.innerHTML = html;
}

function abrirNotif(id, link) {
  var fd = new FormData();
  fd.append('action', 'marcar_leida');
  fd.append('id', id);
  fetch(_notifApi, {method:'POST', body:fd}).catch(function(){});
  if (link) window.location.href = link;
  else toggleNotifPanel();
}

function marcarTodasLeidas() {
  var fd = new FormData();
  fd.append('action', 'marcar_todas');
  fetch(_notifApi, {method:'POST', body:fd})
    .then(function(){ cargarNotificaciones(); })
    .catch(function(){});
}

// Cerrar panel al clic fuera
document.addEventListener('click', function(e) {
  var wrap = document.getElementById('notif-wrap');
  if (wrap && !wrap.contains(e.target) && _notifOpen) {
    _notifOpen = false;
    var p = document.getElementById('notif-panel');
    if (p) p.style.display = 'none';
  }
});

// Polling cada 60 segundos para actualizar badge
function pollNotificaciones() {
  fetch(_notifApi + '?action=count')
    .then(function(r){ return r.ok ? r.json() : null; })
    .then(function(d){ if(d && d.ok) actualizarBadge(d.count); })
    .catch(function(){});
}
pollNotificaciones();
_notifInterval = setInterval(pollNotificaciones, 60000);
function openMobMenu(){
  var sb=document.getElementById('mainSidebar');
  var ov=document.getElementById('mobOverlay');
  if(sb) sb.classList.add('mob-open');
  if(ov) ov.classList.add('active');
}
function closeMobMenu(){
  var sb=document.getElementById('mainSidebar');
  var ov=document.getElementById('mobOverlay');
  if(sb) sb.classList.remove('mob-open');
  if(ov) ov.classList.remove('active');
}

document.addEventListener('DOMContentLoaded',function(){
  /* Cerrar drawer al navegar */
  var sb=document.getElementById('mainSidebar');
  if(sb) sb.querySelectorAll('a').forEach(function(el){
    el.addEventListener('click',function(){
      if(window.innerWidth<=768) closeMobMenu();
    });
  });
});

/* Swipe */
var _swX=0;
document.addEventListener('touchstart',function(e){_swX=e.touches[0].clientX;},{passive:true});
document.addEventListener('touchend',function(e){
  var dx=e.changedTouches[0].clientX-_swX;
  var sb=document.getElementById('mainSidebar');
  if(sb&&sb.classList.contains('mob-open')&&dx<-60) closeMobMenu();
  if(sb&&!sb.classList.contains('mob-open')&&_swX<24&&dx>60) openMobMenu();
},{passive:true});

/* ── Veterinaria menu toggle ── */
function toggleVetMenu(){
  var m=document.getElementById('vet-submenu');
  var c=document.getElementById('vet-caret');
  var open=m.style.display==='block';
  m.style.display=open?'none':'block';
  if(c) c.textContent=open?'∨':'∧';
}

/* ── SUBMENÚ VETERINARIA EN MÓVIL (autocontenido) ──
   Reconstruye los enlaces (Mascotas / Ganado), los deja abiertos por defecto y
   monta un toggle propio que SÍ funciona en el drawer. No depende de
   toggleVetMenu ni de ningún script del header: clona el item para eliminar
   cualquier listener previo y enlaza el suyo. En escritorio no actúa. */
(function(){
  var done = false;
  function setupVetMobile(){
    if (window.innerWidth > 768) return;
    var sm    = document.getElementById('vet-submenu');
    var caret = document.getElementById('vet-caret');
    var item  = caret ? caret.closest('.nav-item') : null;
    if (!sm || !item || done) return;
    done = true;

    // 1) Reconstruir los enlaces con estilos visibles explícitos
    var liStyle = 'display:flex!important;visibility:visible!important;opacity:1!important;'
                + 'align-items:center;gap:10px;padding:9px 12px;margin:2px 6px;border-radius:8px;'
                + 'color:rgba(255,255,255,.75);text-decoration:none;font-size:13px';
    sm.innerHTML =
      '<a href="<?= BASE_URL ?>/index.php?p=mascotas" class="nav-item" style="'+liStyle+'">'
        + '<span class="ni" style="font-size:16px">🐾</span><span>Mascotas</span></a>'
      + '<a href="<?= BASE_URL ?>/index.php?p=ganado" class="nav-item" style="'+liStyle+'">'
        + '<span class="ni" style="font-size:16px">🐄</span><span>Ganado Vacuno</span></a>';

    // 2) Abierto por defecto — forzado con !important (es el único modo que
    //    mostró los enlaces; hay alguna regla heredada que gana al inline normal)
    sm.style.setProperty('display','block','important');
    sm.style.setProperty('visibility','visible','important');
    sm.style.setProperty('height','auto','important');
    sm.style.setProperty('overflow','visible','important');
    caret.textContent = '∧';

    // 3) Toggle propio (clonar el item elimina listeners previos del header).
    //    Abrir/cerrar también con !important para que la regla heredada no gane.
    var fresh = item.cloneNode(true);
    item.parentNode.replaceChild(fresh, item);
    fresh.removeAttribute('onclick');
    fresh.style.cursor = 'pointer';
    fresh.addEventListener('click', function(e){
      e.preventDefault();
      e.stopPropagation();
      var c = document.getElementById('vet-caret');
      var oculto = getComputedStyle(sm).display === 'none';
      if (oculto) {
        sm.style.setProperty('display','block','important');
        sm.style.setProperty('overflow','visible','important');
      } else {
        sm.style.setProperty('display','none','important');
      }
      if (c) c.textContent = oculto ? '∧' : '∨';
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', setupVetMobile);
  else setupVetMobile();
  window.addEventListener('resize', setupVetMobile);
})();

/* ── Helper global ── */
function copyToClipboard(text,btn){
  navigator.clipboard.writeText(text).then(function(){
    var orig=btn.textContent; btn.textContent='✓ Copiado';
    setTimeout(function(){btn.textContent=orig;},2000);
  });
}

/* ── Buscador global con autocomplete ── */
var _gsApi  = '<?= BASE_URL ?>/api/autocomplete.php';
var _gsBase = '<?= BASE_URL ?>';
var _gsEi   = {perro:'🐕',gato:'🐈',conejo:'🐰',ave:'🐦',reptil:'🦎',roedor:'🐭',otro:'🐾'};
var _gsTimer= null;
var _gsFocus= -1;

function gsAutoComplete(val){
  clearTimeout(_gsTimer);
  var drop=document.getElementById('gsDropdown');
  if(!val||val.trim().length<2){drop.style.display='none';_gsFocus=-1;return;}
  _gsTimer=setTimeout(function(){
    fetch(_gsApi+'?q='+encodeURIComponent(val.trim()))
      .then(function(r){return r.ok?r.json():null;})
      .then(function(d){if(d) gsRender(d,val.trim());})
      .catch(function(){});
  },220);
}

function gsRender(d,val){
  var drop=document.getElementById('gsDropdown');
  var hl=function(txt){
    if(!txt) return '';
    var re=new RegExp('('+val.replace(/[.*+?^${}()|[\]\\]/g,'\\$&')+')','gi');
    return String(txt).replace(re,'<strong style="color:var(--primary)">$1</strong>');
  };
  var html='';

  // MASCOTAS
  if(d.mascotas && d.mascotas.length){
    html+='<div style="padding:6px 12px 3px;font-size:10px;font-weight:700;color:var(--text3);text-transform:uppercase;letter-spacing:.8px">🐾 Mascotas</div>';
    d.mascotas.forEach(function(m){
      var foto=m.foto_url
        ? '<img src="'+m.foto_url+'" style="width:34px;height:34px;border-radius:8px;object-fit:cover;border:1px solid var(--border);flex-shrink:0">'
        : '<div style="width:34px;height:34px;border-radius:8px;background:var(--primary-l);display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0">'+(_gsEi[m.especie]||'🐾')+'</div>';
      html+='<div class="gs-item" onclick="gsGoMascota('+m.id+')">'+foto
        +'<div style="flex:1;min-width:0">'
        +'<div style="font-size:13px;font-weight:600;color:var(--text)">'+hl(m.nombre)+'</div>'
        +'<div style="font-size:11px;color:var(--text3)">'+hl(m.raza||'')+(m.raza?' · ':'')+hl(m.dueno)+'</div>'
        +'</div>'
        +'<div style="display:flex;gap:4px;flex-shrink:0">'
        +'<span onclick="event.stopPropagation();window.location.href=\''+_gsBase+'/index.php?p=historial&mascota_id='+m.id+'\'" style="padding:3px 8px;background:var(--primary-l);color:var(--primary-d);border-radius:6px;font-size:10px;font-weight:700;cursor:pointer">Historia</span>'
        +'</div>'
        +'</div>';
    });
  }

  // CLIENTES
  if(d.clientes && d.clientes.length){
    html+='<div style="padding:6px 12px 3px;font-size:10px;font-weight:700;color:var(--text3);text-transform:uppercase;letter-spacing:.8px;border-top:1px solid var(--border);margin-top:4px">👤 Clientes</div>';
    d.clientes.forEach(function(c){
      html+='<div class="gs-item" onclick="gsGoCliente('+c.id+')">'
        +'<div style="width:34px;height:34px;border-radius:8px;background:var(--accent-l);display:flex;align-items:center;justify-content:center;font-size:14px;font-weight:700;color:var(--accent-d);flex-shrink:0">'+(c.nombre||'?').charAt(0).toUpperCase()+'</div>'
        +'<div style="flex:1;min-width:0">'
        +'<div style="font-size:13px;font-weight:600;color:var(--text)">'+hl(c.nombre)+'</div>'
        +'<div style="font-size:11px;color:var(--text3)">'+hl(c.telefono||'')+(c.dni?' · DNI '+c.dni:'')+'</div>'
        +'</div>'
        +'<span style="padding:3px 8px;background:var(--accent-l);color:var(--accent-d);border-radius:6px;font-size:10px;font-weight:700">Ver</span>'
        +'</div>';
    });
  }

  // FACTURAS
  if(d.facturas && d.facturas.length){
    html+='<div style="padding:6px 12px 3px;font-size:10px;font-weight:700;color:var(--text3);text-transform:uppercase;letter-spacing:.8px;border-top:1px solid var(--border);margin-top:4px">🧾 Facturas</div>';
    d.facturas.forEach(function(f){
      var num=f.serie+'-'+String(f.numero).padStart(5,'0');
      html+='<div class="gs-item" onclick="window.location.href=\''+_gsBase+'/index.php?p=facturacion&action=ver&id='+f.id+'\'">'
        +'<div style="width:34px;height:34px;border-radius:8px;background:#f0fdf4;display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0">🧾</div>'
        +'<div style="flex:1;min-width:0">'
        +'<div style="font-size:13px;font-weight:600;color:var(--text)">'+hl(num)+'</div>'
        +'<div style="font-size:11px;color:var(--text3)">'+hl(f.cliente)+' &nbsp;·&nbsp; S/. '+parseFloat(f.total).toFixed(2)+'</div>'
        +'</div>'
        +'<span style="padding:3px 8px;background:#d1fae5;color:#065f46;border-radius:6px;font-size:10px;font-weight:700">Ver</span>'
        +'</div>';
    });
  }

  // CITAS
  if(d.citas && d.citas.length){
    html+='<div style="padding:6px 12px 3px;font-size:10px;font-weight:700;color:var(--text3);text-transform:uppercase;letter-spacing:.8px;border-top:1px solid var(--border);margin-top:4px">📅 Citas</div>';
    d.citas.forEach(function(c){
      var fecha=c.fecha?c.fecha.split('-').reverse().join('/'):'';
      html+='<div class="gs-item" onclick="window.location.href=\''+_gsBase+'/index.php?p=citas&id='+c.id+'\'">'
        +'<div style="width:34px;height:34px;border-radius:8px;background:#eff6ff;display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0">📅</div>'
        +'<div style="flex:1;min-width:0">'
        +'<div style="font-size:13px;font-weight:600;color:var(--text)">'+hl(c.mascota)+' &nbsp;<span style="font-weight:400;color:var(--text3)">'+fecha+(c.hora?' '+c.hora.substring(0,5):'')+'</span></div>'
        +'<div style="font-size:11px;color:var(--text3)">'+hl(c.cliente)+' &nbsp;·&nbsp; '+ucFirst(c.estado)+'</div>'
        +'</div>'
        +'</div>';
    });
  }

  if(!d.mascotas.length && !d.clientes.length && !(d.facturas&&d.facturas.length) && !(d.citas&&d.citas.length)){
    html='<div style="padding:20px;text-align:center;color:var(--text3);font-size:12px">Sin resultados para <strong>'+val+'</strong></div>';
  }

  html+='<div class="gs-item" onclick="gsVerTodos()" style="border-top:1px solid var(--border);margin-top:4px;background:var(--bg3)">'
      +'<div style="font-size:16px;flex-shrink:0">🔍</div>'
      +'<div style="font-size:12px;font-weight:600;color:var(--primary)">Ver todos los resultados para <strong>'+val+'</strong></div>'
      +'</div>';

  drop.innerHTML=html;
  drop.style.display='block';
  _gsFocus=-1;

  drop.querySelectorAll('.gs-item').forEach(function(el){
    el.style.cssText+='display:flex;align-items:center;gap:10px;padding:9px 13px;cursor:pointer;transition:background .1s;border-bottom:1px solid var(--border)';
    el.addEventListener('mouseenter',function(){el.style.background='var(--bg3)';});
    el.addEventListener('mouseleave',function(){el.style.background='';});
  });
}

function ucFirst(s){ return s?s.charAt(0).toUpperCase()+s.slice(1):s; }

function gsKeyDown(e){
  var drop=document.getElementById('gsDropdown');
  var items=drop.querySelectorAll('.gs-item');
  if(!drop.style.display||drop.style.display==='none'){if(e.key==='Enter')gsVerTodos();return;}
  if(e.key==='ArrowDown'){e.preventDefault();_gsFocus=Math.min(_gsFocus+1,items.length-1);items.forEach(function(it,i){it.style.background=i===_gsFocus?'var(--primary-l)':'';});}
  else if(e.key==='ArrowUp'){e.preventDefault();_gsFocus=Math.max(_gsFocus-1,0);items.forEach(function(it,i){it.style.background=i===_gsFocus?'var(--primary-l)':'';});}
  else if(e.key==='Enter'){e.preventDefault();if(_gsFocus>=0&&items[_gsFocus])items[_gsFocus].click();else gsVerTodos();}
  else if(e.key==='Escape'){drop.style.display='none';}
}
function gsGoMascota(id){document.getElementById('gsDropdown').style.display='none';window.location.href=_gsBase+'/index.php?p=historial&mascota_id='+id;}
function gsGoCliente(id){document.getElementById('gsDropdown').style.display='none';window.location.href=_gsBase+'/index.php?p=clientes&action=editar&id='+id;}
function gsVerTodos(){var q=document.getElementById('globalSearchInput');if(q&&q.value.trim())window.location.href=_gsBase+'/index.php?p=buscar&q='+encodeURIComponent(q.value.trim());}
document.addEventListener('click',function(e){
  var w=document.getElementById('globalSearchWrap');
  if(w&&!w.contains(e.target)){var d=document.getElementById('gsDropdown');if(d)d.style.display='none';}
});
</script>

<!-- ══ DICTADO POR VOZ (Web Speech API) — global para todos los formularios ══ -->
<script>
(function(){
  var SpeechRec = window.SpeechRecognition || window.webkitSpeechRecognition;
  if (!SpeechRec) return; // navegador sin soporte → no se muestran micrófonos (no rompe nada)

  var _activo = null;   // textarea que se está dictando ahora
  var _rec = null;
  var _baseText = '';   // texto que ya había antes de empezar a dictar

  function crearMic(ta){
    // Evitar duplicados y campos que no queremos
    if (ta.dataset.voiceReady === '1') return;
    if (ta.dataset.noVoice === '1') return;
    ta.dataset.voiceReady = '1';

    // Envolver el textarea en un contenedor relativo para posicionar el botón
    var wrap = document.createElement('div');
    wrap.style.cssText = 'position:relative;display:block';
    ta.parentNode.insertBefore(wrap, ta);
    wrap.appendChild(ta);
    // dejar espacio para que el texto no quede debajo del botón
    ta.style.paddingRight = '42px';

    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'voz-mic-btn';
    btn.title = 'Dictar por voz';
    btn.innerHTML = '🎤';
    btn.style.cssText = 'position:absolute;top:8px;right:8px;width:30px;height:30px;border-radius:8px;border:1px solid var(--border);background:var(--bg2);cursor:pointer;font-size:14px;display:flex;align-items:center;justify-content:center;transition:all .15s;z-index:3;padding:0';
    btn.onmouseover = function(){ if(_activo!==ta){ this.style.background='var(--primary-l)'; this.style.borderColor='var(--primary)'; } };
    btn.onmouseout  = function(){ if(_activo!==ta){ this.style.background='var(--bg2)'; this.style.borderColor='var(--border)'; } };
    btn.onclick = function(){ toggleDictado(ta, btn); };
    wrap.appendChild(btn);
  }

  function detener(){
    if (_rec) { try{ _rec.stop(); }catch(e){} }
    if (_activo) {
      var b = _activo.parentNode.querySelector('.voz-mic-btn');
      if (b){ b.innerHTML='🎤'; b.style.background='var(--bg2)'; b.style.borderColor='var(--border)'; b.style.animation=''; }
    }
    _activo = null; _rec = null;
  }

  function toggleDictado(ta, btn){
    // Si ya estaba dictando este mismo campo → detener
    if (_activo === ta) { detener(); return; }
    // Si estaba dictando otro → detener primero
    if (_activo) detener();

    _rec = new SpeechRec();
    _rec.lang = 'es-PE';
    _rec.continuous = true;
    _rec.interimResults = true;
    _baseText = ta.value ? (ta.value.trim() + ' ') : '';

    _rec.onresult = function(e){
      var txt = '';
      for (var i=0; i<e.results.length; i++) txt += e.results[i][0].transcript;
      ta.value = _baseText + txt;
      ta.dispatchEvent(new Event('input',{bubbles:true}));
    };
    _rec.onerror = function(e){
      if (e.error === 'not-allowed' || e.error === 'service-not-allowed') {
        alert('Necesito permiso para usar el micrófono. Actívalo en el candado 🔒 de la barra de direcciones.');
      }
      detener();
    };
    _rec.onend = function(){ if(_activo===ta) detener(); };

    try {
      _rec.start();
      _activo = ta;
      btn.innerHTML = '⏹';
      btn.style.background = '#fee2e2';
      btn.style.borderColor = '#ef4444';
      btn.style.animation = 'vozPulse 1.2s infinite';
    } catch(err){ detener(); }
  }

  // Estilo de pulso para el botón activo
  var st = document.createElement('style');
  st.textContent = '@keyframes vozPulse{0%,100%{box-shadow:0 0 0 0 rgba(239,68,68,.4)}50%{box-shadow:0 0 0 5px rgba(239,68,68,0)}}';
  document.head.appendChild(st);

  // Aplicar a todos los textarea relevantes al cargar
  function init(){
    document.querySelectorAll('textarea.form-input, textarea[data-voice="1"]').forEach(crearMic);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
</script>
</body>
</html>
