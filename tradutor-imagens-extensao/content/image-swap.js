// Substitui a imagem original da página pela versão com a tradução sobreposta,
// e restaura o original quando o usuário pede. Só altera atributos de <img>:
// nada é gravado no site, e o estado original fica guardado em memória.

(function () {
  const TI = (globalThis.TI = globalThis.TI || {});
  const saved = new WeakMap();

  function snapshot(img) {
    const picture = img.closest('picture');
    return {
      attrs: { src: img.getAttribute('src'), srcset: img.getAttribute('srcset'), sizes: img.getAttribute('sizes') },
      sources: picture
        ? [...picture.querySelectorAll('source')].map((el) => ({ el, srcset: el.getAttribute('srcset') }))
        : [],
    };
  }

  function setOrRemove(el, name, value) {
    if (value === null || value === undefined) el.removeAttribute(name);
    else el.setAttribute(name, value);
  }

  // Sobreposições em imagens com CORS (sem acesso ao pixel original): um elemento da
  // extensão é posicionado exatamente sobre a imagem e acompanha a rolagem.
  const overlays = new Map(); // elemento -> { node, remove }

  function placeOverlay(element, node) {
    const r = element.getBoundingClientRect();
    Object.assign(node.style, {
      left: `${r.left}px`,
      top: `${r.top}px`,
      width: `${r.width}px`,
      height: `${r.height}px`,
      display: r.bottom > 0 && r.top < window.innerHeight ? 'block' : 'none',
    });
  }

  TI.imageSwap = {
    /** Sobrepõe a tradução sobre o elemento, sem alterá-lo. */
    overlayOn(element, dataUrl) {
      this.overlayOff(element);
      const root = TI.ui.root();
      const node = document.createElement('img');
      node.className = 'ti-page-overlay';
      node.alt = 'Imagem com a tradução sobreposta';
      node.src = dataUrl;

      let frame = 0;
      const update = () => {
        frame = 0;
        if (!element.isConnected) return this.overlayOff(element);
        placeOverlay(element, node);
      };
      const schedule = () => {
        if (!frame) frame = requestAnimationFrame(update);
      };
      window.addEventListener('scroll', schedule, { capture: true, passive: true });
      window.addEventListener('resize', schedule, { passive: true });

      node.addEventListener('error', () => {
        this.overlayOff(element);
        TI.ui.toast('A página bloqueou a exibição da imagem traduzida. Use "Abrir em aba" para vê-la.', 'error', 7000);
      }, { once: true });

      root.appendChild(node);
      placeOverlay(element, node);
      overlays.set(element, {
        node,
        remove: () => {
          window.removeEventListener('scroll', schedule, true);
          window.removeEventListener('resize', schedule);
          cancelAnimationFrame(frame);
          node.remove();
        },
      });
      return true;
    },

    overlayOff(element) {
      const entry = overlays.get(element);
      if (!entry) return;
      entry.remove();
      overlays.delete(element);
    },

    isOverlaid(element) {
      return overlays.has(element);
    },

    /** Mostra a imagem traduzida no lugar da original. Retorna false se o elemento não for <img>. */
    apply(img, dataUrl) {
      if (!(img instanceof HTMLImageElement)) return false;
      if (!saved.has(img)) saved.set(img, snapshot(img));

      // srcset e <picture> têm prioridade sobre src: precisam ser desligados também.
      img.removeAttribute('srcset');
      img.removeAttribute('sizes');
      saved.get(img).sources.forEach(({ el }) => el.removeAttribute('srcset'));

      img.addEventListener(
        'error',
        () => {
          // Algumas páginas bloqueiam data: URLs pela Content-Security-Policy.
          this.restore(img);
          TI.ui.toast(
            'A página bloqueou a exibição da imagem traduzida. Use "Abrir em aba" para vê-la.',
            'error',
            7000,
          );
        },
        { once: true },
      );
      img.setAttribute('src', dataUrl);
      img.dataset.tiSwapped = '1';
      return true;
    },

    restore(img) {
      const state = saved.get(img);
      if (!state) return;
      setOrRemove(img, 'src', state.attrs.src);
      setOrRemove(img, 'srcset', state.attrs.srcset);
      setOrRemove(img, 'sizes', state.attrs.sizes);
      state.sources.forEach(({ el, srcset }) => setOrRemove(el, 'srcset', srcset));
      delete img.dataset.tiSwapped;
      saved.delete(img);
    },

    isSwapped(img) {
      return Boolean(img?.dataset?.tiSwapped);
    },
  };
})();
