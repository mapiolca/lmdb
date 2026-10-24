ALTER TABLE llx_lmdb_attestation_version ADD UNIQUE INDEX uk_lmdb_att_ver (entity, version);
