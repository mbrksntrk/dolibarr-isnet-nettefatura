-- Copyright (C) 2026 M. Burak Şentürk <https://buraksenturk.net>
-- Licensed under GPL-3.0-or-later with attribution terms. See COPYING and ATTRIBUTION.md.

CREATE TABLE llx_isnetefatura_document (
  rowid            integer AUTO_INCREMENT PRIMARY KEY,
  entity           integer DEFAULT 1 NOT NULL,
  fk_facture       integer NOT NULL,            -- id of the Dolibarr object (invoice or shipment)
  element_type     varchar(32) DEFAULT 'facture' NOT NULL, -- facture | shipping
  doc_type         varchar(16) NOT NULL,            -- EFATURA | EARSIV
  scenario         varchar(32) DEFAULT NULL,        -- TEMELFATURA | TICARIFATURA | IHRACAT ...
  invoice_type     varchar(32) DEFAULT NULL,        -- SATIS | IADE | ISTISNA | TEVKIFAT ...
  external_code    varchar(64) NOT NULL,            -- Dolibarr ref sent as ExternalInvoiceCode
  ettn             varchar(64) DEFAULT NULL,
  invoice_number   varchar(32) DEFAULT NULL,        -- number assigned by the integrator
  receiver_tax_code varchar(16) DEFAULT NULL,
  receiver_inbox_tag varchar(255) DEFAULT NULL,
  status           varchar(64) DEFAULT NULL,        -- integrator InvoiceStatus
  detail_status    varchar(64) DEFAULT NULL,        -- integrator InvoiceDetailStatus
  last_error       text DEFAULT NULL,
  request_xml      mediumtext DEFAULT NULL,         -- kept only when ISNETEFATURA_DEBUG=1
  response_xml     mediumtext DEFAULT NULL,
  date_sent        datetime DEFAULT NULL,
  date_checked     datetime DEFAULT NULL,
  fk_user_send     integer DEFAULT NULL,
  date_creation    datetime NOT NULL,
  tms              timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=innodb;
