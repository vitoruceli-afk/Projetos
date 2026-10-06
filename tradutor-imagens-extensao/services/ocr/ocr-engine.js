// Contrato dos mecanismos de OCR e registro deles.
//
// Um engine recebe um canvas já pré-processado e devolve linhas de texto:
//   recognize(canvas, { langs }) -> { lines: [{ text, confidence, bbox: {x0,y0,x1,y1} }] }
//
// Os engines locais rodam no documento offscreen (ver offscreen/offscreen.js),
// porque o service worker do Manifest V3 não tem DOM nem Web Workers estáveis.
// Um engine em nuvem (ex.: Google Cloud Vision) deve ser chamado pelo service
// worker, com a chave guardada nas configurações do usuário, nunca no código.

export class OcrEngine {
  constructor(id, name) {
    this.id = id;
    this.name = name;
  }

  // eslint-disable-next-line no-unused-vars
  async recognize(_canvas, _options) {
    throw new Error('recognize() não implementado');
  }

  async dispose() {}
}
