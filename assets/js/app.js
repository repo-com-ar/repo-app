/* ===== Constants ===== */
const OM = 'https://cdn.jsdelivr.net/npm/openmoji@15.0.0/color/svg/';
// Pexels CDN — fotos gratuitas (pexels.com)
function px(id) { return `https://images.pexels.com/photos/${id}/pexels-photo-${id}.jpeg?auto=compress&cs=tinysrgb&w=120&h=120&fit=crop`; }

/* ===== Auth JWT ===== */
function getToken() { return localStorage.getItem('app_token'); }
function setToken(token) { localStorage.setItem('app_token', token); }
function removeToken() { localStorage.removeItem('app_token'); }

function getClienteId() {
  const token = getToken();
  if (!token) return null;
  try {
    const b64 = token.split('.')[1].replace(/-/g, '+').replace(/_/g, '/');
    const payload = JSON.parse(atob(b64));
    if (payload.exp && payload.exp < Math.floor(Date.now() / 1000)) { removeToken(); return null; }
    return payload.cliente_id || null;
  } catch { return null; }
}

function authHeaders() {
  const h = { 'Content-Type': 'application/json' };
  const token = getToken();
  if (token) h['Authorization'] = 'Bearer ' + token;
  return h;
}

function cerrarSesion() {
  removeToken();
  location.reload();
}

function getSessionId() {
  let sid = localStorage.getItem('app_session_id');
  if (!sid) {
    sid = 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c => {
      const r = Math.random() * 16 | 0;
      return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16);
    });
    localStorage.setItem('app_session_id', sid);
  }
  return sid;
}

/* ===== State ===== */
let pedidoMinimo = 0;
const state = {
  productos: [],
  cart: [],
  categoriaActual: 'todos',
  busqueda: '',
  loading: false,
  clienteLat: null,
  clienteLng: null,
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
    headers: authHeaders(),
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
    await fetch('api/notificar_whatsapp', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    });
  } catch {
    // silencioso — la notificación WA no debe interrumpir el flujo
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
  const clienteId = getClienteId() || 0;
  fetch('api/eventos', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ cliente_id: clienteId, detalle }),
  }).catch(() => {});
}

