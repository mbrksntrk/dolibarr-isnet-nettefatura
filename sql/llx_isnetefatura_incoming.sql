-- Copyright (C) 2026 M. Burak Şentürk <https://buraksenturk.net>
-- Licensed under GPL-3.0-or-later with attribution terms. See COPYING and ATTRIBUTION.md.
--
-- Incoming (supplier) e-Fatura documents pulled from the integrator.

CREATE TABLE llx_isnetefatura_incoming (
  rowid              integer AUTO_INCREMENT PRIMARY KEY,
  entity             integer DEFAULT 1 NOT NULL,
  ettn               varchar(64) NOT NULL,
  doc_kind           varchar(16) DEFAULT 'INVOICE' NOT NULL, -- INVOICE | DESPATCH
  invoice_number     varchar(32) DEFAULT NULL,
  invoice_date       date DEFAULT NULL,
  due_date           date DEFAULT NULL,
  scenario           varchar(32) DEFAULT NULL,
  invoice_type       varchar(32) DEFAULT NULL,
  sender_tax_code    varchar(16) DEFAULT NULL,
  sender_name        varchar(255) DEFAULT NULL,
  sender_tax_office  varchar(128) DEFAULT NULL,
  sender_address     varchar(255) DEFAULT NULL,
  sender_town        varchar(64) DEFAULT NULL,
  sender_city        varchar(64) DEFAULT NULL,
  sender_zip         varchar(16) DEFAULT NULL,
  sender_email       varchar(128) DEFAULT NULL,
  currency_code      varchar(3) DEFAULT NULL,
  cross_rate         double(24,8) DEFAULT NULL,
  total_line_ext     double(24,8) DEFAULT NULL,
  total_vat          double(24,8) DEFAULT NULL,
  total_payable      double(24,8) DEFAULT NULL,
  order_number       varchar(64) DEFAULT NULL,
  status             varchar(64) DEFAULT NULL,        -- integrator InvoiceStatus
  response_status    varchar(64) DEFAULT NULL,        -- ApplicationResponseStatus / our reply
  lines_json         mediumtext DEFAULT NULL,         -- InvoiceDetails as received
  fk_soc             integer DEFAULT NULL,
  fk_facture_fourn   integer DEFAULT NULL,
  date_received      datetime DEFAULT NULL,           -- InvoiceCreationDate at the integrator
  date_sync          datetime DEFAULT NULL,
  date_creation      datetime NOT NULL,
  tms                timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=innodb;
