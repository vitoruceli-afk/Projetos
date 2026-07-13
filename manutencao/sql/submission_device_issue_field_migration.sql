-- Liga o "problema reportado num dispositivo do grupo" a um item específico
-- do checklist (campo tipo checkbox) do formulário.
-- Rode uma única vez: mysql -u root -p manutencao_db < sql/submission_device_issue_field_migration.sql

ALTER TABLE `submission_device_issues`
  ADD COLUMN `field_id` INT(11) DEFAULT NULL AFTER `device_id`,
  ADD KEY `idx_sdi_field` (`field_id`),
  ADD CONSTRAINT `sdi_ibfk_field` FOREIGN KEY (`field_id`) REFERENCES `form_fields` (`id`) ON DELETE SET NULL;
