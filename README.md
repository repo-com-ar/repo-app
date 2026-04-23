# repo-app — Tienda online para clientes

PWA mobile-first donde los clientes navegan el catálogo, agregan productos al carrito y realizan pedidos con verificación por email (OTP) y detección de ubicación por GPS.

---

## Tecnologías

- PHP (APIs REST, sin framework)
- JavaScript vanilla
- MySQL vía PDO (conexión compartida con `repo-api`)
- Google Maps Distance Matrix API
- AWS SES (envío de OTP por email)
- WhatsApp (notificaciones vía datarocket/Evolution)

---

## Estructura

```
repo-app/
├── index.php            # SPA principal (catálogo, carrito, checkout, historial)
├── seguimiento.php      # Seguimiento público de pedido (sin login)
├── manifest.json        # Manifiesto PWA
├── api/
│   ├── auth_otp.php     # Login/registro sin contraseña (OTP por email)
│   ├── pedidos.php      # Crear pedidos, calcular distancia/tiempo
│   ├── productos.php    # Listar productos activos con stock
│   ├── categorias.php   # Categorías de productos
│   ├── clientes.php     # Perfil del cliente (get/update)
│   ├── eventos.php      # Log de actividad
│   └── notificaciones.php
├── assets/
│   ├── css/app.css
│   └── js/app.js
└── favicon/             # Íconos PWA
```

---

## Autenticación

Sin contraseña. El flujo es:

1. El cliente ingresa su email en el checkout.
2. Si el email ya existe → se envía un código OTP de 6 dígitos por email.
3. Si el email es nuevo → se crea la cuenta automáticamente y se genera un JWT sin OTP.
4. El JWT se almacena en la cookie `cliente_id` (365 días).

El token incluye: `cliente_id`, `nombre`, `correo`, `rol: cliente`.

---

## Estados de pedido

```
pendiente → preparacion → asignacion → reparto → entregado
                                               ↘ cancelado
```

---

## Funcionalidades principales

- Catálogo con categorías y stock en tiempo real
- Carrito persistente por sesión
- Checkout en 3 pasos: email → OTP / cuenta nueva → datos de entrega
- Geolocalización del cliente (GPS del navegador)
- Seguimiento público de pedido en `/seguimiento.php?p=PED-XXXXXX`
- Notificación automática por WhatsApp al negocio y al cliente al confirmar pedido
- Instalable como PWA (icono en pantalla de inicio)
- Modo oscuro (preferencia en `localStorage`)

---

## Dependencias externas

| Servicio | Uso |
|---|---|
| `repo-api/config/db.php` | Conexión a la base de datos compartida |
| Google Maps API | Cálculo de distancia y tiempo de entrega |
| AWS SES | Envío de códigos OTP |
| datarocket / Evolution | Notificaciones WhatsApp |
