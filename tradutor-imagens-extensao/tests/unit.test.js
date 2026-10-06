// Testes das partes sem dependência de navegador. Rode com: npm test
// (Node 18+). OCR, captura e overlay exigem o Chrome: veja a lista manual no README.

import { test } from 'node:test';
import assert from 'node:assert/strict';

import { languageInfo, providerLanguageCode } from '../utils/languages.js';
import { TIError, toUserError } from '../utils/errors.js';
import { GoogleTranslationProvider } from '../services/translation/google-provider.js';
import { LibreTranslateProvider } from '../services/translation/libretranslate-provider.js';
import { CustomTranslationProvider } from '../services/translation/custom-provider.js';
import { createTranslationProvider, isProviderConfigured } from '../services/translation/translation-provider.js';
import { otsuThreshold, contrastLut } from '../services/image/image-processing.js';
import { flattenLines } from '../services/ocr/tesseract-engine.js';
import { DEFAULT_SETTINGS } from '../services/storage/settings.js';
import { toBrazilianPortuguese } from '../services/translation/pt-br.js';

function mockFetch(handler) {
  const original = globalThis.fetch;
  globalThis.fetch = async (url, init) => handler(url, init);
  return () => {
    globalThis.fetch = original;
  };
}

function jsonResponse(status, body) {
  return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
}

// ---------- Idiomas ----------

test('languageInfo devolve nome e bandeira em pt-BR', () => {
  assert.deepEqual(languageInfo('en'), { code: 'en', name: 'Inglês', flag: '🇺🇸' });
  assert.equal(languageInfo('pt-BR').name, 'Português (Brasil)');
  assert.equal(languageInfo('en-GB').name, 'Inglês');
  assert.equal(languageInfo('xx').name, 'xx');
  assert.equal(languageInfo(null), null);
});

test('providerLanguageCode converte códigos para cada provedor', () => {
  assert.equal(providerLanguageCode('pt-BR', 'google'), 'pt');
  assert.equal(providerLanguageCode('zh-CN', 'google'), 'zh-CN');
  assert.equal(providerLanguageCode('zh-CN', 'libre'), 'zh');
  assert.equal(providerLanguageCode('auto', 'google'), undefined);
});

// ---------- Erros ----------

test('toUserError nunca expõe o erro técnico original', () => {
  const err = toUserError(new Error('ERR_CORS_IMAGE_ACCESS'));
  assert.equal(err.code, 'unknown');
  assert.doesNotMatch(err.message, /ERR_/);

  const cors = toUserError(new TIError('cors-blocked'));
  assert.match(cors.message, /Selecionar área/);
});

// ---------- Provedores ----------

test('Google: envia a chave no cabeçalho, não na URL, e decodifica entidades', async () => {
  let seen;
  const restore = mockFetch(async (url, init) => {
    seen = { url, headers: init.headers, body: JSON.parse(init.body) };
    return jsonResponse(200, {
      data: { translations: [{ translatedText: 'Olá &amp; bem-vindo', detectedSourceLanguage: 'en' }] },
    });
  });
  try {
    const provider = new GoogleTranslationProvider({ googleApiKey: ' KEY ' });
    const result = await provider.translate(['Hello & welcome'], { source: 'auto', target: 'pt-BR' });
    assert.equal(seen.url.includes('KEY'), false, 'a chave não pode ir na URL');
    assert.equal(seen.headers['x-goog-api-key'], 'KEY');
    assert.equal(seen.body.target, 'pt');
    assert.equal(seen.body.source, undefined);
    assert.deepEqual(result, { translations: ['Olá & bem-vindo'], detectedSource: 'en' });
  } finally {
    restore();
  }
});

test('Google: 403 vira api-key-invalid e 429 vira api-quota', async () => {
  const restore = mockFetch(async () => jsonResponse(403, {}));
  try {
    await assert.rejects(new GoogleTranslationProvider({ googleApiKey: 'k' }).translate(['a']), { code: 'api-key-invalid' });
  } finally {
    restore();
  }
  const restore2 = mockFetch(async () => jsonResponse(429, {}));
  try {
    await assert.rejects(new GoogleTranslationProvider({ googleApiKey: 'k' }).translate(['a']), { code: 'api-quota' });
  } finally {
    restore2();
  }
});

