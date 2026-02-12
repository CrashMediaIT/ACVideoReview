-- =====================================================================
-- Arctic Wolves - Video Review System Database Schema
-- =====================================================================
-- This schema extends the existing Arctic_Wolves database.
-- All tables are prefixed with `vr_` (video review) to avoid conflicts.
--
-- EXISTING TABLES REFERENCED (DO NOT RECREATE):
--   users, teams, team_roster, team_coach_assignments, seasons,
--   game_schedules, drills, videos, notifications, permissions,
--   user_permissions, locations, player_positions
-- =====================================================================

SET FOREIGN_KEY_CHECKS = 0;

-- =====================================================================
-- 1. VIDEO SOURCE MANAGEMENT (Multi-Camera)
-- =====================================================================

CREATE TABLE IF NOT EXISTS `vr_video_sources` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `game_schedule_id` INT NULL COMMENT 'FK to game_schedules; NULL for non-game footage',
    `team_id` INT NOT NULL COMMENT 'FK to teams',
    `title` VARCHAR(255) NOT NULL,
    `description` TEXT NULL,
    `source_type` ENUM('upload','recording','stream','ndi') NOT NULL DEFAULT 'upload',
    `camera_angle` VARCHAR(100) NULL COMMENT 'e.g. wide, tactical, behind_net, bench, ice_level',
    `ndi_camera_id` INT UNSIGNED NULL COMMENT 'FK to ndi_cameras in main Arctic Wolves DB; used when source_type=ndi',
    `file_path` VARCHAR(500) NULL,
    `file_url` VARCHAR(500) NULL,
    `file_size` BIGINT UNSIGNED NULL COMMENT 'File size in bytes',
    `duration_seconds` INT UNSIGNED NULL,
    `format` VARCHAR(50) NULL COMMENT 'e.g. mp4, mov, mkv',
    `thumbnail_path` VARCHAR(500) NULL,
    `resolution` VARCHAR(20) NULL COMMENT 'e.g. 1920x1080, 3840x2160',
    `recorded_at` DATETIME NULL,
    `uploaded_by` INT NOT NULL COMMENT 'FK to users',
    `status` ENUM('uploading','processing','ready','error') NOT NULL DEFAULT 'uploading',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_vr_video_sources_game` (`game_schedule_id`),
    INDEX `idx_vr_video_sources_team` (`team_id`),
    INDEX `idx_vr_video_sources_uploaded_by` (`uploaded_by`),
    INDEX `idx_vr_video_sources_status` (`status`),
    INDEX `idx_vr_video_sources_recorded_at` (`recorded_at`),
    INDEX `idx_vr_video_sources_ndi_camera` (`ndi_camera_id`),
    CONSTRAINT `fk_vr_video_sources_game` FOREIGN KEY (`game_schedule_id`) REFERENCES `game_schedules` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_vr_video_sources_team` FOREIGN KEY (`team_id`) REFERENCES `teams` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_vr_video_sources_uploaded_by` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Multi-camera video source files';

-- =====================================================================
-- 2. VIDEO CLIPS (Tagged segments from source videos)
-- =====================================================================

CREATE TABLE IF NOT EXISTS `vr_video_clips` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `source_video_id` INT UNSIGNED NOT NULL COMMENT 'FK to vr_video_sources',
    `game_schedule_id` INT NULL COMMENT 'FK to game_schedules',
    `title` VARCHAR(255) NOT NULL,
    `description` TEXT NULL,
    `start_time` DECIMAL(10,3) NOT NULL COMMENT 'Start time in seconds',
    `end_time` DECIMAL(10,3) NOT NULL COMMENT 'End time in seconds',
    `duration` DECIMAL(10,3) GENERATED ALWAYS AS (`end_time` - `start_time`) STORED COMMENT 'Computed duration in seconds',
    `clip_file_path` VARCHAR(500) NULL,
    `thumbnail_path` VARCHAR(500) NULL,
    `created_by` INT NOT NULL COMMENT 'FK to users',
    `updated_by` INT NULL COMMENT 'FK to users',
    `is_published` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    CONSTRAINT `chk_vr_video_clips_time_range` CHECK (`end_time` >= `start_time`),
    INDEX `idx_vr_video_clips_source` (`source_video_id`),
    INDEX `idx_vr_video_clips_game` (`game_schedule_id`),
    INDEX `idx_vr_video_clips_created_by` (`created_by`),
    INDEX `idx_vr_video_clips_published` (`is_published`),
    CONSTRAINT `fk_vr_video_clips_source` FOREIGN KEY (`source_video_id`) REFERENCES `vr_video_sources` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_vr_video_clips_game` FOREIGN KEY (`game_schedule_id`) REFERENCES `game_schedules` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_vr_video_clips_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_vr_video_clips_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Clips cut from source videos with time ranges';

