// Service worker (Manifest V3): roteia mensagens, menus de contexto, atalhos e
// injeção dos content scripts sob demanda.
//
// Permissões: activeTab (concedida por clique no ícone, atalho ou menu de contexto)
// em vez de acesso permanente a todos os sites. Os content scripts só são injetados
// na aba em que o usuário pediu a tradução.

import { getSettings } from '../services/storage/settings.js';
import { listHistory } from '../services/storage/history.js';
import { isProviderConfigured } from '../services/translation/translation-provider.js';
import {
  captureArea,
  runImageJob,
  saveJobForPage,
  loadJobForPage,
  translateTextsOnly,
  testTranslation,
} from './pipeline.js';
import { closeOffscreenDocument } from './offscreen-manager.js';
import { TIError, toUserError } from '../utils/errors.js';

const CONTENT_FILES = [
  'shared/ui-styles.js',
  'shared/result-card.js',
  'content/overlay.js',
  'content/selector.js',
  'content/image-source.js',
  'content/image-swap.js',
  'content/image-detector.js',
  'content/page-translator.js',
  'content/content.js',
];

const MENU = {
  image: 'ti-menu-image',
  area: 'ti-menu-area',
  page: 'ti-menu-page',
};

const CLOSE_ALARM = 'ti-close-offscreen';
const MAX_UPLOAD_BYTES = 15 * 1024 * 1024;

// ---------- Instalação: menus de contexto ----------

chrome.runtime.onInstalled.addListener(() => {
  chrome.contextMenus.removeAll(() => {
    chrome.contextMenus.create({ id: MENU.image, title: 'Traduzir imagem para Português', contexts: ['image'] });
    chrome.contextMenus.create({ id: MENU.area, title: 'Traduzir área selecionada', contexts: ['page'] });
    chrome.contextMenus.create({ id: MENU.page, title: 'Traduzir página inteira', contexts: ['page'] });
  });
});

chrome.contextMenus.onClicked.addListener((info, tab) => {
  if (!tab?.id) return;
  if (info.menuItemId === MENU.image) translateFromContext(info, tab);
  if (info.menuItemId === MENU.area) startArea(tab);
  if (info.menuItemId === MENU.page) togglePage(tab);
});

// ---------- Atalhos ----------

chrome.commands.onCommand.addListener(async (command) => {
  const [tab] = await chrome.tabs.query({ active: true, currentWindow: true });
  if (!tab?.id) return;
  if (command === 'translate-area') startArea(tab);
  if (command === 'translate-image') startPicker(tab);
});

// ---------- Estado por aba (sessão) e limpeza ----------

async function getTabStates() {
  const { tabState } = await chrome.storage.session.get('tabState');
  return tabState || {};
}

async function setTabState(tabId, patch) {
  const all = await getTabStates();
  all[tabId] = { ...(all[tabId] || {}), ...patch };
  await chrome.storage.session.set({ tabState: all });
}

chrome.tabs.onRemoved.addListener(async (tabId) => {
  const all = await getTabStates();
  delete all[tabId];
  await chrome.storage.session.set({ tabState: all });
});

chrome.tabs.onUpdated.addListener(async (tabId, change) => {
  // Navegação recarrega o content script: o estado anterior deixa de valer.
  if (change.status === 'loading' && change.url) await setTabState(tabId, { page: false, hover: false });
});

chrome.alarms.onAlarm.addListener((alarm) => {
  if (alarm.name === CLOSE_ALARM) closeOffscreenDocument();
});

// ---------- Injeção e comunicação com a página ----------

const RESTRICTED_URL =
  /^(chrome|chrome-extension|edge|about|devtools|view-source):|^https:\/\/(chromewebstore\.google\.com|chrome\.google\.com\/webstore)/;

function isRestrictedUrl(url) {
  return Boolean(url) && RESTRICTED_URL.test(url);
}

/** Garante que os content scripts estão carregados no frame indicado. */
async function ensureContent(tab, frameId = 0) {
  if (isRestrictedUrl(tab.url)) throw new TIError('page-blocked');
  const target = { tabId: tab.id, frameIds: [frameId] };
  try {
    const [probe] = await chrome.scripting.executeScript({ target, func: () => Boolean(window.__TI_READY) });
    if (probe?.result) return;
    await chrome.scripting.executeScript({ target, files: CONTENT_FILES });
  } catch {
    throw new TIError('page-blocked');
  }
}

