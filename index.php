<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover, user-scalable=no">
  <meta name="theme-color" content="#ffffff" id="metaThemeColor">
  <script>
    (function(){
      var t = localStorage.getItem('tema') || 'light';
      document.documentElement.setAttribute('data-theme', t);
      document.getElementById('metaThemeColor').setAttribute('content', t === 'dark' ? '#3d4248' : '#ffffff');
    })();
  </script>
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="default">
  <meta name="description" content="Pedidos de comestibles rápido y fácil">
  <title>Repo Super Online</title>
  <link rel="icon" type="image/x-icon" href="favicon.ico">
  <link rel="icon" type="image/png" sizes="16x16" href="favicon/favicon-16x16.png">
  <link rel="icon" type="image/png" sizes="32x32" href="favicon/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="96x96" href="favicon/favicon-96x96.png">
  <link rel="apple-touch-icon" sizes="512x512" href="assets/img/splash.png">
  <link rel="manifest" href="manifest.php">
  <link rel="stylesheet" href="assets/css/app.css?v=<?= time() ?>">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
</head>
<body>

<!-- ===== Header ===== -->
<header class="header">
  <div class="header-logo">
    <img class="logo-light" src="assets/img/repo_logo_black.png" alt="Repo Online" style="height:30px; width:auto;">
    <img class="logo-dark"  src="assets/img/repo_logo_withe.png" alt="Repo Online" style="height:30px; width:auto;">
  </div>
  <button class="btn-icon" id="btnTema" onclick="tema.toggle()" title="Cambiar tema">
    <i class="fa-solid fa-moon" style="font-size:18px"></i>
  </button>
  <button class="btn-icon" onclick="openCart()" title="Ver carrito">
    <i class="fa-solid fa-cart-shopping" style="font-size:18px"></i>
    <span class="badge" id="cartBadge">0</span>
  </button>
</header>

<!-- ===== Inicio section ===== -->
<div id="inicioSection">

  <!-- Search -->
  <div class="search-wrap">
    <div class="search-box">
      <span class="search-icon">
        <i class="fa-solid fa-magnifying-glass" style="font-size:16px"></i>
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
      Mi carrito
    </div>
    <button class="btn-close" onclick="closeCart()">✕</button>
  </div>
  <div class="cart-items" id="cartItemsList">
    <div class="cart-empty">
      <span class="empty-icon"><i class="fa-solid fa-cart-shopping" style="font-size:48px"></i></span>
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
    </button>
  </div>
</div>

<!-- ===== Checkout Modal ===== -->
<div class="modal-wrap" id="checkoutModal">
  <div class="modal-backdrop" onclick="closeCheckout()"></div>
  <div class="modal">
    <div class="modal-handle"></div>
    <button class="btn-close product-modal-close" onclick="closeCheckout()">✕</button>

    <!-- Paso 1a: Correo (solo sin sesión) -->
    <div id="checkoutStepEmail" style="display:none">
      <div class="modal-title">Ingresá tu correo</div>
      <p class="otp-sub">Para confirmar tu pedido necesitamos tu correo electrónico.</p>
      <div class="form-group">
        <label>Correo electrónico *</label>
        <input type="email" id="fEmail" placeholder="Ej: maria@gmail.com" autocomplete="email" inputmode="email">
      </div>
      <button class="btn-checkout" id="btnCheckoutEmail" onclick="checkoutEmailContinuar()">Continuar</button>
    </div>

    <!-- Paso 1b: Código OTP (correo existente) -->
    <div id="checkoutStepOtp" style="display:none">
      <div class="modal-title">Verificá tu identidad</div>
      <p class="otp-sub">Enviamos un código de 6 dígitos a <strong id="checkoutOtpEmailLabel"></strong></p>
      <div class="form-group">
        <label>Código de verificación *</label>
        <input type="text" id="fOtpCodigo" placeholder="000000" inputmode="numeric" maxlength="6" autocomplete="one-time-code">
      </div>
      <button class="btn-checkout" id="btnCheckoutOtp" onclick="checkoutVerificarOtp()">Continuar</button>
      <button class="otp-back-btn" onclick="backToCheckoutEmail()">← Cambiar correo</button>
    </div>

    <!-- Paso 1c: Datos extra (cuenta nueva o datos incompletos) -->
    <div id="checkoutStepExtraDatos" style="display:none">
      <div class="modal-title">Completá tus datos</div>
      <div class="form-group">
        <label>Nombre y apellido *</label>
        <input type="text" id="fCliente" placeholder="Ej: María González" autocomplete="name">
      </div>
      <div class="form-group">
        <label>Celular</label>
        <input type="tel" id="fTelefono" placeholder="Ej: 1123456789" autocomplete="tel" inputmode="numeric" pattern="[0-9]+" title="Solo dígitos, sin espacios ni guiones">
      </div>
      <div class="form-group">
        <label>Dirección de entrega *</label>
        <input type="text" id="fDireccion" placeholder="Calle, número, piso/depto" autocomplete="street-address">
      </div>
      <textarea id="fNotas" style="display:none"></textarea>
      <button class="btn-checkout" onclick="checkoutExtraDatosContinuar()">Continuar</button>
    </div>

    <!-- Paso 2: Confirmar (siempre, antes de enviar) -->
    <div id="checkoutStepConfirmar" style="display:none">
      <div class="modal-title">Confirmar pedido</div>

      <div class="co-user-card">
        <div class="co-avatar" id="coAvatar"></div>
        <div class="co-user-info">
          <div class="co-user-name" id="coNombre"></div>
          <div class="co-user-detail"><i class="fa-solid fa-envelope co-detail-icon"></i><span id="coEmail"></span></div>
          <div class="co-user-detail"><i class="fa-solid fa-phone co-detail-icon"></i><span id="coTelefono"></span></div>
        </div>
      </div>

      <div class="co-section-label">Dirección de entrega</div>
      <div class="co-address" id="coDireccion"></div>

      <div class="co-section-label">Detalle del pedido</div>
      <div id="coItemsList" class="co-items"></div>
      <div class="co-total-row">
        <span>Total</span>
        <span id="coTotal"></span>
      </div>

      <button class="btn-checkout" id="btnConfirmar" onclick="submitOrder()">
        Confirmar y enviar pedido
      </button>
    </div>

    <!-- Paso 3: Éxito -->
    <div class="confirm-screen" id="confirmScreen">
      <div class="confirm-icon">
        <i class="fa-solid fa-circle-check" style="font-size:72px;color:#22c55e"></i>
      </div>
      <div class="confirm-title">¡Pedido recibido!</div>
      <div class="confirm-num" id="confirmNum">PED-XXXXXX</div>
      <div class="confirm-sub">Te contactaremos para coordinar la entrega.</div>
      <button class="btn-nuevo" onclick="verUltimoPedido()">Ver pedido</button>
    </div>
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
      <label>Celular</label>
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

