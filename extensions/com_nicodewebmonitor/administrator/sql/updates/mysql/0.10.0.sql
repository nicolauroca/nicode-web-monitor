CREATE TABLE IF NOT EXISTS `#__nwm_notifications` (
 `site` int unsigned NOT NULL,
 `observed_state` varchar(32) NOT NULL,
 `recipient_hash` char(64) NOT NULL,
 `delivery` varchar(24) NOT NULL,
 `evaluated_at` bigint NOT NULL,
 `attempted_at` bigint NOT NULL DEFAULT 0,
 PRIMARY KEY (`site`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;
