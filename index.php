<!DOCTYPE html>
<html lang="es" data-theme="light">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover, user-scalable=no">
  <meta name="theme-color" content="#ff6b35" media="(prefers-color-scheme: light)">
  <meta name="theme-color" content="#1c1c1f"  media="(prefers-color-scheme: dark)">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="default">
  <meta name="description" content="Pedidos de comestibles rápido y fácil">
  <title>Repo Online</title>
  <link rel="manifest" href="manifest.json">
  <link rel="stylesheet" href="assets/css/app.css?v=<?= time() ?>">
</head>
<body>

<!-- ===== Header ===== -->
<header class="header">
  <div class="header-logo">
    <img src="https://cdn.jsdelivr.net/npm/openmoji@15.0.0/color/svg/1F6D2.svg" alt="carrito" width="24" height="24">
    Repo Online
  </div>
  <button class="btn-icon" id="btnTema" onclick="tema.toggle()" title="Cambiar tema">
    <img src="https://cdn.jsdelivr.net/npm/openmoji@15.0.0/color/svg/1F319.svg" alt="noche" width="22" height="22">
  </button>
  <button class="btn-icon" onclick="openCart()" title="Ver carrito">
    <img src="https://cdn.jsdelivr.net/npm/openmoji@15.0.0/color/svg/1F6D2.svg" alt="carrito" width="22" height="22">
    <span class="badge" id="cartBadge">0</span>
  </button>
</header>

<!-- ===== Inicio section ===== -->
<div id="inicioSection">

  <!-- Search -->
  <div class="search-wrap">
    <div class="search-box">
      <span class="search-icon">
        <img src="https://cdn.jsdelivr.net/npm/openmoji@15.0.0/color/svg/1F50D.svg" alt="buscar" width="18" height="18">
      </span>
      <input type="search" id="searchInput" placeholder="Buscar productos..." autocomplete="off" inputmode="search">
    </div>
  </div>

  <!-- Categories -->
  <div class="cats-wrap">
    <div class="cats" id="catsContainer"></div>
  </div>

  <!-- Section title -->
  <div class="section-title">
    Productos <small id="productCount"></small>
  </div>

  <!-- Products grid -->
  <main class="products" id="productsGrid">
    <div class="spinner"><div class="spin"></div></div>
  </main>

</div><!-- /inicioSection -->

<!-- ===== Pedidos section ===== -->
<div id="pedidosSection" style="display:none">
  <div class="section-title">Mis pedidos</div>
  <div id="pedidosList">
    <div class="spinner"><div class="spin"></div></div>
  </div>
</div>

<!-- ===== Perfil section ===== -->
<div id="perfilSection" style="display:none">
  <div class="section-title">Mi perfil</div>
  <div id="perfilContenido">
    <div class="spinner"><div class="spin"></div></div>
  </div>
</div>

<!-- ===== Product Detail Modal ===== -->
<div class="modal-wrap" id="productModal">
  <div class="modal-backdrop" onclick="closeProductModal()"></div>
  <div class="modal product-modal">
    <div class="modal-handle"></div>
    <button class="btn-close product-modal-close" onclick="closeProductModal()">✕</button>
    <div class="product-detail-img">
      <img id="pdImg" src="" alt="" width="160" height="160">
    </div>
    <div class="product-detail-body">
      <div class="product-detail-name" id="pdName"></div>
      <div class="product-detail-unit" id="pdUnit"></div>
      <div class="product-detail-price" id="pdPrice"></div>
      <div class="product-detail-stock" id="pdStock"></div>
      <div class="product-detail-actions" id="pdActions"></div>
    </div>
  </div>
</div>

<!-- ===== Toast ===== -->
<div class="toast" id="toast"></div>

<!-- ===== Overlay ===== -->
<div class="overlay" id="overlay" onclick="closeCart()"></div>

<!-- ===== Cart Drawer ===== -->
<div class="cart-drawer" id="cartDrawer">
  <div class="drawer-handle"></div>
  <div class="drawer-header">
    <div class="drawer-title">
      <img src="https://cdn.jsdelivr.net/npm/openmoji@15.0.0/color/svg/1F6D2.svg" alt="carrito" width="22" height="22">
      Mi carrito
    </div>
    <button class="btn-close" onclick="closeCart()">✕</button>
  </div>
  <div class="cart-items" id="cartItemsList">
    <div class="cart-empty">
      <span class="empty-icon"><img src="https://cdn.jsdelivr.net/npm/openmoji@15.0.0/color/svg/1F6D2.svg" alt="carrito" width="48" height="48"></span>
      <p>Tu carrito está vacío</p>
    </div>
  </div>
  <div class="cart-footer" id="cartFooter" style="display:none">
    <div class="cart-total-row">
      <span class="total-label">Total</span>
      <span class="total-amount">$<span id="cartTotal">0</span></span>
    </div>
    <button class="btn-checkout" onclick="openCheckout()">
      Finalizar Pedido
      <img src="https://cdn.jsdelivr.net/npm/openmoji@15.0.0/color/svg/1F4E6.svg" alt="pedido" width="20" height="20">
    </button>
  </div>
</div>

