// Histórico local. Guarda texto e miniatura pequena (não a imagem completa)
// para manter o uso de armazenamento baixo.

const KEY = 'history';

export async function listHistory() {
  const { [KEY]: items } = await chrome.storage.local.get(KEY);
  return Array.isArray(items) ? items : [];
}

export async function addHistoryItem(item, limit = 100) {
  const items = await listHistory();
  const next = [item, ...items].slice(0, Math.max(1, limit));
  await chrome.storage.local.set({ [KEY]: next });
}

export async function deleteHistoryItem(id) {
  const items = await listHistory();
  await chrome.storage.local.set({ [KEY]: items.filter((i) => i.id !== id) });
}

export async function clearHistory() {
  await chrome.storage.local.remove(KEY);
}
