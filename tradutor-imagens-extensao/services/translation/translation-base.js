// Contrato comum de todos os provedores de tradução.
//
// Para criar um provedor novo:
//   1. Estenda TranslationProvider e implemente requestTranslation().
//   2. Registre a classe em PROVIDERS (translation-provider.js).
//   3. Adicione a opção na tela de Configurações (options/options.html).
//
// requestTranslation(texts, { source, target }) deve retornar:
//   { translations: string[], detectedSource: string | null }
// com o mesmo número de itens de `texts`, na mesma ordem.

import { TIError } from '../../utils/errors.js';

const MAX_ITEMS_PER_REQUEST = 100;

export class TranslationProvider {
  constructor(id, name) {
    this.id = id;
    this.name = name;
  }

  /** Divide em lotes e normaliza a saída. */
  async translate(texts, { source = 'auto', target = 'pt-BR' } = {}) {
    const clean = texts.map((t) => String(t ?? ''));
    const translations = [];
    let detectedSource = null;

    for (let i = 0; i < clean.length; i += MAX_ITEMS_PER_REQUEST) {
      const slice = clean.slice(i, i + MAX_ITEMS_PER_REQUEST);
      const result = await this.requestTranslation(slice, { source, target });
      if (!Array.isArray(result.translations) || result.translations.length !== slice.length) {
        throw new TIError('provider-invalid-response');
      }
      translations.push(...result.translations.map((t) => String(t ?? '')));
      detectedSource = detectedSource || result.detectedSource || null;
    }
    return { translations, detectedSource };
  }

  // eslint-disable-next-line no-unused-vars
  async requestTranslation(_texts, _options) {
    throw new TIError('provider-unavailable', 'requestTranslation não implementado');
  }
}

/** fetch com JSON, traduzindo status HTTP para códigos de erro estáveis. */
export async function postJson(url, body, headers = {}) {
  let response;
  try {
    response = await fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', ...headers },
      body: JSON.stringify(body),
    });
  } catch {
    throw new TIError('network');
  }
  if (response.status === 401 || response.status === 403) throw new TIError('api-key-invalid');
  if (response.status === 429 || response.status === 456) throw new TIError('api-quota');
  if (!response.ok) throw new TIError('provider-unavailable', `HTTP ${response.status}`);
  try {
    return await response.json();
  } catch {
    throw new TIError('provider-invalid-response');
  }
}

/** Google devolve entidades HTML mesmo com format=text. */
export function decodeEntities(text) {
  return String(text)
    .replace(/&quot;/g, '"')
    .replace(/&#39;/g, "'")
    .replace(/&lt;/g, '<')
    .replace(/&gt;/g, '>')
    .replace(/&amp;/g, '&');
}

/** Aceita https em qualquer host, ou http apenas para localhost (desenvolvimento). */
export function assertSafeUrl(value) {
  let url;
  try {
    url = new URL(value);
  } catch {
    throw new TIError('invalid-url');
  }
  const local = url.hostname === 'localhost' || url.hostname === '127.0.0.1';
  if (url.protocol !== 'https:' && !(local && url.protocol === 'http:')) {
    throw new TIError('invalid-url');
  }
  return url.toString().replace(/\/+$/, '');
}
