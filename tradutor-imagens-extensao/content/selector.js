// Seleção de área: o usuário arrasta um retângulo. A camada é removida ANTES
// da captura, para a tela enviada ao OCR não conter o próprio overlay.

(function () {
  const TI = (globalThis.TI = globalThis.TI || {});
  const MIN_SIZE = 8;

  function nextFrames(n) {
    return new Promise((resolve) => {
      const step = (left) => (left <= 0 ? resolve() : requestAnimationFrame(() => step(left - 1)));
      step(n);
    });
  }

  TI.selector = {
    start(onSelect) {
      const root = TI.ui.root();
      root.querySelectorAll('.ti-select-layer').forEach((n) => n.remove());

      const layer = document.createElement('div');
      layer.className = 'ti-select-layer';
      layer.setAttribute('role', 'application');
      layer.setAttribute('aria-label', 'Selecione uma área da página para traduzir. Esc cancela.');

      const box = document.createElement('div');
      box.className = 'ti-select-box';
      box.hidden = true;

      const hint = document.createElement('div');
      hint.className = 'ti-select-hint';
      hint.textContent = 'Arraste para selecionar a área · Esc cancela';

      layer.append(box, hint);
      root.appendChild(layer);

      let origin = null;

      const finish = () => {
        document.removeEventListener('keydown', onKey, true);
        layer.remove();
      };

      const onKey = (event) => {
        if (event.key === 'Escape') {
          finish();
          TI.ui.toast('Seleção cancelada.');
        }
      };

      const paint = (event) => {
        const x = Math.min(origin.x, event.clientX);
        const y = Math.min(origin.y, event.clientY);
        const w = Math.abs(event.clientX - origin.x);
        const h = Math.abs(event.clientY - origin.y);
        Object.assign(box.style, { left: `${x}px`, top: `${y}px`, width: `${w}px`, height: `${h}px` });
      };

      layer.addEventListener('pointerdown', (event) => {
        origin = { x: event.clientX, y: event.clientY };
        layer.setPointerCapture(event.pointerId);
        box.hidden = false;
        paint(event);
      });

      layer.addEventListener('pointermove', (event) => {
        if (origin) paint(event);
      });

      layer.addEventListener('pointerup', async (event) => {
        if (!origin) return;
        const rect = {
          x: Math.min(origin.x, event.clientX),
          y: Math.min(origin.y, event.clientY),
          w: Math.abs(event.clientX - origin.x),
          h: Math.abs(event.clientY - origin.y),
        };
        origin = null;
        finish();

        if (rect.w < MIN_SIZE || rect.h < MIN_SIZE) {
          TI.ui.toast('A área é muito pequena. Tente selecionar novamente.', 'error');
          return;
        }
        // Espera o navegador pintar a página sem o overlay antes da captura.
        await nextFrames(2);
        onSelect(rect, { w: window.innerWidth, h: window.innerHeight });
      });

      document.addEventListener('keydown', onKey, true);
    },
  };
})();
