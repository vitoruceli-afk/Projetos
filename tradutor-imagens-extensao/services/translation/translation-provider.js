// Registro de provedores de tradução. Ponto único de troca do mecanismo:
// a pipeline só conhece createTranslationProvider(), nunca uma classe concreta.

import { GoogleTranslationProvider } from './google-provider.js';
import { LibreTranslateProvider } from './libretranslate-provider.js';
import { CustomTranslationProvider } from './custom-provider.js';
import { TIError } from '../../utils/errors.js';

export const PROVIDERS = {
  google: GoogleTranslationProvider,
  libre: LibreTranslateProvider,
  custom: CustomTranslationProvider,
};

export function createTranslationProvider(settings) {
  const ProviderClass = PROVIDERS[settings.provider];
  if (!ProviderClass) throw new TIError('translation-not-configured');
  return new ProviderClass(settings);
}

/** Usado pelo popup (indicador de status) e pela pipeline antes de chamar a API. */
export function isProviderConfigured(settings) {
  switch (settings.provider) {
    case 'google':
      return Boolean((settings.googleApiKey || '').trim());
    case 'libre':
      return Boolean(settings.libreUrl);
    case 'custom':
      return Boolean(settings.customUrl);
    default:
      return false;
  }
}