test('Google: sem chave configurada falha antes de chamar a rede', () => {
  assert.throws(() => new GoogleTranslationProvider({ googleApiKey: '  ' }), { code: 'translation-not-configured' });
});

test('Falha de rede vira código network', async () => {
  const restore = mockFetch(async () => {
    throw new TypeError('Failed to fetch');
  });
  try {
    await assert.rejects(new GoogleTranslationProvider({ googleApiKey: 'k' }).translate(['a']), { code: 'network' });
  } finally {
    restore();
  }
});

test('LibreTranslate aceita resposta com lista e com objeto de detecção', async () => {
  const restore = mockFetch(async (url, init) => {
    assert.equal(url, 'https://libre.exemplo/translate');
    const body = JSON.parse(init.body);
    assert.equal(body.api_key, 'abc');
    return jsonResponse(200, { translatedText: ['Olá', 'Mundo'], detectedLanguage: [{ language: 'en' }] });
  });
  try {
    const provider = new LibreTranslateProvider({ libreUrl: 'https://libre.exemplo/', libreApiKey: 'abc' });
    const result = await provider.translate(['Hello', 'World'], { source: 'auto', target: 'pt-BR' });
    assert.deepEqual(result, { translations: ['Olá', 'Mundo'], detectedSource: 'en' });
  } finally {
    restore();
  }
});

test('Lotes maiores que 100 itens são divididos em várias chamadas', async () => {
  let calls = 0;
  const restore = mockFetch(async (_url, init) => {
    calls++;
    const q = JSON.parse(init.body).q;
    return jsonResponse(200, { translatedText: q.map((t) => `pt:${t}`), detectedLanguage: { language: 'en' } });
  });
  try {
    const texts = Array.from({ length: 250 }, (_, i) => `t${i}`);
    const provider = new LibreTranslateProvider({ libreUrl: 'http://localhost:5000' });
    const result = await provider.translate(texts, { source: 'auto', target: 'pt-BR' });
    assert.equal(calls, 3);
    assert.equal(result.translations.length, 250);
    assert.equal(result.translations[249], 'pt:t249');
  } finally {
    restore();
  }
});

test('URL insegura (http fora de localhost) é recusada', () => {
  assert.throws(() => new LibreTranslateProvider({ libreUrl: 'http://servidor.exemplo' }), { code: 'invalid-url' });
  assert.doesNotThrow(() => new LibreTranslateProvider({ libreUrl: 'http://localhost:5000' }));
});

test('Serviço personalizado: contrato translations/detectedSource', async () => {
  const restore = mockFetch(async () => jsonResponse(200, { translations: ['Oi'], detectedSource: 'es' }));
  try {
    const provider = new CustomTranslationProvider({ customUrl: 'https://meu.servidor/translate' });
    assert.deepEqual(await provider.translate(['Hola'], { target: 'pt-BR' }), {
      translations: ['Oi'],
      detectedSource: 'es',
    });
  } finally {
    restore();
  }
});

test('Resposta com quantidade diferente de itens é rejeitada', async () => {
  const restore = mockFetch(async () => jsonResponse(200, { translations: ['só um'] }));
  try {
    const provider = new CustomTranslationProvider({ customUrl: 'https://meu.servidor/translate' });
    await assert.rejects(provider.translate(['a', 'b'], {}), { code: 'provider-invalid-response' });
  } finally {
    restore();
  }
});

