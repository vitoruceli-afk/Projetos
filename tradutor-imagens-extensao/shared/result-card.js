// Card de resultado. Usado pelo content script (dentro do shadow DOM) e pela
// página de resultados. Todo texto vindo do OCR ou da tradução é inserido com
// textContent, nunca com innerHTML: é conteúdo não confiável.
//
// Uso: TIResultCard.render(container, job, { onRetry, onClose, onOpen })
// job: { sourceLanguage, targetLanguage, originalText, translatedText, overlayDataUrl, provider }

(function () {
  function el(tag, attrs = {}, children = []) {
    const node = document.createElement(tag);
    for (const [key, value] of Object.entries(attrs)) {
      if (value === undefined || value === null || value === false) continue;
      if (key === 'class') node.className = value;
      else if (key === 'text') node.textContent = value;
      else if (key.startsWith('on') && typeof value === 'function') node.addEventListener(key.slice(2), value);
      else node.setAttribute(key, value === true ? '' : String(value));
    }
    for (const child of [].concat(children)) {
      if (child) node.append(child);
    }
    return node;
  }

  function chip(lang, fallback) {
    if (!lang) return el('span', { class: 'ti-chip', text: fallback });
    return el('span', { class: 'ti-chip', text: `${lang.flag} ${lang.name}` });
  }

  function speak(text, status) {
    if (!('speechSynthesis' in window) || !text) {
      status.textContent = 'Leitura em voz alta não está disponível neste navegador.';
      status.className = 'ti-status ti-status-error';
      return;
    }
    const utterance = new SpeechSynthesisUtterance(text);
    utterance.lang = 'pt-BR';
    window.speechSynthesis.cancel();
    window.speechSynthesis.speak(utterance);
  }

  function copy(text, status, okMessage) {
    const done = () => flash(status, okMessage, 'ok');
    const fail = () => flash(status, 'Não foi possível copiar. Selecione o texto e use Ctrl+C.', 'error');
    if (navigator.clipboard?.writeText) {
      navigator.clipboard.writeText(text).then(done, fail);
    } else {
      fail();
    }
  }

  function flash(status, message, kind) {
    status.textContent = message;
    status.className = `ti-status ${kind === 'error' ? 'ti-status-error' : 'ti-status-ok'}`;
  }

  // Botão que alterna a imagem da página entre a tradução e o original.
  // handlers.onSwapToggle() devolve true quando a tradução está na imagem agora.
  function swapButton(handlers) {
    let showing = Boolean(handlers.swapped);
    const label = (on) => (on ? 'Mostrar original' : 'Mostrar tradução na imagem');
    const button = el('button', {
      class: 'ti-btn',
      type: 'button',
      text: label(showing),
      onclick: () => {
        showing = handlers.onSwapToggle();
        button.textContent = label(showing);
      },
    });
    return button;
  }

  function render(container, job, handlers = {}) {
    container.textContent = '';
    const status = el('p', { class: 'ti-status', role: 'status', 'aria-live': 'polite' });

    const actions = el('div', { class: 'ti-actions' }, [
      el('button', {
        class: 'ti-btn ti-btn-primary',
        type: 'button',
        text: 'Copiar tradução',
        onclick: () => copy(job.translatedText, status, 'Tradução copiada.'),
      }),
      el('button', {
        class: 'ti-btn',
        type: 'button',
        text: 'Copiar texto original',
        onclick: () => copy(job.originalText, status, 'Texto original copiado.'),
      }),
      el('button', {
        class: 'ti-btn',
        type: 'button',
        text: '🔊 Ouvir tradução',
        onclick: () => speak(job.translatedText, status),
      }),
      handlers.onSwapToggle ? swapButton(handlers) : null,
      handlers.onRetry
        ? el('button', {
            class: 'ti-btn',
            type: 'button',
            text: 'Traduzir novamente',
            onclick: () => handlers.onRetry(),
          })
        : null,
      handlers.onOpen
        ? el('button', {
            class: 'ti-btn',
            type: 'button',
            text: 'Abrir em aba',
            onclick: () => handlers.onOpen(),
          })
        : null,
      handlers.onClose
        ? el('button', {
            class: 'ti-btn',
            type: 'button',
            text: 'Fechar',
            onclick: () => handlers.onClose(),
          })
        : null,
    ]);

    const header = el('div', { class: 'ti-header' }, [
      el('h2', { class: 'ti-title', text: 'Tradução' }),
      el('span', { class: 'ti-chip', text: job.provider ? `via ${job.provider}` : '' }),
    ]);

    const langs = el('div', { class: 'ti-langs' }, [
      el('span', { text: 'Idioma detectado:' }),
      chip(job.sourceLanguage, 'Não identificado'),
      el('span', { 'aria-hidden': 'true', text: '→' }),
      chip(job.targetLanguage, 'Português (Brasil)'),
    ]);

    const figure = job.overlayDataUrl
      ? el('figure', { class: 'ti-figure' }, [
          el('img', {
            src: job.overlayDataUrl,
            alt: 'Imagem com a tradução sobreposta',
            onerror: () => {
              flash(
                status,
                'A página bloqueou a exibição da imagem traduzida. Use "Abrir em aba" para vê-la.',
                'error',
              );
            },
          }),
        ])
      : null;

    // Texto reconhecido continua visível mesmo quando a tradução falhou.
    const notice = job.translationError
      ? el('p', { class: 'ti-status ti-status-error', role: 'alert', text: job.translationError })
      : null;

    const card = el('section', {
      class: 'ti-card',
      role: 'dialog',
      'aria-label': 'Resultado da tradução',
      tabindex: '-1',
    }, [
      header,
      langs,
      notice,
      figure,
      el('div', {}, [
        el('div', { class: 'ti-section-label', text: 'Texto original' }),
        el('p', { class: 'ti-text', text: job.originalText || '(sem texto)' }),
      ]),
      el('div', {}, [
        el('div', { class: 'ti-section-label', text: 'Tradução' }),
        el('p', {
          class: 'ti-text ti-text-translated',
          text: job.translatedText || (job.translationError ? '(tradução indisponível)' : '(sem tradução)'),
        }),
      ]),
      actions,
      status,
    ]);

    container.append(card);
    card.focus({ preventScroll: true });

    card.addEventListener('keydown', (event) => {
      if (event.key === 'Escape' && handlers.onClose) handlers.onClose();
    });
  }

  globalThis.TIResultCard = { render };
})();