-- =====================================================================
-- 3. TAG DEFINITIONS
-- =====================================================================

CREATE TABLE IF NOT EXISTS `vr_tags` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(100) NOT NULL,
    `category` ENUM('zone','skill','situation','custom') NOT NULL,
    `description` TEXT NULL,
    `color` VARCHAR(7) NULL COMMENT 'Hex color code e.g. #FF5733',
    `icon` VARCHAR(50) NULL COMMENT 'Icon identifier',
    `is_system` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = built-in tag, cannot be deleted',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE INDEX `idx_vr_tags_name_category` (`name`, `category`),
    INDEX `idx_vr_tags_category` (`category`),
    INDEX `idx_vr_tags_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Tag definitions for categorizing clips';

-- Default Zone tags
INSERT INTO `vr_tags` (`name`, `category`, `color`, `is_system`) VALUES
    ('Offensive Zone', 'zone', '#E74C3C', 1),
    ('Defensive Zone', 'zone', '#3498DB', 1),
    ('Neutral Zone',   'zone', '#95A5A6', 1);

-- Default Skill tags
INSERT INTO `vr_tags` (`name`, `category`, `color`, `is_system`) VALUES
    ('Shooting',     'skill', '#E67E22', 1),
    ('Passing',      'skill', '#2ECC71', 1),
    ('Skating',      'skill', '#9B59B6', 1),
    ('Checking',     'skill', '#E74C3C', 1),
    ('Faceoff',      'skill', '#1ABC9C', 1),
    ('Power Play',   'skill', '#F1C40F', 1),
    ('Penalty Kill', 'skill', '#34495E', 1);

-- Default Situation tags
INSERT INTO `vr_tags` (`name`, `category`, `color`, `is_system`) VALUES
    ('Goal',         'situation', '#27AE60', 1),
    ('Save',         'situation', '#2980B9', 1),
    ('Turnover',     'situation', '#C0392B', 1),
    ('Breakout',     'situation', '#8E44AD', 1),
    ('Zone Entry',   'situation', '#D35400', 1),
    ('Odd Man Rush', 'situation', '#F39C12', 1),
    ('Penalty',      'situation', '#7F8C8D', 1);

-- =====================================================================
-- 4. CLIP TAGS (Many-to-many: clips <-> tags)
-- =====================================================================

CREATE TABLE IF NOT EXISTS `vr_clip_tags` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `clip_id` INT UNSIGNED NOT NULL COMMENT 'FK to vr_video_clips',
    `tag_id` INT UNSIGNED NOT NULL COMMENT 'FK to vr_tags',
    `created_by` INT NOT NULL COMMENT 'FK to users',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE INDEX `idx_vr_clip_tags_unique` (`clip_id`, `tag_id`),
    INDEX `idx_vr_clip_tags_tag` (`tag_id`),
    INDEX `idx_vr_clip_tags_created_by` (`created_by`),
    CONSTRAINT `fk_vr_clip_tags_clip` FOREIGN KEY (`clip_id`) REFERENCES `vr_video_clips` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_vr_clip_tags_tag` FOREIGN KEY (`tag_id`) REFERENCES `vr_tags` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_vr_clip_tags_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Tags applied to video clips';

-- =====================================================================
-- 5. CLIP ATHLETES (Athletes tagged in clips)
-- =====================================================================

CREATE TABLE IF NOT EXISTS `vr_clip_athletes` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `clip_id` INT UNSIGNED NOT NULL COMMENT 'FK to vr_video_clips',
    `athlete_id` INT NOT NULL COMMENT 'FK to users (role=athlete)',
    `role_in_clip` VARCHAR(100) NULL COMMENT 'e.g. primary, secondary, goalie',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE INDEX `idx_vr_clip_athletes_unique` (`clip_id`, `athlete_id`),
    INDEX `idx_vr_clip_athletes_athlete` (`athlete_id`),
    CONSTRAINT `fk_vr_clip_athletes_clip` FOREIGN KEY (`clip_id`) REFERENCES `vr_video_clips` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_vr_clip_athletes_athlete` FOREIGN KEY (`athlete_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Athletes tagged in video clips';

