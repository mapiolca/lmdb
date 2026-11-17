-- One submission is consumed before SMTP. Uncertain sends require human review.
CREATE TABLE IF NOT EXISTS llx_lmdb_attestation_email (
 rowid integer AUTO_INCREMENT PRIMARY KEY,
 entity integer NOT NULL,
 fk_attestation integer NOT NULL,
 submission_hash varchar(64) NOT NULL,
 status varchar(16) NOT NULL,
 date_creation datetime NOT NULL,
 date_sent datetime,
 fk_user integer NOT NULL
) ENGINE=innodb;