/* ===== Cart ===== */
const cart = {
  _syncTimer: null,
  sync() {
    if (!getClienteId()) return;
    clearTimeout(this._syncTimer);
    this._syncTimer = setTimeout(async () => {
      try {
        await fetch('api/carritos', {
          method: 'POST',
          headers: authHeaders(),
          body: JSON.stringify({ session_id: getSessionId(), items: state.cart, total: this.total() }),
        });
      } catch { /* silencioso */ }
    }, 1500);
  },
  add(producto) {
    const idx = state.cart.findIndex(i => i.id === producto.id);
    if (idx >= 0) {
      if (state.cart[idx].cantidad >= (producto.stock_actual ?? Infinity)) {
        showToast('No hay más unidades disponibles', 500);
        return;
      }
      state.cart[idx].cantidad++;
    } else {
      if ((producto.stock_actual ?? 1) < 1) {
        showToast('No hay más unidades disponibles', 500);
        return;
      }
      state.cart.push({ ...producto, cantidad: 1 });
    }
    this.save(); this.updateUI(); this.sync();
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
    this.save(); this.updateUI(); this.sync();
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
    grid.innerHTML = `<div class="empty"><div class="empty-icon"><i class="fa-solid fa-magnifying-glass" style="font-size:56px"></i></div><p>No hay productos para esta búsqueda</p></div>`;
    return;
  }

  // Update section subtitle
  const sub = document.getElementById('productCount');
  if (sub) sub.textContent = `${lista.length} producto${lista.length !== 1 ? 's' : ''}`;

  grid.innerHTML = lista.map(p => {
    const qty = cart.qty(p.id);
    const pJson = JSON.stringify(p).replace(/"/g,'&quot;');
    const controles = qty > 0
      ? `<div class="card-qty-row" onclick="event.stopPropagation()">
           <button class="card-qty-btn" onclick="cart.remove(${p.id});event.stopPropagation()">−</button>
           <span class="card-qty-label">${qty}</span>
           <button class="card-qty-btn" onclick="cart.add(${pJson});event.stopPropagation()">+</button>
         </div>`
      : `<button class="card-add-btn" onclick="cart.add(${pJson});event.stopPropagation()">Agregar al carrito</button>`;

    return `
      <div class="card ${!p.stock ? 'sin-stock' : ''}" onclick="openProductModal(${p.id})">
        ${!p.stock ? '<span class="stock-tag">Sin stock</span>' : ''}
        <div class="card-thumb"><img src="${p.imagen}" alt="${p.nombre}" loading="lazy" width="72" height="72"></div>
        <div class="card-body">
          <div class="card-name">${p.nombre}</div>
          <div class="card-price">$${p.precio.toLocaleString('es-AR')}</div>
          ${controles}
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
        <div class="ci-subtotal">$${(item.precio * item.cantidad).toLocaleString('es-AR')}</div>
      </div>
      <div class="ci-controls">
        <button class="ci-btn" onclick="cart.remove(${item.id})">−</button>
        <span class="ci-qty">${item.cantidad}</span>
        <button class="ci-btn" onclick="cart.add(${JSON.stringify(item).replace(/"/g,'&quot;')})">+</button>
      </div>
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
async function openCheckout() {
  if (!state.cart.length) {
    showToast('Agregá productos al carrito primero');
    return;
  }
  if (pedidoMinimo > 0 && cart.total() < pedidoMinimo) {
    showToast('El pedido mínimo es $' + pedidoMinimo.toLocaleString('es-AR'));
    return;
  }
  closeCart();
  document.getElementById('confirmScreen').classList.remove('show');

  const steps = ['checkoutStepEmail','checkoutStepOtp','checkoutStepExtraDatos','checkoutStepConfirmar'];
  steps.forEach(id => { document.getElementById(id).style.display = 'none'; });

  document.getElementById('checkoutModal').classList.add('open');
  document.body.style.overflow = 'hidden';

  if (getClienteId()) {
    await cargarDirecciones();
    const nombre = document.getElementById('fCliente').value.trim();
    if (nombre && direcciones.length) {
      populateConfirmStep();
      document.getElementById('checkoutStepConfirmar').style.display = '';
    } else {
      document.getElementById('checkoutStepExtraDatos').style.display = '';
      document.getElementById('fCliente').focus();
    }
  } else {
    document.getElementById('checkoutStepEmail').style.display = '';
  }
}

async function checkoutEmailContinuar() {
  const email = document.getElementById('fEmail').value.trim();
  if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
    showToast('Ingresá un correo electrónico válido'); return;
  }
  const btn = document.getElementById('btnCheckoutEmail');
  btn.disabled = true;
  btn.textContent = 'Verificando...';
  try {
    const res  = await fetch('api/auth_otp', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ accion: 'checkout_email', correo: email }),
    });
    const data = await res.json();
    if (!data.ok) { showToast(data.error || 'Error al procesar el correo'); return; }
    if (data.existe) {
      document.getElementById('checkoutOtpEmailLabel').textContent = email;
      document.getElementById('checkoutStepEmail').style.display   = 'none';
      document.getElementById('checkoutStepOtp').style.display     = '';
      clearOtpBoxes('fOtpBoxes');
    } else {
      setToken(data.token);
      showToast('Tu cuenta ha sido creada');
      document.getElementById('fCliente').value   = '';
      document.getElementById('fTelefono').value  = '';
      document.getElementById('fDireccion').value = '';
      document.getElementById('checkoutStepEmail').style.display      = 'none';
      document.getElementById('checkoutStepExtraDatos').style.display = '';
      document.getElementById('fCliente').focus();
    }
  } catch {
    showToast('Sin conexión. Verificá tu internet.');
  } finally {
    btn.disabled    = false;
    btn.textContent = 'Continuar';
  }
}

let checkoutSelectedDireccionId = null;

async function checkoutVerificarOtp() {
  const email  = document.getElementById('fEmail').value.trim();
  const codigo = getOtpValue('fOtpBoxes');
  if (codigo.length !== 6) { showToast('Ingresá el código de 6 dígitos'); return; }
  const btn = document.getElementById('btnCheckoutOtp');
  btn.disabled = true;
  btn.textContent = 'Verificando...';
  try {
    const res  = await fetch('api/auth_otp', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ accion: 'verificar', correo: email, codigo }),
    });
    const data = await res.json();
    if (!data.ok) {
      showToast(data.error || 'Código incorrecto');
      clearOtpBoxes('fOtpBoxes');
      return;
    }
    setToken(data.token);
    try {
      const cliRes  = await fetch('api/clientes', { headers: authHeaders() });
      const cliData = await cliRes.json();
      if (cliData.ok && cliData.data) {
        document.getElementById('fCliente').value   = cliData.data.nombre    || '';
        document.getElementById('fTelefono').value  = cliData.data.celular   || '';
      }
    } catch {}
    await cargarDirecciones();
    document.getElementById('checkoutStepOtp').style.display = 'none';
    const nombre = document.getElementById('fCliente').value.trim();
    if (nombre && direcciones.length) {
      populateConfirmStep();
      document.getElementById('checkoutStepConfirmar').style.display = '';
    } else {
      document.getElementById('checkoutStepExtraDatos').style.display = '';
    }
  } catch {
    showToast('Sin conexión. Verificá tu internet.');
  } finally {
    btn.disabled    = false;
    btn.textContent = 'Continuar';
  }
}

async function checkoutExtraDatosContinuar() {
  const nombre    = document.getElementById('fCliente').value.trim();
  const telefono  = document.getElementById('fTelefono').value.trim();
  const etiqueta  = document.getElementById('fEtiqueta').value.trim() || 'Casa';
  const direccion = document.getElementById('fDireccion').value.trim();
  const clienteId = getClienteId();
  if (!nombre) { showToast('Ingresá tu nombre y apellido'); return; }
  if (!/^[0-9]{10}$/.test(telefono)) {
    showToast('El celular debe tener exactamente 10 dígitos'); return;
  }
  if (!direccion) { showToast('Ingresá la dirección de entrega'); return; }

  // PATCH perfil (nombre + celular) y POST primera dirección en paralelo
  try {
    const [rPerfil, rDir] = await Promise.all([
      fetch('api/clientes', {
        method: 'PATCH',
        headers: authHeaders(),
        body: JSON.stringify({ id: clienteId, nombre, celular: telefono }),
      }),
      fetch('api/clientes_direcciones', {
        method: 'POST',
        headers: authHeaders(),
        body: JSON.stringify({ etiqueta, direccion }),
      }),
    ]);
    const dPerfil = await rPerfil.json();
    const dDir    = await rDir.json();
    if (!dPerfil.ok || !dDir.ok) {
      showToast((dPerfil.error || dDir.error) || 'Error al guardar tus datos');
      return;
    }
    await cargarDirecciones();
  } catch {
    showToast('Sin conexión');
    return;
  }

  document.getElementById('checkoutStepExtraDatos').style.display = 'none';
  populateConfirmStep();
  document.getElementById('checkoutStepConfirmar').style.display = '';
}

function backToCheckoutEmail() {
  document.getElementById('checkoutStepOtp').style.display   = 'none';
  document.getElementById('checkoutStepEmail').style.display = '';
}

async function resendCheckoutOtp() {
  const correo = document.getElementById('fEmail').value.trim();
  if (!correo) { showToast('No se encontró el correo'); return; }
  const btn = document.getElementById('btnReenviarCheckoutOtp');
  btn.disabled = true;
  btn.textContent = 'Enviando...';
  try {
    const res  = await fetch('api/auth_otp', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ accion: 'enviar', correo }),
    });
    const data = await res.json();
    if (data.ok) {
      clearOtpBoxes('fOtpBoxes');
      showToast('Código reenviado a tu correo');
    } else {
      showToast(data.error || 'No se pudo reenviar el código');
    }
  } catch {
    showToast('Sin conexión. Verificá tu internet.');
  } finally {
    btn.disabled = false;
    btn.textContent = 'Reenviar código';
  }
}

function populateConfirmStep() {
  const nombre    = document.getElementById('fCliente').value.trim();
  const email     = document.getElementById('fEmail').value.trim();
  const telefono  = document.getElementById('fTelefono').value.trim();

  const iniciales = nombre.split(' ').map(w => w[0]).filter(Boolean).slice(0, 2).join('').toUpperCase();
  document.getElementById('coAvatar').textContent   = iniciales || '?';
  document.getElementById('coNombre').textContent   = nombre;
  document.getElementById('coEmail').textContent    = email;
  document.getElementById('coTelefono').textContent = telefono;

  // Selector de dirección: principal por defecto
  if (!direcciones.find(d => d.id === checkoutSelectedDireccionId)) {
    const principal = direcciones.find(d => d.es_principal) || direcciones[0];
    checkoutSelectedDireccionId = principal ? principal.id : null;
  }
  renderCheckoutDireccionSelect();

  document.getElementById('coItemsList').innerHTML = state.cart.map(i =>
    `<div class="co-item">
      <span class="co-item-name">${i.nombre} <span class="co-item-qty">×${i.cantidad}</span></span>
      <span class="co-item-price">$${(i.precio * i.cantidad).toLocaleString('es-AR')}</span>
    </div>`).join('');
  document.getElementById('coTotal').textContent = '$' + cart.total().toLocaleString('es-AR');
}

function renderCheckoutDireccionSelect() {
  const cont = document.getElementById('coDireccionSelect');
  if (!cont) return;
  if (!direcciones.length) {
    cont.innerHTML = '<div class="co-dir-empty">No tenés direcciones guardadas</div>';
    return;
  }
  cont.innerHTML = direcciones.map(d => {
    const active  = d.id === checkoutSelectedDireccionId;
    const icono   = iconoEtiqueta(d.etiqueta);
    const lugar   = [d.localidad, d.provincia].filter(Boolean).join(', ');
    return `
      <label class="co-dir-option ${active ? 'co-dir-option--active' : ''}">
        <input type="radio" name="coDireccion" value="${d.id}" ${active ? 'checked' : ''} onchange="selectCheckoutDireccion(${d.id})">
        <span class="co-dir-icon">${icono}</span>
        <span class="co-dir-text">
          <span class="co-dir-etiqueta">${esc(d.etiqueta)}${d.es_principal ? ' <span class="co-dir-principal">· Principal</span>' : ''}</span>
          <span class="co-dir-dir">${esc(d.direccion || '')}</span>
          ${lugar ? `<span class="co-dir-lugar">${esc(lugar)}</span>` : ''}
        </span>
      </label>`;
  }).join('');
}

function selectCheckoutDireccion(id) {
  checkoutSelectedDireccionId = id;
  renderCheckoutDireccionSelect();
}


function closeCheckout() {
  document.getElementById('checkoutModal').classList.remove('open');
  document.body.style.overflow = '';
}

async function getCoords() {
  if (!navigator.geolocation) {
    showToast('Tu dispositivo no soporta geolocalización');
    return { lat: null, lng: null };
  }

  // Consultar estado del permiso; algunos browsers no lo soportan → tratar como 'prompt'.
  let permState = 'prompt';
  try {
    if (navigator.permissions && typeof navigator.permissions.query === 'function') {
      const p = await navigator.permissions.query({ name: 'geolocation' });
      permState = p.state;
    }
  } catch { /* no soportado: asumir prompt */ }

  if (permState === 'denied') {
    showToast('El permiso de ubicación está bloqueado en tu navegador');
    return { lat: null, lng: null };
  }

  if (permState !== 'granted') {
    const userAccepted = await new Promise((resolve) => {
      const geoOverlay = document.getElementById('geoOverlay');
      geoOverlay.classList.add('show');
      const btnPermit = document.getElementById('geoPermitir');
      const btnSkip   = document.getElementById('geoOmitir');
      const cleanup = () => {
        geoOverlay.classList.remove('show');
        btnPermit.onclick = null;
        btnSkip.onclick = null;
      };
      btnSkip.onclick   = () => { cleanup(); resolve(false); };
      btnPermit.onclick = () => { cleanup(); resolve(true); };
    });
    if (!userAccepted) return { lat: null, lng: null };
  }

  // Intento 1: alta precisión. Si falla o hay timeout, intento 2: baja precisión con más tiempo.
  const tryGetPosition = (highAccuracy, timeoutMs) => new Promise((resolve) => {
    navigator.geolocation.getCurrentPosition(
      (pos) => resolve({ lat: pos.coords.latitude, lng: pos.coords.longitude }),
      (err) => { console.warn('Geolocation error', err && err.code, err && err.message); resolve(null); },
      { enableHighAccuracy: highAccuracy, timeout: timeoutMs, maximumAge: 60000 }
    );
  });

  let coords = await tryGetPosition(true, 10000);
  if (!coords) coords = await tryGetPosition(false, 20000);
  if (!coords) {
    showToast('No se pudo obtener la ubicación');
    return { lat: null, lng: null };
  }
  return coords;
}

// Actualización silenciosa de la ubicación del cliente.
// Corre si el permiso de geolocalización está concedido; si está en 'prompt' o
// 'denied' no pedimos permisos, pero escuchamos cambios: si el usuario lo
// concede más tarde en la sesión (por ej. al detectar ubicación en perfil),
// sincronizamos en ese momento.
let _syncUbicEnCurso = false;
async function obtenerYEnviarUbicacion() {
  if (_syncUbicEnCurso || !getClienteId() || !navigator.geolocation) return;
  _syncUbicEnCurso = true;
  try {
    const coords = await new Promise((resolve) => {
      navigator.geolocation.getCurrentPosition(
        (pos) => resolve({ lat: pos.coords.latitude, lng: pos.coords.longitude }),
        ()    => resolve(null),
        { enableHighAccuracy: false, timeout: 10000, maximumAge: 60000 }
      );
    });
    if (!coords) return;

    state.clienteLat = coords.lat;
    state.clienteLng = coords.lng;

    try {
      await fetch('api/clientes', {
        method: 'POST',
        headers: { ...authHeaders(), 'Content-Type': 'application/json' },
        body: JSON.stringify({ lat: coords.lat, lng: coords.lng }),
      });
    } catch { /* silencioso */ }
  } finally {
    _syncUbicEnCurso = false;
  }
}

async function syncUbicacionSilenciosa() {
  if (!getClienteId() || !navigator.geolocation) return;
  if (!navigator.permissions || typeof navigator.permissions.query !== 'function') return;

  let status;
  try {
    status = await navigator.permissions.query({ name: 'geolocation' });
  } catch { return; }

  if (status.state === 'granted') obtenerYEnviarUbicacion();

  status.addEventListener('change', () => {
    if (status.state === 'granted') obtenerYEnviarUbicacion();
  });
}

let lastOrder = null;

function verUltimoPedido() {
  closeCheckout();
  const pedidosTab = document.querySelector('.nav-tab[onclick*="pedidos"]');
  selectTab('pedidos', pedidosTab);
  if (lastOrder) {
    setTimeout(() => openPedModal(lastOrder), 150);
  }
}

async function submitOrder() {
  const btn = document.getElementById('btnConfirmar');
  btn.disabled = true;
  btn.textContent = 'Enviando...';

  // Dirección elegida: si hay selección, la usamos; si no, la principal o la primera
  const dirElegida = direcciones.find(d => d.id === checkoutSelectedDireccionId)
                  || direcciones.find(d => d.es_principal)
                  || direcciones[0]
                  || null;

  const datos = {
    cliente:       document.getElementById('fCliente').value.trim(),
    correo:        document.getElementById('fEmail').value.trim(),
    celular:       document.getElementById('fTelefono').value.trim(),
    direccion:     dirElegida ? (dirElegida.direccion || '') : '',
    notas:         document.getElementById('fNotas') ? document.getElementById('fNotas').value.trim() : '',
    items:         state.cart,
    lat:           dirElegida && dirElegida.lat ? parseFloat(dirElegida.lat) : null,
    lng:           dirElegida && dirElegida.lng ? parseFloat(dirElegida.lng) : null,
    direccion_id:  dirElegida ? dirElegida.id : null,
    session_id:    getSessionId(),
  };

  try {
    const res = await enviarPedido(datos);
    if (res.ok) {
      if (res.token) setToken(res.token);
      lastOrder = { ...res.pedido, fecha: res.pedido.fecha || new Date().toISOString() };
      document.getElementById('checkoutStepConfirmar').style.display = 'none';
      document.getElementById('confirmNum').textContent = res.pedido.numero;
      document.getElementById('confirmScreen').classList.add('show');
      notificarWhatsApp(res.pedido, datos);
      notificarClienteWA(res.pedido, datos);
      cart.clear();
    } else {
      showToast(res.error || 'Error al enviar el pedido. Intentá de nuevo.');
    }
  } catch {
    showToast('Sin conexión. Verificá tu internet.');
  }

  btn.disabled = false;
  btn.textContent = 'Confirmar y enviar pedido';
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

  const clienteId = getClienteId();
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
  pendiente:   'Pendiente',
  preparacion: 'Preparación',
  asignacion:  'Asignación',
  reparto:     'Reparto',
  entregado:   'Entregado',
  cancelado:   'Cancelado',
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
  pendiente:   { label: 'Recibido',   color: '#f59e0b' },
  preparacion: { label: 'Preparación', color: '#8b5cf6' },
  asignacion:  { label: 'Asignación', color: '#3b82f6' },
  reparto:     { label: 'En reparto', color: '#06b6d4' },
  entregado:   { label: 'Entregado',  color: '#22c55e' },
  cancelado:   { label: 'Cancelado',  color: '#ef4444' },
};
const PED_PASOS = ['pendiente', 'preparacion', 'asignacion', 'reparto', 'entregado'];
const PED_PASO_LABELS = ['Recibido', 'Preparación', 'Asignación', 'En reparto', 'Entregado'];

let pedidoAbierto = null;

function openPedModal(p) {
  const est = PED_ESTADOS[p.estado] || { label: p.estado, color: '#64748b' };
  const fecha = new Date(p.fecha).toLocaleDateString('es-AR', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
  const pasoActual = PED_PASOS.indexOf(p.estado);

  pedidoAbierto = p;

  document.getElementById('pmNum').textContent = p.numero;
  document.getElementById('pmFecha').textContent = fecha;
  document.getElementById('pmEstado').innerHTML =
    `<span class="pm-badge" style="background:${est.color}22;color:${est.color}">${est.label}</span>`;

  const btnCancel = document.getElementById('pmBtnCancelar');
  if (btnCancel) {
    btnCancel.style.display = p.estado === 'pendiente' ? '' : 'none';
    btnCancel.disabled = false;
    btnCancel.innerHTML = '<i class="fa-solid fa-ban"></i> Cancelar pedido';
  }

  // Barra de progreso
  if (p.estado !== 'cancelado') {
    const entregado = p.estado === 'entregado';
    document.getElementById('pmSteps').innerHTML = PED_PASOS.map((paso, i) => {
      const done   = pasoActual > i || entregado;
      const active = !done && pasoActual === i;
      const cls    = done ? 'done' : active ? 'active' : 'pending';
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
  pedidoAbierto = null;
}

async function cancelarPedidoCliente() {
  if (!pedidoAbierto || pedidoAbierto.estado !== 'pendiente') return;
  if (!confirm(`¿Cancelar el pedido ${pedidoAbierto.numero}?`)) return;

  const btn = document.getElementById('pmBtnCancelar');
  if (btn) { btn.disabled = true; btn.textContent = 'Cancelando...'; }

  try {
    const res = await fetch('api/pedidos', {
      method: 'PATCH',
      headers: authHeaders(),
      body: JSON.stringify({ id: pedidoAbierto.id, accion: 'cancelar' }),
    });
    const data = await res.json();
    if (data.ok) {
      showToast('Pedido cancelado');
      closePedModal();
      cargarMisPedidos();
    } else {
      showToast(data.error || 'No se pudo cancelar');
      if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-ban"></i> Cancelar pedido'; }
    }
  } catch {
    showToast('Sin conexión');
    if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-ban"></i> Cancelar pedido'; }
  }
}

/* ===== Perfil ===== */
let perfilData = null;
let direcciones = [];
let dirGeoLat = null;
let dirGeoLng = null;

function iconoEtiqueta(etiqueta) {
  const e = (etiqueta || '').toLowerCase();
  if (e.includes('trabajo') || e.includes('oficina') || e.includes('lab')) return '💼';
  if (e.includes('casa') || e.includes('hogar') || e.includes('depto')) return '🏠';
  return '📍';
}

async function cargarDirecciones() {
  if (!getClienteId()) { direcciones = []; return; }
  try {
    const res = await fetch('api/clientes_direcciones', { headers: authHeaders() });
    const data = await res.json();
    if (data.ok) direcciones = data.data || [];
  } catch { /* silencioso */ }
}

async function cargarPerfil() {
  const el = document.getElementById('perfilContenido');
  const clienteId = getClienteId();

  if (!clienteId) {
    el.innerHTML = `
      <div class="perfil-empty">
        <div class="perfil-empty-icon"><i class="fa-solid fa-circle-user" style="font-size:56px"></i></div>
        <p>¡Bienvenido!</p>
        <small>Iniciá sesión para ver tu perfil y tus pedidos anteriores.</small>
        <button class="btn-checkout perfil-login-btn" onclick="openOtpModal()">Iniciar sesión / Crear cuenta</button>
      </div>`;
    return;
  }

  el.innerHTML = `<div class="spinner"><div class="spin"></div></div>`;

  try {
    const [res, resDir] = await Promise.all([
      fetch('api/clientes', { headers: authHeaders() }),
      fetch('api/clientes_direcciones', { headers: authHeaders() }),
    ]);
    const data    = await res.json();
    const dataDir = await resDir.json();
    if (data.ok && data.data) {
      perfilData = data.data;
      direcciones = (dataDir.ok && dataDir.data) ? dataDir.data : [];
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

  const listaDir = direcciones.length ? direcciones.map(d => {
    const icono = iconoEtiqueta(d.etiqueta);
    const principal = d.es_principal
      ? '<span class="dir-principal-badge">Principal</span>' : '';
    const setPrin = !d.es_principal
      ? `<button class="dir-btn-text" onclick="setDireccionPrincipal(${d.id})">Marcar principal</button>`
      : '';
    const lugar = [d.localidad, d.provincia].filter(Boolean).join(', ');
    return `
      <div class="dir-card ${d.es_principal ? 'dir-card--principal' : ''}">
        <div class="dir-card-head">
          <span class="dir-card-icon">${icono}</span>
          <span class="dir-card-etiqueta">${esc(d.etiqueta)}</span>
          ${principal}
        </div>
        <div class="dir-card-dir">${esc(d.direccion || 'Sin dirección')}</div>
        ${lugar ? `<div class="dir-card-lugar">${esc(lugar)}</div>` : ''}
        <div class="dir-card-actions">
          ${setPrin}
          <button class="dir-btn-text" onclick="openDireccionModal(${d.id})">Editar</button>
          <button class="dir-btn-text dir-btn-danger" onclick="eliminarDireccion(${d.id})">Eliminar</button>
        </div>
      </div>`;
  }).join('') : '<div class="dir-empty">Todavía no tenés direcciones guardadas</div>';

  el.innerHTML = `
    <div class="perfil-card">
      <div class="perfil-avatar">${iniciales}</div>
      <div class="perfil-info">
        <div class="perfil-nombre">${esc(cli.nombre || '—')}</div>
        <div class="perfil-fila"><span class="perfil-icono">✉️</span><span>${esc(cli.correo || 'Sin correo')}</span></div>
        <div class="perfil-fila"><span class="perfil-icono">📞</span><span>${esc(cli.celular || 'Sin teléfono')}</span></div>
      </div>

      <div class="perfil-section-title perfil-section-title--row">
        <span>Direcciones</span>
        <button class="dir-add-btn" onclick="openDireccionModal(null)">+ Agregar</button>
      </div>
      <div class="dir-lista">${listaDir}</div>

      <div class="perfil-section-title">Configuración</div>
      <div class="toggle-row">
        <span class="toggle-label"><i class="fa-solid fa-mobile-screen-button" style="color:var(--primary);margin-right:6px"></i> Notificaciones al celular</span>
        <label class="toggle-switch">
          <input type="checkbox" id="pushToggle" onchange="onTogglePush(event)">
          <span class="toggle-track"><span class="toggle-thumb"></span></span>
        </label>
      </div>
      <div id="pushStatus" style="display:none;font-size:.78rem;color:var(--text2);padding:0 4px 10px">—</div>

      <button class="perfil-btn-edit btn-ver-carrito" onclick="openPerfilModal()">Editar datos</button>
      <button class="btn-checkout perfil-btn-logout" onclick="cerrarSesion()">Cerrar sesión</button>
    </div>`;

  if (typeof sincronizarTogglePush === 'function') sincronizarTogglePush();
}

function openPerfilModal() {
  if (!perfilData) return;
  document.getElementById('pNombre').value    = perfilData.nombre    || '';
  document.getElementById('pCorreo').value    = perfilData.correo    || '';
  document.getElementById('pTelefono').value  = perfilData.celular   || '';
  document.getElementById('perfilModal').classList.add('open');
  document.body.style.overflow = 'hidden';
}

function closePerfilModal() {
  document.getElementById('perfilModal').classList.remove('open');
  document.body.style.overflow = '';
}

async function guardarPerfil() {
  const nombre    = document.getElementById('pNombre').value.trim();
  const correo    = document.getElementById('pCorreo').value.trim();
  const celular   = document.getElementById('pTelefono').value.trim();
  const clienteId = getClienteId();

  if (!nombre) { showToast('El nombre es requerido'); return; }
  if (celular && !/^[0-9]{10}$/.test(celular)) {
    showToast('El celular debe tener exactamente 10 dígitos'); return;
  }

  const btn = document.getElementById('btnGuardarPerfil');
  btn.disabled = true;
  btn.textContent = 'Guardando...';

  try {
    const res = await fetch('api/clientes', {
      method: 'PATCH',
      headers: authHeaders(),
      body: JSON.stringify({ id: clienteId, nombre, correo: correo || null, celular: celular || null }),
    });
    const data = await res.json();
    if (data.ok) {
      perfilData = { ...perfilData, nombre, correo, celular };
      renderPerfil(perfilData);
      closePerfilModal();
      showToast('Perfil actualizado');
      const fCli = document.getElementById('fCliente');
      const fTel = document.getElementById('fTelefono');
      if (fCli) fCli.value = nombre;
      if (fTel) fTel.value = celular;
    } else {
      showToast(data.error || 'Error al guardar');
    }
  } catch {
    showToast('Sin conexión');
  }

  btn.disabled = false;
  btn.textContent = 'Guardar cambios';
}

/* ===== Direcciones: modal de alta/edición + CRUD ===== */
function openDireccionModal(id) {
  const esNueva = !id;
  const dir = esNueva ? null : direcciones.find(d => d.id === id);

  document.getElementById('direccionModalTitle').textContent = esNueva ? 'Nueva dirección' : 'Editar dirección';
  document.getElementById('dirId').value        = esNueva ? '' : id;
  document.getElementById('dirEtiqueta').value  = dir ? (dir.etiqueta || 'Casa') : 'Casa';
  document.getElementById('dirDireccion').value = dir ? (dir.direccion || '') : '';

  dirGeoLat = dir && dir.lat ? parseFloat(dir.lat) : null;
  dirGeoLng = dir && dir.lng ? parseFloat(dir.lng) : null;
  actualizarEstadoGeoDir(dirGeoLat, dirGeoLng);

  // Checkbox "principal" solo si ya hay otras direcciones (y esta no es la única)
  const wrap = document.getElementById('dirPrincipalWrap');
  const chk  = document.getElementById('dirPrincipal');
  const otras = direcciones.filter(d => d.id !== id);
  if (otras.length > 0) {
    wrap.style.display = '';
    chk.checked = dir ? !!dir.es_principal : false;
  } else {
    wrap.style.display = 'none';
    chk.checked = false;
  }

  document.getElementById('direccionModal').classList.add('open');
  document.body.style.overflow = 'hidden';
}

function closeDireccionModal() {
  document.getElementById('direccionModal').classList.remove('open');
  document.body.style.overflow = '';
}

function actualizarEstadoGeoDir(lat, lng) {
  const icon  = document.getElementById('dirGeoIcon');
  const label = document.getElementById('dirGeoLabel');
  const btn   = document.getElementById('dirGeoBtn');
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

async function detectarUbicacionDireccion() {
  const btn = document.getElementById('dirGeoBtn');
  btn.disabled = true;
  const prev = btn.textContent;
  btn.textContent = 'Detectando...';
  const coords = await getCoords();
  if (coords.lat && coords.lng) {
    dirGeoLat = coords.lat;
    dirGeoLng = coords.lng;
    // Aprovechar para actualizar también lat/lng del cliente
    state.clienteLat = coords.lat;
    state.clienteLng = coords.lng;
    try {
      await fetch('api/clientes', {
        method: 'POST',
        headers: { ...authHeaders(), 'Content-Type': 'application/json' },
        body: JSON.stringify({ lat: coords.lat, lng: coords.lng }),
      });
    } catch { /* silencioso */ }
  }
  actualizarEstadoGeoDir(dirGeoLat, dirGeoLng);
  btn.disabled = false;
  if (!coords.lat) btn.textContent = prev;
}

async function guardarDireccion() {
  const id        = document.getElementById('dirId').value.trim();
  const etiqueta  = document.getElementById('dirEtiqueta').value.trim() || 'Casa';
  const direccion = document.getElementById('dirDireccion').value.trim();
  const principal = document.getElementById('dirPrincipal').checked;

  if (!direccion) { showToast('Ingresá la dirección'); return; }

  const btn = document.getElementById('btnGuardarDireccion');
  btn.disabled = true;
  btn.textContent = 'Guardando...';

  const payload = {
    etiqueta,
    direccion,
    lat: dirGeoLat,
    lng: dirGeoLng,
    es_principal: principal ? 1 : 0,
  };

  try {
    let res;
    if (id) {
      res = await fetch('api/clientes_direcciones', {
        method: 'PATCH',
        headers: authHeaders(),
        body: JSON.stringify({ id: parseInt(id, 10), ...payload }),
      });
    } else {
      res = await fetch('api/clientes_direcciones', {
        method: 'POST',
        headers: authHeaders(),
        body: JSON.stringify(payload),
      });
    }
    const data = await res.json();
    if (data.ok) {
      const newId = data.id || null;
      await cargarDirecciones();
      if (perfilData) renderPerfil(perfilData);
      // Si el checkout está abierto, refrescar su selector y seleccionar la recién creada
      if (document.getElementById('checkoutModal').classList.contains('open')) {
        if (newId) checkoutSelectedDireccionId = newId;
        renderCheckoutDireccionSelect();
      }
      closeDireccionModal();
      // Restaurar el scroll-lock del checkout si sigue abierto
      if (document.getElementById('checkoutModal').classList.contains('open')) {
        document.body.style.overflow = 'hidden';
      }
      showToast(id ? 'Dirección actualizada' : 'Dirección agregada');
    } else {
      showToast(data.error || 'Error al guardar');
    }
  } catch {
    showToast('Sin conexión');
  }

  btn.disabled = false;
  btn.textContent = 'Guardar';
}

async function eliminarDireccion(id) {
  if (!confirm('¿Eliminar esta dirección?')) return;
  try {
    const res = await fetch(`api/clientes_direcciones?id=${id}`, {
      method: 'DELETE',
      headers: authHeaders(),
    });
    const data = await res.json();
    if (data.ok) {
      await cargarDirecciones();
      if (perfilData) renderPerfil(perfilData);
      showToast('Dirección eliminada');
    } else {
      showToast(data.error || 'No se pudo eliminar');
    }
  } catch {
    showToast('Sin conexión');
  }
}

async function setDireccionPrincipal(id) {
  try {
    const res = await fetch('api/clientes_direcciones', {
      method: 'PATCH',
      headers: authHeaders(),
      body: JSON.stringify({ id, es_principal: 1 }),
    });
    const data = await res.json();
    if (data.ok) {
      await cargarDirecciones();
      if (perfilData) renderPerfil(perfilData);
      showToast('Dirección principal actualizada');
    } else {
      showToast(data.error || 'No se pudo actualizar');
    }
  } catch {
    showToast('Sin conexión');
  }
}

/* ===== HTML escape utility ===== */
function esc(s) {
  return String(s == null ? '' : s)
    .replace(/&/g, '&amp;').replace(/</g, '&lt;')
    .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

/* ===== Toast ===== */
let toastTimer;
function showToast(msg, duration = 3000) {
  const el = document.getElementById('toast');
  if (!el) return;
  el.textContent = msg;
  el.classList.add('show');
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => el.classList.remove('show'), duration);
}

/* ===== OTP boxes helpers ===== */
function initOtpBoxes(containerId) {
  const container = document.getElementById(containerId);
  if (!container) return;
  const boxes = Array.from(container.querySelectorAll('.otp-box'));
  boxes.forEach((box, idx) => {
    box.addEventListener('input', () => {
      box.value = box.value.replace(/\D/g, '').slice(-1);
      if (box.value && idx < boxes.length - 1) boxes[idx + 1].focus();
    });
    box.addEventListener('keydown', (e) => {
      if (e.key === 'Backspace' && !box.value && idx > 0) {
        boxes[idx - 1].focus();
        boxes[idx - 1].value = '';
        e.preventDefault();
      } else if (e.key === 'ArrowLeft' && idx > 0) {
        boxes[idx - 1].focus(); e.preventDefault();
      } else if (e.key === 'ArrowRight' && idx < boxes.length - 1) {
        boxes[idx + 1].focus(); e.preventDefault();
      }
    });
    box.addEventListener('paste', (e) => {
      const pasted = (e.clipboardData || window.clipboardData).getData('text').replace(/\D/g, '').slice(0, boxes.length);
      if (!pasted) return;
      e.preventDefault();
      boxes.forEach((b, i) => { b.value = pasted[i] || ''; });
      const nextEmpty = boxes.findIndex(b => !b.value);
      (nextEmpty === -1 ? boxes[boxes.length - 1] : boxes[nextEmpty]).focus();
    });
    box.addEventListener('focus', () => setTimeout(() => box.select(), 0));
  });
}

function getOtpValue(containerId) {
  const container = document.getElementById(containerId);
  if (!container) return '';
  return Array.from(container.querySelectorAll('.otp-box')).map(b => b.value).join('');
}

function clearOtpBoxes(containerId) {
  const container = document.getElementById(containerId);
  if (!container) return;
  const boxes = container.querySelectorAll('.otp-box');
  boxes.forEach(b => { b.value = ''; });
  if (boxes[0]) boxes[0].focus();
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
  document.getElementById('pdPrice').textContent = '$' + p.precio.toLocaleString('es-AR');

  const stockEl = document.getElementById('pdStock');
  if (!p.stock) {
    stockEl.textContent = 'Sin stock';
    stockEl.className = 'product-detail-stock out-stock';
  } else {
    stockEl.textContent = '';
    stockEl.className = '';
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
      <button class="pd-qty-btn" onclick="addFromDetail()">+</button>
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

/* ===== OTP Login ===== */
function openOtpModal() {
  document.getElementById('otpStep1').style.display = '';
  document.getElementById('otpStep2').style.display = 'none';
  document.getElementById('otpStep3').style.display = 'none';
  document.getElementById('otpEmail').value = '';
  clearOtpBoxes('otpBoxes');
  document.getElementById('otpModal').classList.add('open');
  document.body.style.overflow = 'hidden';
  setTimeout(() => document.getElementById('otpEmail').focus(), 300);
}

function closeOtpModal() {
  document.getElementById('otpModal').classList.remove('open');
  document.body.style.overflow = '';
}

function backOtpStep() {
  document.getElementById('otpStep1').style.display = '';
  document.getElementById('otpStep2').style.display = 'none';
  document.getElementById('otpStep3').style.display = 'none';
}

async function resendOtp() {
  const correo = document.getElementById('otpEmail').value.trim();
  if (!correo) { showToast('No se encontró el correo'); return; }
  const btn = document.getElementById('btnReenviarOtp');
  btn.disabled = true;
  btn.textContent = 'Enviando...';
  try {
    const res  = await fetch('api/auth_otp', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ accion: 'enviar', correo }),
    });
    const data = await res.json();
    if (data.ok) {
      clearOtpBoxes('otpBoxes');
      showToast('Código reenviado a tu correo');
    } else {
      showToast(data.error || 'No se pudo reenviar el código');
    }
  } catch {
    showToast('Sin conexión');
  } finally {
    btn.disabled = false;
    btn.textContent = 'Reenviar código';
  }
}

async function sendOtp() {
  const correo = document.getElementById('otpEmail').value.trim();
  if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(correo)) {
    showToast('Ingresá un correo válido');
    return;
  }
  const btn = document.getElementById('btnEnviarOtp');
  btn.disabled = true;
  btn.textContent = 'Procesando...';
  try {
    const res  = await fetch('api/auth_otp', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ accion: 'checkout_email', correo }),
    });
    const data = await res.json();
    if (!data.ok) {
      showToast(data.error || 'No se pudo procesar el correo');
      return;
    }
    if (data.existe) {
      document.getElementById('otpEmailLabel').textContent = correo;
      document.getElementById('otpStep1').style.display = 'none';
      document.getElementById('otpStep2').style.display = '';
      setTimeout(() => clearOtpBoxes('otpBoxes'), 300);
    } else {
      setToken(data.token);
      showToast('Tu cuenta ha sido creada');
      document.getElementById('otpNombre').value    = '';
      document.getElementById('otpTelefono').value  = '';
      document.getElementById('otpDireccion').value = '';
      document.getElementById('otpStep1').style.display = 'none';
      document.getElementById('otpStep3').style.display = '';
      setTimeout(() => document.getElementById('otpNombre').focus(), 300);
    }
  } catch {
    showToast('Sin conexión');
  } finally {
    btn.disabled = false;
    btn.textContent = 'Continuar';
  }
}

async function otpExtraDatosContinuar() {
  const nombre    = document.getElementById('otpNombre').value.trim();
  const celular   = document.getElementById('otpTelefono').value.trim();
  const direccion = document.getElementById('otpDireccion').value.trim();
  const correo    = document.getElementById('otpEmail').value.trim();
  const clienteId = getClienteId();
  if (!clienteId) { showToast('Sesión inválida. Volvé a ingresar tu correo.'); return; }
  if (!nombre)    { showToast('Ingresá tu nombre y apellido'); return; }
  if (!/^[0-9]{10}$/.test(celular)) {
    showToast('El celular debe tener exactamente 10 dígitos');
    return;
  }
  if (!direccion) { showToast('Ingresá la dirección de entrega'); return; }

  const btn = document.getElementById('btnOtpExtraDatos');
  btn.disabled = true;
  btn.textContent = 'Creando cuenta...';
  try {
    const res = await fetch('api/clientes', {
      method: 'PATCH',
      headers: authHeaders(),
      body: JSON.stringify({
        id: clienteId,
        nombre,
        correo: correo || null,
        celular: celular || null,
        direccion: direccion || null,
      }),
    });
    const data = await res.json();
    if (!data.ok) {
      showToast(data.error || 'No se pudo crear la cuenta');
      return;
    }
    closeOtpModal();
    cargarPerfil();
    const fCli = document.getElementById('fCliente');
    const fEm  = document.getElementById('fEmail');
    const fTel = document.getElementById('fTelefono');
    const fDir = document.getElementById('fDireccion');
    if (fCli) fCli.value = nombre;
    if (fEm)  fEm.value  = correo;
    if (fTel) fTel.value = celular;
    if (fDir) fDir.value = direccion;
    showToast('¡Cuenta creada! Bienvenido.');
  } catch {
    showToast('Sin conexión');
  } finally {
    btn.disabled = false;
    btn.textContent = 'Crear cuenta';
  }
}

async function verifyOtp() {
  const correo = document.getElementById('otpEmail').value.trim();
  const codigo = getOtpValue('otpBoxes');
  if (codigo.length !== 6) { showToast('Ingresá el código de 6 dígitos'); return; }

  const btn = document.getElementById('btnVerificarOtp');
  btn.disabled = true;
  btn.textContent = 'Verificando...';
  try {
    const res  = await fetch('api/auth_otp', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ accion: 'verificar', correo, codigo }),
    });
    const data = await res.json();
    if (data.ok) {
      setToken(data.token);
      closeOtpModal();
      cargarPerfil();
      // Pre-fill formulario de checkout
      try {
        const cliRes  = await fetch('api/clientes', { headers: authHeaders() });
        const cliData = await cliRes.json();
        if (cliData.ok && cliData.data) {
          document.getElementById('fCliente').value   = cliData.data.nombre    || '';
          document.getElementById('fEmail').value     = cliData.data.correo    || '';
          document.getElementById('fTelefono').value  = cliData.data.celular   || '';
          document.getElementById('fDireccion').value = cliData.data.direccion || '';
          state.clienteLat = cliData.data.lat ? parseFloat(cliData.data.lat) : null;
          state.clienteLng = cliData.data.lng ? parseFloat(cliData.data.lng) : null;
        }
      } catch {}
      showToast(data.nuevo ? '¡Cuenta creada! Bienvenido.' : '¡Sesión iniciada!');
    } else {
      showToast(data.error || 'Código incorrecto');
      clearOtpBoxes('otpBoxes');
    }
  } catch {
    showToast('Sin conexión');
  } finally {
    btn.disabled = false;
    btn.textContent = 'Ingresar';
  }
}

/* ===== Init ===== */
document.addEventListener('DOMContentLoaded', async () => {
  tema.init();
  cart.load();
  initOtpBoxes('fOtpBoxes');
  initOtpBoxes('otpBoxes');

  // Auto-fill datos del cliente desde JWT
  if (getClienteId()) {
    let sesionValida = true;
    try {
      var cliRes = await fetch('api/clientes', { headers: authHeaders() });
      var cliData = await cliRes.json();
      if (cliData.ok && cliData.data) {
        document.getElementById('fCliente').value = cliData.data.nombre || '';
        document.getElementById('fEmail').value = cliData.data.correo || '';
        document.getElementById('fTelefono').value = cliData.data.celular || '';
        document.getElementById('fDireccion').value = cliData.data.direccion || '';
        state.clienteLat = cliData.data.lat ? parseFloat(cliData.data.lat) : null;
        state.clienteLng = cliData.data.lng ? parseFloat(cliData.data.lng) : null;
      } else {
        // El usuario del JWT ya no existe en el servidor → cerrar sesión
        removeToken();
        sesionValida = false;
      }
    } catch (e) { /* silencioso: problema de red, conservamos la sesión */ }
    // Sincronizar carrito local con el servidor al iniciar sesión
    if (sesionValida && state.cart.length) cart.sync();
    // Actualización silenciosa de ubicación si el permiso ya está concedido
    if (sesionValida) syncUbicacionSilenciosa();
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

  // Abrir carrito si viene por URL (/carrito o ?carrito=1)
  if (window.location.pathname.endsWith('/carrito') || urlParams.get('carrito') === '1') {
    openCart();
  }

  // Search
  const inp = document.getElementById('searchInput');
  const clearBtn = document.getElementById('searchClear');
  if (inp) {
    inp.addEventListener('input', e => {
      onSearch(e.target.value);
      if (clearBtn) clearBtn.style.display = e.target.value ? '' : 'none';
    });
  }
  if (clearBtn) {
    clearBtn.addEventListener('click', () => {
      inp.value = '';
      clearBtn.style.display = 'none';
      inp.focus();
      onSearch('');
    });
  }

  // Hide/show search+categories bar on scroll
  const stickyBars   = document.getElementById('stickyBars');
  const inicioSection = document.getElementById('inicioSection');
  function syncBarsOffset() {
    if (stickyBars && inicioSection) {
      inicioSection.style.paddingTop = (stickyBars.offsetHeight + parseInt(getComputedStyle(document.documentElement).getPropertyValue('--header-h') || 56)) + 'px';
    }
  }
  syncBarsOffset();
  window.addEventListener('resize', syncBarsOffset, { passive: true });
  if (stickyBars) {
    let lastScrollY = 0;
    let ticking = false;
    window.addEventListener('scroll', () => {
      if (!ticking) {
        requestAnimationFrame(() => {
          const y = window.scrollY;
          if (y > lastScrollY && y > stickyBars.offsetHeight) {
            stickyBars.classList.add('bars-hidden');
          } else if (y < lastScrollY) {
            stickyBars.classList.remove('bars-hidden');
          }
          lastScrollY = y;
          ticking = false;
        });
        ticking = true;
      }
    }, { passive: true });
  }

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