test('pt-BR: troca vocabulário europeu, preserva capitalização e respeita limites de palavra', () => {
  assert.equal(toBrazilianPortuguese('Abra o ecrã do telemóvel'), 'Abra a tela do celular');
  assert.equal(toBrazilianPortuguese('No ecrã'), 'Na tela');
  assert.equal(toBrazilianPortuguese('Ecrã'), 'Tela');
  assert.equal(toBrazilianPortuguese('ECRÃS'), 'TELAS');
  assert.equal(toBrazilianPortuguese('Os utilizadores e o ficheiro'), 'Os usuários e o arquivo');
  assert.equal(toBrazilianPortuguese('pequeno-almoço'), 'café da manhã');
  // Não troca dentro de outras palavras
  assert.equal(toBrazilianPortuguese('factorial'), 'factorial');
  assert.equal(toBrazilianPortuguese('xcontactos'), 'xcontactos');
  // Não altera texto sem nada a converter
  assert.equal(toBrazilianPortuguese('Olá, bem-vindo ao nosso site.'), 'Olá, bem-vindo ao nosso site.');
});

test('LibreTranslate aplica a conversão pt-BR somente quando o destino é pt-BR', async () => {
  const restore = mockFetch(async () =>
    jsonResponse(200, { translatedText: ['Abra o ecrã'], detectedLanguage: { language: 'en' } }),
  );
  try {
    const provider = new LibreTranslateProvider({ libreUrl: 'http://localhost:5000' });
    const br = await provider.translate(['Open the screen'], { source: 'auto', target: 'pt-BR' });
    assert.deepEqual(br.translations, ['Abra a tela']);
  } finally {
    restore();
  }
});

test('Padrão é LibreTranslate local, sem chave e sem custo', () => {
  assert.equal(DEFAULT_SETTINGS.provider, 'libre');
  assert.equal(DEFAULT_SETTINGS.libreUrl, 'http://localhost:5000');
  assert.equal(isProviderConfigured(DEFAULT_SETTINGS), true);
  const provider = createTranslationProvider(DEFAULT_SETTINGS);
  assert.equal(provider.baseUrl, 'http://localhost:5000');
});

test('Registro: provedor desconhecido e configuração ausente', () => {
  assert.throws(() => createTranslationProvider({ provider: 'nao-existe' }), { code: 'translation-not-configured' });
  assert.equal(isProviderConfigured({ provider: 'google', googleApiKey: '' }), false);
  assert.equal(isProviderConfigured({ provider: 'google', googleApiKey: 'x' }), true);
  assert.equal(isProviderConfigured({ provider: 'libre', libreUrl: 'https://libretranslate.com' }), true);
});

// ---------- Imagem (funções puras) ----------

test('Otsu separa duas populações de tons claros e escuros', () => {
  const hist = new Uint32Array(256);
  hist[20] = 500; // fundo escuro
  hist[230] = 500; // texto claro
  const t = otsuThreshold(hist, 1000);
  assert.ok(t >= 20 && t < 230, `limiar ${t} deve ficar entre as duas populações`);
});

test('contrastLut não altera imagem já contrastada e alonga imagem apagada', () => {
  const full = new Uint32Array(256);
  full[0] = 500;
  full[255] = 500;
  assert.equal(contrastLut(full, 1000), null);

  const faded = new Uint32Array(256);
  faded[100] = 500;
  faded[140] = 500;
  const lut = contrastLut(faded, 1000);
  assert.ok(lut, 'imagem apagada deve gerar tabela');
  assert.equal(lut[100], 0);
  assert.equal(lut[140], 255);
});

// ---------- OCR (conversão de saída do Tesseract) ----------

test('flattenLines achata blocos > parágrafos > linhas e descarta linhas vazias', () => {
  const data = {
    blocks: [
      {
        paragraphs: [
          {
            lines: [
              { text: ' Hello ', confidence: 91, bbox: { x0: 1, y0: 2, x1: 30, y1: 12 } },
              { text: '   ', confidence: 80, bbox: { x0: 0, y0: 0, x1: 1, y1: 1 } },
            ],
          },
        ],
      },
      { paragraphs: [{ lines: [{ text: 'World', confidence: 88, bbox: { x0: 1, y0: 20, x1: 40, y1: 30 } }] }] },
    ],
  };
  const lines = flattenLines(data);
  assert.deepEqual(
    lines.map((l) => l.text),
    ['Hello', 'World'],
  );
  assert.equal(lines[0].confidence, 91);
});
