-- Copyright (C) 2026 Pierre Ardoin <developpeur@lesmetiersdubatiment.fr>

CREATE TABLE IF NOT EXISTS llx_lmdb_mailing_schedule
(
	rowid integer AUTO_INCREMENT PRIMARY KEY,
	entity integer DEFAULT 1 NOT NULL,
	fk_mailing integer NOT NULL,
	scheduled_send_at datetime,
	scheduled_started_at datetime,
	date_creation datetime NOT NULL,
	tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	fk_user_creat integer,
	fk_user_modif integer
) ENGINE=innodb;
