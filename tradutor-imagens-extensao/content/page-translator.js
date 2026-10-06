// Tradução da página inteira, de forma reversível.
//   - Textos HTML: troca só o nodeValue dos nós de texto. Guarda o original num Map
//     e restaura ao desativar. Espaços em volta do texto são preservados.
//   - Imagens com texto: guarda src/srcset originais, troca pela versão com a
//     tradução sobreposta e restaura ao desativar.
// Limites para não sobrecarregar a API nem a CPU: nós, caracteres e imagens por rodada.
// Um MutationObserver traduz só o conteúdo novo (SPA, lazy loading), com debounce.

(function () {
  const TI = (globalThis.TI = globalThis.TI || {});

  const SKIP_TAGS = new Set(['SCRIPT', 'STYLE', 'NOSCRIPT', 'CODE', 'PRE', 'TEXTAREA', 'INPUT', 'SELECT', 'SVG', 'MATH', 'TEMPLATE']);
  const MAX_NODES_PER_RUN = 400;
  const MAX_CHARS_PER_RUN = 20000;
  const BATCH_SIZE = 40;
  const MAX_IMAGES_PER_RUN = 6;
  const MIN_IMAGE_BOX = { w: 120, h: 80 };
  const DEBOUNCE_MS = 800;

  const originals = new Map(); // Text node -> texto original
  const imageOriginals = new Map(); // img -> { src, srcset }
  let active = false;
  let keepWhitespace = true; // settings.preserveFormatting
  let observer = null;
  let debounceTimer = null;
  const pendingRoots = new Set();

  function sendMessage(message) {
    return chrome.runtime.sendMessage(message).then((response) => {
      if (!response?.ok) throw new Error(response?.message || 'Falha na tradução');
      return response;
    });
  }

  function isVisible(node) {
    const parent = node.parentElement;
    return Boolean(parent && parent.getClientRects().length);
  }

  function translatableTextNodes(root) {
    const nodes = [];
    const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, {
      acceptNode(node) {
        const parent = node.parentElement;
        if (!parent || SKIP_TAGS.has(parent.tagName) || parent.isContentEditable) return NodeFilter.FILTER_REJECT;
        if (originals.has(node)) return NodeFilter.FILTER_REJECT;
        const text = node.nodeValue.trim();
        if (text.length < 2 || !/\p{L}/u.test(text)) return NodeFilter.FILTER_REJECT;
        return NodeFilter.FILTER_ACCEPT;
      },
    });
    let node = walker.nextNode();
    while (node) {
      if (isVisible(node)) nodes.push(node);
      node = walker.nextNode();
    }
    return nodes;
  }

  async function translateNodes(nodes, budget) {
    let translated = 0;
    let chars = 0;
    const picked = [];
    for (const node of nodes) {
      if (picked.length >= budget.nodes || chars >= MAX_CHARS_PER_RUN) break;
      picked.push(node);
      chars += node.nodeValue.length;
    }

    for (let i = 0; i < picked.length && active; i += BATCH_SIZE) {
      const batch = picked.slice(i, i + BATCH_SIZE);
      const texts = batch.map((n) => n.nodeValue.trim());
      const { translations } = await sendMessage({ type: 'ti:translate-texts', texts });
      // Desativado durante a chamada: não aplica resultados atrasados.
      if (!active) break;

      batch.forEach((node, index) => {
        const value = node.nodeValue;
        const lead = keepWhitespace ? value.match(/^\s*/)[0] : '';
        const trail = keepWhitespace ? value.match(/\s*$/)[0] : '';
        const replacement = translations[index];
        if (!replacement || !node.isConnected) return;
        originals.set(node, value);
        node.nodeValue = lead + replacement + trail;
        translated++;
      });
    }
    budget.nodes -= picked.length;
    return translated;
  }

  function translatableImages() {
    return [...document.images].filter((img) => {
      if (!img.complete || !img.naturalWidth || imageOriginals.has(img)) return false;
      const r = img.getBoundingClientRect();
      return r.width >= MIN_IMAGE_BOX.w && r.height >= MIN_IMAGE_BOX.h && r.bottom > 0 && r.top < window.innerHeight;
    });
  }

  async function translateImages(limit) {
    let done = 0;
    let skipped = 0;
    for (const img of translatableImages().slice(0, limit)) {
      if (!active) break;
      // allowCapture: false, porque a captura rolaria a página sem o pedido do usuário.
      const source = await TI.imageSource.extract(img, { allowCapture: false });
      if (!source.dataUrl) {
        skipped++;
        continue;
      }
      const response = await chrome.runtime.sendMessage({
        type: 'ti:run-image',
        dataUrl: source.dataUrl,
        source: 'page',
        overlay: true,
      });
      if (!response?.ok) {
        skipped++;
        continue;
      }
      if (response.job?.overlayDataUrl && img.isConnected) {
        imageOriginals.set(img, { src: img.getAttribute('src'), srcset: img.getAttribute('srcset') });
        img.removeAttribute('srcset');
        img.setAttribute('src', response.job.overlayDataUrl);
        img.dataset.tiTranslated = '1';
        done++;
      }
    }
    return { done, skipped };
  }

  function restoreImages() {
    for (const [img, saved] of imageOriginals) {
      if (!img.isConnected) continue;
      if (saved.src !== null) img.setAttribute('src', saved.src);
      if (saved.srcset !== null) img.setAttribute('srcset', saved.srcset);
      delete img.dataset.tiTranslated;
    }
    imageOriginals.clear();
  }

  function restoreText() {
    for (const [node, original] of originals) {
      if (node.isConnected) node.nodeValue = original;
    }
    originals.clear();
  }

  function scheduleIncremental() {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(async () => {
      const roots = [...pendingRoots];
      pendingRoots.clear();
      if (!active) return;
      const nodes = roots.flatMap((root) => (root.isConnected ? translatableTextNodes(root) : []));
      if (nodes.length) {
        try {
          await translateNodes(nodes, { nodes: 150 });
        } catch {
          /* falha de rede: o conteúdo novo continua no idioma original */
        }
      }
    }, DEBOUNCE_MS);
  }

  function startObserver() {
    observer = new MutationObserver((mutations) => {
      for (const mutation of mutations) {
        for (const node of mutation.addedNodes) {
          if (node.nodeType === Node.ELEMENT_NODE && !node.closest?.('#ti-root')) pendingRoots.add(node);
          else if (node.nodeType === Node.TEXT_NODE && node.parentElement) pendingRoots.add(node.parentElement);
        }
      }
      if (pendingRoots.size) scheduleIncremental();
    });
    observer.observe(document.body, { childList: true, subtree: true });
  }

  async function enable() {
    const hide = TI.ui.progress('Traduzindo a página…');
    try {
      const { settings } = await chrome.storage.local.get('settings');
      keepWhitespace = settings?.preserveFormatting !== false;

      const budget = { nodes: MAX_NODES_PER_RUN };
      const texts = await translateNodes(translatableTextNodes(document.body), budget);
      const images = await translateImages(MAX_IMAGES_PER_RUN);
      hide();

      if (!active) return;
      startObserver();
      const parts = [`${texts} trechos traduzidos`];
      if (images.done) parts.push(`${images.done} imagem(ns) com tradução`);
      if (images.skipped) parts.push(`${images.skipped} imagem(ns) não puderam ser acessadas`);
      TI.ui.toast(`${parts.join(' · ')}.`, 'success', 7000);
    } catch (err) {
      hide();
      active = false;
      restoreText();
      restoreImages();
      TI.ui.toast(err.message || 'Não foi possível traduzir a página.', 'error', 7000);
    }
  }

  TI.pageTranslator = {
    isActive() {
      return active;
    },

    /** Liga ou desliga. Retorna o novo estado de imediato; o trabalho continua em segundo plano. */
    toggle() {
      if (active) {
        active = false;
        observer?.disconnect();
        observer = null;
        clearTimeout(debounceTimer);
        pendingRoots.clear();
        restoreText();
        restoreImages();
        TI.ui.toast('Tradução da página desativada. O conteúdo original foi restaurado.', 'info');
        return false;
      }
      active = true;
      enable();
      return true;
    },
  };
})();
