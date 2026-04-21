/* ===== Constants ===== */
const OM = 'https://cdn.jsdelivr.net/npm/openmoji@15.0.0/color/svg/';
// Pexels CDN — fotos gratuitas (pexels.com)
function px(id) { return `https://images.pexels.com/photos/${id}/pexels-photo-${id}.jpeg?auto=compress&cs=tinysrgb&w=120&h=120&fit=crop`; }

/* ===== Cookies ===== */
function setCookie(name, value, days) {
  var d = new Date();
  d.setTime(d.getTime() + (days * 24 * 60 * 60 * 1000));
  document.cookie = name + '=' + encodeURIComponent(value) + ';expires=' + d.toUTCString() + ';path=/;SameSite=Lax';
}
function getCookie(name) {
  var match = document.cookie.match(new RegExp('(^| )' + name + '=([^;]+)'));
  return match ? decodeURIComponent(match[2]) : null;
}
function deleteCookie(name) {
  document.cookie = name + '=;expires=Thu, 01 Jan 1970 00:00:00 UTC;path=/;SameSite=Lax';
}
function cerrarSesion() {
  deleteCookie('cliente_id');
  location.reload();
}

/* ===== State ===== */
let pedidoMinimo = 0;
const state = {
  productos: [],
  cart: [],
  categoriaActual: 'todos',
  busqueda: '',
  loading: false,
};

/* ===== Theme ===== */
const tema = {
  init() {
    const saved = localStorage.getItem('tema') || 'light';
    document.documentElement.setAttribute('data-theme', saved);
    this.updateIcon(saved);
  },
  toggle() {
    const actual = document.documentElement.getAttribute('data-theme');
    const nuevo  = actual === 'dark' ? 'light' : 'dark';
    document.documentElement.setAttribute('data-theme', nuevo);
    localStorage.setItem('tema', nuevo);
    this.updateIcon(nuevo);
  },
  updateIcon(t) {
    const btn = document.getElementById('btnTema');
    if (btn) btn.innerHTML = t === 'dark'
      ? `<i class="fa-solid fa-sun" style="font-size:18px"></i>`
      : `<i class="fa-solid fa-moon" style="font-size:18px"></i>`;
    const metaTheme = document.getElementById('metaThemeColor');
    if (metaTheme) metaTheme.setAttribute('content', t === 'dark' ? '#3d4248' : '#ffffff');
  },
};

/* ===== API ===== */
async function fetchProductos(cat = 'todos', q = '') {
  state.loading = true;
  renderProducts([]);

  const params = new URLSearchParams({ categoria: cat, q });
  const res  = await fetch(`api/productos?${params}`);
  const data = await res.json();

  state.productos = data.data || [];
  state.loading   = false;
  renderProducts(state.productos);
}

async function enviarPedido(datos) {
  const res  = await fetch('api/pedidos', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(datos),
  });
  const text = await res.text();
  try {
    return JSON.parse(text);
  } catch {
    console.error('Respuesta no JSON de pedidos:', text);
    return { ok: false, error: text };
  }
}

async function notificarWhatsApp(pedido, datos) {
  const lineas = datos.items.map(i => `• ${i.nombre} ×${i.cantidad} — $${(i.precio * i.cantidad).toLocaleString('es-AR')}`).join('\n');
  const total  = datos.items.reduce((s, i) => s + i.precio * i.cantidad, 0);
  const cuerpo = `🛒 *Nuevo pedido ${pedido.numero}*\n\n`
    + `👤 ${datos.cliente}\n`
    + `📞 ${datos.celular}\n`
    + `📍 ${datos.direccion}\n`
    + (datos.notas ? `📝 ${datos.notas}\n` : '')
    + `\n${lineas}\n\n`
    + `💰 *Total: $${total.toLocaleString('es-AR')}*`;

  try {
    const payload = {
      servicio:     'evolution',
      proyecto:     'vigicom',
      canal:        'repo-hum',
      plantilla:    '',
      remitente:    'Repo Online',
      remite:       '1169391123',
      destinatario: 'Repo',
      destino:      '2644984568',
      prioridad:    '2',
      asunto:       'Nuevo pedido ' + pedido.numero,
      cuerpo:       cuerpo,
      variables:    '',
      codificado:   '0',
      formato:      'T',
      adjunto:      '',
      parametros:   '',
      tags:         'pedido',
    };
    console.log('[WA] payload →', payload);
    const waRes  = await fetch('api/notificar_whatsapp', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    });
    const waText = await waRes.text();
    console.log('[WA] status:', waRes.status, '| respuesta:', waText);
    showToast('[WA] ' + waRes.status + ' ' + waText.slice(0, 80));
  } catch (err) {
    console.error('[WA] error de red:', err);
    showToast('[WA] error de red: ' + err.message);
  }
}

