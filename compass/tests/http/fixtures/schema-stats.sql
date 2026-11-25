-- Tables added for the stats pipeline (migrations 2026_11_25_*), dumped for the HTTP harness
ALTER TABLE `databases` ADD `stats_job_at` datetime DEFAULT NULL;
CREATE TABLE `geo_cities` (
  `id` int(10) unsigned NOT NULL,
  `name` varchar(200) NOT NULL,
  `country` varchar(3) NOT NULL,
  `admin1` varchar(20) DEFAULT NULL,
  `lat` decimal(8,5) NOT NULL,
  `lng` decimal(8,5) NOT NULL,
  `population` int(10) unsigned NOT NULL,
  `tz` varchar(64) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `geo_cities_lat_lng_index` (`lat`,`lng`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
CREATE TABLE `geo_countries` (
  `code` varchar(3) NOT NULL,
  `iso3` varchar(3) NOT NULL,
  `name` varchar(100) NOT NULL,
  `continent` varchar(30) NOT NULL,
  `sovereign` varchar(3) NOT NULL,
  PRIMARY KEY (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
CREATE TABLE `geo_cells` (
  `geohash` char(7) NOT NULL,
  `country` varchar(3) DEFAULT NULL,
  `city_id` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`geohash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
CREATE TABLE `geo_tz_cells` (
  `geohash` char(4) NOT NULL,
  `tz` varchar(64) DEFAULT NULL,
  PRIMARY KEY (`geohash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
CREATE TABLE `stats_shards` (
  `database_id` int(10) unsigned NOT NULL,
  `shard_date` date NOT NULL,
  `version` smallint(5) unsigned DEFAULT NULL,
  `dirty_at` datetime DEFAULT NULL,
  `processed_at` datetime DEFAULT NULL,
  `error` varchar(255) DEFAULT NULL,
  `file_size` bigint(20) unsigned DEFAULT NULL,
  `file_mtime` int(10) unsigned DEFAULT NULL,
  `points_total` int(10) unsigned DEFAULT NULL,
  `points_used` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`database_id`,`shard_date`),
  KEY `stats_shards_dirty_at_index` (`dirty_at`),
  KEY `stats_shards_database_id_version_index` (`database_id`,`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
CREATE TABLE `stats_segments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `database_id` int(10) unsigned NOT NULL,
  `shard_date` date NOT NULL,
  `seq` smallint(5) unsigned NOT NULL,
  `type` enum('stop','move') NOT NULL,
  `inferred` tinyint(1) NOT NULL DEFAULT 0,
  `mode` varchar(10) DEFAULT NULL,
  `mode_source` enum('auto','trip') NOT NULL DEFAULT 'auto',
  `motion` varchar(10) DEFAULT NULL,
  `start_at` datetime NOT NULL,
  `end_at` datetime NOT NULL,
  `tz` varchar(64) NOT NULL,
  `local_date` date NOT NULL,
  `duration_s` int(10) unsigned NOT NULL,
  `distance_m` int(10) unsigned NOT NULL DEFAULT 0,
  `reported_distance_m` int(10) unsigned DEFAULT NULL,
  `start_lat` decimal(8,5) NOT NULL,
  `start_lng` decimal(8,5) NOT NULL,
  `end_lat` decimal(8,5) NOT NULL,
  `end_lng` decimal(8,5) NOT NULL,
  `points` int(10) unsigned NOT NULL DEFAULT 0,
  `speed_p50` decimal(6,2) DEFAULT NULL,
  `speed_p95` decimal(6,2) DEFAULT NULL,
  `alt_max` int(11) DEFAULT NULL,
  `country` varchar(3) DEFAULT NULL,
  `city_id` int(10) unsigned DEFAULT NULL,
  `countries` varchar(60) DEFAULT NULL,
  `clipped_start` tinyint(1) NOT NULL DEFAULT 0,
  `clipped_end` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `stats_segments_database_id_shard_date_seq_unique` (`database_id`,`shard_date`,`seq`),
  KEY `stats_segments_database_id_start_at_index` (`database_id`,`start_at`),
  KEY `stats_segments_database_id_end_at_index` (`database_id`,`end_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
CREATE TABLE `stats_days` (
  `database_id` int(10) unsigned NOT NULL,
  `utc_date` date NOT NULL,
  `local_date` date NOT NULL,
  `distance_m` int(10) unsigned NOT NULL DEFAULT 0,
  `flight_distance_m` int(10) unsigned NOT NULL DEFAULT 0,
  `moving_s` int(10) unsigned NOT NULL DEFAULT 0,
  `stopped_s` int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`database_id`,`utc_date`,`local_date`),
  KEY `stats_days_database_id_local_date_index` (`database_id`,`local_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
CREATE TABLE `stats_day_modes` (
  `database_id` int(10) unsigned NOT NULL,
  `utc_date` date NOT NULL,
  `local_date` date NOT NULL,
  `mode` varchar(10) NOT NULL,
  `distance_m` int(10) unsigned NOT NULL DEFAULT 0,
  `duration_s` int(10) unsigned NOT NULL DEFAULT 0,
  `moves` smallint(5) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`database_id`,`utc_date`,`local_date`,`mode`),
  KEY `stats_day_modes_database_id_local_date_index` (`database_id`,`local_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
CREATE TABLE `stats_day_places` (
  `database_id` int(10) unsigned NOT NULL,
  `utc_date` date NOT NULL,
  `local_date` date NOT NULL,
  `country` varchar(3) NOT NULL DEFAULT '',
  `city_id` int(10) unsigned NOT NULL DEFAULT 0,
  `stopped_s` int(10) unsigned NOT NULL DEFAULT 0,
  `moving_s` int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`database_id`,`utc_date`,`local_date`,`country`,`city_id`),
  KEY `stats_day_places_database_id_local_date_index` (`database_id`,`local_date`),
  KEY `stats_day_places_database_id_country_local_date_index` (`database_id`,`country`,`local_date`),
  KEY `stats_day_places_database_id_city_id_local_date_index` (`database_id`,`city_id`,`local_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
ALTER TABLE `databases` ADD `embed_token` varchar(40) DEFAULT NULL, ADD UNIQUE KEY `databases_embed_token_unique` (`embed_token`);