<!-- ===== Checkout Modal ===== -->
<div class="modal-wrap" id="checkoutModal">
  <div class="modal-backdrop" onclick="closeCheckout()"></div>
  <div class="modal">
    <div class="modal-handle"></div>
    <div class="modal-title">Completar pedido</div>

    <!-- Confirmación -->
    <div class="confirm-screen" id="confirmScreen">
      <div class="confirm-icon">
        <img src="https://cdn.jsdelivr.net/npm/openmoji@15.0.0/color/svg/2705.svg" alt="confirmado" width="72" height="72">
      </div>
      <div class="confirm-title">¡Pedido recibido!</div>
      <div class="confirm-num" id="confirmNum">PED-XXXXXX</div>
      <div class="confirm-sub">Te contactaremos para coordinar la entrega.</div>
      <button class="btn-nuevo" onclick="closeCheckout()">Seguir comprando</button>
    </div>

    <!-- Formulario -->
    <form id="checkoutForm" onsubmit="handleCheckout(event)">
      <!-- Resumen -->
      <div class="order-summary">
        <h3>Resumen del pedido</h3>
        <div id="orderSummaryLines"></div>
        <div class="summary-total">
          <span>Total</span>
          <span>$<span id="summaryTotal">0</span></span>
        </div>
      </div>

      <!-- Datos -->
      <div class="form-group">
        <label>Tu nombre *</label>
        <input type="text" id="fCliente" placeholder="Ej: María González" required autocomplete="name">
      </div>
      <div class="form-group">
        <label>Teléfono *</label>
        <input type="tel" id="fTelefono" placeholder="Ej: 11 2345-6789" required autocomplete="tel">
      </div>
      <div class="form-group">
        <label>Dirección de entrega *</label>
        <input type="text" id="fDireccion" placeholder="Calle, número, piso/depto" required autocomplete="street-address">
      </div>
      <div class="form-group">
        <label>Notas adicionales</label>
        <textarea id="fNotas" placeholder="Indicaciones especiales, horario preferido..."></textarea>
      </div>

      <button type="submit" class="btn-checkout" id="btnConfirmar">
        Confirmar pedido ✓
      </button>
    </form>
  </div>
</div>

<!-- ===== Modal Editar Perfil ===== -->
<div class="modal-wrap" id="perfilModal">
  <div class="modal-backdrop" onclick="closePerfilModal()"></div>
  <div class="modal">
    <div class="modal-handle"></div>
    <div class="modal-title">Editar perfil</div>
    <div class="form-group">
      <label>Nombre *</label>
      <input type="text" id="pNombre" placeholder="Tu nombre completo" autocomplete="name">
    </div>
    <div class="form-group">
      <label>Correo electrónico</label>
      <input type="email" id="pCorreo" placeholder="email@ejemplo.com" autocomplete="email">
    </div>
    <div class="form-group">
      <label>Teléfono</label>
      <input type="tel" id="pTelefono" placeholder="Ej: 11 2345-6789" autocomplete="tel">
    </div>
    <div class="form-group">
      <label>Dirección de entrega</label>
      <input type="text" id="pDireccion" placeholder="Calle, número, piso/depto" autocomplete="street-address">
    </div>
    <div class="form-group">
      <label>Ubicación GPS</label>
      <div class="perfil-geo-row">
        <div class="perfil-geo-status" id="pGeoStatus">
          <span id="pGeoIcon">⚪</span>
          <span id="pGeoLabel">Sin ubicación detectada</span>
        </div>
        <button type="button" class="perfil-geo-btn" id="pGeoBtn" onclick="detectarUbicacionPerfil()">
          Detectar
        </button>
      </div>
    </div>
    <button class="btn-checkout" id="btnGuardarPerfil" onclick="guardarPerfil()">
      Guardar cambios
    </button>
  </div>
</div>

<!-- ===== Geolocation prompt ===== -->
<div class="geo-overlay" id="geoOverlay">
  <div class="geo-card">
    <div class="geo-icon">📍</div>
    <div class="geo-title">¿Permitís acceder a tu ubicación?</div>
    <div class="geo-desc">Usamos tu ubicación para hacer una entrega más rápida y sin errores. No almacenamos tu posición para ningún otro fin.</div>
    <div class="geo-buttons">
      <button class="geo-btn geo-btn-primary" id="geoPermitir">Permitir ubicación</button>
      <button class="geo-btn geo-btn-secondary" id="geoOmitir">Omitir</button>
    </div>
  </div>
</div>

<!-- ===== Bottom nav ===== -->
<nav class="bottom-nav">
  <button class="nav-tab active" onclick="selectTab('inicio', this)">
    <span class="nav-icon"><img src="https://cdn.jsdelivr.net/npm/openmoji@15.0.0/color/svg/1F3E0.svg" alt="inicio" width="24" height="24"></span>
    Inicio
  </button>
  <button class="nav-tab" onclick="openCart()">
    <span class="nav-icon"><img src="https://cdn.jsdelivr.net/npm/openmoji@15.0.0/color/svg/1F6D2.svg" alt="carrito" width="24" height="24"></span>
    Carrito
  </button>
  <button class="nav-tab" onclick="selectTab('pedidos', this)">
    <span class="nav-icon"><img src="https://cdn.jsdelivr.net/npm/openmoji@15.0.0/color/svg/1F4CB.svg" alt="pedidos" width="24" height="24"></span>
    Pedidos
  </button>
  <button class="nav-tab" onclick="selectTab('perfil', this)">
    <span class="nav-icon"><img src="https://cdn.jsdelivr.net/npm/openmoji@15.0.0/color/svg/1F464.svg" alt="perfil" width="24" height="24"></span>
    Perfil
  </button>
</nav>

<script src="assets/js/app.js?v=<?= time() ?>"></script>
</body>
</html>
