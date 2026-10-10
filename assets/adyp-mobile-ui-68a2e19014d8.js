/* ADYP mobile-sheet refinement v2.
   Allowlist: only actual app dialogs with a matching compact-sheet role are enhanced. */
(() => {
  'use strict';

  const MOBILE_QUERY = '(max-width: 699px)';
  let nextPanelId = 1;
  let observer = null;
  const lastVisibility = new WeakMap();

  const isMobileSheetViewport = () =>
    window.matchMedia ? window.matchMedia(MOBILE_QUERY).matches : window.innerWidth <= 699;

  function classifyPanel(modal, overlay) {
    if (!modal || modal.getAttribute('role') === 'alertdialog') return null;
    if (modal.classList.contains('centro') || overlay.classList.contains('centro')) return null;

    if (modal.classList.contains('filtros-panel')) return 'filters';
    if (modal.classList.contains('modal-ancho') && modal.classList.contains('fq')) return 'form';
    if (modal.classList.contains('normas')) return 'info';
    if (modal.getAttribute('aria-label') === 'Confirmar plaza') return 'detail';

    // Deliberately leave all generic confirmations, access/security alerts,
    // WhatsApp settings and success dialogs in their original presentation.
    return null;
  }

  function panelTitle(modal) {
    const heading = modal.querySelector('h1, h2, h3, b');
    const text = (heading?.textContent || '').trim().replace(/\s+/g, ' ');
    return text ? text.slice(0, 80) : 'opciones';
  }

  function ensurePanelId(modal) {
    if (modal.id && document.getElementById(modal.id) === modal) return modal.id;
    let id;
    do { id = `adyp-mobile-panel-${nextPanelId++}`; } while (document.getElementById(id));
    modal.id = id;
    return id;
  }

  function setDetent(modal, handle, expanded) {
    modal.dataset.adypDetent = expanded ? 'expanded' : 'peek';
    handle.setAttribute('aria-expanded', String(expanded));
    const title = panelTitle(modal);
    handle.setAttribute('aria-label', `${expanded ? 'Contraer' : 'Ampliar'} panel: ${title}`);
    handle.title = expanded ? 'Toca para contraer o arrastra hacia abajo' : 'Toca para ampliar o arrastra hacia arriba';
  }

  function visibleOverlay(overlay) {
    if (!overlay || !overlay.isConnected || overlay.hidden) return false;
    const style = window.getComputedStyle(overlay);
    if (style.display === 'none' || style.visibility === 'hidden') return false;
    const rect = overlay.getBoundingClientRect();
    return rect.width > 0 && rect.height > 0;
  }

  function closeFromHandle(overlay) {
    const options = { bubbles: true, cancelable: true, view: window, detail: 1 };
    // Reuse the original React backdrop-close handler; do not add a new close flow.
    overlay.dispatchEvent(new MouseEvent('mousedown', options));
    if (overlay.isConnected) overlay.dispatchEvent(new MouseEvent('click', options));
  }

  function addHandle(overlay, modal) {
    let handle = modal.querySelector(':scope > .adyp-sheet-handle');
    if (handle) return handle;

    handle = document.createElement('button');
    handle.type = 'button';
    handle.className = 'adyp-sheet-handle';
    handle.setAttribute('aria-controls', ensurePanelId(modal));
    handle.setAttribute('aria-expanded', 'false');
    handle.setAttribute('aria-label', `Ampliar panel: ${panelTitle(modal)}`);
    handle.title = 'Toca para ampliar o arrastra hacia arriba';

    const grip = document.createElement('span');
    grip.className = 'adyp-sheet-grip';
    grip.setAttribute('aria-hidden', 'true');
    handle.appendChild(grip);
    modal.insertBefore(handle, modal.firstChild);
    modal.dataset.adypDetent = 'peek';

    let startY = null;
    let suppressClickUntil = 0;
    handle.addEventListener('pointerdown', event => {
      if (event.isPrimary === false || (event.button !== undefined && event.button > 0)) return;
      startY = event.clientY;
      try { handle.setPointerCapture(event.pointerId); } catch (_) {}
      event.stopPropagation();
    });
    handle.addEventListener('pointerup', event => {
      event.stopPropagation();
      if (startY === null) return;
      const delta = event.clientY - startY;
      startY = null;
      if (delta < -30) {
        setDetent(modal, handle, true);
        suppressClickUntil = Date.now() + 350;
      } else if (delta > 30) {
        suppressClickUntil = Date.now() + 350;
        if (modal.dataset.adypDetent === 'expanded') setDetent(modal, handle, false);
        else closeFromHandle(overlay);
      }
    });
    handle.addEventListener('pointercancel', () => { startY = null; });
    handle.addEventListener('click', event => {
      event.preventDefault();
      event.stopPropagation();
      if (Date.now() < suppressClickUntil) return;
      setDetent(modal, handle, modal.dataset.adypDetent !== 'expanded');
    });
    return handle;
  }

  function processOverlay(overlay) {
    if (!isMobileSheetViewport() || !overlay?.matches?.('.modal-fondo')) return;
    const modal = overlay.querySelector('.modal');
    const kind = classifyPanel(modal, overlay);
    if (!kind) return;

    modal.dataset.adypSheet = 'true';
    modal.dataset.adypKind = kind;
    const handle = addHandle(overlay, modal);

    const visible = visibleOverlay(overlay);
    const previous = lastVisibility.get(overlay);
    if (visible && previous !== true) setDetent(modal, handle, false);
    if (!visible && previous === true) setDetent(modal, handle, false);
    lastVisibility.set(overlay, visible);
  }

  function overlaysWithin(node) {
    const result = new Set();
    if (!node || node.nodeType !== Node.ELEMENT_NODE) return result;
    const own = node.matches('.modal-fondo') ? node : node.closest('.modal-fondo');
    if (own) result.add(own);
    node.querySelectorAll?.('.modal-fondo').forEach(overlay => result.add(overlay));
    return result;
  }

  function scanAll() {
    if (!isMobileSheetViewport()) {
      document.querySelectorAll('.modal-fondo .modal[data-adyp-sheet]').forEach(modal => {
        const handle = modal.querySelector(':scope > .adyp-sheet-handle');
        if (handle) setDetent(modal, handle, false);
      });
      return;
    }
    document.querySelectorAll('.modal-fondo').forEach(processOverlay);
  }

  function start() {
    scanAll();
    if (!observer && document.body) {
      observer = new MutationObserver(records => {
        const overlays = new Set();
        for (const record of records) {
          for (const overlay of overlaysWithin(record.target)) overlays.add(overlay);
          record.addedNodes?.forEach(node => {
            for (const overlay of overlaysWithin(node)) overlays.add(overlay);
          });
        }
        overlays.forEach(processOverlay);
      });
      observer.observe(document.body, {
        childList: true,
        subtree: true,
        attributes: true,
        attributeFilter: ['class', 'style', 'hidden', 'aria-hidden']
      });
    }
    window.addEventListener('resize', scanAll, { passive: true });
    window.visualViewport?.addEventListener('resize', scanAll, { passive: true });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, { once: true });
  else start();
})();