<!-- ===== Modal Login OTP ===== -->
<div class="modal-wrap" id="otpModal">
  <div class="modal-backdrop" onclick="closeOtpModal()"></div>
  <div class="modal">
    <div class="modal-handle"></div>
    <button class="btn-close product-modal-close" onclick="closeOtpModal()">✕</button>

    <!-- Paso 1: correo -->
    <div id="otpStep1">
      <div class="modal-title">Iniciar sesión</div>
      <p class="otp-sub">Ingresá tu correo para recibir un código de acceso.</p>
      <div class="form-group">
        <label>Correo electrónico *</label>
        <input type="email" id="otpEmail" placeholder="tu@correo.com" inputmode="email" autocomplete="email">
      </div>
      <button class="btn-checkout" id="btnEnviarOtp" onclick="sendOtp()">Enviar código</button>
    </div>

    <!-- Paso 2: código OTP -->
    <div id="otpStep2" style="display:none">
      <div class="modal-title">Verificar código</div>
      <p class="otp-sub">Enviamos un código de 6 dígitos a <strong id="otpEmailLabel"></strong></p>
      <div class="form-group">
        <label>Código de verificación *</label>
        <input type="text" id="otpCodigo" placeholder="000000" inputmode="numeric" maxlength="6" autocomplete="one-time-code">
      </div>
      <button class="btn-checkout" id="btnVerificarOtp" onclick="verifyOtp()">Ingresar</button>
      <button class="otp-back-btn" onclick="backOtpStep()">← Cambiar correo</button>
    </div>
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
    <span class="nav-icon"><i class="fa-solid fa-house"></i></span>
    Inicio
  </button>
  <button class="nav-tab" onclick="openCart()">
    <span class="nav-icon"><i class="fa-solid fa-cart-shopping"></i></span>
    Carrito
  </button>
  <button class="nav-tab" onclick="selectTab('pedidos', this)">
    <span class="nav-icon"><i class="fa-solid fa-receipt"></i></span>
    Pedidos
  </button>
  <button class="nav-tab" onclick="selectTab('perfil', this)">
    <span class="nav-icon"><i class="fa-solid fa-circle-user"></i></span>
    Perfil
  </button>
</nav>

<!-- ===== Modal detalle pedido ===== -->
<div class="ped-modal-backdrop" id="pedModalBackdrop" onclick="if(event.target===this)closePedModal()">
  <div class="ped-modal" id="pedModal">
    <div class="ped-modal-header">
      <div>
        <div class="ped-modal-num" id="pmNum"></div>
        <div class="ped-modal-fecha" id="pmFecha"></div>
      </div>
      <button class="btn-icon" onclick="closePedModal()"><i class="fa-solid fa-xmark" style="font-size:18px"></i></button>
    </div>
    <div class="ped-modal-body">
      <div id="pmEstado"></div>
      <div class="pm-steps" id="pmSteps"></div>
      <div class="pm-section">
        <div class="pm-section-title">Productos</div>
        <div id="pmItems"></div>
        <div class="pm-total-row"><span>Total</span><span id="pmTotal"></span></div>
      </div>
    </div>
  </div>
</div>

<script src="assets/js/app.js?v=<?= time() ?>"></script>
</body>
</html>
