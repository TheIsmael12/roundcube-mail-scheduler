-- Roundcube Mail Scheduler · esquema inicial (MySQL / MariaDB)
-- Las fechas se guardan como marcas de tiempo Unix (UTC) para que el
-- comportamiento sea idéntico en todos los motores.

CREATE TABLE IF NOT EXISTS `mail_scheduler_messages` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int(10) UNSIGNED NOT NULL,
  `identity_id` int(10) UNSIGNED DEFAULT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'pending',
  `send_at` int(10) UNSIGNED NOT NULL,
  `timezone` varchar(64) NOT NULL DEFAULT 'UTC',
  `recurrence` varchar(16) NOT NULL DEFAULT 'none',
  `recurrence_until` int(10) UNSIGNED DEFAULT NULL,
  `subject` varchar(255) NOT NULL DEFAULT '',
  `mail_from` varchar(255) NOT NULL DEFAULT '',
  `recipients` text NOT NULL,
  `rcpt_display` text NOT NULL,
  `options` text NOT NULL,
  `mime` longtext NOT NULL,
  `size` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `attempts` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `sent_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `last_error` text DEFAULT NULL,
  `last_sent_at` int(10) UNSIGNED DEFAULT NULL,
  `created` int(10) UNSIGNED NOT NULL,
  `changed` int(10) UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  INDEX `mail_scheduler_messages_user_idx` (`user_id`, `status`),
  INDEX `mail_scheduler_messages_due_idx` (`status`, `send_at`),
  CONSTRAINT `mail_scheduler_messages_user_fk` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ROW_FORMAT=DYNAMIC ENGINE=INNODB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `mail_scheduler_autoreplies` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int(10) UNSIGNED NOT NULL,
  `name` varchar(128) NOT NULL DEFAULT '',
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `date_from` int(10) UNSIGNED DEFAULT NULL,
  `date_until` int(10) UNSIGNED DEFAULT NULL,
  `timezone` varchar(64) NOT NULL DEFAULT 'UTC',
  `identity_id` int(10) UNSIGNED DEFAULT NULL,
  `signature_id` int(10) UNSIGNED DEFAULT NULL,
  `subject` varchar(255) NOT NULL DEFAULT '',
  `body` longtext NOT NULL,
  `is_html` tinyint(1) NOT NULL DEFAULT 1,
  `interval_days` smallint(5) UNSIGNED NOT NULL DEFAULT 4,
  `options` text NOT NULL,
  `last_uid` int(10) UNSIGNED DEFAULT NULL,
  `uidvalidity` varchar(32) DEFAULT NULL,
  `last_error` text DEFAULT NULL,
  `reply_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `created` int(10) UNSIGNED NOT NULL,
  `changed` int(10) UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  INDEX `mail_scheduler_autoreplies_user_idx` (`user_id`, `enabled`),
  CONSTRAINT `mail_scheduler_autoreplies_user_fk` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ROW_FORMAT=DYNAMIC ENGINE=INNODB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `mail_scheduler_autoreply_log` (
  `autoreply_id` int(10) UNSIGNED NOT NULL,
  `sender` varchar(191) NOT NULL,
  `sent_at` int(10) UNSIGNED NOT NULL,
  PRIMARY KEY (`autoreply_id`, `sender`),
  CONSTRAINT `mail_scheduler_autoreply_log_fk` FOREIGN KEY (`autoreply_id`)
    REFERENCES `mail_scheduler_autoreplies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ROW_FORMAT=DYNAMIC ENGINE=INNODB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `mail_scheduler_credentials` (
  `user_id` int(10) UNSIGNED NOT NULL,
  `username` varchar(255) NOT NULL,
  `password` text NOT NULL,
  `host` varchar(255) NOT NULL,
  `port` int(10) UNSIGNED NOT NULL DEFAULT 143,
  `ssl` varchar(8) NOT NULL DEFAULT '',
  `changed` int(10) UNSIGNED NOT NULL,
  PRIMARY KEY (`user_id`),
  CONSTRAINT `mail_scheduler_credentials_user_fk` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ROW_FORMAT=DYNAMIC ENGINE=INNODB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
