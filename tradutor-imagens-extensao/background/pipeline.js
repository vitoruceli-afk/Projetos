// Pipeline de tradução: captura -> OCR -> tradução -> sobreposição -> histórico.
// Cada etapa tem uma responsabilidade única; trocar OCR ou tradução não afeta as demais.

import { getSettings } from '../services/storage/settings.js';
import { addHistoryItem } from '../services/storage/history.js';
import { createTranslationProvider } from '../services/translation/translation-provider.js';
import { callOffscreen } from './offscreen-manager.js';
import { TIError, userMessage } from '../utils/errors.js';
import { languageInfo } from '../utils/languages.js';

/** Captura a aba visível e recorta a área pedida (coordenadas da viewport). */
export async function captureArea(windowId, rect, viewport) {
  let screenshot;
  try {
    screenshot = await chrome.tabs.captureVisibleTab(windowId, { format: 'png' });
  } catch (err) {
    // Sem permissão activeTab (ex.: clique feito dentro da própria página).
    if (/activeTab|permission|permiss/i.test(err?.message || '')) throw new TIError('capture-activetab');
    throw new TIError('capture-failed', err?.message);
  }
  const { dataUrl } = await callOffscreen({ type: 'crop', dataUrl: screenshot, rect, viewport });
  return dataUrl;
}

/**
 * Executa a pipeline completa para uma imagem.
 * @param {{ dataUrl: string, source: string, overlay?: boolean }} input
 */
export async function runImageJob({ dataUrl, source = 'page', overlay }) {
  const settings = await getSettings();
  const showOverlay = overlay ?? settings.showOverlay;

  const ocr = await callOffscreen({
    type: 'ocr',
    dataUrl,
    opts: {
      langs: settings.ocrLanguages,
      enhance: settings.enhanceImage,
      maxSide: settings.maxImageSide,
      minSide: settings.minImageSide,
    },
  });

  const texts = ocr.lines.map((l) => l.text);
  let translation;
  let translationError = null;
  try {
    translation = await translateTexts(texts, settings);
  } catch (err) {
    // Falha de tradução não descarta o OCR: o texto reconhecido continua disponível com o aviso.
    if (!(err instanceof TIError)) throw err;
    translationError = userMessage(err.code);
    translation = { translations: texts.map(() => ''), sourceCode: null, providerName: null };
  }
  const { translations, sourceCode, providerName } = translation;

  const lines = ocr.lines.map((line, i) => ({ original: line.text, translated: translations[i] }));

  let overlayDataUrl = null;
  if (showOverlay && !translationError) {
    const rendered = await callOffscreen({
      type: 'overlay',
      dataUrl,
      opts: { maxSide: settings.maxImageSide },
      lines: ocr.lines.map((line, i) => ({ bbox: line.bbox, translated: translations[i] })),
    });
    overlayDataUrl = rendered.dataUrl;
  }

  const job = {
    id: crypto.randomUUID(),
    createdAt: Date.now(),
    source,
    sourceLanguage: languageInfo(sourceCode),
    targetLanguage: languageInfo(settings.targetLanguage),
    originalText: lines.map((l) => l.original).join('\n'),
    translatedText: lines.map((l) => l.translated).join('\n'),
    lines,
    overlayDataUrl,
    translationError,
    thumbnail: ocr.thumbnail,
    provider: providerName,
    width: ocr.width,
    height: ocr.height,
  };

  if (settings.historyEnabled) {
    await addHistoryItem(
      {
        id: job.id,
        createdAt: job.createdAt,
        source,
        sourceCode,
        targetCode: settings.targetLanguage,
        original: job.originalText,
        translated: job.translatedText,
        thumbnail: job.thumbnail,
        provider: providerName,
      },
      settings.historyLimit,
    );
  }
  return job;
}

/** Tradução de textos soltos (página inteira). Não usa OCR. */
export async function translateTextsOnly(texts) {
  const settings = await getSettings();
  const { translations } = await translateTexts(texts, settings);
  return translations;
}

export async function testTranslation() {
  const settings = await getSettings();
  const { translations, providerName } = await translateTexts(
    ['Hello, welcome to our website.'],
    { ...settings, autoDetect: false, sourceLanguage: 'en' },
  );
  return { sample: translations[0], provider: providerName };
}

async function translateTexts(texts, settings) {
  if (!texts.length) return { translations: [], sourceCode: null, providerName: null };

  const provider = createTranslationProvider(settings);
  const source = settings.autoDetect ? 'auto' : settings.sourceLanguage || 'auto';
  const { translations, detectedSource } = await provider.translate(texts, {
    source,
    target: settings.targetLanguage,
  });

  return {
    translations,
    sourceCode: source === 'auto' ? detectedSource : source,
    providerName: provider.name,
  };
}

/** Resultados de upload e de "Abrir em aba" ficam na sessão para a página de resultados. */
export async function saveJobForPage(job, input = null) {
  const sizeOk = !input || input.length < 4_000_000;
  await chrome.storage.session.set({ [`job:${job.id}`]: { job, input: sizeOk ? input : null } });
}

export async function loadJobForPage(id) {
  const key = `job:${id}`;
  const stored = await chrome.storage.session.get(key);
  return stored[key] || null;
}