async function notificarClienteWA(pedido, datos) {
  if (!datos.celular) return;

  const baseUrl = window.location.origin + window.location.pathname.replace(/\/[^/]*$/, '/');
  const linkSeguimiento = baseUrl + 'seguimiento?p=' + encodeURIComponent(pedido.numero);

  const cuerpo = '¡Hola ' + datos.cliente.split(' ')[0] + '! 👋\n\n'
    + 'Recibimos tu pedido *' + pedido.numero + '* y ya lo estamos procesando. 🛒\n\n'
    + '📦 Podés ver el estado de tu pedido en:\n'
    + linkSeguimiento + '\n\n'
    + '¡Gracias por tu compra!';

  try {
    const payload = {
      servicio:     'evolution',
      proyecto:     'vigicom',
      canal:        'repo-hum',
      plantilla:    '',
      remitente:    'Repo Online',
      remite:       '1169391123',
      destinatario: datos.cliente,
      destino:      datos.celular,
      prioridad:    '2',
      asunto:       'Confirmación pedido ' + pedido.numero,
      cuerpo:       cuerpo,
      variables:    '',
      codificado:   '0',
      formato:      'T',
      adjunto:      '',
      parametros:   '',
      tags:         'confirmacion',
    };
    await fetch('api/notificar_whatsapp', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    });
  } catch (err) {
    console.error('[WA cliente] error:', err);
  }
}

function registrarEvento(detalle) {
  const clienteId = parseInt(getCookie('cliente_id')) || 0;
  fetch('api/eventos', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ cliente_id: clienteId, detalle }),
  }).catch(() => {});
}

/* ===== Cart ===== */
const cart = {
  add(producto) {
    const idx = state.cart.findIndex(i => i.id === producto.id);
    if (idx >= 0) {
      if (state.cart[idx].cantidad >= (producto.stock_actual ?? Infinity)) return;
      state.cart[idx].cantidad++;
    } else {
      if ((producto.stock_actual ?? 1) < 1) return;
      state.cart.push({ ...producto, cantidad: 1 });
    }
    this.save(); this.updateUI();
    registrarEvento('Agregó al carrito: ' + producto.nombre);
  },
  remove(id) {
    const idx = state.cart.findIndex(i => i.id === id);
    if (idx < 0) return;
    const nombre = state.cart[idx].nombre;
    registrarEvento('Quitó del carrito: ' + nombre);
    if (state.cart[idx].cantidad > 1) {
      state.cart[idx].cantidad--;
    } else {
      state.cart.splice(idx, 1);
    }
    this.save(); this.updateUI();
  },
  qty(id) {
    return state.cart.find(i => i.id === id)?.cantidad || 0;
  },
  total() {
    return state.cart.reduce((s, i) => s + i.precio * i.cantidad, 0);
  },
  count() {
    return state.cart.reduce((s, i) => s + i.cantidad, 0);
  },
  clear() {
    state.cart = [];
    this.save(); this.updateUI();
  },
  save() {
    localStorage.setItem('cart', JSON.stringify(state.cart));
  },
  load() {
    try { state.cart = JSON.parse(localStorage.getItem('cart')) || []; }
    catch { state.cart = []; }
  },
  updateUI() {
    // Badge
    const c = this.count();
    const badge = document.getElementById('cartBadge');
    if (badge) {
      badge.textContent = c;
      badge.classList.toggle('visible', c > 0);
    }
    // Drawer items
    renderCartItems();
    // Products (qty buttons)
    renderProducts(state.productos);
  },
};

/* ===== Render productos ===== */
function renderProducts(lista) {
  const grid = document.getElementById('productsGrid');
  if (!grid) return;

  if (state.loading) {
    grid.innerHTML = `<div class="spinner"><div class="spin"></div></div>`;
    return;
  }
  if (!lista.length) {
    grid.innerHTML = `<div class="empty"><div class="empty-icon"><img src="${OM}1F50D.svg" alt="sin resultados" width="56" height="56"></div><p>No hay productos para esta búsqueda</p></div>`;
    return;
  }

  // Update section subtitle
  const sub = document.getElementById('productCount');
  if (sub) sub.textContent = `${lista.length} producto${lista.length !== 1 ? 's' : ''}`;

  grid.innerHTML = lista.map(p => {
    const qty = cart.qty(p.id);
    const stockMax = p.stock_actual ?? Infinity;
    const topado  = qty >= stockMax;
    const controles = qty > 0
      ? `<div class="card-qty-wrap">
           <button class="btn-minus" onclick="cart.remove(${p.id});event.stopPropagation()">−</button>
           <span class="qty-label">${qty}</span>
           <button class="btn-add" onclick="cart.add(${JSON.stringify(p).replace(/"/g,'&quot;')});event.stopPropagation()" ${topado ? 'disabled' : ''}>+</button>
         </div>`
      : `<button class="btn-add" onclick="cart.add(${JSON.stringify(p).replace(/"/g,'&quot;')});event.stopPropagation()">+</button>`;

    return `
      <div class="card ${!p.stock ? 'sin-stock' : ''}" onclick="openProductModal(${p.id})">
        ${!p.stock ? '<span class="stock-tag">Sin stock</span>' : ''}
        <div class="card-thumb"><img src="${p.imagen}" alt="${p.nombre}" loading="lazy" width="72" height="72"></div>
        <div class="card-body">
          <div class="card-name">${p.nombre}</div>
          <div class="card-unit">por ${p.unidad}</div>
          <div class="card-footer">
            <div class="card-price">$${p.precio.toLocaleString('es-AR')} <span>/ ${p.unidad}</span></div>
            ${controles}
          </div>
        </div>
      </div>`;
  }).join('');
}

