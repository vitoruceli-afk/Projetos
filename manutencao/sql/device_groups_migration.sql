-- Migração para suportar grupos de dispositivos no preenchimento de formulários.
-- Rode este script uma única vez contra o banco `manutencao_db` já existente
-- (via phpMyAdmin > Importar, ou `mysql -u root -p manutencao_db < sql/device_groups_migration.sql`).

CREATE TABLE `device_groups` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(200) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_device_groups_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `device_group_members` (
  `device_group_id` INT(11) NOT NULL,
  `device_id` INT(11) NOT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`device_group_id`, `device_id`),
  KEY `idx_dgm_device` (`device_id`),
  CONSTRAINT `dgm_ibfk_group` FOREIGN KEY (`device_group_id`) REFERENCES `device_groups` (`id`) ON DELETE CASCADE,
  CONSTRAINT `dgm_ibfk_device` FOREIGN KEY (`device_id`) REFERENCES `devices` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `form_submissions`
  ADD COLUMN `device_group_id` INT(11) DEFAULT NULL AFTER `device_id`,
  ADD KEY `idx_form_submissions_device_group_id` (`device_group_id`),
  ADD CONSTRAINT `form_submissions_ibfk_device_group` FOREIGN KEY (`device_group_id`) REFERENCES `device_groups` (`id`) ON DELETE SET NULL;

CREATE TABLE `submission_device_issues` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `submission_id` INT(11) NOT NULL,
  `device_id` INT(11) NOT NULL,
  `description` TEXT NOT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_sdi_submission` (`submission_id`),
  KEY `idx_sdi_device` (`device_id`),
  CONSTRAINT `sdi_ibfk_submission` FOREIGN KEY (`submission_id`) REFERENCES `form_submissions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `sdi_ibfk_device` FOREIGN KEY (`device_id`) REFERENCES `devices` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
