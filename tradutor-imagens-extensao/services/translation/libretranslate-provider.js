// LibreTranslate: software open source e gratuito. Pode ser usado com uma
// instância própria (recomendado para privacidade) ou com um servidor público
// que exija chave. Documentação: https://docs.libretranslate.com

import { TranslationProvider, postJson, assertSafeUrl } from './translation-base.js';
import { providerLanguageCode } from '../../utils/languages.js';
import { TIError } from '../../utils/errors.js';
import { toBrazilianPortuguese } from './pt-br.js';

export class LibreTranslateProvider extends TranslationProvider {
  constructor(settings) {
    super('libre', 'LibreTranslate');
    if (!settings.libreUrl) throw new TIError('translation-not-configured');
    this.baseUrl = assertSafeUrl(settings.libreUrl);
    this.apiKey = (settings.libreApiKey || '').trim();
  }

  async requestTranslation(texts, { source, target }) {
    const body = {
      q: texts,
      source: providerLanguageCode(source, this.id) || 'auto',
      target: providerLanguageCode(target, this.id),
      format: 'text',
    };
    if (this.apiKey) body.api_key = this.apiKey;

    const data = await postJson(`${this.baseUrl}/translate`, body);
    const raw = data?.translatedText;
    const translations = Array.isArray(raw) ? raw : [raw];

    // Algumas versões devolvem um objeto por item; outras, um único objeto.
    const detected = Array.isArray(data.detectedLanguage) ? data.detectedLanguage[0] : data.detectedLanguage;

    // O modelo é português europeu: converte a saída para a variante brasileira.
    const toBrazil = target === 'pt-BR';
    return {
      translations: translations.map((t) => (toBrazil ? toBrazilianPortuguese(t ?? '') : t ?? '')),
      detectedSource: detected?.language || null,
    };
  }
}
