// Pré-processamento e composição de imagens. Roda no documento offscreen (usa canvas).
// Coordenadas de texto (bbox) sempre ficam no espaço da imagem "base", que é a
// original reduzida para caber em maxSide. OCR e sobreposição usam o mesmo espaço.

const MAX_DECODE_PIXELS = 40_000_000; // ~ 6000x6000; acima disso a decodificação é recusada

export function loadImage(dataUrl) {
  return new Promise((resolve, reject) => {
    const img = new Image();
    img.onload = () => resolve(img);
    img.onerror = () => reject(new Error('Imagem inválida ou corrompida'));
    img.src = dataUrl;
  });
}

/** Desenha a imagem na escala "base" (lado maior <= maxSide). */
export async function loadBase(dataUrl, maxSide) {
  const img = await loadImage(dataUrl);
  const w0 = img.naturalWidth || 1;
  const h0 = img.naturalHeight || 1;
  if (w0 * h0 > MAX_DECODE_PIXELS) throw new Error('Imagem grande demais para processar');

  const scale = Math.min(1, maxSide / Math.max(w0, h0));
  const width = Math.max(1, Math.round(w0 * scale));
  const height = Math.max(1, Math.round(h0 * scale));
  const canvas = makeCanvas(width, height);
  canvas.getContext('2d').drawImage(img, 0, 0, width, height);
  return { canvas, width, height };
}

/**
 * Prepara a imagem para o Tesseract: amplia textos pequenos e, se pedido,
 * converte para tons de cinza com alongamento de contraste.
 * Retorna o canvas e o fator de escala aplicado (para converter bbox de volta).
 */
export function prepareForOcr(baseCanvas, { enhance = true } = {}) {
  const { width, height } = baseCanvas;
  const longest = Math.max(width, height);
  const scale = Math.min(2.5, Math.max(1, 1600 / longest));

  const canvas = makeCanvas(Math.round(width * scale), Math.round(height * scale));
  const ctx = canvas.getContext('2d', { willReadFrequently: true });
  ctx.imageSmoothingQuality = 'high';
  ctx.drawImage(baseCanvas, 0, 0, canvas.width, canvas.height);

  if (enhance) toGrayStretched(ctx, canvas.width, canvas.height);
  return { canvas, scale: canvas.width / width };
}

/** Versão binarizada (Otsu). Usada só como segunda tentativa quando a confiança é baixa. */
export function binarize(preparedCanvas) {
  const { width, height } = preparedCanvas;
  const canvas = makeCanvas(width, height);
  const ctx = canvas.getContext('2d', { willReadFrequently: true });
  ctx.drawImage(preparedCanvas, 0, 0);
  const img = ctx.getImageData(0, 0, width, height);
  const d = img.data;

  const hist = new Uint32Array(256);
  for (let i = 0; i < d.length; i += 4) hist[d[i]]++;
  const threshold = otsuThreshold(hist, width * height);

  let dark = 0;
  for (let v = 0; v < 256; v++) if (v < threshold) dark += hist[v];
  // Texto claro sobre fundo escuro vira texto escuro sobre fundo claro.
  const invert = dark > (width * height) / 2;

  for (let i = 0; i < d.length; i += 4) {
    let value = d[i] > threshold ? 255 : 0;
    if (invert) value = 255 - value;
    d[i] = d[i + 1] = d[i + 2] = value;
  }
  ctx.putImageData(img, 0, 0);
  return canvas;
}

/** Recorta a captura de tela (coordenadas da viewport em CSS px) para a área selecionada. */
export async function cropScreenshot(dataUrl, rect, viewport) {
  const img = await loadImage(dataUrl);
  const scale = img.naturalWidth / viewport.w;
  const sx = clamp(Math.round(rect.x * scale), 0, img.naturalWidth - 1);
  const sy = clamp(Math.round(rect.y * scale), 0, img.naturalHeight - 1);
  const sw = clamp(Math.round(rect.w * scale), 1, img.naturalWidth - sx);
  const sh = clamp(Math.round(rect.h * scale), 1, img.naturalHeight - sy);

  const canvas = makeCanvas(sw, sh);
  canvas.getContext('2d').drawImage(img, sx, sy, sw, sh, 0, 0, sw, sh);
  return canvas.toDataURL('image/png');
}

export function thumbnail(baseCanvas, maxSide = 160) {
  const scale = Math.min(1, maxSide / Math.max(baseCanvas.width, baseCanvas.height));
  const canvas = makeCanvas(Math.max(1, Math.round(baseCanvas.width * scale)), Math.max(1, Math.round(baseCanvas.height * scale)));
  canvas.getContext('2d').drawImage(baseCanvas, 0, 0, canvas.width, canvas.height);
  return canvas.toDataURL('image/jpeg', 0.7);
}

/**
 * Cobre cada linha original com a cor de fundo média da borda e escreve a
 * tradução na mesma posição, reduzindo a fonte até caber na largura da caixa.
 * Cor do texto: média dos pixels que contrastam com o fundo.
 */
export function drawTranslationOverlay(baseCanvas, lines) {
  const canvas = makeCanvas(baseCanvas.width, baseCanvas.height);
  const ctx = canvas.getContext('2d', { willReadFrequently: true });
  ctx.drawImage(baseCanvas, 0, 0);

  for (const line of lines) {
    const { x0, y0, x1, y1 } = line.bbox;
    const x = Math.max(0, Math.floor(x0));
    const y = Math.max(0, Math.floor(y0));
    const w = Math.min(canvas.width - x, Math.ceil(x1 - x0));
    const h = Math.min(canvas.height - y, Math.ceil(y1 - y0));
    if (w < 4 || h < 4 || !line.translated) continue;

    const { bg, ink } = sampleColors(ctx, x, y, w, h);
    ctx.fillStyle = `rgb(${bg[0]},${bg[1]},${bg[2]})`;
    ctx.fillRect(x - 1, y - 1, w + 2, h + 2);
    drawFittedText(ctx, line.translated, x, y, w, h, textColor(bg, ink));
  }
  return canvas;
}

