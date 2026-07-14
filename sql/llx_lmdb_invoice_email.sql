-- Copyright (C) 2026 Pierre Ardoin <developpeur@lesmetiersdubatiment.fr>

CREATE TABLE IF NOT EXISTS llx_lmdb_invoice_email
(
	rowid integer AUTO_INCREMENT PRIMARY KEY,
	entity integer DEFAULT 1 NOT NULL,
	fk_facture integer NOT NULL,
	fk_email_template integer,
	status smallint DEFAULT 0 NOT NULL,
	origin varchar(16) NOT NULL,
	attempts integer DEFAULT 0 NOT NULL,
	date_creation datetime NOT NULL,
	date_attempt datetime,
	date_sent datetime,
	fk_user integer,
	message_id varchar(255),
	last_error text
) ENGINE=innodb;
