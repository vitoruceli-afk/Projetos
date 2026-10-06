// Erros com código estável. A mensagem exibida ao usuário é sempre em pt-BR
// e nunca expõe o erro técnico original (ex.: ERR_CORS_IMAGE_ACCESS).

export class TIError extends Error {
  constructor(code, detail) {
    super(detail || code);
    this.code = code;
  }
}

const MESSAGES = {
  'image-too-small':
    'A imagem é muito pequena para leitura. Tente selecionar uma área maior ou usar uma imagem com mais resolução.',
  'ocr-empty':
    'Não consegui identificar texto nesta imagem. Verifique se há texto visível ou tente selecionar uma área menor.',
  illegible:
    'A imagem parece ilegível para o OCR. Tente uma versão com mais resolução ou mais contraste.',
  'cors-blocked':
    "Não foi possível acessar diretamente esta imagem. Tente utilizar a opção 'Selecionar área' para traduzi-la.",
  'capture-activetab':
    'Para capturar a tela desta página, clique no ícone da extensão ou use o atalho Ctrl+Shift+T (Cmd+Shift+T no Mac) nesta página.',
  'capture-failed': 'Não foi possível capturar a área da tela. Tente novamente.',
  'page-blocked':
    'Esta página não permite o acesso da extensão (páginas internas do Chrome e a Chrome Web Store são bloqueadas).',
  'translation-not-configured':
    'A tradução online não está configurada. Abra as Configurações e informe o provedor e a chave de API.',
  'api-key-invalid':
    'A chave de API de tradução foi recusada. Verifique a chave nas Configurações.',
  'api-quota':
    'O limite de uso da API de tradução foi atingido. Tente novamente mais tarde ou verifique sua cota.',
  network:
    'Não foi possível conectar ao serviço de tradução. Verifique se o LibreTranslate está rodando (http://localhost:5000) ou sua conexão com a internet. O texto original foi preservado.',
  'provider-unavailable':
    'O serviço de tradução está indisponível no momento. Tente novamente em instantes.',
  'provider-invalid-response':
    'O serviço de tradução devolveu uma resposta inesperada.',
  'ocr-failed': 'Ocorreu um erro ao processar a imagem. Tente novamente.',
  'invalid-url': 'O endereço do serviço de tradução é inválido. Use https:// (ou http:// apenas para localhost).',
  'file-too-large': 'O arquivo é grande demais. Use uma imagem de até 15 MB.',
  'retry-unavailable': 'Não é mais possível traduzir novamente esta imagem. Envie-a de novo para traduzir.',
  'fetch-failed': 'Não foi possível baixar esta imagem.',
  'not-an-image': 'O arquivo selecionado não é uma imagem compatível.',
  unknown: 'Algo inesperado aconteceu. Tente novamente.',
};

/** Converte qualquer erro em { ok:false, code, message } para a UI. */
export function toUserError(err) {
  const code = err?.code && MESSAGES[err.code] ? err.code : 'unknown';
  return { ok: false, code, message: MESSAGES[code] };
}

export function userMessage(code) {
  return MESSAGES[code] || MESSAGES.unknown;
}
