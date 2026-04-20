# Repo App

Aplicación web mobile-first para clientes del sistema **Repo Online**. Permite explorar el catálogo de productos, armar un carrito y realizar pedidos con notificación automática por WhatsApp.

## Acceso

```
/repo-app/index.php
```

No requiere instalación ni login previo. El cliente se identifica automáticamente por cookie tras realizar su primer pedido.

---

## Funcionalidades

### Catálogo
- Listado de productos con imagen, precio y estado de stock
- Filtro por categorías (tabs)
- Buscador en tiempo real
- Modal de detalle del producto con control de cantidad (+ / −)

### Carrito
- Drawer lateral persistente con todos los ítems
- Actualización de cantidades desde el carrito
- Total calculado en tiempo real
- Botón **Finalizar Pedido** para avanzar al checkout

### Checkout
- Formulario de datos: nombre, teléfono, dirección, notas
- Detección de ubicación GPS opcional
- Validación de pedido mínimo configurable
- Confirmación visual con número de pedido

### Perfil
- Visualización y edición de datos del cliente (nombre, correo, teléfono, dirección)
- Detección y eliminación de ubicación GPS
- Cierre de sesión

### Mis pedidos
- Historial de pedidos del cliente autenticado
- Estado actual de cada pedido

### Seguimiento de pedido
- Página pública accesible sin login: `seguimiento.php?p=PED-XXXXXX`
- Barra de progreso con 5 estados: Recibido → Confirmado → Preparando → En camino → Entregado
- Detalle de ítems y total

---

## Notificaciones automáticas

Al confirmar un pedido el sistema envía automáticamente **dos mensajes por WhatsApp**:

1. **Al negocio** — detalle completo del pedido (cliente, dirección, ítems, total)
2. **Al cliente** — confirmación con link de seguimiento personalizado

Ambos se registran en la tabla `mensajes` visible desde repo-admin.

---

## API endpoints

Todos en `/repo-app/api/`:

| Archivo | Métodos | Descripción |
|---|---|---|
| `productos.php` | GET | Listado de productos activos con stock |
| `categorias.php` | GET | Listado de categorías activas |
| `pedidos.php` | POST, GET | Crear pedido / listar pedidos del cliente |
| `clientes.php` | GET, PUT | Obtener y actualizar datos del cliente |
| `configuracion.php` | GET | Parámetros públicos (pedido mínimo, nombre del negocio, etc.) |
| `notificar_whatsapp.php` | POST | Proxy hacia datarocket para envío de WhatsApp |
| `eventos.php` | POST | Registro de eventos de actividad del usuario |

---

## Estructura de archivos

```
repo-app/
├── index.php               # SPA principal (catálogo, carrito, checkout, perfil, pedidos)
├── seguimiento.php         # Página pública de seguimiento de pedido
├── manifest.json           # Manifest PWA
├── api/                    # Endpoints REST PHP
└── assets/
    ├── css/app.css         # Estilos de la app
    └── js/app.js           # Lógica completa (vanilla JS)
```

---

## Base de datos

Usa la base de datos `repo`. Conexión definida en `/config/db.php`.

Tablas que consume: `productos`, `categorias`, `pedidos`, `pedido_items`, `clientes`, `mensajes`, `eventos`, `configuracion`.

Para crear el esquema ejecutar:

```
/setup/install.php
```

---

## Integraciones

- **datarocket / Evolution** — envío de mensajes WhatsApp al negocio y al cliente
- **Google Maps Distance Matrix** — cálculo de distancia y tiempo estimado de entrega al crear el pedido
- **GPS del navegador** — detección de ubicación del cliente para facilitar la entrega

---

## Notas de desarrollo

- No usa frameworks JS ni bundlers. Todo es vanilla JS en un único `app.js`.
- La sesión del cliente se mantiene con la cookie `cliente_id` (365 días).
- PWA: tiene `manifest.json` para instalación en pantalla de inicio.
- Los modales usan `.modal-wrap` con `classList.add/remove('open')`.
