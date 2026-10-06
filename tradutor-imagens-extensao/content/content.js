// Ponto de entrada dos content scripts. Injetado pelo service worker apenas na
// aba em que o usuário pediu uma tradução (activeTab), nunca em todas as páginas.

(function () {
  if (globalThis.__TI_READY) return;
  globalThis.__TI_READY = true;

  const TI = globalThis.TI;

  /**
   * Mostra o resultado. Quando há um <img> de origem e a sobreposição está ligada,
   * a tradução já entra no lugar da imagem original; o card permite voltar ao original.
   */
  /**
   * mode 'src': a tradução substitui o src da imagem (quando o navegador deixa ler o pixel).
   * mode 'capture': a imagem tem CORS; a tradução é sobreposta na posição dela, sem alterar o site.
   */
  function present(response, retry, element, mode = 'src') {
    if (!response?.ok) {
      TI.ui.toast(response?.message || 'Não foi possível traduzir esta imagem.', 'error', 7000);
      return;
    }
    const job = response.job;
    if (!element || !job.overlayDataUrl) {
      TI.ui.showResult(job, retry);
      return;
    }

    const useSrc = mode === 'src';
    const show = () =>
      useSrc ? TI.imageSwap.apply(element, job.overlayDataUrl) : TI.imageSwap.overlayOn(element, job.overlayDataUrl);
    const isOn = () => (useSrc ? TI.imageSwap.isSwapped(element) : TI.imageSwap.isOverlaid(element));
    const hide = () => (useSrc ? TI.imageSwap.restore(element) : TI.imageSwap.overlayOff(element));

    if (!show()) {
      TI.ui.showResult(job, retry);
      return;
    }
    TI.ui.showResult(job, retry, {
      swapped: true,
      onSwapToggle: () => {
        if (isOn()) {
          hide();
          return false;
        }
        show();
        return true;
      },
    });
  }

  /** Lê a imagem do elemento e envia para a pipeline (ou pede captura quando o navegador bloqueia). */
  async function translateElement(element) {
    try {
      const source = await TI.imageSource.extract(element);
      if (source.dataUrl) return await translateDataUrl(source.dataUrl, 'page', element);
      return await captureAndTranslate(source.rect, source.viewport, element);
    } catch (err) {
      TI.ui.toast(err.message || 'Erro ao processar a imagem.', 'error', 7000);
    }
  }

  async function translateDataUrl(dataUrl, source, element) {
    const hide = TI.ui.progress('Lendo o texto da imagem…');
    try {
      const response = await chrome.runtime.sendMessage({ type: 'ti:run-image', dataUrl, source });
      present(response, () => translateDataUrl(dataUrl, source, element), element);
    } finally {
      hide();
    }
  }

  async function captureAndTranslate(rect, viewport, element = null) {
    const hide = TI.ui.progress('Capturando a área da tela…');
    try {
      const response = await chrome.runtime.sendMessage({ type: 'ti:run-capture', rect, viewport });
      present(response, () => captureAndTranslate(rect, viewport, element), element, 'capture');
    } finally {
      hide();
    }
  }

  function startArea() {
    TI.selector.start((rect, viewport) => captureAndTranslate(rect, viewport));
  }

  function startPicker() {
    TI.picker.start((img) => translateElement(img));
  }

  async function handle(message) {
    switch (message.type) {
      case 'ti:start-area':
        startArea();
        return { ok: true };

      case 'ti:start-picker':
        startPicker();
        return { ok: true };

      case 'ti:hover-toggle': {
        const enabled = Boolean(message.enabled);
        TI.hover.set(enabled, (img) => translateElement(img), { minPx: 40 });
        return { ok: true, enabled };
      }

      case 'ti:page-toggle':
        return { ok: true, active: TI.pageTranslator.toggle() };

      case 'ti:extract-src': {
        const element = TI.imageSource.findBySrc(message.srcUrl);
        if (!element) return { ok: false, notFound: true };
        const source = await TI.imageSource.extract(element);
        return source.dataUrl
          ? { ok: true, dataUrl: source.dataUrl }
          : { ok: true, needsCapture: true, rect: source.rect, viewport: source.viewport };
      }

      case 'ti:show-result': {
        // Resultado do menu de contexto: localiza a imagem pelo src para trocá-la na página.
        const element = message.srcUrl ? TI.imageSource.findBySrc(message.srcUrl) : null;
        present({ ok: true, job: message.job }, null, element, message.captured ? 'capture' : 'src');
        return { ok: true };
      }

      case 'ti:show-error':
        TI.ui.toast(message.message, 'error', 7000);
        return { ok: true };

      default:
        return { ok: false };
    }
  }

  chrome.runtime.onMessage.addListener((message, _sender, sendResponse) => {
    if (!message?.type?.startsWith('ti:')) return false;
    handle(message).then(sendResponse, (err) => sendResponse({ ok: false, message: err?.message }));
    return true;
  });
})();
