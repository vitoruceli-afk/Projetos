# Tradutor de Imagens (OCR) — extensão para Chrome

Extensão Chrome em **Manifest V3** que reconhece textos em imagens com **OCR local** (Tesseract.js) e traduz o resultado para **Português (Brasil)**. O idioma de origem é detectado pelo provedor de tradução.

Funciona sem servidor próprio. O OCR nunca sai do computador. Só o texto reconhecido é enviado ao provedor de tradução que você configurar.

---

## 1. Análise técnica (antes da implementação)

| # | Pergunta | Resposta |
|---|----------|----------|
| 1 | O que é 100% local? | OCR (Tesseract.js + dados `eng`/`por` na pasta da extensão), pré-processamento, recorte, sobreposição da tradução, histórico, leitura em voz alta (Web Speech API), seleção de área, botão de hover. |
| 2 | O que depende de serviço externo? | Só a **tradução** (Google Cloud Translation, LibreTranslate ou seu endpoint). |
| 3 | APIs oficiais do Google utilizáveis? | **Google Cloud Translation API v2** (com chave do usuário, paga após a cota gratuita). Não há API oficial do Google Lens nem do Google Tradutor web; por isso não há scraping. O **Google Cloud Vision** é o caminho oficial para um OCR em nuvem, previsto na arquitetura mas não incluído. |
| 4 | Limitações de captura no Chrome | `chrome.tabs.captureVisibleTab` captura só a área visível e exige a permissão `activeTab`, concedida por clique no ícone, atalho ou menu de contexto. Um clique feito dentro da própria página **não** concede essa permissão. Páginas internas (`chrome://`), a Chrome Web Store e o visualizador de PDF não aceitam scripts de extensão. |
| 5 | Limitações de CORS | Uma imagem de outra origem sem `Access-Control-Allow-Origin` não pode ser lida em canvas. A extensão tenta: (a) canvas direto; (b) recarregar com `crossOrigin`; (c) captura da área visível. Não contorna CORS. |
| 6 | Melhor OCR para o caso | **Tesseract.js 5.1.1** com core SIMD + LSTM e modelos `tessdata_fast`: boa precisão para texto impresso, roda no navegador, ~6 MB de dados para `eng`+`por`. Um motor em nuvem entra pela interface `OcrEngine`. |
| 7 | Melhor tradução custo-benefício | Google Cloud Translation (500 mil caracteres/mês grátis, depois cobrado por caractere). LibreTranslate self-hosted é gratuito, com a qualidade do modelo open source. |
| 8 | Permissões necessárias | `activeTab`, `scripting`, `storage`, `contextMenus`, `offscreen` (Tesseract em Web Worker), `alarms` (fechar o offscreen ocioso). **Sem** `host_permissions` e sem acesso permanente a sites. |
| 9 | Manter leve | Content scripts só são injetados na aba pedida; o worker do OCR é encerrado após 60 s ocioso; o documento offscreen fecha após 2 min; imagens são reduzidas (lado máximo configurável) antes do OCR; histórico guarda só miniaturas. |
| 10 | MVP × depois | **MVP (incluído):** itens 2.1, 5 (texto e imagens), 6 (A e B), 7, 8, 9, 10, 11, 12, 13, 14, 15, 16 (parcial), 20, 23, 24. **Depois:** PDF, correção de rotação por OSD, OCR em nuvem, tradução offline. Ver seção 9. |

---

## 2. Instalação

1. Abra `chrome://extensions`.
2. Ative o **Modo do desenvolvedor**.
3. Clique em **Carregar sem compactação** e selecione esta pasta (`tradutor-imagens-extensao`).
4. Fixe o ícone na barra do Chrome (opcional).

Requer Chrome **116 ou superior**.

## 3. Uso