/* ===== Render cart drawer ===== */
function renderCartItems() {
  const el = document.getElementById('cartItemsList');
  const footer = document.getElementById('cartFooter');
  if (!el) return;

  if (!state.cart.length) {
    el.innerHTML = `<div class="cart-empty"><span class="empty-icon"><i class="fa-solid fa-cart-shopping" style="font-size:48px"></i></span><p>Tu carrito está vacío</p></div>`;
    if (footer) footer.style.display = 'none';
    return;
  }

  if (footer) footer.style.display = '';
  el.innerHTML = state.cart.map(item => `
    <div class="cart-item">
      <img class="ci-img" src="${item.imagen}" alt="${item.nombre}" loading="lazy" width="36" height="36">
      <div class="ci-info">
        <div class="ci-name">${item.nombre}</div>
        <div class="ci-price">$${item.precio.toLocaleString('es-AR')} c/u</div>
      </div>
      <div class="ci-controls">
        <button class="ci-btn" onclick="cart.remove(${item.id})">−</button>
        <span class="ci-qty">${item.cantidad}</span>
        <button class="ci-btn" onclick="cart.add(${JSON.stringify(item).replace(/"/g,'&quot;')})" ${item.cantidad >= (item.stock_actual ?? Infinity) ? 'disabled' : ''}>+</button>
      </div>
      <span class="ci-subtotal">$${(item.precio * item.cantidad).toLocaleString('es-AR')}</span>
    </div>`).join('');

  const totalEl = document.getElementById('cartTotal');
  if (totalEl) totalEl.textContent = cart.total().toLocaleString('es-AR');
}

/* ===== Drawer ===== */
function openCart() {
  registrarEvento('Ingresó a: Carrito');
  document.getElementById('overlay').classList.add('open');
  document.getElementById('cartDrawer').classList.add('open');
  document.body.style.overflow = 'hidden';
}
function closeCart() {
  document.getElementById('overlay').classList.remove('open');
  document.getElementById('cartDrawer').classList.remove('open');
  document.body.style.overflow = '';
}

/* ===== Checkout Modal ===== */
function openCheckout() {
  if (!state.cart.length) {
    showToast('Agregá productos al carrito primero');
    return;
  }
  if (pedidoMinimo > 0 && cart.total() < pedidoMinimo) {
    showToast('El pedido mínimo es $' + pedidoMinimo.toLocaleString('es-AR'));
    return;
  }
  closeCart();
  renderSummary();
  document.getElementById('checkoutModal').classList.add('open');
  document.getElementById('confirmScreen').classList.remove('show');
  document.getElementById('checkoutForm').style.display = '';
  document.body.style.overflow = 'hidden';
}
function closeCheckout() {
  document.getElementById('checkoutModal').classList.remove('open');
  document.body.style.overflow = '';
}

function renderSummary() {
  const el = document.getElementById('orderSummaryLines');
  if (!el) return;
  el.innerHTML = state.cart.map(i =>
    `<div class="summary-line">
       <span><img src="${i.imagen}" alt="${i.nombre}" width="18" height="18" style="vertical-align:middle;margin-right:4px"> ${i.nombre} ×${i.cantidad}</span>
       <span>$${(i.precio * i.cantidad).toLocaleString('es-AR')}</span>
     </div>`).join('');
  const totalEl = document.getElementById('summaryTotal');
  if (totalEl) totalEl.textContent = cart.total().toLocaleString('es-AR');
}