// ---- helpers ----

function makeCanvas(width, height) {
  const canvas = document.createElement('canvas');
  canvas.width = Math.max(1, width);
  canvas.height = Math.max(1, height);
  return canvas;
}

function clamp(v, min, max) {
  return Math.min(Math.max(v, min), max);
}

function toGrayStretched(ctx, width, height) {
  const img = ctx.getImageData(0, 0, width, height);
  const d = img.data;
  const hist = new Uint32Array(256);

  for (let i = 0; i < d.length; i += 4) {
    const g = (0.299 * d[i] + 0.587 * d[i + 1] + 0.114 * d[i + 2]) | 0;
    d[i] = d[i + 1] = d[i + 2] = g;
    hist[g]++;
  }

  const lut = contrastLut(hist, width * height);
  if (lut) {
    for (let i = 0; i < d.length; i += 4) d[i] = d[i + 1] = d[i + 2] = lut[d[i]];
  }
  ctx.putImageData(img, 0, 0);
}

/**
 * Tabela de alongamento de contraste entre os percentis 1% e 99%.
 * Retorna null quando a imagem já tem contraste suficiente.
 */
export function contrastLut(hist, total) {
  const tail = total * 0.01;
  let lo = 0;
  let hi = 255;
  let acc = 0;
  for (let v = 0; v < 256; v++) {
    acc += hist[v];
    if (acc >= tail) {
      lo = v;
      break;
    }
  }
  acc = 0;
  for (let v = 255; v >= 0; v--) {
    acc += hist[v];
    if (acc >= tail) {
      hi = v;
      break;
    }
  }
  const span = hi - lo;
  // Pouco contraste, ou faixa já completa (LUT identidade): não vale processar.
  if (span <= 16 || span >= 255) return null;

  const lut = new Uint8Array(256);
  for (let v = 0; v < 256; v++) lut[v] = clamp(Math.round(((v - lo) * 255) / span), 0, 255);
  return lut;
}

/** Limiar de Otsu: maximiza a variância entre as classes claro/escuro. */
export function otsuThreshold(hist, total) {
  let sum = 0;
  for (let t = 0; t < 256; t++) sum += t * hist[t];

  let sumB = 0;
  let wB = 0;
  let best = -1;
  let threshold = 127;
  for (let t = 0; t < 256; t++) {
    wB += hist[t];
    if (wB === 0) continue;
    const wF = total - wB;
    if (wF === 0) break;
    sumB += t * hist[t];
    const mB = sumB / wB;
    const mF = (sum - sumB) / wF;
    const between = wB * wF * (mB - mF) * (mB - mF);
    if (between > best) {
      best = between;
      threshold = t;
    }
  }
  return threshold;
}

function sampleColors(ctx, x, y, w, h) {
  const data = ctx.getImageData(x, y, w, h).data;
  const lum = (r, g, b) => 0.299 * r + 0.587 * g + 0.114 * b;

  // Fundo: média das bordas da caixa.
  let br = 0;
  let bg = 0;
  let bb = 0;
  let n = 0;
  const addBorder = (px, py) => {
    const i = (py * w + px) * 4;
    br += data[i];
    bg += data[i + 1];
    bb += data[i + 2];
    n++;
  };
  for (let px = 0; px < w; px++) {
    addBorder(px, 0);
    addBorder(px, h - 1);
  }
  for (let py = 1; py < h - 1; py++) {
    addBorder(0, py);
    addBorder(w - 1, py);
  }
  const bgRgb = n ? [br / n, bg / n, bb / n] : [255, 255, 255];
  const bgLum = lum(...bgRgb);

  // Tinta: pixels com luminância bem diferente do fundo.
  let ir = 0;
  let ig = 0;
  let ib = 0;
  let inkN = 0;
  for (let i = 0; i < data.length; i += 4) {
    if (Math.abs(lum(data[i], data[i + 1], data[i + 2]) - bgLum) > 60) {
      ir += data[i];
      ig += data[i + 1];
      ib += data[i + 2];
      inkN++;
    }
  }
  const ink = inkN > 0 ? [ir / inkN, ig / inkN, ib / inkN] : null;
  return { bg: bgRgb.map(Math.round), ink: ink && ink.map(Math.round) };
}

function textColor(bg, ink) {
  const lum = (c) => 0.299 * c[0] + 0.587 * c[1] + 0.114 * c[2];
  if (ink && Math.abs(lum(ink) - lum(bg)) > 60) return `rgb(${ink[0]},${ink[1]},${ink[2]})`;
  return lum(bg) > 140 ? '#111111' : '#ffffff';
}

function drawFittedText(ctx, text, x, y, w, h, color) {
  let size = Math.max(6, Math.floor(h * 0.85));
  const setFont = () => {
    ctx.font = `${size}px Arial, Helvetica, sans-serif`;
  };
  setFont();
  while (size > 6 && ctx.measureText(text).width > w) {
    size--;
    setFont();
  }
  ctx.fillStyle = color;
  ctx.textBaseline = 'middle';
  ctx.textAlign = 'left';
  // O terceiro argumento comprime horizontalmente como última garantia de encaixe.
  ctx.fillText(text, x, y + h / 2, w);
}
