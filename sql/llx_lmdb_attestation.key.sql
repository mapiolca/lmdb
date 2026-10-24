ALTER TABLE llx_lmdb_attestation ADD UNIQUE INDEX uk_lmdb_att_ref (entity, ref);
ALTER TABLE llx_lmdb_attestation ADD INDEX idx_lmdb_att_soc (fk_soc);
ALTER TABLE llx_lmdb_attestation ADD INDEX idx_lmdb_att_ver (fk_version);
ALTER TABLE llx_lmdb_attestation ADD INDEX idx_lmdb_att_state (entity, status, date_attestation);
