// Google Cloud Translation API v2 (oficial, paga após a cota gratuita).
// A chave é informada pelo próprio usuário nas Configurações e enviada no
// cabeçalho x-goog-api-key (não na URL, para não aparecer em logs).
// Não há scraping do Google Tradutor.
// Preços e cota gratuita: https://cloud.google.com/translate/pricing

import { TranslationProvider, postJson, decodeEntities } from './translation-base.js';
import { providerLanguageCode } from '../../utils/languages.js';
import { TIError } from '../../utils/errors.js';

const ENDPOINT = 'https://translation.googleapis.com/language/translate/v2';

export class GoogleTranslationProvider extends TranslationProvider {
  constructor(settings) {
    super('google', 'Google Cloud Translation');
    const key = (settings.googleApiKey || '').trim();
    if (!key) throw new TIError('translation-not-configured');
    this.key = key;
  }

  async requestTranslation(texts, { source, target }) {
    const body = {
      q: texts,
      target: providerLanguageCode(target, this.id),
      format: 'text',
    };
    const sourceCode = providerLanguageCode(source, this.id);
    if (sourceCode) body.source = sourceCode;

    const data = await postJson(ENDPOINT, body, { 'x-goog-api-key': this.key });
    const items = data?.data?.translations;
    if (!Array.isArray(items)) throw new TIError('provider-invalid-response');

    return {
      translations: items.map((item) => decodeEntities(item.translatedText ?? '')),
      detectedSource: items[0]?.detectedSourceLanguage || null,
    };
  }
}