-- =====================================================================
-- 6. GAME PLANS
-- =====================================================================

CREATE TABLE IF NOT EXISTS `vr_game_plans` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `game_schedule_id` INT NULL COMMENT 'FK to game_schedules; NULL for practice plans',
    `team_id` INT NOT NULL COMMENT 'FK to teams',
    `plan_type` ENUM('pre_game','post_game','practice') NOT NULL,
    `title` VARCHAR(255) NOT NULL,
    `description` TEXT NULL,
    `offensive_strategy` TEXT NULL COMMENT 'JSON structured offensive strategy data',
    `defensive_strategy` TEXT NULL COMMENT 'JSON structured defensive strategy data',
    `special_teams_notes` TEXT NULL,
    `status` ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
    `created_by` INT NOT NULL COMMENT 'FK to users',
    `published_at` DATETIME NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_vr_game_plans_game` (`game_schedule_id`),
    INDEX `idx_vr_game_plans_team` (`team_id`),
    INDEX `idx_vr_game_plans_status` (`status`),
    INDEX `idx_vr_game_plans_created_by` (`created_by`),
    CONSTRAINT `fk_vr_game_plans_game` FOREIGN KEY (`game_schedule_id`) REFERENCES `game_schedules` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_vr_game_plans_team` FOREIGN KEY (`team_id`) REFERENCES `teams` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_vr_game_plans_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Pre-game, post-game, and practice plans';

-- =====================================================================
-- 7. LINE ASSIGNMENTS (Per game plan)
-- =====================================================================

