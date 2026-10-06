// Duas formas de escolher uma imagem na página, sem tocar no DOM do site:
//   - TI.picker: modo "clique para escolher" (atalho Ctrl+Shift+I e popup).
//   - TI.hover:  botão "Traduzir" que aparece só enquanto o mouse está sobre uma imagem.
// Nenhum dos dois insere botões permanentes em todas as imagens.
// Eventos são throttled com requestAnimationFrame para não pesar a CPU.

(function () {
  const TI = (globalThis.TI = globalThis.TI || {});

  function translatableAt(stack, minPx) {
    for (const node of stack) {
      if (node.id === 'ti-root') continue;
      if (node.tagName !== 'IMG' && node.tagName !== 'CANVAS') continue;
      const r = node.getBoundingClientRect();
      if (r.width >= minPx && r.height >= minPx) return node;
    }
    return null;
  }

  function clampValue(value, min, max) {
    return Math.min(Math.max(value, min), max);
  }

  TI.picker = {
    start(onPick, { minPx = 40 } = {}) {
      const root = TI.ui.root();
      root.querySelectorAll('.ti-highlight').forEach((n) => n.remove());

      const highlight = document.createElement('div');
      highlight.className = 'ti-highlight';
      highlight.hidden = true;
      root.appendChild(highlight);

      let current = null;
      let frame = 0;
      let lastX = 0;
      let lastY = 0;

      const stop = () => {
        cancelAnimationFrame(frame);
        document.removeEventListener('mousemove', onMove, true);
        document.removeEventListener('click', onClick, true);
        document.removeEventListener('keydown', onKey, true);
        highlight.remove();
      };

      const update = () => {
        frame = 0;
        current = translatableAt(document.elementsFromPoint(lastX, lastY), minPx);
        if (!current) {
          highlight.hidden = true;
          return;
        }
        const r = current.getBoundingClientRect();
        highlight.hidden = false;
        Object.assign(highlight.style, {
          left: `${r.left}px`,
          top: `${r.top}px`,
          width: `${r.width}px`,
          height: `${r.height}px`,
        });
      };

      function onMove(event) {
        lastX = event.clientX;
        lastY = event.clientY;
        if (!frame) frame = requestAnimationFrame(update);
      }

      function onClick(event) {
        if (!current) return;
        event.preventDefault();
        event.stopPropagation();
        event.stopImmediatePropagation();
        const picked = current;
        stop();
        onPick(picked);
      }

      function onKey(event) {
        if (event.key === 'Escape') {
          stop();
          TI.ui.toast('Seleção cancelada.');
        }
      }

      document.addEventListener('mousemove', onMove, true);
      document.addEventListener('click', onClick, true);
      document.addEventListener('keydown', onKey, true);
      TI.ui.toast('Clique em uma imagem para traduzi-la. Esc cancela.', 'info', 4000);
    },
  };

  const hover = {
    enabled: false,
    button: null,
    target: null,
    onPick: null,
    minPx: 40,
    pending: false,
    lastX: 0,
    lastY: 0,
  };

  function ensureButton() {
    if (hover.button) return hover.button;
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'ti-hover-btn';
    button.textContent = '🌐 Traduzir';
    button.setAttribute('aria-label', 'Traduzir o texto desta imagem para português');
    button.hidden = true;
    button.addEventListener('click', (event) => {
      event.preventDefault();
      event.stopPropagation();
      const img = hover.target;
      hideButton();
      if (img && hover.onPick) hover.onPick(img);
    });
    TI.ui.root().appendChild(button);
    hover.button = button;
    return button;
  }

  function showButtonFor(img) {
    const button = ensureButton();
    const r = img.getBoundingClientRect();
    hover.target = img;
    button.hidden = false;
    const width = button.offsetWidth || 120;
    Object.assign(button.style, {
      left: `${clampValue(r.left + 8, 8, window.innerWidth - width - 8)}px`,
      top: `${clampValue(r.top + 8, 8, window.innerHeight - 40)}px`,
    });
  }

  function hideButton() {
    if (hover.button) hover.button.hidden = true;
    hover.target = null;
  }

  function scheduleCheck() {
    if (hover.pending) return;
    hover.pending = true;
    requestAnimationFrame(() => {
      hover.pending = false;
      const stack = document.elementsFromPoint(hover.lastX, hover.lastY);
      // Mouse sobre o próprio botão: mantém visível.
      if (stack.includes(TI.ui.host())) return;
      const img = translatableAt(stack, hover.minPx);
      if (img) showButtonFor(img);
      else hideButton();
    });
  }

  function onMouseMove(event) {
    hover.lastX = event.clientX;
    hover.lastY = event.clientY;
    scheduleCheck();
  }

  function onScroll() {
    hideButton();
  }

  TI.hover = {
    isEnabled() {
      return hover.enabled;
    },

    set(enabled, onPick, { minPx = 40 } = {}) {
      hover.onPick = onPick;
      hover.minPx = minPx;
      if (enabled === hover.enabled) return;
      hover.enabled = enabled;

      if (enabled) {
        document.addEventListener('mousemove', onMouseMove, { capture: true, passive: true });
        document.addEventListener('scroll', onScroll, { capture: true, passive: true });
      } else {
        document.removeEventListener('mousemove', onMouseMove, true);
        document.removeEventListener('scroll', onScroll, true);
        hideButton();
      }
    },
  };
})();
