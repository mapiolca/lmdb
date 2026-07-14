-- Copyright (C) 2026 Pierre Ardoin <developpeur@lesmetiersdubatiment.fr>

ALTER TABLE llx_lmdb_invoice_email ADD UNIQUE INDEX uk_lmdb_invoice_email (entity, fk_facture);
ALTER TABLE llx_lmdb_invoice_email ADD INDEX idx_lmdb_invoice_email_entity_status (entity, status);
ALTER TABLE llx_lmdb_invoice_email ADD INDEX idx_lmdb_invoice_email_facture (fk_facture);
ALTER TABLE llx_lmdb_invoice_email ADD INDEX idx_lmdb_invoice_email_template (fk_email_template);
