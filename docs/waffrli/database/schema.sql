-- WAFFRLI schema (structure only, no data): mysqldump --no-data of a freshly migrated database.
-- Regenerate: ./scripts/db/schema-dump.sh (Docker) after 'php artisan migrate'.

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
DROP TABLE IF EXISTS `admin_audit_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `admin_audit_logs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `admin_id` bigint unsigned DEFAULT NULL,
  `action` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `auditable_type` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `auditable_id` bigint unsigned DEFAULT NULL,
  `properties` json DEFAULT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_aal_admin` (`admin_id`),
  KEY `idx_aal_auditable` (`auditable_type`,`auditable_id`),
  KEY `idx_aal_action_created` (`action`,`created_at`),
  CONSTRAINT `fk_aal_admin` FOREIGN KEY (`admin_id`) REFERENCES `admins` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `admins`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `admins` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `password` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `locale` varchar(5) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `remember_token` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `admins_email_unique` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `branch_hour_exceptions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `branch_hour_exceptions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `branch_id` bigint unsigned NOT NULL,
  `kind` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `starts_at` timestamp NOT NULL,
  `ends_at` timestamp NOT NULL,
  `reason` json DEFAULT NULL,
  `created_by_admin_id` bigint unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_bhe_admin` (`created_by_admin_id`),
  KEY `idx_bhe_branch_window` (`branch_id`,`starts_at`,`ends_at`),
  CONSTRAINT `fk_bhe_admin` FOREIGN KEY (`created_by_admin_id`) REFERENCES `admins` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_bhe_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_bhe_kind` CHECK ((`kind` in (_utf8mb4'closed',_utf8mb4'open'))),
  CONSTRAINT `chk_bhe_window` CHECK ((`ends_at` > `starts_at`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `branch_opening_hours`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `branch_opening_hours` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `branch_id` bigint unsigned NOT NULL,
  `day_of_week` tinyint unsigned NOT NULL,
  `opens_at` time NOT NULL,
  `closes_at` time NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_boh_branch_day_open` (`branch_id`,`day_of_week`,`opens_at`),
  CONSTRAINT `fk_boh_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chk_boh_day` CHECK ((`day_of_week` <= 6)),
  CONSTRAINT `chk_boh_times` CHECK ((`opens_at` <> `closes_at`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `branch_product_options`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `branch_product_options` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `branch_product_id` bigint unsigned NOT NULL,
  `product_id` bigint unsigned NOT NULL,
  `product_option_id` bigint unsigned NOT NULL,
  `price_delta` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `is_available` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_bpo_bp_option` (`branch_product_id`,`product_option_id`),
  KEY `fk_bpo_bp_product` (`branch_product_id`,`product_id`),
  KEY `fk_bpo_option_product` (`product_option_id`,`product_id`),
  CONSTRAINT `fk_bpo_bp_product` FOREIGN KEY (`branch_product_id`, `product_id`) REFERENCES `branch_products` (`id`, `product_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_bpo_option_product` FOREIGN KEY (`product_option_id`, `product_id`) REFERENCES `product_options` (`id`, `product_id`) ON DELETE CASCADE,
  CONSTRAINT `chk_bpo_price_delta` CHECK ((`price_delta` >= 0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `branch_products`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `branch_products` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `branch_id` bigint unsigned NOT NULL,
  `product_id` bigint unsigned NOT NULL,
  `store_id` bigint unsigned NOT NULL,
  `price` decimal(18,4) NOT NULL,
  `is_available` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_bp_branch_product` (`branch_id`,`product_id`),
  UNIQUE KEY `uq_bp_id_product` (`id`,`product_id`),
  KEY `fk_bp_branch_store` (`branch_id`,`store_id`),
  KEY `fk_bp_product_store` (`product_id`,`store_id`),
  KEY `idx_bp_product` (`product_id`),
  CONSTRAINT `fk_bp_branch_store` FOREIGN KEY (`branch_id`, `store_id`) REFERENCES `branches` (`id`, `store_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_bp_product_store` FOREIGN KEY (`product_id`, `store_id`) REFERENCES `products` (`id`, `store_id`) ON DELETE CASCADE,
  CONSTRAINT `chk_bp_price` CHECK ((`price` >= 0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `branches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `branches` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `store_id` bigint unsigned NOT NULL,
  `city_id` bigint unsigned NOT NULL,
  `name` json NOT NULL,
  `address` json NOT NULL,
  `lat` decimal(10,7) NOT NULL,
  `lng` decimal(10,7) NOT NULL,
  `phone` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `min_order_amount` decimal(18,4) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `is_accepting_orders` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_branches_id_store` (`id`,`store_id`),
  KEY `idx_branches_city_status` (`city_id`,`is_active`,`is_accepting_orders`),
  KEY `idx_branches_store` (`store_id`),
  CONSTRAINT `fk_branches_city` FOREIGN KEY (`city_id`) REFERENCES `cities` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_branches_store` FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_branches_coordinates` CHECK (((`lat` between -(90) and 90) and (`lng` between -(180) and 180))),
  CONSTRAINT `chk_branches_min_order` CHECK (((`min_order_amount` is null) or (`min_order_amount` >= 0)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cache`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cache` (
  `key` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `value` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `expiration` int NOT NULL,
  PRIMARY KEY (`key`),
  KEY `cache_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cache_locks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cache_locks` (
  `key` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `owner` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `expiration` int NOT NULL,
  PRIMARY KEY (`key`),
  KEY `cache_locks_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cart_item_options`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cart_item_options` (
  `cart_item_id` bigint unsigned NOT NULL,
  `product_option_id` bigint unsigned NOT NULL,
  `quantity` tinyint unsigned NOT NULL DEFAULT '1',
  PRIMARY KEY (`cart_item_id`,`product_option_id`),
  KEY `fk_cio_option` (`product_option_id`),
  CONSTRAINT `fk_cio_item` FOREIGN KEY (`cart_item_id`) REFERENCES `cart_items` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cio_option` FOREIGN KEY (`product_option_id`) REFERENCES `product_options` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chk_cio_quantity` CHECK ((`quantity` >= 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cart_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cart_items` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `cart_id` bigint unsigned NOT NULL,
  `branch_product_id` bigint unsigned NOT NULL,
  `quantity` smallint unsigned NOT NULL DEFAULT '1',
  `configuration_hash` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `notes` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cart_items_line` (`cart_id`,`branch_product_id`,`configuration_hash`),
  KEY `fk_cart_items_bp` (`branch_product_id`),
  CONSTRAINT `fk_cart_items_bp` FOREIGN KEY (`branch_product_id`) REFERENCES `branch_products` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cart_items_cart` FOREIGN KEY (`cart_id`) REFERENCES `carts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chk_cart_items_quantity` CHECK ((`quantity` between 1 and 99))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `carts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `carts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `branch_id` bigint unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_carts_user_branch` (`user_id`,`branch_id`),
  KEY `fk_carts_branch` (`branch_id`),
  CONSTRAINT `fk_carts_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_carts_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `categories` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `slug` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `image` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `name` json NOT NULL,
  `description` json DEFAULT NULL,
  `parent_id` bigint unsigned DEFAULT NULL,
  `sort_order` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_categories_slug` (`slug`),
  KEY `idx_categories_parent` (`parent_id`),
  CONSTRAINT `fk_categories_parent` FOREIGN KEY (`parent_id`) REFERENCES `categories` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cities`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cities` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` json NOT NULL,
  `center_lat` decimal(10,7) NOT NULL,
  `center_lng` decimal(10,7) NOT NULL,
  `radius_km` decimal(6,2) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `sort_order` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `cities_is_active_index` (`is_active`),
  CONSTRAINT `chk_cities_lat_range` CHECK ((`center_lat` between -(90) and 90)),
  CONSTRAINT `chk_cities_lng_range` CHECK ((`center_lng` between -(180) and 180)),
  CONSTRAINT `chk_cities_radius_positive` CHECK ((`radius_km` > 0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `currencies`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `currencies` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `code` char(3) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` json NOT NULL,
  `symbol` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `decimals` tinyint unsigned NOT NULL DEFAULT '2',
  `exchange_rate` decimal(24,12) NOT NULL,
  `is_default` tinyint(1) NOT NULL DEFAULT '0',
  `default_marker` tinyint unsigned GENERATED ALWAYS AS ((case when (`is_default` = 1) then 1 else NULL end)) STORED,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `rate_updated_at` timestamp NULL DEFAULT NULL,
  `rate_updated_by_admin_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `currencies_code_unique` (`code`),
  UNIQUE KEY `uq_currencies_single_default` (`default_marker`),
  KEY `fk_currencies_rate_updated_by_admin` (`rate_updated_by_admin_id`),
  KEY `currencies_is_active_index` (`is_active`),
  CONSTRAINT `fk_currencies_rate_updated_by_admin` FOREIGN KEY (`rate_updated_by_admin_id`) REFERENCES `admins` (`id`) ON DELETE SET NULL,
  CONSTRAINT `chk_currencies_code_format` CHECK (regexp_like(`code`,_utf8mb4'^[A-Z]{3}$')),
  CONSTRAINT `chk_currencies_decimals_range` CHECK ((`decimals` <= 4)),
  CONSTRAINT `chk_currencies_default_consistent` CHECK (((`is_default` = 0) or ((`is_active` = 1) and (`exchange_rate` = 1)))),
  CONSTRAINT `chk_currencies_rate_positive` CHECK ((`exchange_rate` > 0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `currency_redenominations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `currency_redenominations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `from_currency_id` bigint unsigned NOT NULL,
  `to_currency_id` bigint unsigned NOT NULL,
  `conversion_rate` decimal(24,12) NOT NULL,
  `status` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `inflight_marker` tinyint unsigned GENERATED ALWAYS AS ((case when (`status` in (_utf8mb4'pending',_utf8mb4'running')) then 1 else NULL end)) STORED,
  `idempotency_key` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `requested_by_admin_id` bigint unsigned NOT NULL,
  `started_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `failed_at` timestamp NULL DEFAULT NULL,
  `error` text COLLATE utf8mb4_unicode_ci,
  `summary` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `currency_redenominations_idempotency_key_unique` (`idempotency_key`),
  UNIQUE KEY `uq_cur_redenom_single_inflight` (`inflight_marker`),
  KEY `fk_cur_redenom_from` (`from_currency_id`),
  KEY `fk_cur_redenom_to` (`to_currency_id`),
  KEY `fk_cur_redenom_admin` (`requested_by_admin_id`),
  CONSTRAINT `fk_cur_redenom_admin` FOREIGN KEY (`requested_by_admin_id`) REFERENCES `admins` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_cur_redenom_from` FOREIGN KEY (`from_currency_id`) REFERENCES `currencies` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_cur_redenom_to` FOREIGN KEY (`to_currency_id`) REFERENCES `currencies` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_cur_redenom_distinct_currencies` CHECK ((`from_currency_id` <> `to_currency_id`)),
  CONSTRAINT `chk_cur_redenom_rate_positive` CHECK ((`conversion_rate` > 0)),
  CONSTRAINT `chk_cur_redenom_status` CHECK ((`status` in (_utf8mb4'pending',_utf8mb4'running',_utf8mb4'completed',_utf8mb4'failed')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `delivery_areas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `delivery_areas` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `city_id` bigint unsigned NOT NULL,
  `name` json NOT NULL,
  `boundary` json NOT NULL,
  `min_lat` decimal(10,7) NOT NULL,
  `max_lat` decimal(10,7) NOT NULL,
  `min_lng` decimal(10,7) NOT NULL,
  `max_lng` decimal(10,7) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `sort_order` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_delivery_areas_city_bbox` (`city_id`,`is_active`,`min_lat`,`max_lat`),
  CONSTRAINT `fk_delivery_areas_city` FOREIGN KEY (`city_id`) REFERENCES `cities` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_delivery_areas_bbox` CHECK (((`min_lat` <= `max_lat`) and (`min_lng` <= `max_lng`)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `delivery_fee_rules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `delivery_fee_rules` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `city_id` bigint unsigned DEFAULT NULL,
  `delivery_area_id` bigint unsigned DEFAULT NULL,
  `scope_key` varchar(40) COLLATE utf8mb4_unicode_ci GENERATED ALWAYS AS ((case when (`delivery_area_id` is not null) then concat(_utf8mb4'a:',`delivery_area_id`) when (`city_id` is not null) then concat(_utf8mb4'c:',`city_id`) else _utf8mb4'g' end)) STORED,
  `distance_from_km` decimal(6,2) NOT NULL DEFAULT '0.00',
  `distance_to_km` decimal(6,2) NOT NULL,
  `base_fee` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `per_km_fee` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `updated_by_admin_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_delivery_fee_rules_scope_band` (`scope_key`,`distance_from_km`),
  KEY `fk_delivery_fee_rules_city` (`city_id`),
  KEY `fk_delivery_fee_rules_area` (`delivery_area_id`),
  KEY `fk_delivery_fee_rules_admin` (`updated_by_admin_id`),
  KEY `idx_delivery_fee_rules_scope_active` (`scope_key`,`is_active`),
  CONSTRAINT `fk_delivery_fee_rules_admin` FOREIGN KEY (`updated_by_admin_id`) REFERENCES `admins` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_delivery_fee_rules_area` FOREIGN KEY (`delivery_area_id`) REFERENCES `delivery_areas` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_delivery_fee_rules_city` FOREIGN KEY (`city_id`) REFERENCES `cities` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_delivery_fee_rules_area_needs_city` CHECK (((`delivery_area_id` is null) or (`city_id` is not null))),
  CONSTRAINT `chk_delivery_fee_rules_band` CHECK (((`distance_from_km` >= 0) and (`distance_to_km` > `distance_from_km`))),
  CONSTRAINT `chk_delivery_fee_rules_fees` CHECK (((`base_fee` >= 0) and (`per_km_fee` >= 0)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `device_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `device_tokens` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tokenable_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tokenable_id` bigint unsigned NOT NULL,
  `token` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `platform` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'unknown',
  `device_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `device_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `last_seen_at` timestamp NULL DEFAULT NULL,
  `revoked_at` timestamp NULL DEFAULT NULL,
  `meta` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `device_tokens_token_unique` (`token`),
  KEY `device_tokens_tokenable_type_tokenable_id_index` (`tokenable_type`,`tokenable_id`),
  KEY `device_tokens_owner_device_idx` (`tokenable_type`,`tokenable_id`,`device_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `driver_cash_settlements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `driver_cash_settlements` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `driver_id` bigint unsigned NOT NULL,
  `currency_id` bigint unsigned NOT NULL,
  `business_date` date NOT NULL,
  `expected_amount` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `received_amount` decimal(18,4) DEFAULT NULL,
  `status` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'open',
  `settled_by_admin_id` bigint unsigned DEFAULT NULL,
  `settled_at` timestamp NULL DEFAULT NULL,
  `note` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_dcs_driver_currency_day` (`driver_id`,`currency_id`,`business_date`),
  KEY `fk_dcs_currency` (`currency_id`),
  KEY `fk_dcs_admin` (`settled_by_admin_id`),
  KEY `driver_cash_settlements_status_index` (`status`),
  CONSTRAINT `fk_dcs_admin` FOREIGN KEY (`settled_by_admin_id`) REFERENCES `admins` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_dcs_currency` FOREIGN KEY (`currency_id`) REFERENCES `currencies` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_dcs_driver` FOREIGN KEY (`driver_id`) REFERENCES `drivers` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_dcs_amounts` CHECK (((`expected_amount` >= 0) and ((`received_amount` is null) or (`received_amount` >= 0)))),
  CONSTRAINT `chk_dcs_status` CHECK ((`status` in (_utf8mb4'open',_utf8mb4'settled',_utf8mb4'disputed')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `drivers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `drivers` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `phone` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL,
  `password` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `city_id` bigint unsigned NOT NULL,
  `vehicle_type` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `vehicle_plate` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `availability` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'offline',
  `last_lat` decimal(10,7) DEFAULT NULL,
  `last_lng` decimal(10,7) DEFAULT NULL,
  `last_location_at` timestamp NULL DEFAULT NULL,
  `language` varchar(2) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'ar',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_drivers_phone` (`phone`),
  KEY `idx_drivers_city_active_availability` (`city_id`,`is_active`,`availability`),
  CONSTRAINT `fk_drivers_city` FOREIGN KEY (`city_id`) REFERENCES `cities` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_drivers_availability` CHECK ((`availability` in (_utf8mb4'offline',_utf8mb4'available'))),
  CONSTRAINT `chk_drivers_language` CHECK ((`language` in (_utf8mb4'ar',_utf8mb4'en'))),
  CONSTRAINT `chk_drivers_phone_e164` CHECK (regexp_like(`phone`,_utf8mb4'^[+][1-9][0-9]{6,14}$'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `failed_jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `failed_jobs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `connection` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `queue` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `exception` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `favorites`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `favorites` (
  `user_id` bigint unsigned NOT NULL,
  `product_id` bigint unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`user_id`,`product_id`),
  KEY `idx_favorites_product` (`product_id`),
  CONSTRAINT `fk_favorites_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_favorites_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `job_batches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `job_batches` (
  `id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `total_jobs` int NOT NULL,
  `pending_jobs` int NOT NULL,
  `failed_jobs` int NOT NULL,
  `failed_job_ids` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `options` mediumtext COLLATE utf8mb4_unicode_ci,
  `cancelled_at` int DEFAULT NULL,
  `created_at` int NOT NULL,
  `finished_at` int DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `jobs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `queue` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `attempts` tinyint unsigned NOT NULL,
  `reserved_at` int unsigned DEFAULT NULL,
  `available_at` int unsigned NOT NULL,
  `created_at` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `jobs_queue_index` (`queue`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `marketing_broadcasts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `marketing_broadcasts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `admin_id` bigint unsigned NOT NULL,
  `title` json DEFAULT NULL,
  `body` json DEFAULT NULL,
  `audience` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'all_customers',
  `city_id` bigint unsigned DEFAULT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'queued',
  `recipients_count` int unsigned NOT NULL DEFAULT '0',
  `started_at` timestamp NULL DEFAULT NULL,
  `sent_at` timestamp NULL DEFAULT NULL,
  `failure_reason` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `marketing_broadcasts_admin_id_foreign` (`admin_id`),
  KEY `marketing_broadcasts_city_id_foreign` (`city_id`),
  KEY `marketing_broadcasts_status_created_at_index` (`status`,`created_at`),
  CONSTRAINT `marketing_broadcasts_admin_id_foreign` FOREIGN KEY (`admin_id`) REFERENCES `admins` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `marketing_broadcasts_city_id_foreign` FOREIGN KEY (`city_id`) REFERENCES `cities` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `migrations` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `batch` int NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `model_has_permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `model_has_permissions` (
  `permission_id` bigint unsigned NOT NULL,
  `model_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `model_id` bigint unsigned NOT NULL,
  PRIMARY KEY (`permission_id`,`model_id`,`model_type`),
  KEY `model_has_permissions_model_id_model_type_index` (`model_id`,`model_type`),
  CONSTRAINT `model_has_permissions_permission_id_foreign` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `model_has_roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `model_has_roles` (
  `role_id` bigint unsigned NOT NULL,
  `model_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `model_id` bigint unsigned NOT NULL,
  PRIMARY KEY (`role_id`,`model_id`,`model_type`),
  KEY `model_has_roles_model_id_model_type_index` (`model_id`,`model_type`),
  CONSTRAINT `model_has_roles_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `notifications` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `notifiable_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `notifiable_id` bigint unsigned NOT NULL,
  `data` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `read_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `notifications_notifiable_type_notifiable_id_index` (`notifiable_type`,`notifiable_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `offers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `offers` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `store_id` bigint unsigned NOT NULL,
  `branch_id` bigint unsigned DEFAULT NULL,
  `product_id` bigint unsigned DEFAULT NULL,
  `name` json DEFAULT NULL,
  `discount_type` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `discount_value` decimal(18,4) NOT NULL,
  `starts_at` timestamp NOT NULL,
  `ends_at` timestamp NULL DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_by_admin_id` bigint unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_offers_branch` (`branch_id`),
  KEY `fk_offers_admin` (`created_by_admin_id`),
  KEY `idx_offers_store_window` (`store_id`,`is_active`,`starts_at`,`ends_at`),
  KEY `idx_offers_product` (`product_id`),
  CONSTRAINT `fk_offers_admin` FOREIGN KEY (`created_by_admin_id`) REFERENCES `admins` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_offers_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_offers_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_offers_store` FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_offers_type` CHECK ((`discount_type` in (_utf8mb4'percent',_utf8mb4'fixed'))),
  CONSTRAINT `chk_offers_value` CHECK (((`discount_value` > 0) and ((`discount_type` <> _utf8mb4'percent') or (`discount_value` <= 100)))),
  CONSTRAINT `chk_offers_window` CHECK (((`ends_at` is null) or (`ends_at` > `starts_at`)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `order_cancellation_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `order_cancellation_requests` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `order_id` bigint unsigned NOT NULL,
  `customer_note` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `pending_marker` bigint unsigned GENERATED ALWAYS AS ((case when (`status` = _utf8mb4'pending') then `order_id` else NULL end)) STORED,
  `resolved_by_admin_id` bigint unsigned DEFAULT NULL,
  `resolved_at` timestamp NULL DEFAULT NULL,
  `admin_note` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ocr_one_pending` (`pending_marker`),
  KEY `fk_ocr_order` (`order_id`),
  KEY `fk_ocr_admin` (`resolved_by_admin_id`),
  KEY `idx_ocr_status_created` (`status`,`created_at`),
  CONSTRAINT `fk_ocr_admin` FOREIGN KEY (`resolved_by_admin_id`) REFERENCES `admins` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_ocr_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_ocr_status` CHECK ((`status` in (_utf8mb4'pending',_utf8mb4'approved',_utf8mb4'rejected')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `order_cancellations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `order_cancellations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `order_id` bigint unsigned NOT NULL,
  `from_status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `cancelled_by` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL,
  `admin_id` bigint unsigned DEFAULT NULL,
  `cancellation_request_id` bigint unsigned DEFAULT NULL,
  `reason_code` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL,
  `note` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `driver_id` bigint unsigned DEFAULT NULL,
  `driver_handling` varchar(24) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'not_applicable',
  `cash_collected_amount` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `wallet_reversed_amount` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `free_delivery_restored` tinyint(1) NOT NULL DEFAULT '0',
  `idempotency_key` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_oc_order` (`order_id`),
  UNIQUE KEY `uq_oc_idempotency` (`idempotency_key`),
  KEY `fk_oc_admin` (`admin_id`),
  KEY `fk_oc_request` (`cancellation_request_id`),
  KEY `fk_oc_driver` (`driver_id`),
  CONSTRAINT `fk_oc_admin` FOREIGN KEY (`admin_id`) REFERENCES `admins` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_oc_driver` FOREIGN KEY (`driver_id`) REFERENCES `drivers` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_oc_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_oc_request` FOREIGN KEY (`cancellation_request_id`) REFERENCES `order_cancellation_requests` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_oc_admin_required` CHECK (((`cancelled_by` = _utf8mb4'customer') or (`admin_id` is not null))),
  CONSTRAINT `chk_oc_after_pickup_handling` CHECK (((`from_status` <> _utf8mb4'picked_up') or (`driver_handling` <> _utf8mb4'not_applicable'))),
  CONSTRAINT `chk_oc_amounts` CHECK (((`cash_collected_amount` >= 0) and (`wallet_reversed_amount` >= 0))),
  CONSTRAINT `chk_oc_by` CHECK ((`cancelled_by` in (_utf8mb4'customer',_utf8mb4'admin'))),
  CONSTRAINT `chk_oc_customer_pending_only` CHECK (((`cancelled_by` = _utf8mb4'admin') or (`from_status` = _utf8mb4'pending'))),
  CONSTRAINT `chk_oc_driver_handling` CHECK ((`driver_handling` in (_utf8mb4'not_applicable',_utf8mb4'returned_to_branch',_utf8mb4'disposed',_utf8mb4'retained_by_customer'))),
  CONSTRAINT `chk_oc_from_status` CHECK ((`from_status` in (_utf8mb4'pending',_utf8mb4'accepted',_utf8mb4'assigned',_utf8mb4'picked_up'))),
  CONSTRAINT `chk_oc_reason` CHECK ((`reason_code` in (_utf8mb4'customer_request',_utf8mb4'product_unavailable',_utf8mb4'branch_unavailable',_utf8mb4'customer_unreachable',_utf8mb4'customer_refused',_utf8mb4'other')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `order_item_options`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `order_item_options` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `order_item_id` bigint unsigned NOT NULL,
  `product_option_id` bigint unsigned DEFAULT NULL,
  `group_name` json NOT NULL,
  `option_name` json NOT NULL,
  `quantity` tinyint unsigned NOT NULL DEFAULT '1',
  `unit_price_delta` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `total_price_delta` decimal(18,4) NOT NULL DEFAULT '0.0000',
  PRIMARY KEY (`id`),
  KEY `fk_oio_item` (`order_item_id`),
  KEY `fk_oio_option` (`product_option_id`),
  CONSTRAINT `fk_oio_item` FOREIGN KEY (`order_item_id`) REFERENCES `order_items` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_oio_option` FOREIGN KEY (`product_option_id`) REFERENCES `product_options` (`id`) ON DELETE SET NULL,
  CONSTRAINT `chk_oio_total` CHECK (((`quantity` >= 1) and (`total_price_delta` = (`unit_price_delta` * `quantity`))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `order_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `order_items` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `order_id` bigint unsigned NOT NULL,
  `product_id` bigint unsigned NOT NULL,
  `branch_product_id` bigint unsigned DEFAULT NULL,
  `product_name` json NOT NULL,
  `product_image` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `base_unit_price` decimal(18,4) NOT NULL,
  `options_unit_price` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `unit_price` decimal(18,4) NOT NULL,
  `quantity` smallint unsigned NOT NULL,
  `line_subtotal` decimal(18,4) NOT NULL,
  `offer_id` bigint unsigned DEFAULT NULL,
  `offer_discount_amount` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `line_total` decimal(18,4) NOT NULL,
  `notes` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_order_items_product` (`product_id`),
  KEY `fk_order_items_bp` (`branch_product_id`),
  KEY `fk_order_items_offer` (`offer_id`),
  KEY `idx_order_items_order` (`order_id`),
  CONSTRAINT `fk_order_items_bp` FOREIGN KEY (`branch_product_id`) REFERENCES `branch_products` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_order_items_offer` FOREIGN KEY (`offer_id`) REFERENCES `offers` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_order_items_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_order_items_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_oi_line` CHECK (((`line_subtotal` = (`unit_price` * `quantity`)) and (`offer_discount_amount` >= 0) and (`offer_discount_amount` <= `line_subtotal`) and (`line_total` = (`line_subtotal` - `offer_discount_amount`)))),
  CONSTRAINT `chk_oi_quantity` CHECK ((`quantity` >= 1)),
  CONSTRAINT `chk_oi_unit_price` CHECK ((`unit_price` = (`base_unit_price` + `options_unit_price`)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `order_payments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `order_payments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `order_id` bigint unsigned NOT NULL,
  `payment_method_id` bigint unsigned NOT NULL,
  `currency_id` bigint unsigned NOT NULL,
  `amount` decimal(18,4) NOT NULL,
  `collected_amount` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `reversed_amount` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `refunded_amount` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `status` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `paid_at` timestamp NULL DEFAULT NULL,
  `collected_by_driver_id` bigint unsigned DEFAULT NULL,
  `driver_cash_settlement_id` bigint unsigned DEFAULT NULL,
  `provider` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `provider_reference` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `meta` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_op_order_method` (`order_id`,`payment_method_id`),
  UNIQUE KEY `uq_op_provider_reference` (`provider`,`provider_reference`),
  KEY `fk_op_method` (`payment_method_id`),
  KEY `fk_op_currency` (`currency_id`),
  KEY `fk_op_settlement` (`driver_cash_settlement_id`),
  KEY `idx_op_driver_settlement` (`collected_by_driver_id`,`status`,`driver_cash_settlement_id`),
  CONSTRAINT `fk_op_currency` FOREIGN KEY (`currency_id`) REFERENCES `currencies` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_op_driver` FOREIGN KEY (`collected_by_driver_id`) REFERENCES `drivers` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_op_method` FOREIGN KEY (`payment_method_id`) REFERENCES `payment_methods` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_op_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_op_settlement` FOREIGN KEY (`driver_cash_settlement_id`) REFERENCES `driver_cash_settlements` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_op_amounts` CHECK (((`amount` > 0) and (`collected_amount` >= 0) and (`reversed_amount` >= 0) and (`refunded_amount` >= 0) and (`collected_amount` <= `amount`) and ((`reversed_amount` + `refunded_amount`) <= `collected_amount`))),
  CONSTRAINT `chk_op_status` CHECK ((`status` in (_utf8mb4'pending',_utf8mb4'paid',_utf8mb4'failed',_utf8mb4'cancelled',_utf8mb4'reversed')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `order_refunds`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `order_refunds` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `order_id` bigint unsigned NOT NULL,
  `order_payment_id` bigint unsigned NOT NULL,
  `order_cancellation_id` bigint unsigned DEFAULT NULL,
  `currency_id` bigint unsigned NOT NULL,
  `amount` decimal(18,4) NOT NULL,
  `reason_code` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL,
  `note` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `idempotency_key` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_by_admin_id` bigint unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_or_idempotency` (`idempotency_key`),
  KEY `fk_or_order` (`order_id`),
  KEY `fk_or_payment` (`order_payment_id`),
  KEY `fk_or_cancellation` (`order_cancellation_id`),
  KEY `fk_or_currency` (`currency_id`),
  KEY `fk_or_admin` (`created_by_admin_id`),
  CONSTRAINT `fk_or_admin` FOREIGN KEY (`created_by_admin_id`) REFERENCES `admins` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_or_cancellation` FOREIGN KEY (`order_cancellation_id`) REFERENCES `order_cancellations` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_or_currency` FOREIGN KEY (`currency_id`) REFERENCES `currencies` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_or_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_or_payment` FOREIGN KEY (`order_payment_id`) REFERENCES `order_payments` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_or_amount` CHECK ((`amount` > 0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `order_status_histories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `order_status_histories` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `order_id` bigint unsigned NOT NULL,
  `from_status` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `to_status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `actor_type` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL,
  `actor_id` bigint unsigned DEFAULT NULL,
  `driver_id` bigint unsigned DEFAULT NULL,
  `note` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_osh_driver` (`driver_id`),
  KEY `idx_osh_order_created` (`order_id`,`created_at`),
  CONSTRAINT `fk_osh_driver` FOREIGN KEY (`driver_id`) REFERENCES `drivers` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_osh_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chk_osh_actor` CHECK ((`actor_type` in (_utf8mb4'customer',_utf8mb4'admin',_utf8mb4'driver',_utf8mb4'system')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `orders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `orders` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `order_number` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `client_request_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `branch_id` bigint unsigned NOT NULL,
  `city_id` bigint unsigned NOT NULL,
  `delivery_area_id` bigint unsigned DEFAULT NULL,
  `delivery_fee_rule_id` bigint unsigned DEFAULT NULL,
  `user_address_id` bigint unsigned DEFAULT NULL,
  `delivery_address` json NOT NULL,
  `delivery_lat` decimal(10,7) DEFAULT NULL,
  `delivery_lng` decimal(10,7) DEFAULT NULL,
  `delivery_distance_km` decimal(8,3) NOT NULL,
  `distance_method` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL,
  `scheduled_for` timestamp NULL DEFAULT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `urgent_at` timestamp NULL DEFAULT NULL,
  `base_currency_id` bigint unsigned NOT NULL,
  `currency_id` bigint unsigned NOT NULL,
  `exchange_rate` decimal(24,12) NOT NULL,
  `subtotal` decimal(18,4) NOT NULL,
  `offer_discount_total` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `plus_discount_amount` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `delivery_fee_original` decimal(18,4) NOT NULL,
  `delivery_fee` decimal(18,4) NOT NULL,
  `total` decimal(18,4) NOT NULL,
  `plus_cycle_id` bigint unsigned DEFAULT NULL,
  `plus_cycle_discount_id` bigint unsigned DEFAULT NULL,
  `free_delivery_applied` tinyint(1) NOT NULL DEFAULT '0',
  `customer_notes` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `driver_id` bigint unsigned DEFAULT NULL,
  `accepted_by_admin_id` bigint unsigned DEFAULT NULL,
  `assigned_by_admin_id` bigint unsigned DEFAULT NULL,
  `accepted_at` timestamp NULL DEFAULT NULL,
  `assigned_at` timestamp NULL DEFAULT NULL,
  `picked_up_at` timestamp NULL DEFAULT NULL,
  `delivered_at` timestamp NULL DEFAULT NULL,
  `delivery_photo_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_orders_user_client_request` (`user_id`,`client_request_id`),
  UNIQUE KEY `uq_orders_number` (`order_number`),
  KEY `fk_orders_city` (`city_id`),
  KEY `fk_orders_area` (`delivery_area_id`),
  KEY `fk_orders_fee_rule` (`delivery_fee_rule_id`),
  KEY `fk_orders_address` (`user_address_id`),
  KEY `fk_orders_base_currency` (`base_currency_id`),
  KEY `fk_orders_currency` (`currency_id`),
  KEY `fk_orders_plus_cycle` (`plus_cycle_id`),
  KEY `fk_orders_plus_discount` (`plus_cycle_discount_id`),
  KEY `fk_orders_accepted_admin` (`accepted_by_admin_id`),
  KEY `fk_orders_assigned_admin` (`assigned_by_admin_id`),
  KEY `idx_orders_user_created` (`user_id`,`created_at`),
  KEY `idx_orders_branch_status` (`branch_id`,`status`),
  KEY `idx_orders_status_schedule` (`status`,`type`,`scheduled_for`),
  KEY `idx_orders_driver_status` (`driver_id`,`status`),
  KEY `idx_orders_urgent` (`urgent_at`),
  CONSTRAINT `fk_orders_accepted_admin` FOREIGN KEY (`accepted_by_admin_id`) REFERENCES `admins` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_orders_address` FOREIGN KEY (`user_address_id`) REFERENCES `user_addresses` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_orders_area` FOREIGN KEY (`delivery_area_id`) REFERENCES `delivery_areas` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_orders_assigned_admin` FOREIGN KEY (`assigned_by_admin_id`) REFERENCES `admins` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_orders_base_currency` FOREIGN KEY (`base_currency_id`) REFERENCES `currencies` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_orders_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_orders_city` FOREIGN KEY (`city_id`) REFERENCES `cities` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_orders_currency` FOREIGN KEY (`currency_id`) REFERENCES `currencies` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_orders_driver` FOREIGN KEY (`driver_id`) REFERENCES `drivers` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_orders_fee_rule` FOREIGN KEY (`delivery_fee_rule_id`) REFERENCES `delivery_fee_rules` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_orders_plus_cycle` FOREIGN KEY (`plus_cycle_id`) REFERENCES `plus_cycles` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_orders_plus_discount` FOREIGN KEY (`plus_cycle_discount_id`) REFERENCES `plus_cycle_discounts` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_orders_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_orders_amounts` CHECK (((`subtotal` >= 0) and (`offer_discount_total` >= 0) and (`plus_discount_amount` >= 0) and (`delivery_fee` >= 0) and (`delivery_fee_original` >= 0) and (`delivery_fee` <= `delivery_fee_original`) and (`total` >= 0))),
  CONSTRAINT `chk_orders_free_delivery` CHECK (((`free_delivery_applied` = 0) or ((`plus_cycle_id` is not null) and (`delivery_fee` = 0)))),
  CONSTRAINT `chk_orders_rate` CHECK ((`exchange_rate` > 0)),
  CONSTRAINT `chk_orders_schedule` CHECK (((`type` = _utf8mb4'scheduled') = (`scheduled_for` is not null))),
  CONSTRAINT `chk_orders_status` CHECK ((`status` in (_utf8mb4'pending',_utf8mb4'accepted',_utf8mb4'assigned',_utf8mb4'picked_up',_utf8mb4'delivered',_utf8mb4'cancelled'))),
  CONSTRAINT `chk_orders_total` CHECK ((`total` = (((`subtotal` - `offer_discount_total`) - `plus_discount_amount`) + `delivery_fee`))),
  CONSTRAINT `chk_orders_type` CHECK ((`type` in (_utf8mb4'immediate',_utf8mb4'scheduled')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `otps`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `otps` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `verifiable_type` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `verifiable_id` bigint unsigned DEFAULT NULL,
  `purpose` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `channel` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `destination_hash` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `code_hash` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `attempts` smallint unsigned NOT NULL DEFAULT '0',
  `max_attempts` smallint unsigned NOT NULL,
  `expires_at` timestamp NOT NULL,
  `consumed_at` timestamp NULL DEFAULT NULL,
  `invalidated_at` timestamp NULL DEFAULT NULL,
  `ip_hash` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `delivered_at` timestamp NULL DEFAULT NULL,
  `delivery_failed_at` timestamp NULL DEFAULT NULL,
  `last_delivery_error` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `otps_verifiable_type_verifiable_id_index` (`verifiable_type`,`verifiable_id`),
  KEY `otps_verifiable_purpose_idx` (`verifiable_type`,`verifiable_id`,`purpose`),
  KEY `otps_destination_purpose_idx` (`destination_hash`,`purpose`),
  KEY `otps_expires_at_index` (`expires_at`),
  CONSTRAINT `chk_otps_binding` CHECK (((`verifiable_id` is not null) or (`destination_hash` is not null)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `password_reset_grants`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `password_reset_grants` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `verifiable_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `verifiable_id` bigint unsigned NOT NULL,
  `token_hash` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `expires_at` timestamp NOT NULL,
  `consumed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `password_reset_grants_verifiable_type_verifiable_id_index` (`verifiable_type`,`verifiable_id`),
  KEY `password_reset_grants_verifiable_idx` (`verifiable_type`,`verifiable_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `password_reset_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `password_reset_tokens` (
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `token` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `payment_methods`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `payment_methods` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `kind` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` json NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `sort_order` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_payment_methods_code` (`code`),
  CONSTRAINT `chk_payment_methods_kind` CHECK ((`kind` in (_utf8mb4'wallet',_utf8mb4'cash',_utf8mb4'online')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `permissions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `guard_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `permissions_name_guard_name_unique` (`name`,`guard_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `personal_access_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `personal_access_tokens` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tokenable_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tokenable_id` bigint unsigned NOT NULL,
  `name` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `token` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `abilities` text COLLATE utf8mb4_unicode_ci,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `personal_access_tokens_token_unique` (`token`),
  KEY `personal_access_tokens_tokenable_type_tokenable_id_index` (`tokenable_type`,`tokenable_id`),
  KEY `personal_access_tokens_expires_at_index` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `plus_cycle_discounts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `plus_cycle_discounts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `plus_cycle_id` bigint unsigned NOT NULL,
  `store_id` bigint unsigned NOT NULL,
  `source` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL,
  `discount_type` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `snapshot_currency_id` bigint unsigned NOT NULL,
  `snapshot_discount_value` decimal(18,4) NOT NULL,
  `snapshot_max_discount_amount` decimal(18,4) DEFAULT NULL,
  `snapshot_min_order_amount` decimal(18,4) DEFAULT NULL,
  `max_uses_per_cycle` smallint unsigned DEFAULT NULL,
  `op_currency_id` bigint unsigned NOT NULL,
  `op_discount_value` decimal(18,4) NOT NULL,
  `op_max_discount_amount` decimal(18,4) DEFAULT NULL,
  `op_min_order_amount` decimal(18,4) DEFAULT NULL,
  `op_redenomination_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pcd_cycle_store` (`plus_cycle_id`,`store_id`),
  KEY `fk_pcd_store` (`store_id`),
  KEY `fk_pcd_snapshot_currency` (`snapshot_currency_id`),
  KEY `fk_pcd_op_currency` (`op_currency_id`),
  KEY `fk_pcd_redenomination` (`op_redenomination_id`),
  CONSTRAINT `fk_pcd_cycle` FOREIGN KEY (`plus_cycle_id`) REFERENCES `plus_cycles` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_pcd_op_currency` FOREIGN KEY (`op_currency_id`) REFERENCES `currencies` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_pcd_redenomination` FOREIGN KEY (`op_redenomination_id`) REFERENCES `currency_redenominations` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_pcd_snapshot_currency` FOREIGN KEY (`snapshot_currency_id`) REFERENCES `currencies` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_pcd_store` FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_pcd_source` CHECK ((`source` in (_utf8mb4'plan',_utf8mb4'exception'))),
  CONSTRAINT `chk_pcd_type` CHECK ((`discount_type` in (_utf8mb4'percent',_utf8mb4'fixed'))),
  CONSTRAINT `chk_pcd_values` CHECK (((`snapshot_discount_value` > 0) and (`op_discount_value` > 0)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `plus_cycles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `plus_cycles` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `plus_membership_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `plus_plan_id` bigint unsigned NOT NULL,
  `plus_request_id` bigint unsigned DEFAULT NULL,
  `cycle_number` smallint unsigned NOT NULL,
  `starts_at` timestamp NOT NULL,
  `ends_at` timestamp NOT NULL,
  `status` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'scheduled',
  `active_marker` bigint unsigned GENERATED ALWAYS AS ((case when (`status` = _utf8mb4'active') then `plus_membership_id` else NULL end)) STORED,
  `plan_name` json NOT NULL,
  `renewal_policy` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL,
  `snapshot_currency_id` bigint unsigned NOT NULL,
  `free_deliveries_total` smallint unsigned NOT NULL,
  `free_deliveries_used` smallint unsigned NOT NULL DEFAULT '0',
  `confirmed_by_admin_id` bigint unsigned NOT NULL,
  `confirmed_at` timestamp NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pcy_membership_number` (`plus_membership_id`,`cycle_number`),
  UNIQUE KEY `uq_pcy_one_active` (`active_marker`),
  UNIQUE KEY `uq_pcy_request` (`plus_request_id`),
  KEY `fk_pcy_plan` (`plus_plan_id`),
  KEY `fk_pcy_currency` (`snapshot_currency_id`),
  KEY `fk_pcy_admin` (`confirmed_by_admin_id`),
  KEY `idx_pcy_user_status_window` (`user_id`,`status`,`starts_at`,`ends_at`),
  CONSTRAINT `fk_pcy_admin` FOREIGN KEY (`confirmed_by_admin_id`) REFERENCES `admins` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_pcy_currency` FOREIGN KEY (`snapshot_currency_id`) REFERENCES `currencies` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_pcy_membership` FOREIGN KEY (`plus_membership_id`) REFERENCES `plus_memberships` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_pcy_plan` FOREIGN KEY (`plus_plan_id`) REFERENCES `plus_plans` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_pcy_request` FOREIGN KEY (`plus_request_id`) REFERENCES `plus_requests` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_pcy_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_pcy_status` CHECK ((`status` in (_utf8mb4'scheduled',_utf8mb4'active',_utf8mb4'expired',_utf8mb4'cancelled'))),
  CONSTRAINT `chk_pcy_usage` CHECK ((`free_deliveries_used` <= `free_deliveries_total`)),
  CONSTRAINT `chk_pcy_window` CHECK ((`ends_at` > `starts_at`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `plus_memberships`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `plus_memberships` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `plus_plan_id` bigint unsigned NOT NULL,
  `next_plan_id` bigint unsigned DEFAULT NULL,
  `renewal_policy` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL,
  `custom_duration_days` smallint unsigned DEFAULT NULL,
  `free_deliveries_override` smallint unsigned DEFAULT NULL,
  `override_approved_by_admin_id` bigint unsigned DEFAULT NULL,
  `status` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `policy_set_by_admin_id` bigint unsigned NOT NULL,
  `cancelled_at` timestamp NULL DEFAULT NULL,
  `ended_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_plus_memberships_user` (`user_id`),
  KEY `fk_pm_plan` (`plus_plan_id`),
  KEY `fk_pm_next_plan` (`next_plan_id`),
  KEY `fk_pm_override_admin` (`override_approved_by_admin_id`),
  KEY `fk_pm_policy_admin` (`policy_set_by_admin_id`),
  KEY `plus_memberships_status_index` (`status`),
  CONSTRAINT `fk_pm_next_plan` FOREIGN KEY (`next_plan_id`) REFERENCES `plus_plans` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_pm_override_admin` FOREIGN KEY (`override_approved_by_admin_id`) REFERENCES `admins` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_pm_plan` FOREIGN KEY (`plus_plan_id`) REFERENCES `plus_plans` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_pm_policy_admin` FOREIGN KEY (`policy_set_by_admin_id`) REFERENCES `admins` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_pm_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_pm_custom_days` CHECK (((`renewal_policy` = _utf8mb4'custom') = (`custom_duration_days` is not null))),
  CONSTRAINT `chk_pm_policy` CHECK ((`renewal_policy` in (_utf8mb4'monthly',_utf8mb4'one_time',_utf8mb4'custom'))),
  CONSTRAINT `chk_pm_status` CHECK ((`status` in (_utf8mb4'active',_utf8mb4'cancelled',_utf8mb4'ended')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `plus_plans`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `plus_plans` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` json NOT NULL,
  `description` json DEFAULT NULL,
  `default_duration_days` smallint unsigned NOT NULL DEFAULT '30',
  `free_deliveries_per_cycle` smallint unsigned NOT NULL DEFAULT '0',
  `display_price` decimal(18,4) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `sort_order` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_plus_plans_code` (`code`),
  CONSTRAINT `chk_plus_plans_duration` CHECK ((`default_duration_days` >= 1)),
  CONSTRAINT `chk_plus_plans_price` CHECK (((`display_price` is null) or (`display_price` >= 0)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `plus_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `plus_requests` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `plus_membership_id` bigint unsigned DEFAULT NULL,
  `type` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL,
  `requested_plan_id` bigint unsigned DEFAULT NULL,
  `customer_note` varchar(1000) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'customer',
  `status` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `pending_key` varchar(40) COLLATE utf8mb4_unicode_ci GENERATED ALWAYS AS ((case when (`status` = _utf8mb4'pending') then concat(`user_id`,_utf8mb4':',`type`) else NULL end)) STORED,
  `admin_note` varchar(1000) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reviewed_by_admin_id` bigint unsigned DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pr_one_pending_per_type` (`pending_key`),
  KEY `fk_pr_user` (`user_id`),
  KEY `fk_pr_membership` (`plus_membership_id`),
  KEY `fk_pr_plan` (`requested_plan_id`),
  KEY `fk_pr_admin` (`reviewed_by_admin_id`),
  KEY `idx_pr_status_created` (`status`,`created_at`),
  CONSTRAINT `fk_pr_admin` FOREIGN KEY (`reviewed_by_admin_id`) REFERENCES `admins` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_pr_membership` FOREIGN KEY (`plus_membership_id`) REFERENCES `plus_memberships` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_pr_plan` FOREIGN KEY (`requested_plan_id`) REFERENCES `plus_plans` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_pr_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_pr_plan_required` CHECK (((`type` not in (_utf8mb4'new',_utf8mb4'change')) or (`requested_plan_id` is not null))),
  CONSTRAINT `chk_pr_source` CHECK ((`source` in (_utf8mb4'customer',_utf8mb4'system'))),
  CONSTRAINT `chk_pr_status` CHECK ((`status` in (_utf8mb4'pending',_utf8mb4'approved',_utf8mb4'rejected',_utf8mb4'withdrawn'))),
  CONSTRAINT `chk_pr_type` CHECK ((`type` in (_utf8mb4'new',_utf8mb4'renew',_utf8mb4'change',_utf8mb4'cancel',_utf8mb4'custom')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `plus_store_discounts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `plus_store_discounts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `plus_plan_id` bigint unsigned DEFAULT NULL,
  `plus_membership_id` bigint unsigned DEFAULT NULL,
  `store_id` bigint unsigned NOT NULL,
  `is_excluded` tinyint(1) NOT NULL DEFAULT '0',
  `discount_type` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `discount_value` decimal(18,4) DEFAULT NULL,
  `max_discount_amount` decimal(18,4) DEFAULT NULL,
  `min_order_amount` decimal(18,4) DEFAULT NULL,
  `max_uses_per_cycle` smallint unsigned DEFAULT NULL,
  `approved_by_admin_id` bigint unsigned DEFAULT NULL,
  `note` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_psd_plan_store` (`plus_plan_id`,`store_id`),
  UNIQUE KEY `uq_psd_membership_store` (`plus_membership_id`,`store_id`),
  KEY `fk_psd_store` (`store_id`),
  KEY `fk_psd_admin` (`approved_by_admin_id`),
  CONSTRAINT `fk_psd_admin` FOREIGN KEY (`approved_by_admin_id`) REFERENCES `admins` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_psd_membership` FOREIGN KEY (`plus_membership_id`) REFERENCES `plus_memberships` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_psd_plan` FOREIGN KEY (`plus_plan_id`) REFERENCES `plus_plans` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_psd_store` FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_psd_amounts` CHECK ((((`max_discount_amount` is null) or (`max_discount_amount` > 0)) and ((`min_order_amount` is null) or (`min_order_amount` >= 0)))),
  CONSTRAINT `chk_psd_one_owner` CHECK (((`plus_plan_id` is null) <> (`plus_membership_id` is null))),
  CONSTRAINT `chk_psd_shape` CHECK ((((`is_excluded` = 1) and (`plus_membership_id` is not null) and (`discount_type` is null)) or ((`is_excluded` = 0) and (`discount_type` in (_utf8mb4'percent',_utf8mb4'fixed')) and (`discount_value` > 0) and ((`discount_type` <> _utf8mb4'percent') or (`discount_value` <= 100)))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `product_category`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `product_category` (
  `product_id` bigint unsigned NOT NULL,
  `category_id` bigint unsigned NOT NULL,
  PRIMARY KEY (`product_id`,`category_id`),
  KEY `idx_pc_category_product` (`category_id`,`product_id`),
  CONSTRAINT `fk_pc_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pc_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `product_option_groups`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `product_option_groups` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `product_id` bigint unsigned NOT NULL,
  `name` json NOT NULL,
  `kind` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'choice',
  `min_select` tinyint unsigned NOT NULL DEFAULT '0',
  `max_select` tinyint unsigned NOT NULL DEFAULT '1',
  `sort_order` int unsigned NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pog_id_product` (`id`,`product_id`),
  KEY `fk_pog_product` (`product_id`),
  CONSTRAINT `fk_pog_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chk_pog_kind` CHECK ((`kind` in (_utf8mb4'size',_utf8mb4'choice',_utf8mb4'addon'))),
  CONSTRAINT `chk_pog_selection` CHECK (((`max_select` >= 1) and (`max_select` >= `min_select`))),
  CONSTRAINT `chk_pog_size_single` CHECK (((`kind` <> _utf8mb4'size') or ((`min_select` = 1) and (`max_select` = 1))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `product_options`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `product_options` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `option_group_id` bigint unsigned NOT NULL,
  `product_id` bigint unsigned NOT NULL,
  `name` json NOT NULL,
  `max_quantity` tinyint unsigned NOT NULL DEFAULT '1',
  `sort_order` int unsigned NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_po_id_product` (`id`,`product_id`),
  KEY `fk_po_group_product` (`option_group_id`,`product_id`),
  KEY `idx_po_group` (`option_group_id`),
  CONSTRAINT `fk_po_group_product` FOREIGN KEY (`option_group_id`, `product_id`) REFERENCES `product_option_groups` (`id`, `product_id`) ON DELETE CASCADE,
  CONSTRAINT `chk_po_max_quantity` CHECK ((`max_quantity` >= 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `product_ratings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `product_ratings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `product_id` bigint unsigned NOT NULL,
  `order_id` bigint unsigned NOT NULL,
  `rating` tinyint unsigned NOT NULL,
  `comment` text COLLATE utf8mb4_unicode_ci,
  `is_hidden` tinyint(1) NOT NULL DEFAULT '0',
  `hidden_at` timestamp NULL DEFAULT NULL,
  `hidden_by_admin_id` bigint unsigned DEFAULT NULL,
  `hidden_reason` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_prt_user_product` (`user_id`,`product_id`),
  KEY `fk_prt_order` (`order_id`),
  KEY `fk_prt_admin` (`hidden_by_admin_id`),
  KEY `idx_prt_product_hidden` (`product_id`,`is_hidden`),
  CONSTRAINT `fk_prt_admin` FOREIGN KEY (`hidden_by_admin_id`) REFERENCES `admins` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_prt_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_prt_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_prt_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_prt_rating` CHECK ((`rating` between 1 and 5))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `products`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `products` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `store_id` bigint unsigned NOT NULL,
  `name` json NOT NULL,
  `description` json DEFAULT NULL,
  `image` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `rating_count` int unsigned NOT NULL DEFAULT '0',
  `rating_sum` int unsigned NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_products_id_store` (`id`,`store_id`),
  KEY `idx_products_store_active` (`store_id`,`is_active`),
  CONSTRAINT `fk_products_store` FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_products_rating` CHECK (((`rating_sum` >= `rating_count`) and (`rating_sum` <= (5 * `rating_count`))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `role_has_permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `role_has_permissions` (
  `permission_id` bigint unsigned NOT NULL,
  `role_id` bigint unsigned NOT NULL,
  PRIMARY KEY (`permission_id`,`role_id`),
  KEY `role_has_permissions_role_id_foreign` (`role_id`),
  CONSTRAINT `role_has_permissions_permission_id_foreign` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `role_has_permissions_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `roles` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `guard_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `roles_name_guard_name_unique` (`name`,`guard_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sessions` (
  `id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` text COLLATE utf8mb4_unicode_ci,
  `payload` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `last_activity` int NOT NULL,
  PRIMARY KEY (`id`),
  KEY `sessions_user_id_index` (`user_id`),
  KEY `sessions_last_activity_index` (`last_activity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `settings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `key` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `value` json NOT NULL,
  `is_monetary` tinyint(1) NOT NULL DEFAULT '0',
  `updated_by_admin_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `settings_key_unique` (`key`),
  KEY `fk_settings_updated_by_admin` (`updated_by_admin_id`),
  CONSTRAINT `fk_settings_updated_by_admin` FOREIGN KEY (`updated_by_admin_id`) REFERENCES `admins` (`id`) ON DELETE SET NULL,
  CONSTRAINT `chk_settings_key_format` CHECK (regexp_like(`key`,_utf8mb4'^[a-z0-9_.]+$'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `store_categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `store_categories` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` json NOT NULL,
  `slug` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `image` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sort_order` int unsigned NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_store_categories_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `store_store_category`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `store_store_category` (
  `store_id` bigint unsigned NOT NULL,
  `store_category_id` bigint unsigned NOT NULL,
  PRIMARY KEY (`store_id`,`store_category_id`),
  KEY `idx_ssc_category` (`store_category_id`),
  CONSTRAINT `fk_ssc_category` FOREIGN KEY (`store_category_id`) REFERENCES `store_categories` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ssc_store` FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `stores`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `stores` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` json NOT NULL,
  `slug` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `logo` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` json DEFAULT NULL,
  `contact_phone` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `contact_whatsapp` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `sort_order` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_stores_slug` (`slug`),
  KEY `stores_is_active_index` (`is_active`),
  CONSTRAINT `chk_stores_contact_phone` CHECK (((`contact_phone` is null) or regexp_like(`contact_phone`,_utf8mb4'^[+][1-9][0-9]{6,14}$'))),
  CONSTRAINT `chk_stores_contact_whatsapp` CHECK (((`contact_whatsapp` is null) or regexp_like(`contact_whatsapp`,_utf8mb4'^[+][1-9][0-9]{6,14}$')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `tests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tests` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `image` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `user_addresses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_addresses` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `city_id` bigint unsigned NOT NULL,
  `label` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `address_line` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `details` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `lat` decimal(10,7) NOT NULL,
  `lng` decimal(10,7) NOT NULL,
  `is_default` tinyint(1) NOT NULL DEFAULT '0',
  `default_marker` bigint unsigned GENERATED ALWAYS AS ((case when ((`is_default` = 1) and (`deleted_at` is null)) then `user_id` else NULL end)) STORED,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_addresses_one_default` (`default_marker`),
  KEY `fk_user_addresses_user` (`user_id`),
  KEY `fk_user_addresses_city` (`city_id`),
  CONSTRAINT `fk_user_addresses_city` FOREIGN KEY (`city_id`) REFERENCES `cities` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_user_addresses_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_user_addresses_coordinates` CHECK (((`lat` between -(90) and 90) and (`lng` between -(180) and 180)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `first_name` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_name` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `avatar` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `password` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `remember_token` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `status` tinyint(1) NOT NULL DEFAULT '0',
  `social_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `prefix_phone` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `phone_number` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `phone` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `otp_delivery_method` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `activated_at` timestamp NULL DEFAULT NULL,
  `email_status` tinyint(1) NOT NULL DEFAULT '0',
  `phone_status` tinyint(1) NOT NULL DEFAULT '0',
  `phone_verified_at` timestamp NULL DEFAULT NULL,
  `language` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT 'en',
  `verified_email` varchar(255) COLLATE utf8mb4_unicode_ci GENERATED ALWAYS AS ((case when (`email_verified_at` is not null) then `email` else NULL end)) STORED,
  `account_status` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `suspended_at` timestamp NULL DEFAULT NULL,
  `suspended_by_admin_id` bigint unsigned DEFAULT NULL,
  `suspension_reason` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `otp_channel` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'sms',
  `display_currency_id` bigint unsigned DEFAULT NULL,
  `profile_completed_at` timestamp NULL DEFAULT NULL,
  `recovery_until` timestamp NULL DEFAULT NULL,
  `anonymized_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_social_id_unique` (`social_id`),
  UNIQUE KEY `uq_users_phone` (`phone`),
  UNIQUE KEY `uq_users_verified_email` (`verified_email`),
  KEY `fk_users_suspended_by_admin` (`suspended_by_admin_id`),
  KEY `fk_users_display_currency` (`display_currency_id`),
  KEY `users_account_status_index` (`account_status`),
  KEY `users_recovery_until_index` (`recovery_until`),
  KEY `idx_users_email` (`email`),
  CONSTRAINT `fk_users_display_currency` FOREIGN KEY (`display_currency_id`) REFERENCES `currencies` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_users_suspended_by_admin` FOREIGN KEY (`suspended_by_admin_id`) REFERENCES `admins` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_users_account_status` CHECK ((`account_status` in (_utf8mb4'active',_utf8mb4'suspended'))),
  CONSTRAINT `chk_users_otp_channel` CHECK ((`otp_channel` in (_utf8mb4'sms',_utf8mb4'whatsapp'))),
  CONSTRAINT `chk_users_phone_e164` CHECK (((`phone` is null) or regexp_like(`phone`,_utf8mb4'^[+][1-9][0-9]{6,14}$')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `wallet_transactions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `wallet_transactions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `wallet_id` bigint unsigned NOT NULL,
  `type` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL,
  `direction` varchar(6) COLLATE utf8mb4_unicode_ci NOT NULL,
  `amount` decimal(18,4) NOT NULL,
  `balance_after` decimal(18,4) NOT NULL,
  `order_id` bigint unsigned DEFAULT NULL,
  `order_payment_id` bigint unsigned DEFAULT NULL,
  `order_refund_id` bigint unsigned DEFAULT NULL,
  `idempotency_key` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `note` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_wt_idempotency` (`idempotency_key`),
  UNIQUE KEY `uq_wt_payment_type` (`order_payment_id`,`type`),
  UNIQUE KEY `uq_wt_refund` (`order_refund_id`),
  KEY `idx_wt_wallet` (`wallet_id`,`id`),
  KEY `idx_wt_order` (`order_id`),
  CONSTRAINT `fk_wt_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_wt_payment` FOREIGN KEY (`order_payment_id`) REFERENCES `order_payments` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_wt_refund` FOREIGN KEY (`order_refund_id`) REFERENCES `order_refunds` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_wt_wallet` FOREIGN KEY (`wallet_id`) REFERENCES `wallets` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_wt_amounts` CHECK (((`amount` > 0) and (`balance_after` >= 0))),
  CONSTRAINT `chk_wt_direction` CHECK (((`direction` in (_utf8mb4'credit',_utf8mb4'debit')) and ((`type` = _utf8mb4'order_payment') = (`direction` = _utf8mb4'debit')))),
  CONSTRAINT `chk_wt_type` CHECK ((`type` in (_utf8mb4'order_payment',_utf8mb4'order_payment_reversal',_utf8mb4'order_refund')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `wallets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `wallets` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `currency_id` bigint unsigned NOT NULL,
  `balance` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_wallets_user_currency` (`user_id`,`currency_id`),
  KEY `fk_wallets_currency` (`currency_id`),
  CONSTRAINT `fk_wallets_currency` FOREIGN KEY (`currency_id`) REFERENCES `currencies` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_wallets_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_wallets_balance` CHECK ((`balance` >= 0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

