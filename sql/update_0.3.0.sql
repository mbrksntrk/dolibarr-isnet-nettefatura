-- Copyright (C) 2026 M. Burak Şentürk <https://buraksenturk.net>
-- Licensed under GPL-3.0-or-later with attribution terms. See COPYING and ATTRIBUTION.md.
-- 0.2 -> 0.3: documents can belong to shipments; incoming rows can be despatch advices.

ALTER TABLE llx_isnetefatura_document ADD COLUMN element_type varchar(32) DEFAULT 'facture' NOT NULL AFTER fk_facture;
ALTER TABLE llx_isnetefatura_incoming ADD COLUMN doc_kind varchar(16) DEFAULT 'INVOICE' NOT NULL AFTER ettn;
