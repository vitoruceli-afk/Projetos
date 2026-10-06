// Host de OCR e de imagem. Recebe mensagens do service worker (target: 'offscreen').

import { TesseractEngine } from '../services/ocr/tesseract-engine.js';
import * as IMG from '../services/image/image-processing.js';
import { TIError } from '../utils/errors.js';

const MIN_CONFIDENCE = 25; // linhas abaixo disso são descartadas como ruído
const RETRY_BELOW = 60; // média de confiança que dispara a segunda tentativa binarizada
const ILLEGIBLE_BELOW = 25;

const engines = {
  tesseract: null,
};

function getEngine(id = 'tesseract') {
  if (!engines[id]) engines[id] = new TesseractEngine();
  return engines[id];
}

chrome.runtime.onMessage.addListener((message, _sender, sendResponse) => {
  if (message?.target !== 'offscreen') return false;

  handle(message).then(
    (data) => sendResponse({ ok: true, data }),
    (err) => {
      console.warn('[TI offscreen]', err);
      sendResponse({ ok: false, code: err?.code || 'ocr-failed', message: err?.message });
    },
  );
  return true; // resposta assíncrona
});

async function handle(message) {
  switch (message.type) {
    case 'crop':
      return { dataUrl: await IMG.cropScreenshot(message.dataUrl, message.rect, message.viewport) };
    case 'ocr':
      return runOcr(message);
    case 'overlay':
      return { dataUrl: await renderOverlay(message) };
    default:
      throw new Error(`Tipo de mensagem desconhecido: ${message.type}`);
  }
}

async function runOcr({ dataUrl, opts }) {
  const base = await IMG.loadBase(dataUrl, opts.maxSide);
  if (Math.min(base.width, base.height) < opts.minSide) throw new TIError('image-too-small');

  const engine = getEngine();
  const langs = opts.langs?.length ? opts.langs : ['eng', 'por'];

  const prepared = IMG.prepareForOcr(base.canvas, { enhance: opts.enhance });
  let best = scoreResult(await engine.recognize(prepared.canvas, { langs }), prepared.scale);

  if (opts.enhance && best.avgConfidence < RETRY_BELOW) {
    const binary = IMG.binarize(prepared.canvas);
    const second = scoreResult(await engine.recognize(binary, { langs }), prepared.scale);
    if (second.avgConfidence > best.avgConfidence) best = second;
  }

  if (!best.lines.length) throw new TIError('ocr-empty');
  if (best.avgConfidence < ILLEGIBLE_BELOW) throw new TIError('illegible');

  return {
    lines: best.lines,
    width: base.width,
    height: base.height,
    thumbnail: IMG.thumbnail(base.canvas),
  };
}

/** Converte linhas para o espaço base e calcula a confiança média ponderada pelo tamanho do texto. */
function scoreResult(result, scale) {
  const lines = result.lines
    .filter((l) => l.confidence >= MIN_CONFIDENCE && /[\p{L}\p{N}]/u.test(l.text))
    .map((l) => ({
      text: l.text,
      confidence: Math.round(l.confidence),
      bbox: {
        x0: l.bbox.x0 / scale,
        y0: l.bbox.y0 / scale,
        x1: l.bbox.x1 / scale,
        y1: l.bbox.y1 / scale,
      },
    }));

  const chars = lines.reduce((sum, l) => sum + l.text.length, 0);
  const weighted = lines.reduce((sum, l) => sum + l.confidence * l.text.length, 0);
  return { lines, avgConfidence: chars ? weighted / chars : 0 };
}

async function renderOverlay({ dataUrl, opts, lines }) {
  const base = await IMG.loadBase(dataUrl, opts.maxSide);
  return IMG.drawTranslationOverlay(base.canvas, lines).toDataURL('image/png');
}