async function handleCheckout(e) {
  e.preventDefault();
  const btn = document.getElementById('btnConfirmar');
  btn.disabled = true;

  // Paso 1: Obtener ubicación (sin preguntar si ya fue otorgado el permiso)
  btn.textContent = 'Obteniendo ubicación...';
  const geoOverlay = document.getElementById('geoOverlay');
  let coords = { lat: null, lng: null };

  try {
    // Consultar el estado del permiso sin dispararlo
    const permState = navigator.permissions
      ? (await navigator.permissions.query({ name: 'geolocation' })).state
      : 'prompt';

    if (permState === 'granted') {
      // Ya tiene permiso: obtener posición directamente sin mostrar el overlay
      coords = await new Promise(function(resolve) {
        navigator.geolocation.getCurrentPosition(
          function(pos) { resolve({ lat: pos.coords.latitude, lng: pos.coords.longitude }); },
          function()    { resolve({ lat: null, lng: null }); },
          { enableHighAccuracy: true, timeout: 10000, maximumAge: 60000 }
        );
      });
    } else if (permState === 'denied') {
      // Permiso denegado: continuar sin coordenadas
      coords = { lat: null, lng: null };
    } else {
      // Estado 'prompt': mostrar el overlay por primera vez
      coords = await new Promise(function(resolve) {
        geoOverlay.classList.add('show');
        var btnPermit = document.getElementById('geoPermitir');
        var btnSkip   = document.getElementById('geoOmitir');

        function cleanup() {
          geoOverlay.classList.remove('show');
          btnPermit.onclick = null;
          btnSkip.onclick = null;
        }

        btnSkip.onclick = function() {
          cleanup();
          resolve({ lat: null, lng: null });
        };

        btnPermit.onclick = function() {
          cleanup();
          if (!navigator.geolocation) {
            resolve({ lat: null, lng: null });
            return;
          }
          navigator.geolocation.getCurrentPosition(
            function(pos) { resolve({ lat: pos.coords.latitude, lng: pos.coords.longitude }); },
            function()    { resolve({ lat: null, lng: null }); },
            { enableHighAccuracy: true, timeout: 10000, maximumAge: 60000 }
          );
        };
      });
    }
  } catch (err) {
    // Si falla la API de permisos o geolocation, continuar sin coordenadas
  }

  // Paso 2: Enviar pedido con coordenadas
  btn.textContent = 'Enviando...';

  const datos = {
    cliente:   document.getElementById('fCliente').value.trim(),
    correo:    document.getElementById('fEmail').value.trim(),
    celular:  document.getElementById('fTelefono').value.trim(),
    direccion: document.getElementById('fDireccion').value.trim(),
    notas:     document.getElementById('fNotas').value.trim(),
    items:     state.cart,
    lat:       coords.lat,
    lng:       coords.lng,
  };

  try {
    const res = await enviarPedido(datos);
    if (res.ok) {
      // Guardar cliente_id en cookie (365 días)
      if (res.pedido && res.pedido.cliente_id) {
        setCookie('cliente_id', res.pedido.cliente_id, 365);
      }
      document.getElementById('checkoutForm').style.display = 'none';
      document.getElementById('confirmNum').textContent = res.pedido.numero;
      document.getElementById('confirmScreen').classList.add('show');
      notificarWhatsApp(res.pedido, datos);
      notificarClienteWA(res.pedido, datos);
      cart.clear();
    } else {
      console.error('Error pedido:', res);
      showToast(res.error || 'Error al enviar el pedido. Intentá de nuevo.');
    }
  } catch (err) {
    console.error('Excepción pedido:', err);
    showToast('Sin conexión. Verificá tu internet.');
  }

  btn.disabled = false;
  btn.textContent = 'Confirmar pedido';
}

/* ===== Categories ===== */
let categorias = [
  { id: 'todos', label: 'Todos', emoji: '🛒', imagen: px(256318) },
];

async function cargarCategorias() {
  try {
    const res = await fetch('api/categorias');
    const data = await res.json();
    if (data.ok && data.data.length) {
      categorias = [
        { id: 'todos', label: 'Todos', emoji: '🛒', imagen: px(256318) },
        ...data.data
      ];
    }
  } catch (e) { console.error('Error cargando categorías', e); }
}

function renderCats() {
  const wrap = document.getElementById('catsContainer');
  if (!wrap) return;
  wrap.innerHTML = categorias.map(c => `
    <button class="cat-btn ${state.categoriaActual === c.id ? 'active' : ''}"
            onclick="selectCat('${c.id}')">
      <span class="cat-emoji">${c.emoji}</span>${c.label}
    </button>`).join('');
}

function selectCat(id) {
  state.categoriaActual = id;
  renderCats();
  fetchProductos(id, state.busqueda);
}

/* ===== Search ===== */
let searchTimer;
function onSearch(val) {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(() => {
    state.busqueda = val;
    fetchProductos(state.categoriaActual, val);
  }, 300);
}

/* ===== Tabs ===== */
function selectTab(tab, el) {
  // Marcar tab activo (excluye el de carrito que no tiene sección)
  document.querySelectorAll('.nav-tab').forEach(b => b.classList.remove('active'));
  if (el) el.classList.add('active');

  const inicio   = document.getElementById('inicioSection');
  const pedidos  = document.getElementById('pedidosSection');
  const perfil   = document.getElementById('perfilSection');

  inicio.style.display  = 'none';
  pedidos.style.display = 'none';
  perfil.style.display  = 'none';

  if (tab === 'pedidos') {
    pedidos.style.display = '';
    cargarMisPedidos();
    registrarEvento('Ingresó a: Pedidos');
  } else if (tab === 'perfil') {
    perfil.style.display = '';
    cargarPerfil();
    registrarEvento('Ingresó a: Perfil');
  } else {
    registrarEvento('Ingresó a: Inicio');
    inicio.style.display = '';
  }
}

/* ===== Mis pedidos ===== */
async function cargarMisPedidos() {
  const lista = document.getElementById('pedidosList');
  lista.innerHTML = `<div class="spinner"><div class="spin"></div></div>`;

  const clienteId = getCookie('cliente_id');
  if (!clienteId) {
    lista.innerHTML = `<div class="empty"><div class="empty-icon"><i class="fa-solid fa-receipt" style="font-size:56px"></i></div><p>Aún no hiciste ningún pedido</p></div>`;
    return;
  }
  try {
    const res  = await fetch(`api/pedidos?cliente_id=${clienteId}`);
    const data = await res.json();
    if (data.ok) {
      renderPedidos(data.data);
    } else {
      lista.innerHTML = `<div class="empty"><p>No se pudieron cargar los pedidos</p></div>`;
    }
  } catch {
    lista.innerHTML = `<div class="empty"><p>Sin conexión</p></div>`;
  }
}

