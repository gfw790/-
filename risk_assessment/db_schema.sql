CREATE DATABASE IF NOT EXISTS `risk_assessment`
  DEFAULT CHARACTER SET utf8mb4
  DEFAULT COLLATE utf8mb4_unicode_ci;

USE `risk_assessment`;

-- 단위위험성평가 헤더
-- 엑셀 기준:
-- A4  = unit_ra_id
-- B4  = unit_code
-- D4  = unit_title
-- I4  = process_name
-- L4  = unit_type
-- O4  = remark
-- A6  = created_by
-- G6  = updated_by
-- M6  = use_yn
-- O6  = sort_no
-- P6  = safe_work_standard_no
-- Q6  = evaluator_name
CREATE TABLE IF NOT EXISTS `unit_ra_header` (
  `unit_ra_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `unit_code` VARCHAR(50) NULL,
  `unit_title` VARCHAR(255) NOT NULL,
  `unit_type` ENUM('target', 'major_work', 'tool', 'env') NOT NULL DEFAULT 'major_work',
  `process_name` VARCHAR(255) NULL,
  `evaluator_name` VARCHAR(100) NULL,
  `remark` TEXT NULL,
  `use_yn` CHAR(1) NOT NULL DEFAULT 'Y',
  `sort_no` INT NOT NULL DEFAULT 0,
  `safe_work_standard_no` VARCHAR(100) NULL,
  `created_by` VARCHAR(100) NULL,
  `updated_by` VARCHAR(100) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`unit_ra_id`),
  UNIQUE KEY `uk_unit_ra_header_unit_code` (`unit_code`),
  KEY `idx_unit_ra_header_type_use_sort` (`unit_type`, `use_yn`, `sort_no`),
  KEY `idx_unit_ra_header_safe_work_standard_no` (`safe_work_standard_no`),
  KEY `idx_unit_ra_header_process_name` (`process_name`),
  KEY `idx_unit_ra_header_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 단위위험성평가 상세 항목
-- 엑셀 기준:
-- A  = sort_no
-- B  = task_code
-- C  = task_name
-- D  = hazard_name
-- E  = accident_type
-- F  = injury_result
-- G  = cause_text
-- H  = likelihood_before
-- I  = severity_before
-- J  = risk_score_before
-- K  = current_control_text
-- L  = likelihood_current
-- M  = severity_current
-- N  = risk_score_current
-- O  = additional_control_text
-- P  = likelihood_after
-- Q  = severity_after
-- R  = risk_score_after
-- S  = improvement_due_date
-- T  = remark
CREATE TABLE IF NOT EXISTS `unit_ra_item` (
  `item_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `unit_ra_id` BIGINT UNSIGNED NOT NULL,
  `sort_no` INT NOT NULL DEFAULT 0,
  `task_code` VARCHAR(50) NULL,
  `task_name` VARCHAR(255) NOT NULL,
  `hazard_name` VARCHAR(255) NOT NULL,
  `accident_type` VARCHAR(255) NULL,
  `injury_result` VARCHAR(255) NULL,
  `cause_text` TEXT NULL,
  `current_control_text` TEXT NULL,
  `additional_control_text` TEXT NULL,
  `likelihood_before` TINYINT UNSIGNED NULL,
  `severity_before` TINYINT UNSIGNED NULL,
  `risk_score_before` TINYINT UNSIGNED NULL,
  `likelihood_current` TINYINT UNSIGNED NULL,
  `severity_current` TINYINT UNSIGNED NULL,
  `risk_score_current` TINYINT UNSIGNED NULL,
  `likelihood_after` TINYINT UNSIGNED NULL,
  `severity_after` TINYINT UNSIGNED NULL,
  `risk_score_after` TINYINT UNSIGNED NULL,
  `improvement_due_date` DATE NULL,
  `remark` TEXT NULL,
  `use_yn` CHAR(1) NOT NULL DEFAULT 'Y',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`item_id`),
  KEY `idx_unit_ra_item_header_sort` (`unit_ra_id`, `sort_no`),
  KEY `idx_unit_ra_item_use_yn` (`use_yn`),
  KEY `idx_unit_ra_item_task_code` (`task_code`),
  CONSTRAINT `fk_unit_ra_item_header`
    FOREIGN KEY (`unit_ra_id`) REFERENCES `unit_ra_header` (`unit_ra_id`)
    ON DELETE CASCADE
    ON UPDATE CASCADE,
  CONSTRAINT `chk_unit_ra_item_use_yn`
    CHECK (`use_yn` IN ('Y', 'N')),
  CONSTRAINT `chk_unit_ra_item_likelihood_before`
    CHECK (`likelihood_before` IS NULL OR `likelihood_before` BETWEEN 1 AND 5),
  CONSTRAINT `chk_unit_ra_item_severity_before`
    CHECK (`severity_before` IS NULL OR `severity_before` BETWEEN 1 AND 5),
  CONSTRAINT `chk_unit_ra_item_likelihood_current`
    CHECK (`likelihood_current` IS NULL OR `likelihood_current` BETWEEN 1 AND 5),
  CONSTRAINT `chk_unit_ra_item_severity_current`
    CHECK (`severity_current` IS NULL OR `severity_current` BETWEEN 1 AND 5),
  CONSTRAINT `chk_unit_ra_item_likelihood_after`
    CHECK (`likelihood_after` IS NULL OR `likelihood_after` BETWEEN 1 AND 5),
  CONSTRAINT `chk_unit_ra_item_severity_after`
    CHECK (`severity_after` IS NULL OR `severity_after` BETWEEN 1 AND 5),
  CONSTRAINT `chk_unit_ra_item_risk_score_before`
    CHECK (`risk_score_before` IS NULL OR `risk_score_before` BETWEEN 1 AND 25),
  CONSTRAINT `chk_unit_ra_item_risk_score_current`
    CHECK (`risk_score_current` IS NULL OR `risk_score_current` BETWEEN 1 AND 25),
  CONSTRAINT `chk_unit_ra_item_risk_score_after`
    CHECK (`risk_score_after` IS NULL OR `risk_score_after` BETWEEN 1 AND 25)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `unit_ra_item_history` (
  `history_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `unit_ra_id` BIGINT UNSIGNED NOT NULL,
  `item_id` BIGINT UNSIGNED NULL,
  `source_item_id` BIGINT UNSIGNED NULL,
  `action_type` VARCHAR(20) NOT NULL,
  `changed_fields` JSON NULL,
  `before_data` JSON NULL,
  `after_data` JSON NULL,
  `changed_by_login_id` VARCHAR(100) NULL,
  `changed_by_name` VARCHAR(100) NULL,
  `changed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`history_id`),
  KEY `idx_unit_ra_item_history_unit_changed` (`unit_ra_id`, `changed_at`),
  KEY `idx_unit_ra_item_history_item` (`item_id`),
  KEY `idx_unit_ra_item_history_source_item` (`source_item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `unit_ra_header`
  ADD CONSTRAINT `chk_unit_ra_header_use_yn`
  CHECK (`use_yn` IN ('Y', 'N'));
