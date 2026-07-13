# Guia de Uso - Sistema de Manutenção de Máquinas

## Sumário
1. Login e Autenticação
2. Módulo de Usuários
3. Módulo de Perfis
4. Módulo SMTP
5. Módulo de Formulários
6. Módulo de Preenchimentos
7. Módulo de Setores
8. Módulo de Relatórios

---

## 1. Login e Autenticação

### Acessar o Sistema
- URL: `http://localhost/manutencao/public/`
- Escolha o tipo de autenticação: Local ou LDAP
- Insira seu usuário e senha

### Credenciais Padrão
- **Usuário**: admin
- **Senha**: admin

**IMPORTANTE**: Altere a senha padrão imediatamente após o primeiro acesso!

---

## 2. Módulo de Usuários

### Criar um Novo Usuário
1. Clique em "Usuários" no menu lateral
2. Clique em "+ Novo Usuário"
3. Preencha os campos:
   - **Nome de Usuário**: Identificador único
   - **Email**: Endereço de email válido
   - **Nome Completo**: Nome do usuário
   - **Tipo de Autenticação**: Local ou LDAP
   - **Perfil**: Selecione o perfil de acesso
4. Clique em "Criar Usuário"

### Editar Usuário
1. Vá para "Usuários"
2. Clique em "Editar" no usuário desejado
3. Altere os dados e clique em "Salvar"

### Deletar Usuário
1. Vá para "Usuários"
2. Clique em "Deletar" no usuário desejado
3. Confirme a ação

---

## 3. Módulo de Perfis

### Criar um Novo Perfil
1. Clique em "Perfis" no menu lateral
2. Clique em "+ Novo Perfil"
3. Defina:
   - **Nome**: Nome do perfil
   - **Descrição**: Descrição do perfil
4. Clique em "Criar Perfil"
5. Será redirecionado para a tela de permissões

### Configurar Permissões
1. Vá para "Perfis"
2. Clique em "Permissões" no perfil desejado
3. Marque as permissões para cada módulo:
   - ✓ **Visualizar**: Pode acessar o módulo
   - ✓ **Criar**: Pode criar novos registros
   - ✓ **Editar**: Pode editar registros
   - ✓ **Deletar**: Pode deletar registros
4. Clique em "Salvar Permissões"

### Módulos Disponíveis
- Usuários
- Perfis
- SMTP
- Formulários
- Preenchimentos
- Setores
- Relatórios

---

## 4. Módulo SMTP

### Configurar Servidor SMTP
1. Clique em "SMTP" no menu lateral
2. Preencha os campos:
   - **Host SMTP**: smtp.seuservidor.com
   - **Porta SMTP**: 587 (TLS) ou 465 (SSL)
   - **Usuário SMTP**: seu.email@dominio.com
   - **Senha SMTP**: sua_senha
   - **Email de Origem**: seu.email@dominio.com
   - **Criptografia**: TLS, SSL ou Nenhuma
3. Marque "Ativar esta configuração SMTP"
4. Clique em "Testar Conexão" para verificar
5. Clique em "Salvar Configuração"

### Exemplo: Gmail
- **Host**: smtp.gmail.com
- **Porta**: 587
- **Usuário**: seu_email@gmail.com
- **Criptografia**: TLS
- **Nota**: Use "Senhas de Aplicativo" em vez da senha da conta

---

## 5. Módulo de Formulários

### Criar um Novo Formulário
1. Clique em "Formulários" no menu lateral
2. Clique em "+ Novo Formulário"
3. Defina:
   - **Título**: Nome do formulário
   - **Descrição**: Descrição do formulário
   - **Categoria**: Selecione uma categoria
4. Clique em "Criar Formulário"

### Usar o Editor de Formulários
1. No formulário criado, clique em "Editor"
2. **Adicionar Seção**:
   - Clique em "+ Adicionar Seção"
   - Defina o título e descrição
   - Configure o número de colunas
3. **Adicionar Campo**:
   - Clique em "+ Adicionar Campo"
   - Selecione o tipo de campo (texto, número, email, etc.)
   - Configure propriedades (rótulo, placeholder, obrigatório)
4. **Arrastar e Reordenar**: Arraste os campos para reorganizá-los
5. **Salvar**: Clique em "Salvar Formulário"

### Tipos de Campos Disponíveis
- **Texto**: Para informações curtas
- **Email**: Validação de email
- **Senha**: Campo mascarado
- **Número**: Apenas números
- **Telefone**: Formato de telefone
- **URL**: Validação de URL
- **Checkbox**: Múltiplas seleções
- **Radio**: Seleção única
- **Select**: Menu suspenso
- **Data**: Seletor de data
- **Hora**: Seletor de hora
- **DateTime**: Data e hora
- **Textarea**: Texto longo
- **File**: Upload de arquivo