const ESTADO_LABEL = {
  pendiente:  'Pendiente',
  preparando: 'Preparando',
  listo:      'Listo',
  entregado:  'Entregado',
  cancelado:  'Cancelado',
};

function renderPedidos(lista) {
  const el = document.getElementById('pedidosList');
  if (!lista.length) {
    el.innerHTML = `<div class="empty"><div class="empty-icon"><i class="fa-solid fa-receipt" style="font-size:56px"></i></div><p>Aún no hiciste ningún pedido</p></div>`;
    return;
  }
  el.innerHTML = lista.map(p => {
    const fecha  = new Date(p.fecha).toLocaleDateString('es-AR', { day:'numeric', month:'short', year:'numeric' });
    const items  = p.items.map(i => `<div class="pcard-item">${i.cantidad}× ${i.nombre} <span>$${(i.precio * i.cantidad).toLocaleString('es-AR')}</span></div>`).join('');
    return `
      <div class="pcard" onclick='openPedModal(${JSON.stringify(p)})' style="cursor:pointer">
        <div class="pcard-head">
          <div>
            <div class="pcard-num">${p.numero}</div>
            <div class="pcard-fecha">${fecha}</div>
          </div>
          <span class="pcard-estado pcard-estado-${p.estado}">${ESTADO_LABEL[p.estado] || p.estado}</span>
        </div>
        <div class="pcard-items">${items}</div>
        <div class="pcard-foot">
          <span class="pcard-total">Total $${p.total.toLocaleString('es-AR')}</span>
        </div>
      </div>`;
  }).join('');
}

/* ===== Modal detalle pedido ===== */
const PED_ESTADOS = {
  pendiente:  { label: 'Recibido',   color: '#f59e0b' },
  confirmado: { label: 'Confirmado', color: '#3b82f6' },
  preparando: { label: 'Preparando', color: '#8b5cf6' },
  enviado:    { label: 'En camino',  color: '#06b6d4' },
  entregado:  { label: 'Entregado',  color: '#22c55e' },
  cancelado:  { label: 'Cancelado',  color: '#ef4444' },
};
const PED_PASOS = ['pendiente', 'confirmado', 'preparando', 'enviado', 'entregado'];
const PED_PASO_LABELS = ['Recibido', 'Confirmado', 'Preparando', 'En camino', 'Entregado'];

