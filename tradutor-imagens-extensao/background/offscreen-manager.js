// Cria o documento offscreen sob demanda e o fecha depois de um tempo ocioso,
// para não manter o Tesseract (WASM + Worker) residente sem uso.

import { TIError } from '../utils/errors.js';

const DOCUMENT_PATH = 'offscreen/offscreen.html';
const CLOSE_ALARM = 'ti-close-offscreen';
const CLOSE_AFTER_MINUTES = 2;

let creating = null;

async function hasOffscreenDocument() {
  if (!chrome.runtime.getContexts) return false;
  const contexts = await chrome.runtime.getContexts({
    contextTypes: ['OFFSCREEN_DOCUMENT'],
    documentUrls: [chrome.runtime.getURL(DOCUMENT_PATH)],
  });
  return contexts.length > 0;
}

async function ensureOffscreenDocument() {
  if (await hasOffscreenDocument()) return;
  if (!creating) {
    creating = chrome.offscreen
      .createDocument({
        url: DOCUMENT_PATH,
        reasons: ['WORKERS', 'BLOBS'],
        justification: 'Executar OCR local (Tesseract.js) em Web Worker e processar imagens.',
      })
      .finally(() => {
        creating = null;
      });
  }
  await creating;
}

/** Envia uma mensagem ao documento offscreen e converte erros em TIError. */
export async function callOffscreen(message) {
  await ensureOffscreenDocument();
  const response = await chrome.runtime.sendMessage({ target: 'offscreen', ...message });
  scheduleClose();

  if (!response) throw new TIError('ocr-failed', 'Sem resposta do documento offscreen');
  if (!response.ok) throw new TIError(response.code, response.message);
  return response.data;
}

function scheduleClose() {
  chrome.alarms.create(CLOSE_ALARM, { delayInMinutes: CLOSE_AFTER_MINUTES });
}

export async function closeOffscreenDocument() {
  if (await hasOffscreenDocument()) {
    await chrome.offscreen.closeDocument().catch(() => {});
  }
}
