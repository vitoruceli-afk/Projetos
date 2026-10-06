// Página de resultados: mostra uma tradução guardada na sessão (upload ou "Abrir em aba").

const params = new URLSearchParams(location.search);
let id = params.get('id');
const container = document.getElementById('result');

// Aplica o mesmo CSS do content script; em página comum, :host vira :root.
const style = document.createElement('style');
style.textContent = (globalThis.TI_UI_CSS || '').replaceAll(':host', ':root');
document.head.appendChild(style);

document.title = 'Resultado da tradução · Tradutor de Imagens';

function showMessage(text) {
  container.textContent = '';
  const p = document.createElement('p');
  p.className = 'muted';
  p.textContent = text;
  container.appendChild(p);
}

function renderJob(job, canRetry) {
  TIResultCard.render(container, job, {
    onRetry: canRetry ? () => retry() : null,
    onClose: () => window.close(),
  });
}

async function load() {
  if (!id) return showMessage('Resultado não encontrado. Faça uma nova tradução.');

  const key = `job:${id}`;
  const stored = (await chrome.storage.session.get(key))[key];
  if (!stored) {
    return showMessage('Este resultado não está mais disponível (a sessão do navegador foi reiniciada). O histórico tem as traduções salvas.');
  }
  renderJob(stored.job, stored.input);
}

async function retry() {
  showMessage('Traduzindo novamente…');
  const response = await chrome.runtime.sendMessage({ type: 'job:retry', id });
  if (!response?.ok) {
    return showMessage(response?.message || 'Não foi possível traduzir novamente.');
  }
  // Novo resultado: troca o id na URL para o recarregamento continuar funcionando.
  id = response.id;
  history.replaceState(null, '', `?id=${id}`);
  renderJob(response.job, true);
}

load();