function openPedModal(p) {
  const est = PED_ESTADOS[p.estado] || { label: p.estado, color: '#64748b' };
  const fecha = new Date(p.fecha).toLocaleDateString('es-AR', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
  const pasoActual = PED_PASOS.indexOf(p.estado);

  document.getElementById('pmNum').textContent = p.numero;
  document.getElementById('pmFecha').textContent = fecha;
  document.getElementById('pmEstado').innerHTML =
    `<span class="pm-badge" style="background:${est.color}22;color:${est.color}">${est.label}</span>`;

  // Barra de progreso
  if (p.estado !== 'cancelado') {
    document.getElementById('pmSteps').innerHTML = PED_PASOS.map((paso, i) => {
      const done = pasoActual > i, active = pasoActual === i;
      const cls = done ? 'done' : active ? 'active' : 'pending';
      return `<div class="pm-step">
        <div class="pm-step-dot ${cls}">${done ? '✓' : i + 1}</div>
        <div class="pm-step-label">${PED_PASO_LABELS[i]}</div>
        ${i < PED_PASOS.length - 1 ? `<div class="pm-step-line ${done ? 'done' : 'pending'}"></div>` : ''}
      </div>`;
    }).join('');
  } else {
    document.getElementById('pmSteps').innerHTML = '';
  }

  // Items
  document.getElementById('pmItems').innerHTML = p.items.map(i =>
    `<div class="pm-item-row">
      <div><div class="pm-item-nombre">${i.nombre}</div><div class="pm-item-cant">× ${i.cantidad}</div></div>
      <div class="pm-item-precio">$${(i.precio * i.cantidad).toLocaleString('es-AR')}</div>
    </div>`
  ).join('');
  document.getElementById('pmTotal').textContent = '$' + p.total.toLocaleString('es-AR');

  document.getElementById('pedModalBackdrop').classList.add('open');
  document.body.style.overflow = 'hidden';
}

function closePedModal() {
  document.getElementById('pedModalBackdrop').classList.remove('open');
  document.body.style.overflow = '';
}

/* ===== Perfil ===== */
let perfilData = null;
let perfilGeoLat = null;
let perfilGeoLng = null;

async function cargarPerfil() {
  const el = document.getElementById('perfilContenido');
  const clienteId = getCookie('cliente_id');

  if (!clienteId) {
    el.innerHTML = `
      <div class="perfil-empty">
        <div class="perfil-empty-icon"><i class="fa-solid fa-circle-user" style="font-size:56px"></i></div>
        <p>Aún no tenés un perfil guardado.</p>
        <small>Realizá tu primer pedido para crear tu perfil.</small>
      </div>`;
    return;
  }

  el.innerHTML = `<div class="spinner"><div class="spin"></div></div>`;

  try {
    const res  = await fetch(`api/clientes?id=${clienteId}`);
    const data = await res.json();
    if (data.ok && data.data) {
      perfilData = data.data;
      renderPerfil(data.data);
    } else {
      el.innerHTML = `<div class="perfil-empty"><p>No se pudo cargar el perfil.</p></div>`;
    }
  } catch {
    el.innerHTML = `<div class="perfil-empty"><p>Sin conexión.</p></div>`;
  }
}

function renderPerfil(cli) {
  const el = document.getElementById('perfilContenido');
  const iniciales = (cli.nombre || '?').split(' ').map(w => w[0]).slice(0, 2).join('').toUpperCase();
  const lat = cli.lat ? parseFloat(cli.lat) : null;
  const lng = cli.lng ? parseFloat(cli.lng) : null;
  const tieneUbic = lat && lng;
  const mapsUrl = tieneUbic
    ? 'https://www.google.com/maps?q=' + lat.toFixed(6) + ',' + lng.toFixed(6)
    : null;

  const filaCorreo = '<div class="perfil-fila"><span class="perfil-icono">✉️</span><span>' + (cli.correo || 'Sin correo') + '</span></div>';

  const filaGps = tieneUbic
    ? '<div class="perfil-fila"><span class="perfil-icono"><img src="' + OM + '1F4CD.svg" alt="ubicación" width="16" height="16"></span>'
      + '<a href="' + mapsUrl + '" target="_blank" class="perfil-geo-link">'
      + 'Ubicación detectada</a></div>'
    : '';

  const ubicBtn = tieneUbic
    ? ''
    : '<button class="perfil-btn-ubic perfil-btn-ubic--detectar" onclick="detectarUbicacionDirecta()">📍 Detectar ubicación</button>';

  el.innerHTML =
    '<div class="perfil-card">'
    + '<div class="perfil-avatar">' + iniciales + '</div>'
    + '<div class="perfil-info">'
      + '<div class="perfil-nombre">' + (cli.nombre || '—') + '</div>'
      + '<div class="perfil-fila"><span class="perfil-icono">📞</span><span>' + (cli.celular || 'Sin teléfono') + '</span></div>'
      + filaCorreo
      + '<div class="perfil-fila"><span class="perfil-icono">🏠</span><span>' + (cli.direccion || 'Sin dirección') + '</span></div>'
      + filaGps
      + ubicBtn
    + '</div>'
    + '<button class="perfil-btn-edit btn-ver-carrito" onclick="openPerfilModal()">Editar datos</button>'
    + '<button class="btn-checkout perfil-btn-logout" onclick="cerrarSesion()">Cerrar sesión</button>'
    + '</div>';
}

function openPerfilModal() {
  if (!perfilData) return;
  document.getElementById('pNombre').value    = perfilData.nombre    || '';
  document.getElementById('pCorreo').value    = perfilData.correo    || '';
  document.getElementById('pTelefono').value  = perfilData.celular  || '';
  document.getElementById('pDireccion').value = perfilData.direccion || '';

  // Estado GPS
  perfilGeoLat = perfilData.lat ? parseFloat(perfilData.lat) : null;
  perfilGeoLng = perfilData.lng ? parseFloat(perfilData.lng) : null;
  actualizarEstadoGeo(perfilGeoLat, perfilGeoLng);

  document.getElementById('perfilModal').classList.add('open');
  document.body.style.overflow = 'hidden';
}

function closePerfilModal() {
  document.getElementById('perfilModal').classList.remove('open');
  document.body.style.overflow = '';
}

function actualizarEstadoGeo(lat, lng) {
  const icon  = document.getElementById('pGeoIcon');
  const label = document.getElementById('pGeoLabel');
  const btn   = document.getElementById('pGeoBtn');
  if (!icon || !label) return;
  if (lat && lng) {
    icon.textContent  = '🟢';
    label.textContent = `Detectada (${lat.toFixed(5)}, ${lng.toFixed(5)})`;
    btn.textContent   = 'Actualizar';
  } else {
    icon.textContent  = '⚪';
    label.textContent = 'Sin ubicación detectada';
    btn.textContent   = 'Detectar';
  }
}

function detectarUbicacionPerfil() {
  if (!navigator.geolocation) {
    showToast('Tu dispositivo no soporta geolocalización');
    return;
  }
  const btn   = document.getElementById('pGeoBtn');
  const label = document.getElementById('pGeoLabel');
  btn.disabled = true;
  label.textContent = 'Detectando...';

  navigator.geolocation.getCurrentPosition(
    function(pos) {
      perfilGeoLat = pos.coords.latitude;
      perfilGeoLng = pos.coords.longitude;
      actualizarEstadoGeo(perfilGeoLat, perfilGeoLng);
      btn.disabled = false;
    },
    function() {
      actualizarEstadoGeo(perfilGeoLat, perfilGeoLng);
      btn.disabled = false;
      showToast('No se pudo obtener la ubicación');
    },
    { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 }
  );
}

/* Detectar ubicación directamente desde la vista de perfil */
function detectarUbicacionDirecta() {
  if (!navigator.geolocation) {
    showToast('Tu dispositivo no soporta geolocalización');
    return;
  }
  const clienteId = getCookie('cliente_id');
  if (!clienteId || !perfilData) return;

  showToast('Detectando ubicación...');

  navigator.geolocation.getCurrentPosition(
    async function(pos) {
      const lat = pos.coords.latitude;
      const lng = pos.coords.longitude;
      try {
        const res = await fetch('api/clientes', {
          method: 'PATCH',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ id: parseInt(clienteId), lat, lng }),
        });
        const data = await res.json();
        if (data.ok) {
          perfilData.lat = lat;
          perfilData.lng = lng;
          perfilGeoLat = lat;
          perfilGeoLng = lng;
          renderPerfil(perfilData);
          showToast('Ubicación actualizada');
        } else {
          showToast(data.error || 'Error al guardar ubicación');
        }
      } catch {
        showToast('Sin conexión');
      }
    },
    function() {
      showToast('No se pudo obtener la ubicación');
    },
    { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 }
  );
}

