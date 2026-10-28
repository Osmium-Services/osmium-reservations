ALTER TABLE `{PREFIX}reservations`
  MODIFY COLUMN `status` enum('requested','confirmed','seated','declined','cancelled') NOT NULL DEFAULT 'requested';
