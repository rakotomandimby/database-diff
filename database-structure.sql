CREATE TABLE `mrktmbcdatabase`.`comparison_runs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `source_label` VARCHAR(255) NOT NULL,
  `target_label` VARCHAR(255) NOT NULL,
  `source_database` VARCHAR(255) NOT NULL,
  `target_database` VARCHAR(255) NOT NULL,
  `started_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `completed_at` DATETIME NULL,
  `status` ENUM('running','completed','failed') NOT NULL DEFAULT 'running',
  `error_message` TEXT NULL,
  PRIMARY KEY (`id`),
  INDEX (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `mrktmbcdatabase`.`table_snapshots` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `run_id` BIGINT UNSIGNED NOT NULL,
  `database_side` ENUM('source','target') NOT NULL,
  `table_name` VARCHAR(255) NOT NULL,
  `engine` VARCHAR(64) NULL,
  `collation` VARCHAR(64) NULL,
  `checksum` VARCHAR(64) NULL,
  `metadata_json` JSON NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_run_side_table` (`run_id`,`database_side`,`table_name`),
  CONSTRAINT `fk_table_snapshot_run`
    FOREIGN KEY (`run_id`) REFERENCES `comparison_runs`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `mrktmbcdatabase`.`column_snapshots` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `table_snapshot_id` BIGINT UNSIGNED NOT NULL,
  `column_name` VARCHAR(255) NOT NULL,
  `ordinal_position` INT NOT NULL,
  `column_type` VARCHAR(255) NOT NULL,
  `data_type` VARCHAR(64) NOT NULL,
  `is_nullable` ENUM('YES','NO') NOT NULL,
  `column_key` VARCHAR(3) NOT NULL DEFAULT '',
  `column_default` TEXT NULL,
  `extra` VARCHAR(255) NOT NULL DEFAULT '',
  `collation` VARCHAR(64) NULL,
  `comment` TEXT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_table_column` (`table_snapshot_id`,`column_name`),
  CONSTRAINT `fk_column_snapshot_table`
    FOREIGN KEY (`table_snapshot_id`) REFERENCES `table_snapshots`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `mrktmbcdatabase`.`foreign_key_snapshots` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `table_snapshot_id` BIGINT UNSIGNED NOT NULL,
  `constraint_name` VARCHAR(255) NOT NULL,
  `update_rule` VARCHAR(30) NOT NULL,
  `delete_rule` VARCHAR(30) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_table_constraint` (`table_snapshot_id`,`constraint_name`),
  CONSTRAINT `fk_fk_snapshot_table`
    FOREIGN KEY (`table_snapshot_id`) REFERENCES `table_snapshots`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `mrktmbcdatabase`.`foreign_key_columns` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `foreign_key_id` BIGINT UNSIGNED NOT NULL,
  `position` INT NOT NULL,
  `column_name` VARCHAR(255) NOT NULL,
  `referenced_table` VARCHAR(255) NOT NULL,
  `referenced_column` VARCHAR(255) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_fk_position` (`foreign_key_id`,`position`),
  CONSTRAINT `fk_fk_columns_fk`
    FOREIGN KEY (`foreign_key_id`) REFERENCES `foreign_key_snapshots`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `mrktmbcdatabase`.`table_differences` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `run_id` BIGINT UNSIGNED NOT NULL,
  `table_name` VARCHAR(255) NOT NULL,
  `difference_type` ENUM(
    'missing_in_source',
    'missing_in_target',
    'metadata',
    'columns',
    'foreign_keys',
    'data'
  ) NOT NULL,
  `database_side` ENUM('source','target','both') NOT NULL DEFAULT 'both',
  `payload` JSON NOT NULL,
  PRIMARY KEY (`id`),
  INDEX `idx_run_table` (`run_id`,`table_name`),
  CONSTRAINT `fk_table_diff_run`
    FOREIGN KEY (`run_id`) REFERENCES `comparison_runs`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `mrktmbcdatabase`.`generated_sql` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `run_id` BIGINT UNSIGNED NOT NULL,
  `table_name` VARCHAR(255) NOT NULL,
  `statements` LONGTEXT NOT NULL,
  `model_name` VARCHAR(100) NOT NULL,
  `generated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_run_table_sql` (`run_id`,`table_name`),
  CONSTRAINT `fk_generated_sql_run`
    FOREIGN KEY (`run_id`) REFERENCES `comparison_runs`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
