// Popup: ações rápidas. A lógica pesada fica no service worker (mensagens).

import { getSettings, saveSettings } from '../services/storage/settings.js';
import { TARGET_LANGUAGES, languageInfo } from '../utils/languages.js';

const MAX_FILE_BYTES = 15 * 1024 * 1024;

const $ = (selector) => document.querySelector(selector);
const statusEl = $('#status');

function setStatus(message, kind = '') {
  statusEl.textContent = message;
  statusEl.className = `status ${kind}`.trim();
}

function send(message) {
  return chrome.runtime.sendMessage(message);
}

async function currentTab() {
  const [tab] = await chrome.tabs.query({ active: true, currentWindow: true });
  return tab;
}

function setBusy(busy) {
  document.querySelectorAll('.action').forEach((b) => (b.disabled = busy));
}

async function fillLanguages(settings) {
  const select = $('#target-language');
  select.textContent = '';
  for (const code of TARGET_LANGUAGES) {
    const info = languageInfo(code);
    const option = document.createElement('option');
    option.value = code;
    option.textContent = `${info.flag} ${info.name}`;
    select.appendChild(option);
  }
  select.value = settings.targetLanguage;
}

function renderSettings(settings) {
  $('#opt-auto').checked = settings.autoDetect;
  $('#opt-overlay').checked = settings.showOverlay;
  $('#opt-format').checked = settings.preserveFormatting;
}

async function renderProviderBadge() {
  const status = await send({ type: 'ui:status' });
  const badge = $('#badge-translation');
  const dot = badge.querySelector('.dot');
  dot.className = `dot ${status?.providerReady ? 'ok' : 'off'}`;
  if (status?.providerReady) {
    $('#badge-text').textContent = status.provider === 'libre' ? 'Tradução local disponível' : 'Tradução disponível';
  } else if (status?.provider === 'libre') {
    $('#badge-text').textContent = 'Tradução indisponível (inicie o LibreTranslate)';
  } else {
    $('#badge-text').textContent = 'Tradução indisponível (configure uma chave)';
  }
}

async function renderTabState(tab) {
  if (!tab?.id) return;
  const state = await send({ type: 'ui:action', action: 'status', tabId: tab.id });
  $('#btn-page').setAttribute('aria-pressed', String(Boolean(state?.page)));
  $('#page-label').textContent = state?.page ? 'Desativar tradução da página' : 'Traduzir página inteira';
  $('#opt-hover').checked = Boolean(state?.hover);
}

/** Executa uma ação na aba atual. Em sucesso, fecha o popup (exceto alternâncias). */
async function runAction(action, { closeOnSuccess = true, extra = {} } = {}) {
  const tab = await currentTab();
  if (!tab?.id) {
    setStatus('Não encontrei a aba atual.', 'error');
    return null;
  }
  setBusy(true);
  try {
    const response = await send({ type: 'ui:action', action, tabId: tab.id, ...extra });
    if (!response?.ok) {
      setStatus(response?.message || 'Não foi possível executar esta ação nesta página.', 'error');
      return response;
    }
    if (closeOnSuccess) window.close();
    return response;
  } finally {
    setBusy(false);
  }
}

// ---------- Eventos ----------

$('#btn-image').addEventListener('click', () => runAction('picker'));
$('#btn-area').addEventListener('click', () => runAction('area'));

$('#btn-page').addEventListener('click', async () => {
  const response = await runAction('page-toggle', { closeOnSuccess: false });
  if (response?.ok) {
    setStatus(response.active ? 'Traduzindo a página…' : 'Tradução da página desativada.', 'ok');
    await renderTabState(await currentTab());
  }
});

$('#opt-hover').addEventListener('change', async (event) => {
  const response = await runAction('hover', { closeOnSuccess: false, extra: { enabled: event.target.checked } });
  if (response && !response.ok) event.target.checked = false;
  if (response?.ok) setStatus(response.hover ? 'Passe o mouse sobre uma imagem para ver o botão.' : '', 'ok');
});

$('#target-language').addEventListener('change', (event) => saveSettings({ targetLanguage: event.target.value }));
$('#opt-auto').addEventListener('change', (event) => saveSettings({ autoDetect: event.target.checked }));
$('#opt-overlay').addEventListener('change', (event) => saveSettings({ showOverlay: event.target.checked }));
$('#opt-format').addEventListener('change', (event) => saveSettings({ preserveFormatting: event.target.checked }));

$('#link-settings').addEventListener('click', () => chrome.runtime.openOptionsPage());
$('#link-history').addEventListener('click', () => {
  chrome.tabs.create({ url: chrome.runtime.getURL('options/options.html#historico') });
  window.close();
});

// ---------- Upload por arquivo e arrastar e soltar ----------

const dropzone = $('#dropzone');
const fileInput = $('#file');

dropzone.addEventListener('click', () => fileInput.click());
dropzone.addEventListener('keydown', (event) => {
  if (event.key === 'Enter' || event.key === ' ') {
    event.preventDefault();
    fileInput.click();
  }
});
fileInput.addEventListener('change', () => handleFile(fileInput.files[0]));

['dragenter', 'dragover'].forEach((type) =>
  dropzone.addEventListener(type, (event) => {
    event.preventDefault();
    dropzone.classList.add('dragging');
  }),
);
['dragleave', 'drop'].forEach((type) =>
  dropzone.addEventListener(type, (event) => {
    event.preventDefault();
    dropzone.classList.remove('dragging');
  }),
);
dropzone.addEventListener('drop', (event) => handleFile(event.dataTransfer.files[0]));

function readAsDataUrl(file) {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => resolve(reader.result);
    reader.onerror = () => reject(new Error('Não foi possível ler o arquivo.'));
    reader.readAsDataURL(file);
  });
}

async function handleFile(file) {
  if (!file) return;
  if (!file.type.startsWith('image/')) {
    setStatus('Escolha um arquivo de imagem (PNG, JPG, WebP, GIF ou SVG).', 'error');
    return;
  }
  if (file.size > MAX_FILE_BYTES) {
    setStatus('O arquivo é grande demais. Use uma imagem de até 15 MB.', 'error');
    return;
  }

  setBusy(true);
  setStatus('Lendo o texto da imagem…');
  try {
    const dataUrl = await readAsDataUrl(file);
    const response = await send({ type: 'upload', dataUrl, name: file.name });
    if (response?.ok) {
      // O service worker abre a página de resultados em uma nova aba.
      window.close();
    } else {
      setStatus(response?.message || 'Não foi possível traduzir esta imagem.', 'error');
    }
  } catch (err) {
    setStatus(err.message || 'Erro ao processar a imagem.', 'error');
  } finally {
    setBusy(false);
    fileInput.value = '';
  }
}

// ---------- Inicialização ----------

(async function init() {
  const settings = await getSettings();
  await fillLanguages(settings);
  renderSettings(settings);
  await Promise.all([renderProviderBadge(), renderTabState(await currentTab())]);
})();
