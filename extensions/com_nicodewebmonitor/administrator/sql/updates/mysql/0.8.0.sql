CREATE TABLE IF NOT EXISTS `#__nwm_core_update_credentials` (
 `site` int unsigned NOT NULL, `credential` text NOT NULL, PRIMARY KEY (`site`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `#__nwm_core_update_operations` (
 `operation` varchar(64) NOT NULL, `site` int unsigned NOT NULL, `site_id` char(36) NOT NULL,
 `status` varchar(16) NOT NULL, `receipt` text NOT NULL, `updated_at` bigint NOT NULL,
 PRIMARY KEY (`operation`), KEY `site_history` (`site`,`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
