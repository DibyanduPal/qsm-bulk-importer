-- QSM Bulk Importer: Install SQL template
-- This file is a template. The plugin provides a PHP helper that replaces
-- the placeholder __PREFIX__ with the active WordPress table prefix and
-- __CHARSET__ with the site's charset/collation before running the SQL.
--
-- Do NOT run this file directly in phpMyAdmin without replacing __PREFIX__
-- and __CHARSET__ with appropriate values (e.g. wp_ and DEFAULT CHARACTER SET ...).
--
-- Table: __PREFIX__qsm_import_logs
CREATE TABLE IF NOT EXISTS __PREFIX__qsm_import_logs (
  id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  file_name TEXT NOT NULL,
  uploader_id BIGINT(20) NOT NULL DEFAULT 0,
  quiz_id BIGINT(20) NOT NULL DEFAULT 0,
  quiz_name TEXT DEFAULT '',
  import_time DATETIME NOT NULL,
  status VARCHAR(50) NOT NULL DEFAULT 'pending',
  total_rows INT NOT NULL DEFAULT 0,
  success_rows INT NOT NULL DEFAULT 0,
  failed_rows INT NOT NULL DEFAULT 0,
  question_ids LONGTEXT DEFAULT NULL,
  errors LONGTEXT DEFAULT NULL,
  PRIMARY KEY (id),
  KEY quiz_id_idx (quiz_id)
) __CHARSET__;
