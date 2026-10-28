-- Reversão do módulo de classificação por IA (migração v33). Executar manualmente.
--
-- 1) Desligar a IA e remover o cron do ia_runner.php (/etc/cron.d/produtividade-ia). A coleta e as regras do administrador
--    continuam normais:
UPDATE ia_config SET modo = 'desligado' WHERE id = 1;
--
-- 2) Apagar as regras criadas pela IA. Os eventos que elas já classificaram continuam com a categoria gravada (a
--    categorização é estática); para devolvê-los a "sem categoria", rode depois Categorias > "Reprocessar Eventos Existentes"
--    (reaplica só as regras que sobraram, as do administrador):
DELETE FROM categoria_regras WHERE origem = 'ia';
--
-- 3) (Opcional) Remover as tabelas e colunas do módulo; deixá-las não afeta nada:
DROP TABLE IF EXISTS ia_avaliacao;
DROP TABLE IF EXISTS ia_log;
DROP TABLE IF EXISTS ia_sugestoes;
DROP TABLE IF EXISTS ia_config;
ALTER TABLE categoria_regras DROP COLUMN sugestao_id, DROP COLUMN origem;
