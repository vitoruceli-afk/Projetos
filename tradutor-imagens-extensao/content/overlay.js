// Camada de interface da extensão dentro da página. Fica num shadow DOM fechado
// para o CSS e os scripts do site não alterarem a aparência nem lerem o conteúdo.

(function () {
  const TI = (globalThis.TI = globalThis.TI || {});
  let host = null;
  let shadow = null;
  let stack = null;

  function el(tag, className, text) {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined) node.textContent = text;
    return node;
  }

  function ensureRoot() {
    if (host && host.isConnected) return shadow;

    host = document.createElement('div');
    host.id = 'ti-root';
    host.style.cssText = 'all: initial; position: fixed; top: 0; left: 0; width: 0; height: 0; z-index: 2147483647;';
    (document.documentElement || document.body).appendChild(host);

    shadow = host.attachShadow({ mode: 'closed' });
    applyStyles(shadow);
    stack = el('div', 'ti-stack');
    stack.setAttribute('data-ti', 'stack');
    shadow.appendChild(stack);

    // Tamanho de fonte configurado nas Configurações (acessibilidade).
    chrome.storage.local
      .get('settings')
      .then(({ settings }) => host.style.setProperty('--ti-font-scale', String(settings?.fontScale || 1)))
      .catch(() => {});
    return shadow;
  }

  function applyStyles(root) {
    const css = globalThis.TI_UI_CSS || '';
    try {
      const sheet = new CSSStyleSheet();
      sheet.replaceSync(css);
      root.adoptedStyleSheets = [sheet];
    } catch {
      const style = el('style');
      style.textContent = css;
      root.appendChild(style);
    }
  }

  function getStack() {
    ensureRoot();
    return stack;
  }

  TI.ui = {
    /** Raiz do shadow DOM, para elementos de overlay (seleção, destaque, botão). */
    root() {
      return ensureRoot();
    },

    host() {
      ensureRoot();
      return host;
    },

    toast(message, kind = 'info', ms = 5000) {
      const node = el('div', `ti-toast ti-toast-${kind}`, message);
      node.setAttribute('role', kind === 'error' ? 'alert' : 'status');
      getStack().appendChild(node);
      setTimeout(() => node.remove(), ms);
      return node;
    },

    /** Mostra um indicador de progresso. Chame o retorno para fechar. */
    progress(message) {
      const node = this.toast(message, 'info', 120000);
      return () => node.remove();
    },

    showResult(job, retry, extra = {}) {
      const stackEl = getStack();
      stackEl.querySelectorAll('[data-ti-card]').forEach((n) => n.remove());

      const holder = el('div');
      holder.setAttribute('data-ti-card', '');
      stackEl.appendChild(holder);

      TIResultCard.render(holder, job, {
        onRetry: retry ? () => retry() : null,
        onOpen: () => chrome.runtime.sendMessage({ type: 'ui:open-result', job }),
        onClose: () => holder.remove(),
        ...extra,
      });
    },

    clearResults() {
      if (stack) stack.querySelectorAll('[data-ti-card]').forEach((n) => n.remove());
    },
  };
})();
