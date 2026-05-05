USE `risk_assessment`;

ALTER TABLE `unit_ra_item`
  ADD COLUMN IF NOT EXISTS `hazard_4m` VARCHAR(10) NULL
  COMMENT '4M 분류: M1 인적, M2 기계적, M3 관리적, M4 물질환경적'
  AFTER `hazard_name`;
