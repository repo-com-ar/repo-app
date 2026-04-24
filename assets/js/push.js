/* ================================================
   Repo App — Web Push (gestión de suscripción cliente)
   ================================================ */

const PUSH_API = 'api/push';

// ─── Detección de entorno ─────────────────────────────────────
const pushEnv = (() => {
  const ua = navigator.userAgent;
  const esIOS = /iPad|iPhone|iPod/.test(ua) && !window.MSStream;
  const esStandalone = window.matchMedia('(display-mode: standalone)').matches
                    || window.navigator.standalone === true;
  const soportaSW   = 'serviceWorker' in navigator;
  const soportaPush = 'PushManager' in window;
  return { esIOS, esStandalone, soportaSW, soportaPush };
})();

function pushDisponible() {
  if (!pushEnv.soportaSW || !pushEnv.soportaPush) return false;
  if (pushEnv.esIOS && !pushEnv.esStandalone) return false;
  return true;
}

function pushRequiereInstalar() {
  return pushEnv.esIOS && !pushEnv.esStandalone;
}

// ─── Service Worker ──────────────────────────────────────────
let swRegistration = null;
async function registrarSW() {
  if (!pushEnv.soportaSW) return null;
  if (swRegistration) return swRegistration;
  try {
    swRegistration = await navigator.serviceWorker.register('sw.js');
    return swRegistration;
  } catch (e) {
    console.error('SW register error', e);
    return null;
  }
}

// ─── VAPID public key ─────────────────────────────────────────
let vapidPublicKey = null;
async function getVapidPublicKey() {
  if (vapidPublicKey) return vapidPublicKey;
  const r = await fetch(PUSH_API);
  const data = await r.json();
  if (!data.ok) throw new Error(data.error || 'No se pudo leer VAPID key');
  vapidPublicKey = data.publicKey;
  return vapidPublicKey;
}

function b64urlToUint8Array(b64url) {
  const pad = '='.repeat((4 - (b64url.length % 4)) % 4);
  const b64 = (b64url + pad).replace(/-/g, '+').replace(/_/g, '/');
  const raw = atob(b64);
  const out = new Uint8Array(raw.length);
  for (let i = 0; i < raw.length; i++) out[i] = raw.charCodeAt(i);
  return out;
}

// ─── Activar / desactivar ────────────────────────────────────
async function activarPush() {
  if (!pushDisponible()) {
    if (pushRequiereInstalar()) {
      mostrarInstallBannerIOS();
      return false;
    }
    if (typeof showToast === 'function') showToast('Este navegador no soporta notificaciones');
    return false;
  }

  const permission = await Notification.requestPermission();
  if (permission !== 'granted') {
    if (typeof showToast === 'function') showToast('Permiso denegado — activalo desde la configuración del navegador');
    return false;
  }

  const reg = await registrarSW();
  if (!reg) {
    if (typeof showToast === 'function') showToast('No se pudo registrar el service worker');
    return false;
  }

  if (!reg.active) {
    await new Promise(res => {
      const sw = reg.installing || reg.waiting;
      if (!sw) return res();
      sw.addEventListener('statechange', () => { if (sw.state === 'activated') res(); });
    });
  }

  try {
    const pub = await getVapidPublicKey();
    let sub = await reg.pushManager.getSubscription();
    if (!sub) {
      sub = await reg.pushManager.subscribe({
        userVisibleOnly:      true,
        applicationServerKey: b64urlToUint8Array(pub),
      });
    }

    const headers = (typeof authHeaders === 'function')
      ? authHeaders()
      : { 'Content-Type': 'application/json' };

    const r = await fetch(PUSH_API, {
      method: 'POST',
      headers,
      body: JSON.stringify(sub.toJSON()),
    });
    const data = await r.json();
    if (!data.ok) throw new Error(data.error || 'Error guardando suscripción');

    localStorage.setItem('appPushActivo', '1');
    if (typeof showToast === 'function') showToast('🔔 Notificaciones activadas');
    return true;
  } catch (e) {
    console.error('activarPush error', e);
    if (typeof showToast === 'function') showToast('Error activando notificaciones: ' + e.message);
    return false;
  }
}

async function desactivarPush() {
  try {
    const reg = await registrarSW();
    if (reg) {
      const sub = await reg.pushManager.getSubscription();
      if (sub) {
        const headers = (typeof authHeaders === 'function')
          ? authHeaders()
          : { 'Content-Type': 'application/json' };
        await fetch(PUSH_API, {
          method: 'DELETE',
          headers,
          body: JSON.stringify({ endpoint: sub.endpoint }),
        });
        await sub.unsubscribe();
      }
    }
    localStorage.setItem('appPushActivo', '0');
    if (typeof showToast === 'function') showToast('🔕 Notificaciones desactivadas');
    return true;
  } catch (e) {
    console.error('desactivarPush error', e);
    return false;
  }
}

// ─── UI: sync del toggle ──────────────────────────────────────
async function sincronizarTogglePush() {
  const toggle = document.getElementById('pushToggle');
  const status = document.getElementById('pushStatus');
  if (!toggle) return;

  if (!pushDisponible()) {
    toggle.checked  = false;
    toggle.disabled = pushRequiereInstalar() ? false : true;
    if (status) {
      status.textContent = pushRequiereInstalar()
        ? 'Para activar en iPhone, instalá la app primero'
        : 'Este dispositivo no soporta notificaciones';
      status.style.display = '';
    }
    return;
  }

  const reg = await registrarSW();
  const sub = reg ? await reg.pushManager.getSubscription() : null;
  const activo = !!sub && Notification.permission === 'granted';
  toggle.checked = activo;
  toggle.disabled = false;
  if (status) status.style.display = 'none';
}

async function onTogglePush(e) {
  const toggle = e.target;
  toggle.disabled = true;
  const ok = toggle.checked ? await activarPush() : await desactivarPush();
  toggle.disabled = false;
  if (!ok) toggle.checked = !toggle.checked;
}

// ─── Banner iOS: instalar PWA ─────────────────────────────────
function mostrarInstallBannerIOS() {
  const b = document.getElementById('pushInstallBanner');
  if (b) b.classList.add('open');
}
function cerrarInstallBannerIOS() {
  const b = document.getElementById('pushInstallBanner');
  if (b) b.classList.remove('open');
}

// ─── Boot ─────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', async () => {
  if (pushEnv.soportaSW) { registrarSW(); }
});

// Re-suscripción silenciosa si el navegador invalida la suscripción
navigator.serviceWorker?.addEventListener('message', async (event) => {
  if (event.data && event.data.type === 'pushsubscriptionchange') {
    if (localStorage.getItem('appPushActivo') === '1') {
      await activarPush();
    }
  }
});