CREATE TABLE IF NOT EXISTS `vr_line_assignments` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `game_plan_id` INT UNSIGNED NOT NULL COMMENT 'FK to vr_game_plans',
    `line_type` ENUM('forward','defense','power_play','penalty_kill','overtime') NOT NULL,
    `line_number` INT NOT NULL COMMENT 'Line number (1st, 2nd, etc.)',
    `position` VARCHAR(50) NOT NULL COMMENT 'e.g. LW, C, RW, LD, RD, G',
    `athlete_id` INT NOT NULL COMMENT 'FK to users (role=athlete)',
    `notes` TEXT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_vr_line_assignments_plan` (`game_plan_id`),
    INDEX `idx_vr_line_assignments_athlete` (`athlete_id`),
    INDEX `idx_vr_line_assignments_type_number` (`line_type`, `line_number`),
    CONSTRAINT `fk_vr_line_assignments_plan` FOREIGN KEY (`game_plan_id`) REFERENCES `vr_game_plans` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_vr_line_assignments_athlete` FOREIGN KEY (`athlete_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Line assignments within a game plan';

-- =====================================================================
-- 8. DRAW PLAYS (Drill / play drawings for game plans)
-- =====================================================================

CREATE TABLE IF NOT EXISTS `vr_draw_plays` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `game_plan_id` INT UNSIGNED NOT NULL COMMENT 'FK to vr_game_plans',
    `title` VARCHAR(255) NOT NULL,
    `description` TEXT NULL,
    `play_type` ENUM('offensive','defensive','breakout','forecheck','power_play','penalty_kill','faceoff') NOT NULL,
    `canvas_data` LONGTEXT NULL COMMENT 'JSON drawing data from the canvas editor',
    `thumbnail_path` VARCHAR(500) NULL,
    `display_order` INT NOT NULL DEFAULT 0,
    `created_by` INT NOT NULL COMMENT 'FK to users',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_vr_draw_plays_plan` (`game_plan_id`),
    INDEX `idx_vr_draw_plays_type` (`play_type`),
    INDEX `idx_vr_draw_plays_order` (`game_plan_id`, `display_order`),
    INDEX `idx_vr_draw_plays_created_by` (`created_by`),
    CONSTRAINT `fk_vr_draw_plays_plan` FOREIGN KEY (`game_plan_id`) REFERENCES `vr_game_plans` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_vr_draw_plays_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Play diagrams and drawings attached to game plans';

-- =====================================================================
-- 9. REVIEW SESSIONS
-- =====================================================================

CREATE TABLE IF NOT EXISTS `vr_review_sessions` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `game_schedule_id` INT NULL COMMENT 'FK to game_schedules',
    `title` VARCHAR(255) NOT NULL,
    `description` TEXT NULL,
    `session_type` ENUM('team','individual','coaches_only') NOT NULL,
    `scheduled_at` DATETIME NOT NULL,
    `completed_at` DATETIME NULL,
    `created_by` INT NOT NULL COMMENT 'FK to users',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_vr_review_sessions_game` (`game_schedule_id`),
    INDEX `idx_vr_review_sessions_scheduled` (`scheduled_at`),
    INDEX `idx_vr_review_sessions_type` (`session_type`),
    INDEX `idx_vr_review_sessions_created_by` (`created_by`),
    CONSTRAINT `fk_vr_review_sessions_game` FOREIGN KEY (`game_schedule_id`) REFERENCES `game_schedules` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_vr_review_sessions_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Scheduled video review sessions for teams or individuals';

-- =====================================================================
-- 10. REVIEW SESSION CLIPS (Clips in a review session)
-- =====================================================================

CREATE TABLE IF NOT EXISTS `vr_review_session_clips` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `review_session_id` INT UNSIGNED NOT NULL COMMENT 'FK to vr_review_sessions',
    `clip_id` INT UNSIGNED NOT NULL COMMENT 'FK to vr_video_clips',
    `display_order` INT NOT NULL DEFAULT 0,
    `notes` TEXT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE INDEX `idx_vr_review_session_clips_unique` (`review_session_id`, `clip_id`),
    INDEX `idx_vr_review_session_clips_clip` (`clip_id`),
    INDEX `idx_vr_review_session_clips_order` (`review_session_id`, `display_order`),
    CONSTRAINT `fk_vr_review_session_clips_session` FOREIGN KEY (`review_session_id`) REFERENCES `vr_review_sessions` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_vr_review_session_clips_clip` FOREIGN KEY (`clip_id`) REFERENCES `vr_video_clips` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Clips included in a review session with ordering';

-- =====================================================================
-- 11. CALENDAR IMPORTS
-- =====================================================================

CREATE TABLE IF NOT EXISTS `vr_calendar_imports` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `team_id` INT NOT NULL COMMENT 'FK to teams',
    `source_name` VARCHAR(255) NOT NULL COMMENT 'Human-readable name for this import source',
    `source_type` ENUM('teamlinkt','ical','csv','manual') NOT NULL,
    `import_url` TEXT NULL COMMENT 'URL for iCal/API feed',
    `last_synced_at` DATETIME NULL,
    `auto_sync` TINYINT(1) NOT NULL DEFAULT 0,
    `sync_interval_hours` INT NOT NULL DEFAULT 24,
    `imported_by` INT NOT NULL COMMENT 'FK to users',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_vr_calendar_imports_team` (`team_id`),
    INDEX `idx_vr_calendar_imports_type` (`source_type`),
    INDEX `idx_vr_calendar_imports_imported_by` (`imported_by`),
    CONSTRAINT `fk_vr_calendar_imports_team` FOREIGN KEY (`team_id`) REFERENCES `teams` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_vr_calendar_imports_imported_by` FOREIGN KEY (`imported_by`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Tracking calendar import sources and sync schedules';

-- =====================================================================
-- 12. VIDEO PERMISSIONS (Per-user video editor permissions)
-- =====================================================================

CREATE TABLE IF NOT EXISTS `vr_video_permissions` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT NOT NULL COMMENT 'FK to users',
    `team_id` INT NOT NULL COMMENT 'FK to teams',
    `can_upload` TINYINT(1) NOT NULL DEFAULT 0,
    `can_clip` TINYINT(1) NOT NULL DEFAULT 0,
    `can_tag` TINYINT(1) NOT NULL DEFAULT 0,
    `can_publish` TINYINT(1) NOT NULL DEFAULT 0,
    `can_delete` TINYINT(1) NOT NULL DEFAULT 0,
    `granted_by` INT NOT NULL COMMENT 'FK to users',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE INDEX `idx_vr_video_permissions_user_team` (`user_id`, `team_id`),
    INDEX `idx_vr_video_permissions_team` (`team_id`),
    INDEX `idx_vr_video_permissions_granted_by` (`granted_by`),
    CONSTRAINT `fk_vr_video_permissions_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_vr_video_permissions_team` FOREIGN KEY (`team_id`) REFERENCES `teams` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_vr_video_permissions_granted_by` FOREIGN KEY (`granted_by`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Granular video editing permissions per user per team';

-- =====================================================================
-- 13. VIDEO REVIEW NOTIFICATIONS
-- =====================================================================

CREATE TABLE IF NOT EXISTS `vr_notifications` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT NOT NULL COMMENT 'FK to users',
    `notification_type` ENUM('new_video','clip_tagged','game_plan_published','review_session','video_ready','calendar_update') NOT NULL,
    `title` VARCHAR(255) NOT NULL,
    `message` TEXT NULL,
    `link_url` VARCHAR(500) NULL,
    `is_read` TINYINT(1) NOT NULL DEFAULT 0,
    `related_id` INT UNSIGNED NULL COMMENT 'ID of related entity',
    `related_type` VARCHAR(50) NULL COMMENT 'Type of related entity e.g. clip, game_plan, review_session',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_vr_notifications_user` (`user_id`),
    INDEX `idx_vr_notifications_user_read` (`user_id`, `is_read`),
    INDEX `idx_vr_notifications_type` (`notification_type`),
    INDEX `idx_vr_notifications_related` (`related_type`, `related_id`),
    CONSTRAINT `fk_vr_notifications_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Video review specific notifications';

-- =====================================================================
-- 14. DEVICE PAIRING (Dual-device: viewer + controller)
-- =====================================================================

CREATE TABLE IF NOT EXISTS `vr_device_sessions` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `session_code` VARCHAR(8) NOT NULL COMMENT 'Short pairing code shown on viewer',
    `user_id` INT NOT NULL COMMENT 'FK to users',
    `viewer_device_id` VARCHAR(255) NULL COMMENT 'Browser fingerprint / device id of viewer',
    `controller_device_id` VARCHAR(255) NULL COMMENT 'Browser fingerprint / device id of controller',
    `current_video_id` INT UNSIGNED NULL COMMENT 'Currently playing vr_video_sources id',
    `current_clip_id` INT UNSIGNED NULL COMMENT 'Currently playing vr_video_clips id',
    `playback_time` DECIMAL(10,3) NULL COMMENT 'Current playback position in seconds',
    `is_playing` TINYINT(1) NOT NULL DEFAULT 0,
    `status` ENUM('waiting','paired','active','expired') NOT NULL DEFAULT 'waiting',
    `paired_at` DATETIME NULL,
    `last_heartbeat` DATETIME NULL COMMENT 'Last ping from either device',
    `expires_at` DATETIME NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE INDEX `idx_vr_device_sessions_code` (`session_code`),
    INDEX `idx_vr_device_sessions_user` (`user_id`),
    INDEX `idx_vr_device_sessions_status` (`status`),
    INDEX `idx_vr_device_sessions_expires` (`expires_at`),
    CONSTRAINT `fk_vr_device_sessions_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Pairs a viewer device with a controller device for live telestration';

-- =====================================================================
-- 15. TELESTRATION ANNOTATIONS (Live draw on video)
-- =====================================================================

CREATE TABLE IF NOT EXISTS `vr_telestrations` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `clip_id` INT UNSIGNED NULL COMMENT 'FK to vr_video_clips; NULL for source-level annotations',
    `source_video_id` INT UNSIGNED NULL COMMENT 'FK to vr_video_sources',
    `created_by` INT NOT NULL COMMENT 'FK to users',
    `title` VARCHAR(255) NULL,
    `video_time` DECIMAL(10,3) NOT NULL COMMENT 'Timestamp in seconds where annotation appears',
    `duration` DECIMAL(10,3) NULL DEFAULT 3.000 COMMENT 'How long annotation is visible (seconds)',
    `canvas_data` LONGTEXT NOT NULL COMMENT 'JSON: strokes, shapes, arrows, text drawn on canvas',
    `canvas_width` INT NOT NULL DEFAULT 1920 COMMENT 'Reference canvas width for scaling',
    `canvas_height` INT NOT NULL DEFAULT 1080 COMMENT 'Reference canvas height for scaling',
    `is_saved` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '0=live only, 1=persisted',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_vr_telestrations_clip` (`clip_id`),
    INDEX `idx_vr_telestrations_source` (`source_video_id`),
    INDEX `idx_vr_telestrations_time` (`video_time`),
    INDEX `idx_vr_telestrations_created_by` (`created_by`),
    CONSTRAINT `fk_vr_telestrations_clip` FOREIGN KEY (`clip_id`) REFERENCES `vr_video_clips` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_vr_telestrations_source` FOREIGN KEY (`source_video_id`) REFERENCES `vr_video_sources` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_vr_telestrations_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Telestration annotations drawn on video frames';

SET FOREIGN_KEY_CHECKS = 1;
