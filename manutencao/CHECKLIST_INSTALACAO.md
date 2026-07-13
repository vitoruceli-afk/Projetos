# Checklist de Instalação - Sistema de Manutenção de Máquinas

## Requisitos de Servidor
- [ ] Apache 2.4+ instalado
- [ ] PHP 8.0+ instalado
- [ ] MySQL 5.7+ ou MariaDB 10.3+ instalado
- [ ] mod_rewrite habilitado no Apache
- [ ] PDO MySQL habilitado no PHP

## Preparação de Diretórios
- [ ] Pasta `/manutencao` criada em `/var/www/html` (Linux) ou `htdocs` (Windows)
- [ ] Pasta `logs/` criada e com permissão de escrita
- [ ] Pasta `public/uploads/` criada e com permissão de escrita
- [ ] Arquivo `.htaccess` presente em `public/`

## Configuração do Banco de Dados
- [ ] Banco de dados `manutencao_db` criado
- [ ] Arquivo `sql/schema.sql` importado
- [ ] Tabelas criadas com sucesso
- [ ] Usuário padrão 'admin' criado
- [ ] Arquivo `config/Database.php` editado com credenciais corretas

## Configuração da Aplicação
- [ ] Arquivo `config/Config.php` revisado
- [ ] BASE_URL configurada corretamente
- [ ] Fuso horário definido
- [ ] Permissões de arquivo corretas

## Teste de Acesso
- [ ] Acessar `http://localhost/manutencao/public/` com sucesso
- [ ] Login com usuário padrão (admin / admin)
- [ ] Dashboard carregado corretamente
- [ ] Menu lateral funcional
- [ ] Todos os módulos acessíveis

## Validação de Funcionalidades

### Módulo de Usuários
- [ ] Listar usuários
- [ ] Criar novo usuário
- [ ] Editar usuário
- [ ] Deletar usuário
- [ ] Filtrar por status/tipo

### Módulo de Perfis
- [ ] Listar perfis
- [ ] Criar novo perfil
- [ ] Configurar permissões
- [ ] Editar perfil
- [ ] Deletar perfil

### Módulo SMTP
- [ ] Acessar página de configuração
- [ ] Preencher dados SMTP
- [ ] Testar conexão
- [ ] Ativar/desativar SMTP

### Módulo de Formulários
- [ ] Listar formulários
- [ ] Criar novo formulário
- [ ] Acessar editor
- [ ] Adicionar campos
- [ ] Adicionar seções
- [ ] Salvar formulário
- [ ] Duplicar formulário

### Módulo de Preenchimentos
- [ ] Listar preenchimentos
- [ ] Criar novo preenchimento
- [ ] Preencher formulário
- [ ] Anexar arquivo
- [ ] Salvar preenchimento
- [ ] Visualizar preenchimento
- [ ] Ver histórico de alterações

### Módulo de Setores
- [ ] Listar setores
- [ ] Criar novo setor
- [ ] Adicionar dispositivos
- [ ] Adicionar periféricos
- [ ] Editar setor
- [ ] Deletar setor

### Módulo de Relatórios
- [ ] Gerar relatório resumido
- [ ] Gerar relatório por formulário
- [ ] Gerar relatório por técnico
- [ ] Gerar relatório por setor
- [ ] Exportar para CSV

## Configuração de Segurança
- [ ] Alterar senha do admin
- [ ] Criar usuários adicionais
- [ ] Criar perfis apropriados
- [ ] Configurar permissões por perfil
- [ ] Testar acesso com usuário não-admin

## Validação de Performance
- [ ] Páginas carregam em menos de 3 segundos
- [ ] Uploadde arquivo funciona corretamente
- [ ] Filtros e buscas funcionam rapidamente
- [ ] Geração de relatórios completa
- [ ] Exportação CSV funciona

## Backup e Manutenção
- [ ] Criar script de backup automático
- [ ] Testar restauração de backup
- [ ] Limpar cache (se aplicável)
- [ ] Revisar logs de erro
- [ ] Verificar permissões de arquivo

## Documentação
- [ ] README.md lido e compreendido
- [ ] GUIA_USO.md fornecido aos usuários
- [ ] Credenciais padrão anotadas com segurança
- [ ] Contatos de suporte documentados

## Treinamento
- [ ] Administradores treinados
- [ ] Técnicos treinados
- [ ] Usuários finais treinados
- [ ] Manual disponível para consulta

## Pós-Instalação

### Primeira Semana
- [ ] Monitorar logs de erro
- [ ] Coletar feedback dos usuários
- [ ] Ajustar permissões conforme necessário
- [ ] Adicionar usuários faltantes

### Primeira Mês
- [ ] Verificar uso do sistema
- [ ] Fazer backup do banco de dados
- [ ] Revisar relatórios gerados
- [ ] Avaliar performance

### Mensal
- [ ] Fazer backup do banco de dados
- [ ] Revisar logs de auditoria
- [ ] Limpeza de uploads antigos
- [ ] Atualizar dados de setores/dispositivos

---

## Notas Importantes

### Senhas
- Senha padrão: **admin**
- **ALTERAR IMEDIATAMENTE APÓS INSTALAÇÃO**
- Usar senhas fortes (mín. 8 caracteres)

### Backup
```bash
# Backup do banco de dados
mysqldump -u root -p manutencao_db > backup_$(date +%Y%m%d).sql

# Backup da aplicação
tar -czf manutencao_$(date +%Y%m%d).tar.gz /var/www/html/manutencao/
```

### Permissões Linux
```bash
chmod 755 /var/www/html/manutencao/
chmod 755 /var/www/html/manutencao/logs/
chmod 755 /var/www/html/manutencao/public/uploads/
chown -R www-data:www-data /var/www/html/manutencao/
```

---

## Suporte

Se algum item não funcionar:
1. Verifique os logs em `manutencao/logs/error.log`
2. Consulte a documentação README.md
3. Verifique permissões de arquivo/pasta
4. Confirme credenciais do banco de dados

---

**Data de Conclusão**: ___________  
**Responsável**: _______________  
**Assinatura**: ________________
