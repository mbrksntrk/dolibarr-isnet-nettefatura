-- Copyright (C) 2026 M. Burak Şentürk <https://buraksenturk.net>
-- Licensed under GPL-3.0-or-later with attribution terms. See COPYING and ATTRIBUTION.md.
-- 0.5 -> 0.6: incoming list filters (source invoice, unit, envelope, detail status).

ALTER TABLE llx_isnetefatura_incoming ADD COLUMN billing_reference varchar(32) DEFAULT NULL AFTER order_number;
ALTER TABLE llx_isnetefatura_incoming ADD COLUMN company_vendor_number varchar(32) DEFAULT NULL AFTER billing_reference;
ALTER TABLE llx_isnetefatura_incoming ADD COLUMN envelope_id varchar(64) DEFAULT NULL AFTER company_vendor_number;
ALTER TABLE llx_isnetefatura_incoming ADD COLUMN detail_status varchar(64) DEFAULT NULL AFTER status;