function sendToTab(tabId, message, frameId = 0) {
  return chrome.tabs.sendMessage(tabId, message, { frameId }).catch(() => {
    throw new TIError('page-blocked');
  });
}

/** Mostra um resultado ou erro dentro da página (sobreposição, sem abrir abas). */
async function notifyPage(tab, message) {
  await ensureContent(tab, 0);
  await sendToTab(tab.id, message, 0);
}

async function finishInPage(tab, work, srcUrl, captured = false) {
  try {
    const job = await work();
    await notifyPage(tab, { type: 'ti:show-result', job, srcUrl, captured });
  } catch (err) {
    const { message } = toUserError(err);
    await notifyPage(tab, { type: 'ti:show-error', message }).catch(() => {});
  }
}

// ---------- Ações iniciadas pelo usuário ----------

async function startArea(tab) {
  try {
    await ensureContent(tab, 0);
    await sendToTab(tab.id, { type: 'ti:start-area' });
  } catch (err) {
    await notifyError(tab, err);
  }
}

async function startPicker(tab) {
  try {
    await ensureContent(tab, 0);
    await sendToTab(tab.id, { type: 'ti:start-picker' });
  } catch (err) {
    await notifyError(tab, err);
  }
}

async function notifyError(tab, err) {
  const { message } = toUserError(err);
  console.warn('[TI]', err);
  if (!tab?.id) return;
  // Em páginas bloqueadas não há como exibir um aviso dentro da página: marca o ícone.
  const shown = await chrome.tabs.sendMessage(tab.id, { type: 'ti:show-error', message }).then(
    () => true,
    () => false,
  );
  if (!shown) {
    chrome.action.setBadgeBackgroundColor({ tabId: tab.id, color: '#1a66e0' });
    chrome.action.setBadgeText({ tabId: tab.id, text: '!' });
    setTimeout(() => chrome.action.setBadgeText({ tabId: tab.id, text: '' }).catch(() => {}), 6000);
  }
}

/** Alterna a tradução da página. Lança erro para quem chamou (popup mostra a mensagem). */
async function togglePageOrThrow(tab) {
  await ensureContent(tab, 0);
  const response = await sendToTab(tab.id, { type: 'ti:page-toggle' });
  const active = Boolean(response?.active);
  await setTabState(tab.id, { page: active });
  return active;
}

async function togglePage(tab) {
  try {
    return await togglePageOrThrow(tab);
  } catch (err) {
    await notifyError(tab, err);
    return false;
  }
}

async function translateFromContext(info, tab) {
  const frameId = info.frameId ?? 0;
  try {
    await ensureContent(tab, frameId);
    const probe = await sendToTab(tab.id, { type: 'ti:extract-src', srcUrl: info.srcUrl }, frameId);

    if (probe?.dataUrl) {
      return finishInPage(tab, () => runImageJob({ dataUrl: probe.dataUrl, source: 'context' }), info.srcUrl);
    }
    if (probe?.needsCapture) {
      return finishInPage(
        tab,
        async () => {
          const dataUrl = await captureArea(tab.windowId, probe.rect, probe.viewport);
          return runImageJob({ dataUrl, source: 'area' });
        },
        info.srcUrl,
        true,
      );
    }
    // Imagem não encontrada no DOM (ex.: CSS background): tenta baixar diretamente.
    return finishInPage(
      tab,
      async () => {
        const dataUrl = await fetchAsDataUrl(info.srcUrl).catch(() => {
          throw new TIError('cors-blocked');
        });
        return runImageJob({ dataUrl, source: 'context' });
      },
      null,
    );
  } catch (err) {
    await notifyError(tab, err);
  }
}

async function fetchAsDataUrl(url) {
  const response = await fetch(url, { credentials: 'omit' });
  if (!response.ok) throw new TIError('fetch-failed');
  const blob = await response.blob();
  if (!blob.type.startsWith('image/')) throw new TIError('not-an-image');
  if (blob.size > MAX_UPLOAD_BYTES) throw new TIError('file-too-large');
  return blobToDataUrl(blob);
}

async function blobToDataUrl(blob) {
  const bytes = new Uint8Array(await blob.arrayBuffer());
  let binary = '';
  const CHUNK = 0x8000;
  for (let i = 0; i < bytes.length; i += CHUNK) {
    binary += String.fromCharCode(...bytes.subarray(i, i + CHUNK));
  }
  return `data:${blob.type};base64,${btoa(binary)}`;
}