/* Quitar ubicación directamente desde la vista de perfil */
async function quitarUbicacionDirecta() {
  const clienteId = getCookie('cliente_id');
  if (!clienteId || !perfilData) return;

  try {
    const res = await fetch('api/clientes', {
      method: 'PATCH',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ id: parseInt(clienteId), lat: null, lng: null }),
    });
    const data = await res.json();
    if (data.ok) {
      perfilData.lat = null;
      perfilData.lng = null;
      perfilGeoLat = null;
      perfilGeoLng = null;
      renderPerfil(perfilData);
      showToast('Ubicación eliminada');
    } else {
      showToast(data.error || 'Error al quitar ubicación');
    }
  } catch {
    showToast('Sin conexión');
  }
}

async function guardarPerfil() {
  const nombre    = document.getElementById('pNombre').value.trim();
  const correo    = document.getElementById('pCorreo').value.trim();
  const celular  = document.getElementById('pTelefono').value.trim();
  const direccion = document.getElementById('pDireccion').value.trim();
  const clienteId = getCookie('cliente_id');

  if (!nombre) { showToast('El nombre es requerido'); return; }

  const btn = document.getElementById('btnGuardarPerfil');
  btn.disabled = true;
  btn.textContent = 'Guardando...';

  const payload = {
    id: parseInt(clienteId),
    nombre, correo: correo || null,
    celular: celular || null,
    direccion: direccion || null,
    lat: perfilGeoLat,
    lng: perfilGeoLng,
  };

  try {
    const res = await fetch('api/clientes', {
      method: 'PATCH',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    });
    const data = await res.json();
    if (data.ok) {
      perfilData = { ...perfilData, nombre, correo, celular, direccion, lat: perfilGeoLat, lng: perfilGeoLng };
      renderPerfil(perfilData);
      closePerfilModal();
      showToast('Perfil actualizado');
      // Sincronizar campos del checkout
      const fCli = document.getElementById('fCliente');
      const fTel = document.getElementById('fTelefono');
      const fDir = document.getElementById('fDireccion');
      if (fCli) fCli.value = nombre;
      if (fTel) fTel.value = celular;
      if (fDir) fDir.value = direccion;
    } else {
      showToast(data.error || 'Error al guardar');
    }
  } catch {
    showToast('Sin conexión');
  }

  btn.disabled = false;
  btn.textContent = 'Guardar cambios';
}

/* ===== Toast ===== */
let toastTimer;
function showToast(msg) {
  const el = document.getElementById('toast');
  if (!el) return;
  el.textContent = msg;
  el.classList.add('show');
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => el.classList.remove('show'), 3000);
}

/* ===== Product Detail Modal ===== */
let currentDetailProduct = null;

function openProductModal(id) {
  const p = state.productos.find(x => x.id === id);
  if (!p) return;
  currentDetailProduct = p;

  document.getElementById('pdImg').src = p.imagen;
  document.getElementById('pdImg').alt = p.nombre;
  document.getElementById('pdName').textContent = p.nombre;
  document.getElementById('pdUnit').textContent = 'por ' + p.unidad;
  document.getElementById('pdPrice').textContent = '$' + p.precio.toLocaleString('es-AR') + ' / ' + p.unidad;

  const stockEl = document.getElementById('pdStock');
  if (p.stock) {
    stockEl.textContent = 'Disponible';
    stockEl.className = 'product-detail-stock in-stock';
  } else {
    stockEl.textContent = 'Sin stock';
    stockEl.className = 'product-detail-stock out-stock';
  }

  renderDetailActions();

  // Actualizar URL sin recargar
  const url = new URL(window.location);
  url.searchParams.set('producto', id);
  history.pushState({ producto: id }, '', url);

  document.getElementById('productModal').classList.add('open');
  document.body.style.overflow = 'hidden';
}

function closeProductModal() {
  document.getElementById('productModal').classList.remove('open');
  document.body.style.overflow = '';
  currentDetailProduct = null;

  // Restaurar URL sin el parámetro producto
  const url = new URL(window.location);
  url.searchParams.delete('producto');
  history.pushState({}, '', url);
}

