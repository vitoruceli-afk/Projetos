// Configurações ficam em chrome.storage.local (não sincronizam com a conta Google,
// de propósito: chaves de API não devem ir para a nuvem do Chrome).
// Content scripts leem a mesma chave 'settings' diretamente, com os mesmos padrões.

export const DEFAULT_SETTINGS = {
  // Tradução
  targetLanguage: 'pt-BR',
  sourceLanguage: 'auto',
  autoDetect: true,
  provider: 'libre', // 'libre' (padrão, local e gratuito) | 'google' | 'custom'
  googleApiKey: '',
  libreUrl: 'http://localhost:5000',
  libreApiKey: '',
  customUrl: '',

  // OCR
  ocrLanguages: ['eng', 'por'],
  enhanceImage: true,

  // Visual
  showOverlay: true,
  preserveFormatting: true,

  // Desempenho
  maxImageSide: 2000, // lado maior, em px, antes do OCR
  minImageSide: 40, // imagens menores que isso não são processadas

  // Aparência e acessibilidade
  fontScale: 1,

  // Histórico
  historyEnabled: true,
  historyLimit: 100,
};

const KEY = 'settings';

export async function getSettings() {
  const { [KEY]: stored } = await chrome.storage.local.get(KEY);
  return { ...DEFAULT_SETTINGS, ...(stored || {}) };
}

export async function saveSettings(patch) {
  const next = { ...(await getSettings()), ...patch };
  await chrome.storage.local.set({ [KEY]: next });
  return next;
}