| Ação | Como |
|------|------|
| Traduzir uma imagem | Botão direito na imagem → **Traduzir imagem para Português**. Ou `Ctrl+Shift+I` e clique na imagem. |
| Selecionar área | `Ctrl+Shift+T` (ou popup → Selecionar área) e arraste um retângulo. Esc cancela. |
| Traduzir página inteira | Popup → Traduzir página inteira. Clique de novo para desativar: o conteúdo original é restaurado. |
| Imagem do computador | Popup → arraste a imagem para a área pontilhada ou clique para escolher. Abre a página de resultados. |
| Botão ao passar o mouse | Popup → marque “Botão ‘Traduzir’ ao passar o mouse”. Só aparece sobre imagens. |
| Histórico | Popup → Histórico (abre Configurações). |

O resultado mostra o idioma detectado, o texto original, a tradução e os botões **Copiar tradução**, **Copiar texto original**, **🔊 Ouvir tradução** (pt-BR), **Traduzir novamente** e **Fechar**. Quando a opção “Mostrar tradução sobre a imagem” está ligada, a imagem traduzida aparece no card.

---

## 4. Configuração

### 4.1 Google Cloud Translation (opcional, pago após a cota gratuita)

1. Crie um projeto em [Google Cloud Console](https://console.cloud.google.com/).
2. Ative a **Cloud Translation API** e configure o faturamento (há cota gratuita).
3. Em **APIs e serviços → Credenciais**, crie uma **Chave de API**.
4. Em **Restringir chave**, limite a API **Cloud Translation API**. Opcional: adicione restrição de aplicativo do tipo “Aplicativos Chrome” com o ID da extensão.
5. Em Configurações → Tradução → cole a chave.

**Importante sobre a chave:** uma chave usada numa extensão fica disponível para quem tem a extensão instalada (ela roda no seu navegador). Por isso ela deve ser restrita à API e, se a extensão for distribuída, a chave deve ficar atrás de um **servidor seu** (use o provedor “Serviço personalizado”). Nunca publique a chave dentro do código-fonte.

### 4.2 LibreTranslate local (padrão: gratuito e sem envio de texto para fora)

A extensão já vem configurada para `http://localhost:5000`. Você só precisa rodar o servidor.

**Com Docker (recomendado):**

```bash
docker run -d --name libretranslate -p 5000:5000 libretranslate/libretranslate --load-only en,pt
```

`--load-only en,pt` carrega só os modelos de inglês e português, o que reduz o tempo de inicialização e a memória. Adicione outros códigos separados por vírgula se precisar (ex.: `en,pt,es,fr`).

**Primeira execução:** o contêiner baixa os modelos de tradução, o que exige internet e pode levar alguns minutos. Espere até o log mostrar que o servidor está escutando na porta 5000. Depois disso, a tradução funciona offline.

**Verificação:** abra `http://localhost:5000/languages` no navegador. Deve aparecer uma lista JSON de idiomas.

**Sem Docker:** é possível instalar com `pip install libretranslate` (requer Python) e rodar `libretranslate --load-only en,pt`. A documentação oficial está em [docs.libretranslate.com](https://docs.libretranslate.com).

No popup, o indicador “Tradução local disponível” fica verde quando o servidor responde. Se ficar vermelho, o servidor não está rodando.

**Servidor remoto (opcional):** em Configurações → Provedor, troque o endereço para `https://…` e informe a chave, se o servidor exigir.

### 4.3 Serviço personalizado

Seu endpoint recebe `POST {"texts": [...], "source": "auto", "target": "pt-BR"}` e responde `{"translations": [...], "detectedSource": "en"}`. Veja `services/translation/custom-provider.js`.

### 4.4 Atalhos

Os atalhos ficam em `chrome://extensions/shortcuts`. `Ctrl+Shift+I` pode conflitar com o DevTools em algumas versões do Chrome. Nesse caso, troque o atalho ali.

---

## 5. Estrutura do projeto

```
tradutor-imagens-extensao/
├── manifest.json                    Manifest V3 (permissões mínimas, comandos, CSP)
├── background/                      Service worker (módulos ES)
│   ├── service-worker.js            Roteador de mensagens, menus, atalhos, injeção sob demanda
│   ├── pipeline.js                  Captura → OCR → tradução → sobreposição → histórico
│   └── offscreen-manager.js         Cria/fecha o documento offscreen
├── offscreen/                       Documento invisível: Tesseract e processamento de imagem (DOM/canvas)
├── content/                       Scripts injetados na página (clássicos, sem módulos)
│   ├── content.js                   Entrada: mensagens do service worker
│   ├── overlay.js                   Camada de UI em shadow DOM (fechado) + toasts + cards
│   ├── selector.js                  Seleção de área (arrastar retângulo)
│   ├── image-source.js              Extrai pixels da imagem (canvas → CORS → captura)
│   ├── image-detector.js            Modo "clique para escolher" (picker) e botão hover
│   └── page-translator.js           Tradução reversível de textos e imagens da página
├── shared/
│   ├── ui-styles.js                 CSS dos componentes (CSSStyleSheet, compatível com CSP)
│   └── result-card.js               Card de resultado (usado pela página e pelo content script)
├── popup/                           Popup (ESM)
├── options/                         Configurações, histórico, privacidade, licenças (ESM)
├── results/                         Página de resultados (upload e "Abrir em aba")
├── services/
│   ├── ocr/                         OcrEngine (contrato), TesseractEngine
│   ├── image/                       Pré-processamento, Otsu, recorte, miniatura, sobreposição
│   ├── translation/                 TranslationProvider (contrato), Google, LibreTranslate, Custom, registro
│   └── storage/                     Configurações e histórico (chrome.storage.local)
├── utils/                           Erros com mensagens em pt-BR, idiomas
├── vendor/tesseract/                tesseract.min.js 5.1.1, worker, core SIMD+LSTM (Apache-2.0)
├── lang/                            eng.traineddata, por.traineddata (tessdata_fast, Apache-2.0)
├── icons/                           Ícones 16/48/128
└── tests/unit.test.js               Testes unitários (Node)
```

**Separação de responsabilidades:** OCR, tradução, processamento de imagem, interface, armazenamento e APIs do navegador ficam em módulos separados. A pipeline só conhece `runOcr`, `createTranslationProvider` e `callOffscreen`.

---

## 6. Desenvolvimento

### 6.1 Testes unitários

```bash
npm test
```

Cobrem idiomas, mensagens de erro, provedores (com `fetch` simulado), limiar de Otsu, alongamento de contraste e conversão da saída do Tesseract. Não substituem os testes manuais da seção 7.

### 6.2 Como modificar o OCR

- Parâmetros do Tesseract (modo de segmentação, por exemplo): `services/ocr/tesseract-engine.js`, método `recognize`.
- Pré-processamento: `services/image/image-processing.js` (`prepareForOcr`, `binarize`). A segunda tentativa binarizada acontece em `offscreen/offscreen.js` (`RETRY_BELOW`).
- Para um OCR em nuvem (ex.: Google Cloud Vision): crie uma classe que estende `OcrEngine` e implemente `recognize(canvas, { langs })` devolvendo `{ lines: [{ text, confidence, bbox: {x0,y0,x1,y1} }] }`. Como a chamada exige chave, rode-a no service worker, com a chave nas configurações, e não no offscreen.

### 6.3 Como adicionar outro tradutor

1. Crie `services/translation/meu-provider.js` estendendo `TranslationProvider` (de `translation-base.js`) e implemente `requestTranslation(texts, { source, target })`, que retorna `{ translations, detectedSource }`.
2. Registre a classe em `PROVIDERS` (`services/translation/translation-provider.js`).
3. Adicione a opção no `<select id="provider">` de `options/options.html`, o bloco `data-provider` com os campos, e o caso em `isProviderConfigured`.
4. Adicione os campos padrão em `DEFAULT_SETTINGS` (`services/storage/settings.js`).

Use `postJson()` (`translation-base.js`) para ter os erros HTTP convertidos em códigos de erro (`api-key-invalid`, `api-quota`, `network`).

### 6.4 Como adicionar idiomas

- **Destino (tradução):** adicione o código em `TARGET_LANGUAGES` e o nome/bandeira em `NAMES` (`utils/languages.js`). Se o provedor usar outro código, ajuste `providerLanguageCode`.
- **Origem (OCR):** baixe o modelo `tessdata_fast` (ex.: `spa.traineddata`) em [tessdata_fast](https://github.com/tesseract-ocr/tessdata_fast) e coloque em `lang/`. A tela de Configurações marca o idioma como “instalado” automaticamente. Para `chi_sim`, `jpn` e `kor`, o modelo também é necessário.

### 6.5 Como gerar uma versão para distribuição

1. Confira a versão em `manifest.json` e `package.json`.
2. Rode `npm test`.
3. Remova `tests/` e `package.json` do pacote, se não forem necessários.
4. Compacte a pasta (o `manifest.json` precisa estar na raiz do ZIP).
5. Envie para o [Painel do desenvolvedor da Chrome Web Store](https://chrome.google.com/webstore/devconsole) (taxa única de registro). Revise a justificativa das permissões: `activeTab`, `scripting`, `storage`, `contextMenus`, `offscreen`, `alarms`.
6. Não inclua chaves de API no pacote.

---

## 7. Lista de testes manuais

Use uma página de teste com os casos abaixo. Carregue a extensão sem compactação e abra o console do service worker (`chrome://extensions` → Detalhes → Inspecionar visualizações) e o console da página para verificar erros.

| Caso | Passos | Resultado esperado |
|------|--------|--------------------|
| Imagem simples | Botão direito numa imagem com uma frase em inglês → Traduzir imagem | Card com idioma “Inglês 🇺🇸”, original e tradução em pt-BR. |
| Múltiplos textos | Imagem com 3+ blocos (cartaz, botões) | Cada linha aparece na lista; sobreposição cobre cada bloco. |
| Imagem pequena | Imagem de 20×20 px | Mensagem “A imagem é muito pequena…”. Nada é enviado à tradução. |
| Texto inclinado (≤ 15°) | Imagem com texto levemente rotacionado | Reconhecimento parcial aceitável. Acima de ~20°, espere falhas (ver seção 9). |
| Página inteira | Popup → Traduzir página inteira em um artigo | Textos são traduzidos; desativar restaura o original byte a byte. |
| Seleção de área | Ctrl+Shift+T e arraste sobre um gráfico | Card com o texto da área; Esc cancela; área < 8 px avisa. |
| Imagens dinâmicas | Página com carrossel/lazy loading; ative a tradução e role a página | Textos novos são traduzidos após ~1 s (MutationObserver). |
| Página sem imagens | Página só de texto, Ctrl+Shift+I | Mensagem “Clique em uma imagem…”; Esc cancela. Sem erro no console. |
| Tradução local offline | Com o LibreTranslate rodando, desconecte a internet e traduza uma imagem | Tradução funciona normalmente (o modelo já foi baixado). |
| Servidor de tradução parado | Pare o contêiner (`docker stop libretranslate`) e traduza uma imagem | Card com o aviso “Não foi possível conectar ao serviço de tradução…”. O texto original reconhecido continua visível. O popup mostra “Tradução indisponível (inicie o LibreTranslate)”. |
| Erro de API | Chave inválida em Configurações → Testar tradução | “A chave de API de tradução foi recusada…”. |
| CORS | Imagem de outro domínio sem CORS, botão direito | Tentativa de captura da área; se não for possível, mensagem com a sugestão “Selecionar área”. |
| PDF | Abra um PDF no visualizador do Chrome e use Ctrl+Shift+T | Não é suportado (ver seção 9). A captura de tela funciona só se a área estiver visível, mas o overlay não é injetado no visualizador. |
| Páginas internas | Abra `chrome://extensions` e clique no ícone | “Esta página não permite o acesso da extensão…”; o ícone mostra “!”. |
| Histórico | Traduza 2 imagens → Configurações → Histórico | Dois itens com data, miniatura e idiomas. “Limpar histórico” esvazia a lista. |
| Upload | Popup → arraste uma imagem | Abre a aba de resultados; “Traduzir novamente” funciona enquanto a sessão do navegador durar. |
| Ocioso | Faça uma tradução, espere ~3 min sem uso e veja o Gerenciador de Tarefas do Chrome (Shift+Esc) | O processo do documento offscreen deve ter sido encerrado (o alarme fecha o documento 2 min após o último uso). |

---

## 8. Privacidade e segurança

- **OCR local:** a imagem não sai do navegador para reconhecer o texto.
- **Tradução:** envia somente o texto reconhecido (ou o texto da página, na tradução de página inteira) ao provedor escolhido. A imagem não é enviada.
- **Armazenamento:** configurações, chave de API e histórico ficam em `chrome.storage.local` neste navegador. O histórico guarda miniaturas pequenas, não a imagem original.
- **Permissões:** sem acesso permanente a sites. Os scripts só atuam na aba em que você pediu.
- **CSP:** `script-src 'self' 'wasm-unsafe-eval'` (necessário para o WebAssembly do Tesseract). Sem `eval`, sem código remoto.
- **XSS:** texto de OCR e de tradução é inserido com `textContent`, nunca com `innerHTML`.
- **Chaves:** ficam no armazenamento local do navegador, nunca no código. A chave do Google é enviada no cabeçalho `x-goog-api-key`, não na URL.

---

## 9. Limitações conhecidas

- **Sem teste em Chrome real neste ambiente.** O código foi validado por análise de sintaxe (todos os arquivos JS) e pelos testes unitários das funções puras. A integração com o navegador (offscreen, CSP, Worker do Tesseract, captura) precisa ser verificada pela lista da seção 7.
- **PDF:** não suportado. O visualizador de PDF do Chrome não aceita scripts de extensão, então não há como injetar a seleção de área nem ler o texto do PDF. Alternativa: baixar o PDF e abrir a imagem da página, ou usar uma captura de tela e a imagem enviada pelo popup.
- **Rotação:** não há correção automática de rotação (o OSD do Tesseract não está incluído). O modo de página automática cobre inclinações pequenas.
- **Alinhamento na sobreposição:** o texto traduzido sempre fica alinhado à esquerda da caixa original. Cores são aproximadas.
- **Google Lens:** não há API pública oficial. Não foi implementado, e não há scraping.
- **Tradução offline dentro da extensão:** não incluída. Modelos neurais têm dezenas de MB por par de idiomas e exigiriam um runtime WASM de terceiros. A alternativa implementada é o LibreTranslate rodando no próprio computador (seção 4.2), que funciona sem internet depois de baixar os modelos. O servidor precisa estar no ar.
- **Botão de hover em imagens com CORS:** o clique no botão não concede `activeTab`, então a captura de tela não funciona nesse caso. Use o atalho ou o ícone da barra.
- **Sites com CSP restritiva:** a sobreposição (imagem traduzida dentro da página) pode ser bloqueada pelo site. O card mostra um aviso e o botão **Abrir em aba** abre o resultado completo.
- **Idiomas de OCR:** só `eng` e `por` acompanham a extensão. Os demais precisam do arquivo na pasta `lang/`.
- **Dialeto:** o Google retorna português sem garantir a variante brasileira. A extensão pede `pt` ao Google.
- **Histórico de upload:** “Traduzir novamente” depende da sessão do navegador. Imagens maiores que 4 MB não são guardadas para isso.

---

## 10. Bibliotecas e licenças

| Nome | Versão | Licença | Finalidade |
|------|--------|---------|------------|
| Tesseract.js | 5.1.1 | Apache-2.0 | Wrapper JavaScript do Tesseract OCR (`vendor/tesseract/tesseract.min.js`, `worker.min.js`) |
| tesseract.js-core | 5.1.0 | Apache-2.0 | Núcleo WebAssembly do Tesseract, SIMD + LSTM (`vendor/tesseract/core/`) |
| tessdata_fast (`eng`, `por`) | — | Apache-2.0 | Modelos de reconhecimento (`lang/`) |

Nenhuma biblioteca comercial é usada. Não há frameworks de interface. As licenças completas estão em `vendor/tesseract/LICENSE.md` e `vendor/tesseract/core/LICENSE`.