async function handleUpload({ dataUrl }) {
  if (typeof dataUrl !== 'string' || !dataUrl.startsWith('data:image/')) throw new TIError('not-an-image');
  const job = await runImageJob({ dataUrl, source: 'upload' });
  await saveJobForPage(job, dataUrl);
  await chrome.tabs.create({ url: chrome.runtime.getURL(`results/result.html?id=${job.id}`) });
  return { ok: true, id: job.id };
}

async function handleRetry({ id }) {
  const stored = await loadJobForPage(id);
  if (!stored?.input) throw new TIError('retry-unavailable');
  const job = await runImageJob({ dataUrl: stored.input, source: stored.job.source });
  await saveJobForPage(job, stored.input);
  return { ok: true, job, id: job.id };
}

/** Ações disparadas pelo popup. O popup não tem a aba ativa como parâmetro: recebe tabId. */
async function handleUiAction(msg) {
  const tab = await chrome.tabs.get(msg.tabId);

  switch (msg.action) {
    case 'picker':
      await ensureContent(tab, 0);
      await sendToTab(tab.id, { type: 'ti:start-picker' });
      return { ok: true };

    case 'area':
      await ensureContent(tab, 0);
      await sendToTab(tab.id, { type: 'ti:start-area' });
      return { ok: true };

    case 'page-toggle': {
      const active = await togglePageOrThrow(tab);
      return { ok: true, active };
    }

    case 'hover': {
      const enabled = Boolean(msg.enabled);
      await ensureContent(tab, 0);
      await sendToTab(tab.id, { type: 'ti:hover-toggle', enabled });
      await setTabState(tab.id, { hover: enabled });
      return { ok: true, hover: enabled };
    }

    case 'status': {
      const state = (await getTabStates())[tab.id] || {};
      return { ok: true, page: Boolean(state.page), hover: Boolean(state.hover) };
    }

    default:
      throw new TIError('unknown');
  }
}

/** Verifica se o LibreTranslate responde (timeout curto para não travar o popup). */
async function isLibreReachable(url) {
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), 1500);
  try {
    const response = await fetch(`${url.replace(/\/+$/, '')}/languages`, { signal: controller.signal });
    return response.ok;
  } catch {
    return false;
  } finally {
    clearTimeout(timer);
  }
}

async function handleStatus() {
  const settings = await getSettings();
  const history = await listHistory();
  const configured = isProviderConfigured(settings);
  const reachable = configured && settings.provider === 'libre' ? await isLibreReachable(settings.libreUrl) : configured;
  return {
    ok: true,
    providerConfigured: configured,
    providerReady: reachable,
    provider: settings.provider,
    historyCount: history.length,
  };
}

// ---------- Roteador de mensagens ----------

chrome.runtime.onMessage.addListener((msg, sender, sendResponse) => {
  route(msg, sender).then(sendResponse, (err) => {
    console.warn('[TI]', err);
    sendResponse(toUserError(err));
  });
  return true; // resposta assíncrona
});

async function route(msg, sender) {
  switch (msg?.type) {
    // Vindos das páginas (content scripts)
    case 'ti:run-image': {
      const job = await runImageJob({ dataUrl: msg.dataUrl, source: msg.source || 'page', overlay: msg.overlay });
      return { ok: true, job };
    }
    case 'ti:run-capture': {
      const dataUrl = await captureArea(sender.tab.windowId, msg.rect, msg.viewport);
      const job = await runImageJob({ dataUrl, source: 'area' });
      return { ok: true, job };
    }
    case 'ti:translate-texts':
      return { ok: true, translations: await translateTextsOnly(msg.texts) };
    case 'ui:open-result': {
      await saveJobForPage(msg.job, null);
      await chrome.tabs.create({ url: chrome.runtime.getURL(`results/result.html?id=${msg.job.id}`) });
      return { ok: true };
    }

    // Vindos do popup, da página de configurações ou da página de resultados
    case 'ui:action':
      return handleUiAction(msg);
    case 'ui:status':
      return handleStatus();
    case 'upload':
      return handleUpload(msg);
    case 'job:retry':
      return handleRetry(msg);
    case 'translate:test':
      return { ok: true, ...(await testTranslation()) };
    default:
      throw new TIError('unknown', `Mensagem desconhecida: ${msg?.type}`);
  }
}
