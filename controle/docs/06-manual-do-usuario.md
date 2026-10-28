# 06 — Manual do Usuário / Guia de Operação

Guia passo a passo das principais operações do **Sistema de Controle**.
Acesse pelo navegador em **https://controle.cepemar.com**.

> Em alguns ambientes pode aparecer um aviso de certificado (certificado
> interno/autoassinado). É esperado — prossiga para o site.

---

## 1. Entrar no sistema (login)

1. Abra `https://controle.cepemar.com`.
2. Informe **Usuário ou Email** e **Senha**.
   - Use seu login de rede (Active Directory, ex.: `nome.sobrenome`) ou um
     usuário local cadastrado.
3. Clique **Entrar**.
4. Para sair, use o menu do usuário no canto superior direito → **Sair**.

Se o login falhar, confira usuário/senha; o acesso via rede exige que você
pertença a um dos grupos autorizados (fale com a TI).

## 2. Área inicial

Após entrar, escolha o que gerenciar:

- **Rateio Microsoft** — licenças Microsoft.
- **Rateio Telefonia** — linhas telefônicas.
- **PEPs / Projetos** — cadastro compartilhado.
- **Usuários**, **Contatos** e **Configuração SMTP** — apenas administradores.

A barra superior mostra o **contexto atual** (Inicial / Microsoft / Telefonia).

## 3. PEPs / Projetos

1. Menu **PEPs / Projetos** → **Listar**.
2. **Novo**: botão *Novo PEP*, preencha código do PEP, projeto, centro de
   custo, período, datas e responsável → **Salvar**.
3. **Editar/Excluir**: ícones na linha correspondente.
4. **Importar vários**: *Importar CSV* → baixe o **modelo** (`PEP, Projeto`),
   preencha, envie. PEPs já existentes são ignorados (reportados no resumo).

## 4. Rateio Microsoft

### 4.1 Cadastrar contas e licenças
1. **Licenças** → cadastre código, descrição e valor de cada licença.
2. **Contas** → cadastre o usuário (nome, e-mail), selecione o **PEP** e marque
   as **licenças** que ele usa.
3. Importação em massa de contas: *Importar CSV* (`Nome, Email, PEP, Licencas`;
   várias licenças separadas por `;`, ex.: `E3;Power BI`).

### 4.2 Lançar a cobrança do período
1. **Cobranças** → *Nova*: informe **mês**, **ano** e **valor total** (o valor
   do boleto/fatura do período) → **Salvar**.

### 4.3 Gerar o relatório/rateio
1. **Relatórios** → selecione o período (mês/ano).
2. O sistema calcula o rateio proporcional por PEP (ver fórmula em
   [01 — Requisitos](01-requisitos-funcionalidades.md#4-regra-de-negócio-central--cálculo-do-rateio)).
3. **Gerar** salva o resultado em **Rateios Gerados** (histórico).
4. **Exportar CSV** baixa o resultado (abre corretamente no Excel).

## 5. Rateio Telefonia

Fluxo igual ao Microsoft, com estas diferenças:

- **Contas**: nome do usuário, telefone, operadora, PEP, valor e **Conta
  Telefonia**.
- Importação: `Nome do Usuario, Telefone, Operadora, PEP, Valor, Conta Telefonia`.
- **Atualizar valores por CSV**: atualiza o campo *valor* das linhas existentes
  casando pelo **telefone** (útil quando chega a fatura do mês).
- **Cobranças**: mês, ano, valor total e Conta Telefonia.

## 6. Enviar o rateio por e-mail

1. Pré-requisitos (admin): **Configuração SMTP** preenchida e **Contatos**
   cadastrados na lista da gerência (Microsoft e/ou Telefonia).
2. Em **Rateios Gerados**, na linha do rateio, clique **E-mail**.
3. O sistema envia o **resumo por PEP** no corpo do e-mail + **CSV anexo** para
   os contatos da lista correspondente.

## 7. Controle de Dispositivos

### 7.1 Cadastro manual
1. **Dispositivos** → **Listar** → *Novo*.
2. Escolha o **tipo**, preencha nome, nº de série/IMEI, modelo, fabricante,
   status, localização, responsável, PEP e valor → **Salvar**.

### 7.2 Importar do GLPI (admin)
1. **Dispositivos** → **Sincronizar GLPI**.
2. Confirme a sincronização. O sistema importa computadores, monitores,
   celulares, impressoras, equipamentos de rede e periféricos do GLPI.
3. Itens já importados são atualizados (não duplicam). O resultado fica
   registrado no log de sincronizações.

### 7.3 Vincular PEP e relatórios
- **Vincular PEP**: selecione dispositivos na lista e atribua um PEP.
- **Relatórios**: visão por tipo, status e PEP.

## 8. Exportação e importação CSV (geral)

- **Exportar**: nas listagens, marque itens (checkbox) e *Exportar
  selecionados*, ou *Exportar todos* do filtro atual.
- **Importar** (admin): baixe o **modelo** da tela, preencha e envie. O sistema
  aceita separador `,` ou `;`, valida linha a linha e exibe um resumo com os
  registros importados e os recusados (com o motivo).

## 9. Perfis e permissões

- **Administrador**: tudo (cadastrar, editar, excluir, importar, configurar).
- **Usuário**: apenas consulta das telas + gerar e exportar relatórios.

Se uma ação exibir "Acesso negado", seu perfil é *usuario* — solicite à TI.
