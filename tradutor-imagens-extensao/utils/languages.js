// Idiomas suportados na interface. Os códigos seguem BCP-47 (ex.: pt-BR).
// Para adicionar um idioma de destino, inclua uma entrada em TARGET_LANGUAGES.
// Para adicionar um idioma de OCR, veja OCR_LANGUAGES e a seção "Desenvolvimento" do README.

const NAMES = {
  pt: { name: 'Português', flag: '🇵🇹' },
  'pt-BR': { name: 'Português (Brasil)', flag: '🇧🇷' },
  en: { name: 'Inglês', flag: '🇺🇸' },
  es: { name: 'Espanhol', flag: '🇪🇸' },
  fr: { name: 'Francês', flag: '🇫🇷' },
  de: { name: 'Alemão', flag: '🇩🇪' },
  it: { name: 'Italiano', flag: '🇮🇹' },
  ja: { name: 'Japonês', flag: '🇯🇵' },
  ko: { name: 'Coreano', flag: '🇰🇷' },
  'zh-CN': { name: 'Chinês (simplificado)', flag: '🇨🇳' },
  'zh-TW': { name: 'Chinês (tradicional)', flag: '🇹🇼' },
  ru: { name: 'Russo', flag: '🇷🇺' },
  nl: { name: 'Holandês', flag: '🇳🇱' },
  pl: { name: 'Polonês', flag: '🇵🇱' },
  tr: { name: 'Turco', flag: '🇹🇷' },
  ar: { name: 'Árabe', flag: '🇸🇦' },
  hi: { name: 'Hindi', flag: '🇮🇳' },
};

/** Idiomas que o usuário pode escolher como destino. */
export const TARGET_LANGUAGES = [
  'pt-BR', 'en', 'es', 'fr', 'de', 'it', 'ja', 'ko', 'zh-CN', 'ru',
];

/** Idiomas de origem: 'auto' ou qualquer código conhecido. */
export const SOURCE_LANGUAGES = ['auto', ...TARGET_LANGUAGES.filter((c) => c !== 'pt-BR'), 'pt-BR'];

/**
 * Idiomas de OCR (Tesseract). Cada código precisa de lang/<code>.traineddata.
 * Só 'eng' e 'por' acompanham a extensão; os demais são opcionais.
 */
export const OCR_LANGUAGES = [
  { code: 'eng', name: 'Inglês' },
  { code: 'por', name: 'Português' },
  { code: 'spa', name: 'Espanhol' },
  { code: 'fra', name: 'Francês' },
  { code: 'deu', name: 'Alemão' },
  { code: 'ita', name: 'Italiano' },
  { code: 'rus', name: 'Russo' },
  { code: 'jpn', name: 'Japonês' },
  { code: 'kor', name: 'Coreano' },
  { code: 'chi_sim', name: 'Chinês simplificado' },
];

/** Retorna { code, name, flag } para exibir na UI. Nunca retorna null para códigos válidos. */
export function languageInfo(code) {
  if (!code) return null;
  const entry = NAMES[code] || NAMES[code.split('-')[0]];
  return { code, name: entry?.name || code, flag: entry?.flag || '🌐' };
}

/**
 * Converte um código da extensão para o código aceito por cada provedor.
 * Google aceita 'pt' (sem variante); LibreTranslate usa 'zh' em vez de 'zh-CN'.
 */
export function providerLanguageCode(code, providerId) {
  if (!code || code === 'auto') return undefined;
  if (code === 'pt-BR' || code === 'pt-PT') return 'pt';
  if (code === 'zh-CN' || code === 'zh-TW') return providerId === 'libre' ? 'zh' : code;
  return code.split('-')[0] === code ? code : code.split('-')[0];
}
