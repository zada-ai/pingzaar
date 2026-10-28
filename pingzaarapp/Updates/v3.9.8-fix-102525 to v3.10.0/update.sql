SET CHARACTER SET utf8mb4;

CREATE TABLE IF NOT EXISTS `ai_conversations` (
  `id` int NOT NULL AUTO_INCREMENT,
  `uid` int NOT NULL,
  `conversation_key` varchar(255) NOT NULL,
  `role` varchar(16) NOT NULL,
  `content` longtext NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `uid` (`uid`),
  KEY `conversation_key` (`conversation_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `settings` (`name`, `value`) SELECT 'currency_api_key', ''
  WHERE NOT EXISTS (SELECT 1 FROM `settings` WHERE `name` = 'currency_api_key');

INSERT INTO `settings` (`name`, `value`) SELECT 'realtime_driver', 'pusher'
  WHERE NOT EXISTS (SELECT 1 FROM `settings` WHERE `name` = 'realtime_driver');
INSERT INTO `settings` (`name`, `value`) SELECT 'realtime_app_id', ''
  WHERE NOT EXISTS (SELECT 1 FROM `settings` WHERE `name` = 'realtime_app_id');
INSERT INTO `settings` (`name`, `value`) SELECT 'realtime_key', ''
  WHERE NOT EXISTS (SELECT 1 FROM `settings` WHERE `name` = 'realtime_key');
INSERT INTO `settings` (`name`, `value`) SELECT 'realtime_secret', ''
  WHERE NOT EXISTS (SELECT 1 FROM `settings` WHERE `name` = 'realtime_secret');
INSERT INTO `settings` (`name`, `value`) SELECT 'realtime_cluster', 'mt1'
  WHERE NOT EXISTS (SELECT 1 FROM `settings` WHERE `name` = 'realtime_cluster');
INSERT INTO `settings` (`name`, `value`) SELECT 'realtime_host', ''
  WHERE NOT EXISTS (SELECT 1 FROM `settings` WHERE `name` = 'realtime_host');

-- ORDERING IS LOad-BEARING: every plain CREATE TABLE comes BEFORE the txn
-- migration below, never after.
--
-- The importer's try/catch wraps the whole read loop and only echoes, so one
-- failing statement silently abandons every statement after it while the updater
-- still reports success. The txn migration is by far the most likely thing here
-- to fail on real data or an older server, so nothing structural may sit behind
-- it. `pairing` used to be the LAST statement in this file: any failure in the
-- migration left it uncreated, and device pairing (device_model.php:168,172,199,
-- 206 and device.php:327) was broken estate-wide after a "successful" update.
CREATE TABLE IF NOT EXISTS `pairing` (`id` int(11) NOT NULL AUTO_INCREMENT, `uid` int(11) NOT NULL, `token` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL, `expire_date` datetime NOT NULL, `create_date` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (`id`), UNIQUE KEY `token` (`token`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Payment replay protection. Providers retry webhooks aggressively, and the only
-- previous guard was deleting the order row after fulfilment -- a race, because
-- two concurrent deliveries could both read the order before either deleted it.
-- A unique txn makes the second insert fail instead of crediting twice.
--
-- IMPORTANT, about the shape of the statements below. The updater imports this
-- file with vendor/thamaraiselvam/mysql-import, which splits on any line ending
-- in ";" and runs each piece through mysqli::query() -- one statement per call,
-- no multi-statement support. Worse, its try/catch wraps the whole read loop and
-- only echoes the error, so ONE failing statement silently abandons every
-- statement after it and the updater still reports success. So: one statement
-- per line, and nothing here may fail on a re-run or on unusual data.
--
-- txn also moves from TEXT to VARCHAR(255): MySQL cannot index TEXT without a
-- prefix length, and 255 covers the longest provider ids in practice (Stripe
-- documents 255 for object ids). Anything longer is shortened first, because
-- MODIFY would otherwise abort under STRICT_TRANS_TABLES -- the default on any
-- modern server -- leaving the table half-migrated.
--
-- NOTHING IS DESTROYED TO ACHIEVE THAT. The old column was TEXT, so values above
-- 255 characters were legal under 3.9.8, and a customer whose gateway stored a
-- long token or a concatenated reference there would otherwise lose the tail of
-- their payment audit trail with no way back. The full value is copied to
-- txn_legacy first. That column exists in db.sql too, so a fresh install and an
-- updated one have identical schemas.
SET @has_txn_legacy = (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'transactions' AND column_name = 'txn_legacy');

SET @sql = IF(@has_txn_legacy = 0, 'ALTER TABLE `transactions` ADD COLUMN `txn_legacy` text COLLATE utf8mb4_bin NULL DEFAULT NULL', 'DO 0');

PREPARE add_txn_legacy FROM @sql;

EXECUTE add_txn_legacy;

DEALLOCATE PREPARE add_txn_legacy;

UPDATE `transactions` SET `txn_legacy` = `txn` WHERE CHAR_LENGTH(`txn`) > 255 AND `txn_legacy` IS NULL;

UPDATE `transactions` SET `txn` = CONCAT('legacy-', `id`) WHERE `txn` = '' OR `txn` IS NULL;

UPDATE `transactions` SET `txn` = CONCAT(LEFT(`txn`, 200), '-long-', `id`) WHERE CHAR_LENGTH(`txn`) > 255;

-- Narrow and switch collation BEFORE deduplicating, so the dedup below compares
-- byte-for-byte.
--
-- utf8mb4_unicode_ci is case-insensitive AND pad-space, which is wrong for
-- provider transaction ids: Stripe ids are mixed-case base62, so 'pi_ABC' and
-- 'pi_abc' are DIFFERENT payments. Under the old collation the dedup treated
-- them as duplicates and rewrote one, and worse, the resulting unique index
-- would go on to reject a legitimate new payment whose id differed from an
-- existing one only by case or trailing whitespace -- presenting as a customer
-- charged and credited nothing. mpg_txn_exists() looks the value up with a plain
-- `txn = ?`, so it inherits this collation; binary is what replay detection
-- actually wants. Internal ids (manual-/voucher- plus lowercase md5) are
-- unaffected either way.
ALTER TABLE `transactions` MODIFY `txn` varchar(255) COLLATE utf8mb4_bin NOT NULL;

-- Dedup WITHOUT a window function. ROW_NUMBER() needs MySQL 8.0 / MariaDB 10.2,
-- and requirements.html states no minimum version -- 5.7 shared hosting is still
-- common for this product. On such a server the window form is a SYNTAX ERROR,
-- which (see above) silently abandons the whole rest of the file.
--
-- A self-join on `id` keeps the earliest row and rewrites the later ones. The
-- duplicate ids are collected into a temporary table FIRST, so the UPDATE never
-- reads a table it is concurrently writing. (This DROP is of a TEMPORARY table
-- only -- it never touches customer data.)
DROP TEMPORARY TABLE IF EXISTS `txn_dupes`;

CREATE TEMPORARY TABLE `txn_dupes` (`id` int NOT NULL PRIMARY KEY) ENGINE=InnoDB;

INSERT INTO `txn_dupes` (`id`) SELECT t.`id` FROM `transactions` t JOIN `transactions` k ON k.`txn` = t.`txn` AND k.`id` < t.`id` GROUP BY t.`id`;

UPDATE `transactions` t JOIN `txn_dupes` d ON d.`id` = t.`id` SET t.`txn` = CONCAT(LEFT(t.`txn`, 200), '-dup-', t.`id`);

-- Second pass. The suffix is unique among REWRITTEN rows because `id` is, but it
-- can still collide with a pre-existing row that literally holds that string
-- (e.g. rows 'abc', 'abc-dup-7', 'abc' -- the first pass turns row 7 into
-- 'abc-dup-7' and duplicates row 5). Without this the ADD UNIQUE KEY below
-- aborts, the column is left migrated but unindexed, and every statement after
-- it is abandoned.
DELETE FROM `txn_dupes`;

INSERT INTO `txn_dupes` (`id`) SELECT t.`id` FROM `transactions` t JOIN `transactions` k ON k.`txn` = t.`txn` AND k.`id` < t.`id` GROUP BY t.`id`;

UPDATE `transactions` t JOIN `txn_dupes` d ON d.`id` = t.`id` SET t.`txn` = CONCAT(LEFT(t.`txn`, 190), '-dup2-', t.`id`);

DROP TEMPORARY TABLE IF EXISTS `txn_dupes`;

-- NON_UNIQUE = 0 matters. Counting any index named `txn` treats a pre-existing
-- NON-unique KEY `txn` (`txn`) as "already done", so the unique index is never
-- added -- and payment replay protection, the entire point of this migration,
-- is silently absent on that install forever. The data was mangled for nothing,
-- and the failure only ever surfaces as a customer charged but not credited.
SET @has_txn_index = (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'transactions' AND index_name = 'txn' AND NON_UNIQUE = 0);

SET @drop_txn_index = (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'transactions' AND index_name = 'txn' AND NON_UNIQUE = 1);

SET @sql = IF(@drop_txn_index > 0, 'ALTER TABLE `transactions` DROP INDEX `txn`', 'DO 0');

PREPARE drop_txn_index FROM @sql;

EXECUTE drop_txn_index;

DEALLOCATE PREPARE drop_txn_index;

SET @sql = IF(@has_txn_index = 0, 'ALTER TABLE `transactions` ADD UNIQUE KEY `txn` (`txn`)', 'DO 0');

PREPARE add_txn_index FROM @sql;

EXECUTE add_txn_index;

DEALLOCATE PREPARE add_txn_index;
