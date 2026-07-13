-- Migração para suportar a integração com o GLPI.
-- Rode este script uma única vez contra o banco `manutencao_db` já existente
-- (via phpMyAdmin > Importar, ou `mysql -u root -p manutencao_db < sql/glpi_migration.sql`).

-- Dispositivos importados do GLPI (mantém o cadastro manual funcionando normalmente)
ALTER TABLE `devices`
  ADD COLUMN `source` ENUM('manual','glpi') NOT NULL DEFAULT 'manual' AFTER `status`,
  ADD COLUMN `glpi_itemtype` VARCHAR(50) DEFAULT NULL AFTER `source`,
  ADD COLUMN `glpi_items_id` INT(11) DEFAULT NULL AFTER `glpi_itemtype`,
  ADD COLUMN `last_synced_at` DATETIME DEFAULT NULL AFTER `glpi_items_id`,
  ADD UNIQUE KEY `uniq_devices_glpi_ref` (`glpi_itemtype`, `glpi_items_id`);

-- Usuários sincronizados do GLPI (super-admins da entidade configurada, viram Técnicos locais)
ALTER TABLE `users`
  MODIFY COLUMN `auth_type` ENUM('local','ldap','glpi') NOT NULL DEFAULT 'local',
  ADD COLUMN `glpi_user_id` INT(11) DEFAULT NULL AFTER `profile_id`,
  ADD UNIQUE KEY `uniq_users_glpi_user_id` (`glpi_user_id`);

-- Preenchimentos: dispositivo/técnico selecionados + chamado GLPI resultante
ALTER TABLE `form_submissions`
  ADD COLUMN `device_id` INT(11) DEFAULT NULL AFTER `user_id`,
  ADD COLUMN `technician_id` INT(11) DEFAULT NULL AFTER `device_id`,
  ADD COLUMN `glpi_ticket_id` INT(11) DEFAULT NULL AFTER `technician_id`,
  ADD COLUMN `glpi_ticket_error` TEXT DEFAULT NULL AFTER `glpi_ticket_id`,
  ADD KEY `idx_form_submissions_device_id` (`device_id`),
  ADD KEY `idx_form_submissions_technician_id` (`technician_id`);

ALTER TABLE `form_submissions`
  ADD CONSTRAINT `form_submissions_ibfk_device` FOREIGN KEY (`device_id`) REFERENCES `devices` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `form_submissions_ibfk_technician` FOREIGN KEY (`technician_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;
