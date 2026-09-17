-- Copyright (C) 2026 M. Burak Şentürk <https://buraksenturk.net>
-- Licensed under GPL-3.0-or-later with attribution terms. See COPYING and ATTRIBUTION.md.

ALTER TABLE llx_isnetefatura_code ADD INDEX idx_isnetefatura_code_lookup (code_type, city_code, name_norm);
ALTER TABLE llx_isnetefatura_code ADD UNIQUE INDEX uk_isnetefatura_code (code_type, code);
