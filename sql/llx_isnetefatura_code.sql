-- Copyright (C) 2026 M. Burak Şentürk <https://buraksenturk.net>
-- Licensed under GPL-3.0-or-later with attribution terms. See COPYING and ATTRIBUTION.md.
--
-- Local copy of the integrator's city / town / tax office code lists.

CREATE TABLE llx_isnetefatura_code (
  rowid       integer AUTO_INCREMENT PRIMARY KEY,
  code_type   varchar(16) NOT NULL,          -- CITY | TOWN | TAXOFFICE
  city_code   varchar(4) DEFAULT NULL,       -- plate number, zero padded as delivered
  code        varchar(16) NOT NULL,
  name        varchar(255) NOT NULL,
  name_norm   varchar(255) NOT NULL,         -- ASCII-folded upper-case name for matching
  tms         timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=innodb;
