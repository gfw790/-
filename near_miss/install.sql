-- ============================================
-- 사내 게시판 데이터베이스 스키마
-- 실행: mysql -u root -p < install.sql
--    또는 phpMyAdmin Import
-- ============================================

CREATE DATABASE IF NOT EXISTS `board` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `board`;

-- 사용자 캐시 테이블 (사내 시스템과 동기화되는 보조 정보)
CREATE TABLE IF NOT EXISTS `users` (
  `id` VARCHAR(50) NOT NULL COMMENT '사내 계정 ID',
  `name` VARCHAR(50) NOT NULL,
  `dept` VARCHAR(50) DEFAULT NULL,
  `role` ENUM('admin','user') NOT NULL DEFAULT 'user',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_seen` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 직원 목록은 HR 시스템(auth_accounts)에서 직접 조회하므로
-- 로컬 employees 테이블은 생성하지 않습니다.

-- 카테고리
CREATE TABLE IF NOT EXISTS `categories` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(30) NOT NULL,
  `name` VARCHAR(50) NOT NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  `write_role` ENUM('admin','user') NOT NULL DEFAULT 'user' COMMENT '글쓰기 권한',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 게시글
CREATE TABLE IF NOT EXISTS `posts` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `category_id` INT NOT NULL,
  `title` VARCHAR(200) NOT NULL,
  `content` MEDIUMTEXT NOT NULL,
  `author_id` VARCHAR(50) NOT NULL,
  `author_name` VARCHAR(50) NOT NULL,
  `author_dept` VARCHAR(50) DEFAULT NULL,
  `is_notice` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '공지글 여부',
  `views` INT NOT NULL DEFAULT 0,
  `like_count` INT NOT NULL DEFAULT 0,
  `comment_count` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_category` (`category_id`, `created_at`),
  KEY `idx_author` (`author_id`),
  KEY `idx_notice` (`is_notice`, `created_at`),
  FULLTEXT KEY `ft_title_content` (`title`, `content`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 댓글
CREATE TABLE IF NOT EXISTS `comments` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `post_id` INT NOT NULL,
  `parent_id` INT DEFAULT NULL COMMENT '대댓글일 경우 부모 댓글 ID',
  `content` TEXT NOT NULL,
  `author_id` VARCHAR(50) NOT NULL,
  `author_name` VARCHAR(50) NOT NULL,
  `author_dept` VARCHAR(50) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `is_deleted` TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_post` (`post_id`, `created_at`),
  KEY `idx_parent` (`parent_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 첨부파일
CREATE TABLE IF NOT EXISTS `attachments` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `post_id` INT NOT NULL,
  `original_name` VARCHAR(255) NOT NULL,
  `stored_name` VARCHAR(255) NOT NULL,
  `file_size` INT NOT NULL,
  `mime_type` VARCHAR(100) DEFAULT NULL,
  `download_count` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_post` (`post_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 좋아요
CREATE TABLE IF NOT EXISTS `likes` (
  `post_id` INT NOT NULL,
  `user_id` VARCHAR(50) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`post_id`, `user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 투표
CREATE TABLE IF NOT EXISTS `polls` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `post_id` INT NOT NULL,
  `question` VARCHAR(200) NOT NULL,
  `multi_select` TINYINT(1) NOT NULL DEFAULT 0,
  `is_anonymous` TINYINT(1) NOT NULL DEFAULT 0,
  `closes_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_post` (`post_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 투표 항목
CREATE TABLE IF NOT EXISTS `poll_options` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `poll_id` INT NOT NULL,
  `option_text` VARCHAR(200) NOT NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_poll` (`poll_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 투표 응답
CREATE TABLE IF NOT EXISTS `poll_votes` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `poll_id` INT NOT NULL,
  `option_id` INT NOT NULL,
  `user_id` VARCHAR(50) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_option` (`option_id`, `user_id`),
  KEY `idx_poll_user` (`poll_id`, `user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 아차사고(near miss) 설문 데이터
CREATE TABLE IF NOT EXISTS `near_miss_reports` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `post_id` INT NOT NULL,
  `source_excel_id` BIGINT DEFAULT NULL COMMENT '엑셀 원본 ID',
  `source_written_at` DATETIME DEFAULT NULL COMMENT '엑셀 작성 시간',
  `incident_at` DATETIME NOT NULL COMMENT '발생 일시',
  `location` VARCHAR(200) NOT NULL COMMENT '발생 장소',
  `work_type` VARCHAR(100) NOT NULL COMMENT '작업 유형',
  `risk_type` VARCHAR(100) DEFAULT NULL COMMENT '위험 유형',
  `description` TEXT NOT NULL COMMENT '상황 설명',
  `cause` TEXT NOT NULL COMMENT '원인',
  `action_taken` TEXT NOT NULL COMMENT '즉시 조치',
  `prevention_plan` TEXT DEFAULT NULL COMMENT '재발 방지 대책',
  `reporter_contact` VARCHAR(100) DEFAULT NULL COMMENT '연락처',
  `status` ENUM('open','in_progress','closed') NOT NULL DEFAULT 'open' COMMENT '처리 상태',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_post_id` (`post_id`),
  UNIQUE KEY `uk_source_excel_id` (`source_excel_id`),
  KEY `idx_incident_at` (`incident_at`),
  KEY `idx_status` (`status`),
  CONSTRAINT `fk_near_miss_post`
    FOREIGN KEY (`post_id`) REFERENCES `posts`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================
-- 기본 카테고리 데이터
-- ============================================
INSERT INTO `categories` (`code`, `name`, `sort_order`, `write_role`) VALUES
('notice', '공지사항', 1, 'admin'),
('free',   '자유게시판', 2, 'user'),
('qna',    'Q&A',     3, 'user'),
('data',   '자료실',    4, 'user'),
('near_miss', '아차사고', 5, 'user')
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`);

