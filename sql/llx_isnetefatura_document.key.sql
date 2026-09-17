-- Copyright (C) 2026 M. Burak Şentürk <https://buraksenturk.net>
-- Licensed under GPL-3.0-or-later with attribution terms. See COPYING and ATTRIBUTION.md.

ALTER TABLE llx_isnetefatura_document ADD INDEX idx_isnetefatura_document_fk_facture (fk_facture);
ALTER TABLE llx_isnetefatura_document ADD INDEX idx_isnetefatura_document_ettn (ettn);
ALTER TABLE llx_isnetefatura_document ADD INDEX idx_isnetefatura_document_entity (entity);

