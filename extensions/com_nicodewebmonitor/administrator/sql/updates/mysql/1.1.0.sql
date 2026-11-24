CREATE TABLE IF NOT EXISTS `#__nwm_pairing` (
 `code_hash` char(64) NOT NULL, `label` varchar(120) NOT NULL,
 `created_by` int unsigned NOT NULL, `expires_at` bigint NOT NULL,
 `request_hash` char(64) DEFAULT NULL, `site` int unsigned DEFAULT NULL,
 PRIMARY KEY (`code_hash`), KEY `expiration` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
