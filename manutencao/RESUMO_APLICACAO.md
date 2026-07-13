# Sistema de Manutenção de Máquinas - RESUMO EXECUTIVO

## Visão Geral

O Sistema de Manutenção de Máquinas é uma aplicação web completa para gerenciar manutenções preventivas e corretivas de equipamentos. Desenvolvida em PHP 8.0+ com arquitetura MVC, utiliza MySQL como banco de dados e Apache como servidor web.

**Versão**: 1.0.0  
**Desenvolvido em**: Junho 2024  
**Licença**: MIT

---

## Características Principais

### ✅ Funcionalidades Implementadas

#### 1. **Autenticação e Autorização**
- Login local com usuário e senha
- Autenticação via LDAP/Active Directory
- Sistema de perfis com granulação de permissões
- Log de todas as ações do sistema

#### 2. **Gerenciamento de Usuários**
- Criar, editar e deletar usuários
- Suporte para autenticação Local e LDAP
- Atribuição de perfis de acesso
- Filtros avançados

#### 3. **Controle de Perfis**
- Criar perfis customizados
- Configurar permissões por módulo
- Controlar ações: Visualizar, Criar, Editar, Deletar
- Módulos: Usuários, Perfis, SMTP, Formulários, Preenchimentos, Setores, Relatórios

#### 4. **Configuração SMTP**
- Configurar servidor SMTP
- Suporte para TLS, SSL ou sem criptografia
- Autenticação básica
- Teste de conexão
- Ativar/desativar facilmente

#### 5. **Builder de Formulários**
- Editor visual de formulários
- 14 tipos de campos diferentes:
  - Texto, Email, Senha, Número, Telefone, URL
  - Checkbox, Radio, Select
  - Data, Hora, DateTime
  - Textarea, File Upload
- Organizar campos em seções
- Configurar colunas por seção
- Validação de campos obrigatórios
- Duplicar formulários existentes

#### 6. **Categorias de Formulários**
- Organizar formulários por categoria
- Criar, editar e deletar categorias
- Associar formulários a categorias
- Listagem organizada

#### 7. **Preenchimento de Formulários**
- Preencher formulários criados
- Manutenção avulsa (máquina específica) ou programada (setor)
- Tipo de manutenção: Preventiva ou Corretiva
- Upload de arquivos anexos
- Log completo de alterações
- Atribui técnico responsável

#### 8. **Gerenciamento de Setores/Laboratórios**
- Criar setores com responsável
- Adicionar múltiplos dispositivos por setor
- Registrar periféricos para cada dispositivo
- Rastrear localizações

#### 9. **Gerenciamento de Dispositivos**
- Cadastrar máquinas e equipamentos
- Registrar modelo, serial number, tipo
- Adicionar periféricos (impressoras, monitores, etc.)
- Status: Ativo, Inativo, Manutenção

#### 10. **Relatórios Avançados**
- Resumo geral de manutenções
- Relatórios por formulário
- Relatórios por técnico
- Relatórios por setor
- Análise por tipo de manutenção
- Filtrar por período
- Exportar para CSV

#### 11. **Auditoria e Logging**
- Log de todas as ações do sistema
- Rastreamento de alterações de formulários
- Histórico de submissões
- Registro de logins
- Filtros de log

#### 12. **Interface Amigável**
- Design responsivo
- Dashboard com estatísticas
- Menu intuitivo
- Formulários validados
- Mensagens de feedback
- Paginação

---

## Arquitetura Técnica

### Estrutura de Diretórios
```
manutencao/
├── config/              # Configurações
│   ├── Config.php
│   └── Database.php
├── core/                # Classes principais
│   ├── Auth.php        # Autenticação
│   └── Logger.php      # Auditoria
├── models/              # Modelos de dados (OOP)
│   ├── User.php
│   ├── Profile.php
│   ├── SMTPConfig.php
│   ├── Form.php
│   ├── FormCategory.php
│   ├── FormSubmission.php
│   ├── Sector.php
│   └── Device.php
├── controllers/         # Controladores
│   ├── AuthController.php
│   ├── UserController.php
│   ├── ProfileController.php
│   ├── SMTPController.php
│   ├── FormController.php
│   ├── SubmissionController.php
│   ├── SectorController.php
│   └── ReportController.php
├── views/               # Templates HTML
│   ├── header.php       # Layout padrão
│   ├── footer.php
│   ├── login.php
│   ├── dashboard.php
│   ├── users/
│   ├── profiles/
│   ├── smtp/
│   ├── forms/
│   ├── submissions/
│   ├── sectors/
│   └── reports/
├── public/              # Arquivos públicos
│   ├── index.php        # Ponto de entrada
│   ├── .htaccess        # Configuração Apache
│   ├── css/
│   │   └── style.css
│   ├── js/
│   │   └── main.js
│   └── uploads/         # Uploads de usuários
├── sql/
│   └── schema.sql       # Script SQL
├── logs/                # Logs do sistema
├── README.md            # Documentação
├── GUIA_USO.md          # Guia do usuário
└── CHECKLIST_INSTALACAO.md
```

### Banco de Dados

#### Tabelas Principais
- **users**: Usuários do sistema
- **profiles**: Perfis de acesso
- **permissions**: Permissões por módulo
- **forms**: Formulários criados
- **form_categories**: Categorias de formulários
- **form_fields**: Campos dos formulários
- **form_sections**: Seções dos formulários
- **form_submissions**: Preenchimentos
- **form_answers**: Respostas dos preenchimentos
- **sectors**: Setores/Laboratórios
- **devices**: Dispositivos/Máquinas
- **peripherals**: Periféricos dos dispositivos
- **smtp_config**: Configuração SMTP
- **audit_logs**: Log de auditoria
- **submission_history**: Histórico de submissões
- **attachments**: Arquivos anexados

