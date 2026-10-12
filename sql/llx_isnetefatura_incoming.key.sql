-- Copyright (C) 2026 M. Burak Şentürk <https://buraksenturk.net>
-- Licensed under GPL-3.0-or-later with attribution terms. See COPYING and ATTRIBUTION.md.

ALTER TABLE llx_isnetefatura_incoming ADD UNIQUE INDEX uk_isnetefatura_incoming_ettn (entity, ettn);
ALTER TABLE llx_isnetefatura_incoming ADD INDEX idx_isnetefatura_incoming_date (invoice_date);
ALTER TABLE llx_isnetefatura_incoming ADD INDEX idx_isnetefatura_incoming_soc (fk_soc);
ALTER TABLE llx_isnetefatura_incoming ADD INDEX idx_isnetefatura_incoming_taxcode (sender_tax_code);
ALTER TABLE llx_isnetefatura_incoming ADD INDEX idx_isnetefatura_incoming_status (status);