function renderDetailActions() {
  const el = document.getElementById('pdActions');
  if (!el || !currentDetailProduct) return;
  const p = currentDetailProduct;
  const qty = cart.qty(p.id);
  const shareBtn = `<button class="btn-share" onclick="shareProduct()">
    <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M18 16.08c-.76 0-1.44.3-1.96.77L8.91 12.7c.05-.23.09-.46.09-.7s-.04-.47-.09-.7l7.05-4.11c.54.5 1.25.81 2.04.81 1.66 0 3-1.34 3-3s-1.34-3-3-3-3 1.34-3 3c0 .24.04.47.09.7L8.04 9.81C7.5 9.31 6.79 9 6 9c-1.66 0-3 1.34-3 3s1.34 3 3 3c.79 0 1.5-.31 2.04-.81l7.12 4.16c-.05.21-.08.43-.08.65 0 1.61 1.31 2.92 2.92 2.92s2.92-1.31 2.92-2.92-1.31-2.92-2.92-2.92z"/></svg> Compartir
  </button>`;

  if (!p.stock) {
    el.innerHTML = `<button class="btn-checkout" disabled style="opacity:.5">
      Sin stock
    </button>${shareBtn}`;
    return;
  }

  const stockMax = currentDetailProduct.stock_actual ?? Infinity;
  const finalizarBtn = `<button class="btn-checkout btn-ver-carrito" onclick="closeProductModal();openCart()">
    Ver Carrito
  </button>`;

  if (qty > 0) {
    el.style.marginBottom = '';
    el.innerHTML = `<div class="pd-qty-row">
      <button class="pd-qty-btn" onclick="removeFromDetail()">−</button>
      <span class="pd-qty-label">${qty}</span>
      <button class="pd-qty-btn" onclick="addFromDetail()" ${qty >= stockMax ? 'disabled' : ''}>+</button>
    </div>${finalizarBtn}`;
  } else {
    el.style.marginBottom = '0px';
    el.innerHTML = `<button class="btn-checkout" onclick="addFromDetail()">
      Agregar al carrito
    </button>${shareBtn}`;
  }
}

function addFromDetail() {
  if (!currentDetailProduct || !currentDetailProduct.stock) return;
  cart.add(currentDetailProduct);
  renderDetailActions();
}

function removeFromDetail() {
  if (!currentDetailProduct) return;
  cart.remove(currentDetailProduct.id);
  renderDetailActions();
}

function shareProduct() {
  if (!currentDetailProduct) return;
  const url = new URL(window.location);
  url.searchParams.set('producto', currentDetailProduct.id);
  const shareUrl = url.toString();

  if (navigator.share) {
    navigator.share({
      title: currentDetailProduct.nombre + ' - Repo Online',
      text: currentDetailProduct.nombre + ' $' + currentDetailProduct.precio.toLocaleString('es-AR'),
      url: shareUrl,
    }).catch(() => {});
  } else {
    navigator.clipboard.writeText(shareUrl).then(() => {
      showToast('Link copiado al portapapeles');
    }).catch(() => {
      // Fallback para navegadores sin clipboard API
      prompt('Copiá este link:', shareUrl);
    });
  }
}

// Manejar botón atrás del navegador
window.addEventListener('popstate', function() {
  const params = new URLSearchParams(window.location.search);
  if (!params.has('producto')) {
    document.getElementById('productModal').classList.remove('open');
    document.body.style.overflow = '';
    currentDetailProduct = null;
  }
});

/* ===== Init ===== */
document.addEventListener('DOMContentLoaded', async () => {
  tema.init();
  cart.load();

  // Auto-fill datos del cliente desde cookie
  var clienteId = getCookie('cliente_id');
  if (clienteId) {
    try {
      var cliRes = await fetch('api/clientes?id=' + clienteId);
      var cliData = await cliRes.json();
      if (cliData.ok && cliData.data) {
        document.getElementById('fCliente').value = cliData.data.nombre || '';
        document.getElementById('fEmail').value = cliData.data.correo || '';
        document.getElementById('fTelefono').value = cliData.data.celular || '';
        document.getElementById('fDireccion').value = cliData.data.direccion || '';
      }
    } catch (e) { /* silencioso */ }
  }

  // Cargar config
  try {
    const cfgRes = await fetch('api/configuracion');
    const cfgData = await cfgRes.json();
    if (cfgData.ok && cfgData.data) {
      pedidoMinimo = parseInt(cfgData.data.pedido_minimo) || 0;
    }
  } catch (e) { /* silencioso */ }
  await cargarCategorias();
  renderCats();
  await fetchProductos();
  cart.updateUI();
  registrarEvento('Ingresó a: Inicio');

  // Abrir producto si viene por URL (?producto=ID)
  const urlParams = new URLSearchParams(window.location.search);
  const productoId = parseInt(urlParams.get('producto'));
  if (productoId && state.productos.length) {
    openProductModal(productoId);
  }

  // Search
  const inp = document.getElementById('searchInput');
  if (inp) inp.addEventListener('input', e => onSearch(e.target.value));

  // Swipe to close drawer
  let startY = 0;
  const drawer = document.getElementById('cartDrawer');
  if (drawer) {
    drawer.addEventListener('touchstart', e => { startY = e.touches[0].clientY; }, { passive: true });
    drawer.addEventListener('touchend', e => {
      if (e.changedTouches[0].clientY - startY > 80) closeCart();
    }, { passive: true });
  }
});
