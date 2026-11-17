-- This catalogue describes the supplier's attested releases, not installed core releases.
CREATE TABLE IF NOT EXISTS llx_lmdb_attestation_version (
 rowid integer AUTO_INCREMENT PRIMARY KEY,
 entity integer DEFAULT 1 NOT NULL,
 version varchar(128) NOT NULL,
 date_release date NOT NULL,
 covered_scope text NOT NULL,
 excluded_scope text,
 active smallint DEFAULT 0 NOT NULL
) ENGINE=innodb;
