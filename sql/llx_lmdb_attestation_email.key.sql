ALTER TABLE llx_lmdb_attestation_email ADD UNIQUE INDEX uk_lmdb_att_mail (submission_hash);
ALTER TABLE llx_lmdb_attestation_email ADD INDEX idx_lmdb_att_mail (entity, fk_attestation, status);
