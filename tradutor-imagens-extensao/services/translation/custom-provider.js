// Provedor genérico para qualquer serviço próprio de tradução.
//
// Contrato HTTP (o seu endpoint deve implementar):
//   POST <customUrl>
//   Corpo:    { "texts": ["..."], "source": "auto" | "en" | ..., "target": "pt-BR" }
//   Resposta: { "translations": ["..."], "detectedSource": "en" }
//
// Exemplo de servidor: um proxy seu que chama outra API. Não coloque chaves
// de API de terceiros no código da extensão.

import { TranslationProvider, postJson, assertSafeUrl } from './translation-base.js';
import { TIError } from '../../utils/errors.js';

export class CustomTranslationProvider extends TranslationProvider {
  constructor(settings) {
    super('custom', 'Serviço personalizado');
    if (!settings.customUrl) throw new TIError('translation-not-configured');
    this.url = assertSafeUrl(settings.customUrl);
  }

  async requestTranslation(texts, { source, target }) {
    const data = await postJson(this.url, { texts, source, target });
    return {
      translations: data?.translations ?? [],
      detectedSource: data?.detectedSource || null,
    };
  }
}
