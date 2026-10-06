// OCR local com Tesseract.js 5.1.1 (Apache-2.0). Usa o core SIMD + LSTM
// empacotado em vendor/tesseract/core/ e os dados de idioma em lang/.
// O worker é encerrado após IDLE_MS sem uso, para liberar memória.

import { OcrEngine } from './ocr-engine.js';

const IDLE_MS = 60_000;
const VENDOR = 'vendor/tesseract';

export class TesseractEngine extends OcrEngine {
  constructor() {
    super('tesseract', 'Tesseract.js (local)');
    this.worker = null;
    this.langKey = '';
    this.idleTimer = null;
  }

  async #ensureWorker(langs) {
    const key = langs.join('+');
    if (this.worker && this.langKey === key) return this.worker;

    await this.dispose();
    if (typeof Tesseract === 'undefined') throw new Error('Biblioteca Tesseract não carregada');

    this.worker = await Tesseract.createWorker(key, 1, {
      workerPath: chrome.runtime.getURL(`${VENDOR}/worker.min.js`),
      // Caminho de arquivo (terminando em .js): o worker o usa diretamente.
      corePath: chrome.runtime.getURL(`${VENDOR}/core/tesseract-core-simd-lstm.wasm.js`),
      langPath: chrome.runtime.getURL('lang'),
      gzip: false, // os .traineddata são enviados sem compactação
      workerBlobURL: false, // blob: workers podem ser bloqueados pela CSP da extensão
      cacheMethod: 'write',
    });
    this.langKey = key;
    return this.worker;
  }

  async recognize(canvas, { langs = ['eng', 'por'], psm = '3' } = {}) {
    this.#touch();
    const worker = await this.#ensureWorker(langs);
    // user_defined_dpi evita a mensagem "Estimating resolution as N" do Tesseract,
    // que aparece como erro em chrome://extensions mesmo sem falha real.
    await worker.setParameters({
      tessedit_pageseg_mode: psm,
      preserve_interword_spaces: '1',
      user_defined_dpi: '300',
    });
    const { data } = await worker.recognize(canvas, {}, { blocks: true });
    this.#touch();
    return { lines: flattenLines(data) };
  }

  #touch() {
    clearTimeout(this.idleTimer);
    this.idleTimer = setTimeout(() => this.dispose(), IDLE_MS);
  }

  async dispose() {
    clearTimeout(this.idleTimer);
    const worker = this.worker;
    this.worker = null;
    this.langKey = '';
    if (worker) await worker.terminate().catch(() => {});
  }
}

/** Achata blocos > parágrafos > linhas, descartando linhas vazias. */
export function flattenLines(data) {
  const lines = [];
  const push = (line) => {
    const text = (line.text || '').trim();
    if (text && line.bbox) {
      lines.push({ text, confidence: line.confidence ?? 0, bbox: line.bbox });
    }
  };

  if (Array.isArray(data?.blocks) && data.blocks.length) {
    for (const block of data.blocks) {
      for (const paragraph of block.paragraphs || []) {
        for (const line of paragraph.lines || []) push(line);
      }
    }
  } else if (Array.isArray(data?.lines)) {
    data.lines.forEach(push);
  }
  return lines;
}
