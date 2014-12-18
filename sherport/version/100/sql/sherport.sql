#ALTER TABLE `tkunde` ADD `sherport_id` VARCHAR(15) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE `tzahlungsid` DROP `sherport_token`, ADD `sherport_token` VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