#### Relacionamentos
- Usuários → Perfis (1:N)
- Perfis → Permissões (1:N)
- Formulários → Categorias (N:1)
- Formulários → Campos (1:N)
- Formulários → Seções (1:N)
- Submissões → Formulários (N:1)
- Submissões → Respostas (1:N)
- Setores → Dispositivos (1:N)
- Dispositivos → Periféricos (1:N)

### Padrão de Projeto

**MVC (Model-View-Controller)**
- **Models**: Lógica de dados e negócio
- **Controllers**: Lógica de aplicação
- **Views**: Apresentação ao usuário

**OOP (Programação Orientada a Objetos)**
- Classes para cada entidade
- Encapsulamento
- Reutilização de código
- Herança e polimorfismo onde aplicável

### Segurança

- ✓ Hashing de senhas com bcrypt
- ✓ Proteção contra SQL Injection
- ✓ Validação de entrada
- ✓ CSRF protection ready
- ✓ Log de todas as ações
- ✓ Controle de acesso por perfil
- ✓ Sessões seguras

---

## Requisitos de Sistema

### Servidor
- Apache 2.4+ com mod_rewrite habilitado
- PHP 8.0+ com extensões PDO MySQL
- MySQL 5.7+ ou MariaDB 10.3+

### Cliente
- Navegador moderno (Chrome, Firefox, Edge, Safari)
- JavaScript habilitado
- Cookies habilitados

### Espaço em Disco
- Mínimo: 100 MB para aplicação
- Recomendado: 500 MB (incluindo uploads)

---

## Guia Rápido de Instalação

### 1. Preparar Servidor
```bash
# Linux
mkdir -p /var/www/html/manutencao
cd /var/www/html/manutencao
# Copiar arquivos para este diretório
```

### 2. Criar Banco de Dados
```bash
mysql -u root -p < sql/schema.sql
```

### 3. Configurar Credenciais
Editar `config/Database.php`:
```php
private $host = 'localhost';
private $db_name = 'manutencao_db';
private $username = 'root';
private $password = '';
```

### 4. Habilitar mod_rewrite
```bash
sudo a2enmod rewrite
sudo systemctl restart apache2
```

### 5. Acessar
`http://localhost/manutencao/public/`

**Credenciais padrão**:
- Usuário: admin
- Senha: admin

---

## Exemplos de Uso

### Criar Formulário de Manutenção
1. Ir a "Formulários" → "+ Novo Formulário"
2. Título: "Manutenção de Impressora HP M428"
3. Editar e adicionar campos:
   - Série da impressora (texto)
   - Data da manutenção (data)
   - Tipo de manutenção (radio: preventiva/corretiva)
   - Problemas encontrados (textarea)
   - Fotos antes/depois (file)
   - Recomendações (textarea)
4. Salvar

### Criar Preenchimento
1. Ir a "Preenchimentos" → "+ Novo Preenchimento"
2. Selecionar formulário criado
3. Selecionar setor/dispositivo
4. Preencher campos
5. Anexar fotos
6. Enviar

### Gerar Relatório
1. Ir a "Relatórios"
2. Selecionar tipo: "Por Técnico"
3. Definir período
4. Clicar em "Atualizar"
5. Clicar em "Exportar CSV" para salvar

---

## Recursos Adicionais

### Documentação
- `README.md`: Instruções de instalação
- `GUIA_USO.md`: Manual de usuário
- `CHECKLIST_INSTALACAO.md`: Checklist de implantação

### Suporte
Para dúvidas ou problemas:
1. Consulte os logs em `manutencao/logs/`
2. Verifique o console do navegador (F12)
3. Revise as documentações fornecidas

---

## Limitações Conhecidas

- OAuth2 para SMTP ainda não implementado (use autenticação básica)
- Email de notificação automática não implementado
- Não há sistema de agendamento de manutenções
- Não há integração com API externa
- Relatórios não têm gráficos avançados (apenas doughnut chart)

---

## Próximas Versões (Futuro)

- [ ] OAuth2 para SMTP
- [ ] Notificações por email automáticas
- [ ] Agendamento de manutenções
- [ ] Integração com APIs de terceiros
- [ ] Aplicativo mobile
- [ ] Dashboard com mais gráficos
- [ ] Backup automático
- [ ] Multi-idioma

---

## Histórico de Versões

### v1.0.0 (Junho 2024)
- ✅ Todas as funcionalidades principais implementadas
- ✅ Sistema de autenticação completo
- ✅ Builder de formulários
- ✅ Módulo de preenchimentos
- ✅ Relatórios básicos
- ✅ Auditoria completa

---

## Créditos

**Desenvolvido por**: Sistema de Manutenção v1.0  
**Linguagem**: PHP 8.0+  
**Banco de Dados**: MySQL/MariaDB  
**Servidor Web**: Apache  
**Licença**: MIT

---

## Licença MIT

Copyright (c) 2024 Sistema de Manutenção

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction...

[Veja o arquivo LICENSE para detalhes completos]

---

## Suporte Técnico

Para problemas na instalação ou uso:

1. **Verifique os logs**
   ```bash
   tail -f manutencao/logs/error.log
   ```

2. **Teste a conexão MySQL**
   ```bash
   mysql -h localhost -u root -p manutencao_db
   ```

3. **Verifique permissões**
   ```bash
   ls -la manutencao/logs/
   ls -la manutencao/public/uploads/
   ```

4. **Consulte a documentação**
   - README.md
   - GUIA_USO.md

---

**Data**: Junho 2024  
**Status**: ✅ Completo e Funcional  
**Pronto para Produção**: ✅ Sim

---

*Para mais informações, consulte a documentação incluída no pacote.*
