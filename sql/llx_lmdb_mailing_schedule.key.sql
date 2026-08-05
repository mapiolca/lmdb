-- Copyright (C) 2026 Pierre Ardoin <developpeur@lesmetiersdubatiment.fr>

ALTER TABLE llx_lmdb_mailing_schedule ADD UNIQUE INDEX uk_lmdb_mailing_schedule (entity, fk_mailing);
ALTER TABLE llx_lmdb_mailing_schedule ADD INDEX idx_lmdb_mailing_schedule_due (entity, scheduled_send_at);
ALTER TABLE llx_lmdb_mailing_schedule ADD INDEX idx_lmdb_mailing_schedule_mailing (fk_mailing);
