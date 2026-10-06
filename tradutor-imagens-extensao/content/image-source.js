// Obtém os pixels de uma imagem da página, de forma segura.
//
// Ordem de tentativas:
//   1. Desenhar o elemento num canvas (funciona para imagens da mesma origem ou
//      servidas com CORS e carregadas com crossOrigin).
//   2. Recarregar a imagem com crossOrigin="anonymous" (funciona se o servidor
//      enviar Access-Control-Allow-Origin).
//   3. Se ainda houver bloqueio do navegador (canvas "tainted"), devolver a
//      posição do elemento para a captura da área visível (activeTab).
// A extensão nunca contorna CORS: só usa o que o navegador permite.

(function () {
  const TI = (globalThis.TI = globalThis.TI || {});
  const MAX_SIDE = 3000; // evita canvas gigantes que estouram a memória

  function drawToDataUrl(source, width, height) {
    const scale = Math.min(1, MAX_SIDE / Math.max(width, height));
    const canvas = document.createElement('canvas');
    canvas.width = Math.max(1, Math.round(width * scale));
    canvas.height = Math.max(1, Math.round(height * scale));
    canvas.getContext('2d').drawImage(source, 0, 0, canvas.width, canvas.height);
    // toDataURL lança SecurityError quando o canvas está contaminado por CORS.
    return canvas.toDataURL('image/png');
  }

  function reloadWithCors(src) {
    return new Promise((resolve) => {
      const probe = new Image();
      probe.crossOrigin = 'anonymous';
      probe.onload = () => resolve(probe);
      probe.onerror = () => resolve(null);
      probe.src = src;
    });
  }

  async function extractImage(img) {
    if (!img.complete || !img.naturalWidth) return null;
    try {
      return drawToDataUrl(img, img.naturalWidth, img.naturalHeight);
    } catch {
      /* canvas contaminado: tenta a versão com CORS abaixo */
    }
    const retry = await reloadWithCors(img.currentSrc || img.src);
    if (retry?.naturalWidth) {
      try {
        return drawToDataUrl(retry, retry.naturalWidth, retry.naturalHeight);
      } catch {
        /* sem acesso mesmo com CORS */
      }
    }
    return null;
  }

  function extractCanvas(canvas) {
    try {
      return canvas.toDataURL('image/png');
    } catch {
      return null;
    }
  }

  function nextFrame() {
    return new Promise((resolve) => requestAnimationFrame(() => resolve()));
  }

  TI.imageSource = {
    /**
     * @returns {Promise<{dataUrl: string} | {needsCapture: true, rect: object, viewport: object}>}
     */
    async extract(element, { allowCapture = true } = {}) {
      const dataUrl = element.tagName === 'CANVAS' ? extractCanvas(element) : await extractImage(element);
      if (dataUrl) return { dataUrl };
      if (!allowCapture) return { dataUrl: null };

      // Restrição do navegador: o pixel só pode ser lido pela captura da tela.
      element.scrollIntoView({ block: 'center', inline: 'center' });
      await nextFrame();
      const r = element.getBoundingClientRect();
      return {
        needsCapture: true,
        rect: { x: r.left, y: r.top, w: r.width, h: r.height },
        viewport: { w: window.innerWidth, h: window.innerHeight },
      };
    },

    /** Procura o elemento visível cujo src corresponde ao srcUrl do menu de contexto. */
    findBySrc(srcUrl) {
      const candidates = [...document.images].filter((img) => img.currentSrc === srcUrl || img.src === srcUrl);
      if (!candidates.length) return null;
      // Prefere o maior elemento visível (a mesma URL pode aparecer em miniaturas).
      return candidates.sort((a, b) => b.width * b.height - a.width * a.height)[0];
    },
  };
})();
