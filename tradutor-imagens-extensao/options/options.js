// Página de configurações. Salva automaticamente ao alterar qualquer campo [data-setting].

import { getSettings, saveSettings } from '../services/storage/settings.js';
import { listHistory, deleteHistoryItem, clearHistory } from '../services/storage/history.js';
import { TARGET_LANGUAGES, SOURCE_LANGUAGES, OCR_LANGUAGES, languageInfo } from '../utils/languages.js';

const $ = (selector) => document.querySelector(selector);
const $$ = (selector) => [...document.querySelectorAll(selector)];

const NUMERIC = new Set(['maxImageSide', 'minImageSide', 'historyLimit']);
const DECIMAL = new Set(['fontScale']);

// ---------- Opções de idioma ----------

function fillSelect(select, codes, labelOf) {
  select.textContent = '';
  for (const code of codes) {
    const option = document.createElement('option');
    option.value = code;
    option.textContent = labelOf(code);
    select.appendChild(option);
  }
}

function labelLanguage(code) {
  if (code === 'auto') return 'Detectar automaticamente';
  const info = languageInfo(code);
  return `${info.flag} ${info.name}`;
}

// ---------- Escrita das configurações ----------

let saveTimer = null;

function toast(message, kind = '') {
  const node = $('#toast');
  node.textContent = message;
  node.className = `toast ${kind}`.trim();
  node.hidden = false;
  clearTimeout(toast.timer);
  toast.timer = setTimeout(() => (node.hidden = true), 2400);
}

function readValue(input) {
  if (input.type === 'checkbox') return input.checked;
  if (NUMERIC.has(input.id)) return Number.parseInt(input.value, 10);
  if (DECIMAL.has(input.id)) return Number.parseFloat(input.value);
  return input.value;
}

async function persist(key, value) {
  try {
    await saveSettings({ [key]: value });
    clearTimeout(saveTimer);
    saveTimer = setTimeout(() => toast('Configurações salvas.', 'ok'), 150);
  } catch {
    toast('Não foi possível salvar. Tente novamente.', 'error');
  }
}

function bindSettings(settings) {
  for (const input of $$('[data-setting]')) {
    const value = settings[input.id];
    if (input.type === 'checkbox') input.checked = Boolean(value);
    else input.value = String(value ?? '');

    input.addEventListener('change', () => {
      persist(input.id, readValue(input));
      if (input.id === 'provider') showProvider(input.value);
    });
  }
}

function showProvider(id) {
  for (const block of $$('[data-provider]')) block.hidden = block.dataset.provider !== id;
}

// ---------- OCR: idiomas instalados ----------

async function isInstalled(code) {
  try {
    const response = await fetch(chrome.runtime.getURL(`lang/${code}.traineddata`), { method: 'HEAD' });
    return response.ok;
  } catch {
    return false;
  }
}

async function renderOcrLanguages(settings) {
  const list = $('#ocr-list');
  list.textContent = '';
  const states = await Promise.all(OCR_LANGUAGES.map((l) => isInstalled(l.code)));

  OCR_LANGUAGES.forEach((lang, index) => {
    const installed = states[index];
    const label = document.createElement('label');
    label.className = 'toggle';

    const input = document.createElement('input');
    input.type = 'checkbox';
    input.value = lang.code;
    input.checked = settings.ocrLanguages.includes(lang.code);
    input.disabled = !installed;
    input.addEventListener('change', async () => {
      const selected = $$('#ocr-list input:checked').map((i) => i.value);
      if (!selected.length) {
        input.checked = true;
        toast('Mantenha pelo menos um idioma de OCR.', 'error');
        return;
      }
      await persist('ocrLanguages', selected);
    });

    const text = document.createElement('span');
    text.textContent = `${lang.name} (${lang.code})`;
    const badge = document.createElement('small');
    badge.className = installed ? 'badge ok' : 'badge';
    badge.textContent = installed ? 'instalado' : 'não instalado';

    label.append(input, text, badge);
    list.appendChild(label);
  });
}

// ---------- Teste do provedor ----------

async function testTranslation() {
  const out = $('#test-result');
  out.textContent = 'Testando…';
  out.className = 'muted';
  const response = await chrome.runtime.sendMessage({ type: 'translate:test' });
  if (response?.ok) {
    out.textContent = `OK via ${response.provider}: “${response.sample}”`;
    out.className = 'ok-text';
  } else {
    out.textContent = response?.message || 'Falha no teste.';
    out.className = 'error-text';
  }
}

// ---------- Histórico ----------

function formatDate(ts) {
  return new Date(ts).toLocaleString('pt-BR', { dateStyle: 'short', timeStyle: 'short' });
}

async function renderHistory() {
  const items = await listHistory();
  $('#history-count').textContent = items.length ? `${items.length} item(ns)` : 'Nenhum item salvo.';
  const list = $('#history-list');
  list.textContent = '';

  for (const item of items) {
    const li = document.createElement('li');
    li.className = 'history-item';

    if (item.thumbnail) {
      const img = document.createElement('img');
      img.src = item.thumbnail;
      img.alt = '';
      img.width = 56;
      img.height = 56;
      li.appendChild(img);
    }

    const body = document.createElement('div');
    body.className = 'history-body';

    const meta = document.createElement('div');
    meta.className = 'muted';
    meta.textContent = `${formatDate(item.createdAt)} · ${languageInfo(item.sourceCode)?.name || 'Idioma não identificado'} → ${languageInfo(item.targetCode)?.name || item.targetCode}`;

    const original = document.createElement('p');
    original.className = 'history-original';
    original.textContent = item.original;

    const translated = document.createElement('p');
    translated.className = 'history-translated';
    translated.textContent = item.translated;

    const remove = document.createElement('button');
    remove.type = 'button';
    remove.className = 'btn btn-small';
    remove.textContent = 'Remover';
    remove.addEventListener('click', async () => {
      await deleteHistoryItem(item.id);
      renderHistory();
    });

    body.append(meta, original, translated, remove);
    li.appendChild(body);
    list.appendChild(li);
  }
}

// ---------- Inicialização ----------

(async function init() {
  const settings = await getSettings();

  fillSelect($('#targetLanguage'), TARGET_LANGUAGES, labelLanguage);
  fillSelect($('#sourceLanguage'), SOURCE_LANGUAGES, labelLanguage);
  bindSettings(settings);
  showProvider(settings.provider);

  await renderOcrLanguages(settings);
  await renderHistory();

  $$('[data-toggle-password]').forEach((button) => {
    button.addEventListener('click', () => {
      const input = document.getElementById(button.dataset.togglePassword);
      const showing = input.type === 'text';
      input.type = showing ? 'password' : 'text';
      button.textContent = showing ? 'Mostrar' : 'Ocultar';
    });
  });

  $('#test-translation').addEventListener('click', testTranslation);

  $('#clear-history').addEventListener('click', async () => {
    if (!confirm('Apagar todo o histórico de traduções deste navegador?')) return;
    await clearHistory();
    renderHistory();
    toast('Histórico limpo.', 'ok');
  });

  // Mostra o link #historico com foco visível no cabeçalho da seção.
  if (location.hash) document.querySelector(location.hash)?.scrollIntoView({ block: 'start' });
})();