### Gerenciar Categorias
1. Em "Formulários", clique em "Categorias"
2. Crie, edite ou delete categorias
3. As categorias ajudam a organizar os formulários

---

## 6. Módulo de Preenchimentos

### Criar um Novo Preenchimento
1. Clique em "Preenchimentos" no menu lateral
2. Clique em "+ Novo Preenchimento"
3. Selecione:
   - **Formulário**: O formulário a preencher
   - **Tipo**: Avulso (máquina específica) ou Programada (setor)
   - **Local**: Setor ou Dispositivo
   - **Tipo de Manutenção**: Preventiva ou Corretiva
   - **Técnico Responsável**: Selecione um técnico
4. Clique em "Continuar"

### Preencher Formulário
1. Preencha todos os campos obrigatórios
2. Para arquivos, clique em "Selecionar Arquivo"
3. Clique em "Enviar Preenchimento"

### Visualizar Preenchimento
1. Vá para "Preenchimentos"
2. Clique em "Ver" no preenchimento desejado
3. Visualize as respostas e o histórico

### Histórico de Alterações
- Cada preenchimento mantém um log de:
  - Data e hora de criação
  - Alterações realizadas
  - Usuário responsável

---

## 7. Módulo de Setores

### Criar um Novo Setor
1. Clique em "Setores" no menu lateral
2. Clique em "+ Novo Setor"
3. Preencha:
   - **Nome**: Nome do setor
   - **Localização**: Onde fica o setor
   - **Responsável**: Usuário responsável
   - **Email**: Email do setor
   - **Telefone**: Telefone de contato
4. Clique em "Criar Setor"

### Adicionar Dispositivos
1. Na página do setor, clique em "Adicionar Dispositivo"
2. Preencha:
   - **Nome**: Nome do dispositivo
   - **Modelo**: Modelo do dispositivo
   - **Número de Série**: Serial number
   - **Tipo**: Tipo de dispositivo
3. Clique em "Salvar"

### Adicionar Periféricos
1. Clique no dispositivo
2. Clique em "Adicionar Periférico"
3. Preencha:
   - **Nome**: Nome do periférico (impressora, monitor, etc.)
   - **Tipo**: Tipo de periférico
   - **Modelo**: Modelo
   - **Serial**: Número de série
4. Clique em "Salvar"

---

## 8. Módulo de Relatórios

### Gerar Relatórios
1. Clique em "Relatórios" no menu lateral
2. Selecione o tipo:
   - **Resumo Geral**: Total de manutenções
   - **Por Formulário**: Quebrada por formulário usado
   - **Por Técnico**: Quebrada por técnico responsável
   - **Por Setor**: Quebrada por setor
   - **Por Tipo de Manutenção**: Preventivas vs. Corretivas
3. Defina o período (data inicial e final)
4. Clique em "Atualizar"

### Exportar Relatório
1. Após gerar o relatório
2. Clique em "Exportar CSV"
3. Abra em Excel ou Calc para análise

---

## Boas Práticas

### Segurança
- ✓ Altere a senha padrão imediatamente
- ✓ Use senhas fortes para todos os usuários
- ✓ Configure LDAP para integração com Active Directory
- ✓ Revise permissões regularmente

### Organização
- ✓ Crie categorias claras para formulários
- ✓ Use nomes descritivos para usuários e setores
- ✓ Mantenha os dados de técnicos atualizados
- ✓ Registre todas as manutenções (preventivas e corretivas)

### Manutenção
- ✓ Faça backup regular do banco de dados
- ✓ Revise os logs de auditoria
- ✓ Limpe dados antigos periodicamente
- ✓ Monitore o uso de disco

---

## Troubleshooting

### Não consigo fazer login
- Verifique se o usuário existe
- Confirme a senha (diferenciam maiúsculas/minúsculas)
- Se usar LDAP, verifique a conexão com o servidor

### Não posso ver um módulo
- Seu perfil pode não ter permissão
- Peça ao administrador para revisar suas permissões

### Upload de arquivo não funciona
- Verifique se o arquivo não é muito grande
- Certifique-se que a pasta uploads tem permissões de escrita
- Use formatos permitidos (jpg, png, pdf, etc.)

### Email não é enviado
- Verifique a configuração SMTP
- Clique em "Testar Conexão"
- Confirme que o SMTP está ativado

---

## Contato e Suporte

Para dúvidas ou problemas:
1. Consulte a documentação do README.md
2. Verifique os logs do sistema
3. Contate o administrador do sistema

---

**Versão**: 1.0.0  
**Última Atualização**: Junho 2024
