<?php
/* Copyright (C) 2026 M. Burak Şentürk <https://buraksenturk.net>
 * Licensed under GPL-3.0-or-later with attribution terms. See COPYING and ATTRIBUTION.md.
 */

dol_include_once('/isnetefatura/class/isnetclient.class.php');
dol_include_once('/isnetefatura/class/isnetcoderesolver.class.php');
dol_include_once('/isnetefatura/class/isnetaudit.class.php');
require_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php';

/**
 * Incoming (supplier) e-Fatura documents: sync from the integrator, turn into draft
 * supplier invoices, and answer commercial invoices (accept / reject).
 */
class IsnetIncoming
{
	const TABLE = 'isnetefatura_incoming';

	public $db;
	public $error = '';
	public $output = '';

	public $id = 0;
	public $entity = 1;
	public $ettn = '';
	public $doc_kind = 'INVOICE';
	public $invoice_number = '';
	public $invoice_date = null;
	public $due_date = null;
	public $scenario = '';
	public $invoice_type = '';
	public $sender_tax_code = '';
	public $sender_name = '';
	public $sender_tax_office = '';
	public $sender_address = '';
	public $sender_town = '';
	public $sender_city = '';
	public $sender_zip = '';
	public $sender_email = '';
	public $currency_code = '';
	public $cross_rate = 0.0;
	public $total_line_ext = 0.0;
	public $total_vat = 0.0;
	public $total_payable = 0.0;
	public $order_number = '';
	public $billing_reference = '';
	public $company_vendor_number = '';
	public $envelope_id = '';
	public $status = '';
	public $detail_status = '';
	public $response_status = '';
	public $lines_json = '';
	public $fk_soc = 0;
	public $fk_facture_fourn = 0;
	public $date_received = null;
	public $date_sync = null;

	public function __construct($db)
	{
		$this->db = $db;
	}

	private function hydrate($o)
	{
		foreach (get_object_vars($this) as $k => $v) {
			if ($k === 'db' || $k === 'error' || $k === 'output') {
				continue;
			}
			if (!property_exists($o, $k === 'id' ? 'rowid' : $k)) {
				continue;
			}
			$src = $k === 'id' ? 'rowid' : $k;
			if (in_array($k, array('invoice_date', 'due_date', 'date_received', 'date_sync'), true)) {
				$this->$k = $o->$src ? $this->db->jdate($o->$src) : null;
			} elseif (in_array($k, array('id', 'entity', 'fk_soc', 'fk_facture_fourn'), true)) {
				$this->$k = (int) $o->$src;
			} elseif (in_array($k, array('cross_rate', 'total_line_ext', 'total_vat', 'total_payable'), true)) {
				$this->$k = (float) $o->$src;
			} else {
				$this->$k = (string) $o->$src;
			}
		}
	}

	public function fetch($id)
	{
		$res = $this->db->query('SELECT * FROM '.MAIN_DB_PREFIX.self::TABLE.' WHERE rowid = '.((int) $id));
		$o = $res ? $this->db->fetch_object($res) : null;
		if (!$o) {
			return 0;
		}
		$this->hydrate($o);
		return 1;
	}

	/**
	 * @return IsnetIncoming[]
	 */
	public function fetchAll($filters = array(), $limit = 200)
	{
		$total = 0;
		return $this->fetchList($filters, $total, $limit);
	}

	/** Columns the list may be sorted on */
	const SORTABLE = array('invoice_date', 'date_received', 'invoice_number', 'sender_name', 'scenario', 'invoice_type', 'total_line_ext', 'total_vat', 'total_payable', 'status', 'company_vendor_number');

	/** Days a commercial invoice can be answered after it is received (GİB) */
	const REPLY_DAYS = 8;

	/**
	 * Filtered, sorted, paged list.
	 *
	 * Filter keys: kind, search, unlinked (1 = not imported, 2 = imported), number, sender, taxcode,
	 * scenario, type, status, detail_status, approval (see approvalSql), vendor, currency, ref (source invoice),
	 * date_start/date_end (invoice date), received_start/received_end, amount_min/amount_max (payable).
	 *
	 * @param  array $filters
	 * @param  int   $total   Out: number of matching rows
	 * @return IsnetIncoming[]
	 */
	public function fetchList($filters, &$total, $limit = 50, $offset = 0, $sortfield = 'invoice_date', $sortorder = 'DESC')
	{
		$where = $this->buildWhere($filters);
		$total = 0;
		$res = $this->db->query('SELECT COUNT(*) AS n FROM '.MAIN_DB_PREFIX.self::TABLE.' WHERE '.$where);
		if ($res && ($o = $this->db->fetch_object($res))) {
			$total = (int) $o->n;
		}
		$sortfield = in_array($sortfield, self::SORTABLE, true) ? $sortfield : 'invoice_date';
		$sortorder = strtoupper($sortorder) === 'ASC' ? 'ASC' : 'DESC';
		$sql = 'SELECT * FROM '.MAIN_DB_PREFIX.self::TABLE.' WHERE '.$where.' ORDER BY '.$sortfield.' '.$sortorder.', rowid '.$sortorder;
		if ($limit > 0) {
			$sql .= ' LIMIT '.((int) $limit).' OFFSET '.max(0, (int) $offset);
		}
		$out = array();
		$res = $this->db->query($sql);
		while ($res && ($o = $this->db->fetch_object($res))) {
			$x = new IsnetIncoming($this->db);
			$x->hydrate($o);
			$out[] = $x;
		}
		return $out;
	}

	/**
	 * Sums of the filtered rows per currency.
	 *
	 * @return array currency => array(n, ht, vat, ttc)
	 */
	public function totalsByCurrency($filters)
	{
		$sql = 'SELECT currency_code, COUNT(*) AS n, SUM(total_line_ext) AS ht, SUM(total_vat) AS vat, SUM(total_payable) AS ttc FROM '.MAIN_DB_PREFIX.self::TABLE;
		$sql .= ' WHERE '.$this->buildWhere($filters).' GROUP BY currency_code ORDER BY n DESC';
		$out = array();
		$res = $this->db->query($sql);
		while ($res && ($o = $this->db->fetch_object($res))) {
			$out[(string) $o->currency_code] = array('n' => (int) $o->n, 'ht' => (float) $o->ht, 'vat' => (float) $o->vat, 'ttc' => (float) $o->ttc);
		}
		return $out;
	}

	/**
	 * Distinct non-empty values of a column, for filter drop-downs.
	 */
	public function distinctValues($column, $kind = 'INVOICE')
	{
		if (!in_array($column, array('scenario', 'invoice_type', 'status', 'detail_status', 'currency_code', 'company_vendor_number', 'sender_name'), true)) {
			return array();
		}
		$sql = 'SELECT DISTINCT '.$column.' AS v FROM '.MAIN_DB_PREFIX.self::TABLE.' WHERE entity IN ('.getEntity('supplier_invoice').')';
		$sql .= " AND doc_kind = '".$this->db->escape($kind)."' AND ".$column." IS NOT NULL AND ".$column." <> '' ORDER BY ".$column;
		$out = array();
		$res = $this->db->query($sql);
		while ($res && ($o = $this->db->fetch_object($res))) {
			$out[] = (string) $o->v;
		}
		return $out;
	}

	/**
	 * SQL condition for an approval state: pending | expiring | accepted | auto | rejected | returned.
	 * The integrator status carries the state; our own reply (response_status) overrides it.
	 */
	private function approvalSql($state)
	{
		$noReply = "(response_status IS NULL OR response_status = '')";
		switch ($state) {
			case 'pending':
				return "(status = 'Onay_Bekliyor' AND ".$noReply.')';
			case 'expiring':
				$from = $this->db->idate(dol_now() - self::REPLY_DAYS * 86400);
				$to = $this->db->idate(dol_now() - (self::REPLY_DAYS - 3) * 86400);
				return "(status = 'Onay_Bekliyor' AND ".$noReply." AND scenario = 'TICARIFATURA' AND COALESCE(date_received, invoice_date) BETWEEN '".$from."' AND '".$to."')";
			case 'accepted':
				return "(response_status = 'KABUL' OR (".$noReply." AND status IN ('Onaylandi', 'Alici_Kabul_Etti')))";
			case 'auto':
				return "(".$noReply." AND status LIKE 'Otomatik%')";
			case 'rejected':
				return "(response_status = 'RED' OR (".$noReply." AND status IN ('Reddedildi', 'Alici_Reddetti')))";
			case 'returned':
				return "(response_status = 'IADE_EDILDI' OR (".$noReply." AND status IN ('Iade_Edildi', 'Alici_Iade_Etti')))";
		}
		return '';
	}

	/**
	 * Approval state of this row (same buckets as the filter, without "expiring").
	 */
	public function approvalState()
	{
		$map = array('KABUL' => 'accepted', 'RED' => 'rejected', 'IADE_EDILDI' => 'returned');
		if (isset($map[$this->response_status])) {
			return $map[$this->response_status];
		}
		if ($this->status === 'Onay_Bekliyor') {
			return 'pending';
		}
		if (strpos($this->status, 'Otomatik') === 0) {
			return 'auto';
		}
		if (in_array($this->status, array('Onaylandi', 'Alici_Kabul_Etti'), true)) {
			return 'accepted';
		}
		if (in_array($this->status, array('Reddedildi', 'Alici_Reddetti'), true)) {
			return 'rejected';
		}
		if (in_array($this->status, array('Iade_Edildi', 'Alici_Iade_Etti'), true)) {
			return 'returned';
		}
		return '';
	}

	/**
	 * Whether this document still accepts our KABUL / RED answer.
	 */
	public function canReply()
	{
		return $this->doc_kind === 'INVOICE' && $this->response_status === '' && in_array($this->status, array('', 'Onay_Bekliyor'), true);
	}

	/**
	 * Days left to answer a commercial invoice, null when not applicable.
	 */
	public function replyDaysLeft()
	{
		if ($this->scenario !== 'TICARIFATURA' || !$this->canReply()) {
			return null;
		}
		$from = $this->date_received ?: $this->invoice_date;
		if (!$from) {
			return null;
		}
		return (int) floor(($from + self::REPLY_DAYS * 86400 - dol_now()) / 86400);
	}

	private function buildWhere($f)
	{
		$db = $this->db;
		// Drop-downs post -1 for their empty choice: that means "any", never a value to match.
		foreach (array('scenario', 'type', 'status', 'detail_status', 'approval', 'currency', 'vendor', 'unlinked') as $k) {
			if (isset($f[$k]) && (string) $f[$k] === '-1') {
				unset($f[$k]);
			}
		}
		$w = array('entity IN ('.getEntity('supplier_invoice').')');
		$w[] = "doc_kind = '".$db->escape(!empty($f['kind']) ? $f['kind'] : 'INVOICE')."'";
		if (!empty($f['unlinked'])) {
			$w[] = (int) $f['unlinked'] === 2 ? 'fk_facture_fourn > 0' : '(fk_facture_fourn IS NULL OR fk_facture_fourn = 0)';
		}
		if (!empty($f['search'])) {
			$s = $db->escape($db->escapeforlike($f['search']));
			$w[] = "(invoice_number LIKE '%".$s."%' OR sender_name LIKE '%".$s."%' OR sender_tax_code LIKE '%".$s."%' OR ettn LIKE '%".$s."%')";
		}
		$like = array('number' => 'invoice_number', 'sender' => 'sender_name', 'taxcode' => 'sender_tax_code', 'ref' => 'billing_reference');
		foreach ($like as $k => $col) {
			if (isset($f[$k]) && trim((string) $f[$k]) !== '') {
				$w[] = $col." LIKE '%".$db->escape($db->escapeforlike(trim((string) $f[$k])))."%'";
			}
		}
		$eq = array('scenario' => 'scenario', 'type' => 'invoice_type', 'status' => 'status', 'detail_status' => 'detail_status', 'currency' => 'currency_code', 'vendor' => 'company_vendor_number');
		foreach ($eq as $k => $col) {
			if (isset($f[$k]) && (string) $f[$k] !== '') {
				$w[] = $col." = '".$db->escape((string) $f[$k])."'";
			}
		}
		if (!empty($f['approval']) && ($a = $this->approvalSql($f['approval'])) !== '') {
			$w[] = $a;
		}
		$dates = array('date_start' => array('invoice_date', '>='), 'date_end' => array('invoice_date', '<='), 'received_start' => array('date_received', '>='), 'received_end' => array('date_received', '<='));
		foreach ($dates as $k => $d) {
			if (!empty($f[$k])) {
				$w[] = $d[0].' '.$d[1]." '".$db->idate((int) $f[$k])."'";
			}
		}
		if (isset($f['amount_min']) && $f['amount_min'] !== '' && $f['amount_min'] !== null) {
			$w[] = 'total_payable >= '.((float) price2num($f['amount_min']));
		}
		if (isset($f['amount_max']) && $f['amount_max'] !== '' && $f['amount_max'] !== null) {
			$w[] = 'total_payable <= '.((float) price2num($f['amount_max']));
		}
		return implode(' AND ', $w);
	}

	/* ------------------------------------------------------------------ units (CompanyVendorNumber) */

	/**
	 * Units of the company at the integrator, from the cache refreshed by the sync job.
	 * The page never calls the integrator for this: a dead connection must not stall the list.
	 *
	 * @param  bool $refresh Ask the integrator now and update the cache
	 * @return array number => name
	 */
	public static function vendors($db, $refresh = false)
	{
		global $conf;
		if ($refresh) {
			$client = new IsnetClient($db);
			$list = $client->getCompanyVendors();
			if ($client->error === '') {
				require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
				dolibarr_set_const($db, 'ISNETEFATURA_VENDORS_CACHE', json_encode($list, JSON_UNESCAPED_UNICODE), 'chaine', 0, '', $conf->entity);
				return $list;
			}
		}
		$list = json_decode(getDolGlobalString('ISNETEFATURA_VENDORS_CACHE', '[]'), true);
		return is_array($list) ? $list : array();
	}

	/**
	 * Unit holding this document. When the company has a single unit, documents without one
	 * belong to it.
	 */
	public function vendorNumber(array $vendors)
	{
		if ($this->company_vendor_number !== '') {
			return $this->company_vendor_number;
		}
		return count($vendors) === 1 ? (string) key($vendors) : '';
	}

	/**
	 * Find an incoming invoice by sender VKN/TCKN and number: locally first, then at the integrator
	 * (the hit is stored, so it shows up in the list).
	 *
	 * @return int >0 found (this is loaded), 0 not found, <0 error
	 */
	public function locate($taxCode, $number)
	{
		global $conf;
		$taxCode = preg_replace('/\D+/', '', (string) $taxCode);
		$number = strtoupper(trim((string) $number));
		if ($number === '') {
			return 0;
		}
		$sql = 'SELECT rowid FROM '.MAIN_DB_PREFIX.self::TABLE.' WHERE entity IN ('.getEntity('supplier_invoice').") AND doc_kind = 'INVOICE'";
		$sql .= " AND invoice_number = '".$this->db->escape($number)."'".($taxCode !== '' ? " AND sender_tax_code = '".$this->db->escape($taxCode)."'" : '');
		$res = $this->db->query($sql.' LIMIT 1');
		if ($res && ($o = $this->db->fetch_object($res))) {
			return $this->fetch((int) $o->rowid);
		}
		$client = new IsnetClient($this->db);
		$list = $client->searchInvoice(array(
			'MinInvoiceNumber' => $number,
			'MaxInvoiceNumber' => $number,
			'ResultSet' => IsnetClient::resultSet(array('IsInvoiceDetailIncluded')),
		), 'Incoming');
		if ($client->error !== '') {
			$this->error = $client->error;
			return -1;
		}
		foreach ($list as $inv) {
			$vkn = preg_replace('/\D+/', '', (string) ($inv->Receiver->ReceiverTaxCode ?? ''));
			if ($taxCode !== '' && $vkn !== $taxCode) {
				continue;
			}
			if ($this->upsert($inv) < 0) {
				return -1;
			}
			$res = $this->db->query('SELECT rowid FROM '.MAIN_DB_PREFIX.self::TABLE." WHERE ettn = '".$this->db->escape((string) $inv->ETTN)."' AND entity = ".((int) $conf->entity));
			if ($res && ($o = $this->db->fetch_object($res))) {
				return $this->fetch((int) $o->rowid);
			}
		}
		return 0;
	}

	/**
	 * Move this document to another unit at the integrator.
	 *
	 * @return int >0 ok, <0 error
	 */
	public function moveToVendor($toVendor, User $user)
	{
		$client = new IsnetClient($this->db);
		$from = $this->company_vendor_number;
		if (!$client->directInvoice(array($this->ettn), $from, $toVendor)) {
			$this->error = $client->error;
			return -1;
		}
		$this->company_vendor_number = (string) $toVendor;
		$this->db->query('UPDATE '.MAIN_DB_PREFIX.self::TABLE." SET company_vendor_number = '".$this->db->escape($this->company_vendor_number)."' WHERE rowid = ".((int) $this->id));
		IsnetAudit::logSecurity($this->db, 'ISNET_DIRECT', 'Incoming e-Fatura '.$this->invoice_number.' ('.$this->ettn.') moved from unit "'.$from.'" to "'.$toVendor.'" by '.$user->login, $user);
		return 1;
	}

	public function lines()
	{
		$l = json_decode($this->lines_json ?: '[]', true);
		return is_array($l) ? $l : array();
	}

	private static function dateOrNull($s)
	{
		$s = (string) $s;
		if ($s === '' || strpos($s, '0001-01-01') === 0) {
			return null;
		}
		return dol_stringtotime(substr($s, 0, 19), 1);
	}

	/* ------------------------------------------------------------------ sync */

	/**
	 * Pull incoming documents from the integrator and upsert them locally.
	 *
	 * @param  int $days Lookback window
	 * @return int number of rows upserted, <0 on error
	 */
	public function sync($days = 30)
	{
		global $conf;
		$client = new IsnetClient($this->db);
		$since = dol_print_date(dol_now() - max(1, (int) $days) * 86400, '%Y-%m-%d', 'tzserver').'T00:00:00';
		$n = 0;
		$page = 1;
		do {
			$list = $client->searchInvoice(array(
				'MinInvoiceDate' => $since,
				'PagingRequest' => array('PageNumber' => $page, 'RecordsPerPage' => 50),
				'ResultSet' => IsnetClient::resultSet(array('IsInvoiceDetailIncluded')),
			), 'Incoming');
			if ($client->error !== '') {
				$this->error = $client->error;
				return -1;
			}
			foreach ($list as $inv) {
				if ($this->upsert($inv) > 0) {
					$n++;
				}
			}
			$page++;
		} while (count($list) === 50 && $page <= 20);
		$this->output = $n.' incoming documents synced';
		return $n;
	}

	private function upsert($inv)
	{
		global $conf;
		$ettn = (string) ($inv->ETTN ?? '');
		if ($ettn === '') {
			return 0;
		}
		$r = $inv->Receiver ?? null;
		$a = $r->Address ?? null;
		$lines = array();
		foreach (IsnetClient::toList($inv->InvoiceDetails ?? null, 'InvoiceDetail') as $d) {
			$lines[] = array(
				'name' => (string) ($d->Product->ProductName ?? ''),
				'code' => (string) ($d->Product->ProductCode ?? ($d->Product->ExternalProductCode ?? '')),
				'unit' => (string) ($d->Product->MeasureUnit ?? ''),
				'unit_price' => (float) ($d->Product->UnitPrice ?? 0),
				'qty' => (float) ($d->Quantity ?? 0),
				'line_ext' => (float) ($d->LineExtensionAmount ?? 0),
				'vat_rate' => (float) ($d->VATRate ?? 0),
				'vat_amount' => (float) ($d->VATAmount ?? 0),
				'discount' => (float) ($d->DiscountAmount ?? 0),
				'note' => (string) ($d->Note ?? ''),
			);
		}
		$vals = array(
			'invoice_number' => (string) ($inv->InvoiceNumber ?? ''),
			'invoice_date' => self::dateOrNull($inv->InvoiceDate ?? ''),
			'due_date' => self::dateOrNull($inv->LastPaymentDate ?? ''),
			'scenario' => (string) ($inv->ScenarioType ?? ''),
			'invoice_type' => (string) ($inv->InvoiceType ?? ''),
			'sender_tax_code' => preg_replace('/\D+/', '', (string) ($r->ReceiverTaxCode ?? '')),
			'sender_name' => (string) ($r->ReceiverName ?? ''),
			'sender_tax_office' => (string) ($a->TaxOfficeName ?? ''),
			'sender_address' => (string) ($a->BoulevardAveneuStreetName ?? ''),
			'sender_town' => (string) ($a->TownName ?? ''),
			'sender_city' => (string) ($a->CityName ?? ''),
			'sender_zip' => (string) ($a->PostalCode ?? ''),
			'sender_email' => (string) ($a->EMail ?? ''),
			'currency_code' => (string) ($inv->CurrencyCode ?? 'TRY'),
			'cross_rate' => (float) ($inv->CrossRate ?? 0),
			'total_line_ext' => (float) ($inv->TotalLineExtensionAmount ?? 0),
			'total_vat' => (float) ($inv->TotalVATAmount ?? 0),
			'total_payable' => (float) ($inv->TotalPayableAmount ?? 0),
			'order_number' => (string) ($inv->OrderNumber ?? ''),
			'billing_reference' => (string) ($inv->ReturnInvoiceNumber ?? ''),
			'company_vendor_number' => (string) ($inv->CompanyVendorNumber ?? ''),
			'envelope_id' => (string) ($inv->EnvelopeId ?? ''),
			'status' => (string) ($inv->Status ?? ''),
			'detail_status' => (string) ($inv->DetailStatus ?? ''),
			'response_status' => (string) ($inv->ApplicationResponseStatus ?? ''),
			'lines_json' => json_encode($lines, JSON_UNESCAPED_UNICODE),
			'date_received' => self::dateOrNull($inv->InvoiceCreationDate ?? ''),
			'date_sync' => dol_now(),
		);

		$res = $this->db->query('SELECT rowid FROM '.MAIN_DB_PREFIX.self::TABLE." WHERE ettn = '".$this->db->escape($ettn)."' AND entity = ".((int) $conf->entity));
		$existing = $res ? $this->db->fetch_object($res) : null;

		$set = array();
		foreach ($vals as $k => $v) {
			if (in_array($k, array('invoice_date', 'due_date', 'date_received', 'date_sync'), true)) {
				$set[] = $k.' = '.($v ? "'".$this->db->idate($v)."'" : 'NULL');
			} elseif (is_float($v)) {
				$set[] = $k.' = '.$v;
			} else {
				$set[] = $k." = '".$this->db->escape($v)."'";
			}
		}
		if ($existing) {
			$sql = 'UPDATE '.MAIN_DB_PREFIX.self::TABLE.' SET '.implode(', ', $set).' WHERE rowid = '.((int) $existing->rowid);
		} else {
			$sql = 'INSERT INTO '.MAIN_DB_PREFIX.self::TABLE." SET entity = ".((int) $conf->entity).", ettn = '".$this->db->escape($ettn)."', date_creation = '".$this->db->idate(dol_now())."', ".implode(', ', $set);
		}
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			dol_syslog(__METHOD__.' '.$this->error, LOG_ERR);
			return -1;
		}
		return 1;
	}

	/* ------------------------------------------------------------------ incoming despatch advices */

	/**
	 * Pull incoming e-İrsaliye documents into the same table (doc_kind = DESPATCH).
	 */
	public function syncDespatch($days = 30)
	{
		global $conf;
		$client = new IsnetClient($this->db);
		$since = dol_print_date(dol_now() - max(1, (int) $days) * 86400, '%Y-%m-%d', 'tzserver').'T00:00:00';
		$n = 0;
		$page = 1;
		do {
			$list = $client->searchDespatchAdvice(array(
				'MinDespatchAdviceDate' => $since,
				'PagingRequest' => array('PageNumber' => $page, 'RecordsPerPage' => 50),
				'ResultSet' => IsnetClient::despatchResultSet(array('IsDespatchAdviceDetailIncluded')),
			), 'Incoming');
			if ($client->error !== '') {
				$this->error = $client->error;
				return -1;
			}
			foreach ($list as $adv) {
				$ettn = (string) ($adv->ETTN ?? '');
				if ($ettn === '') {
					continue;
				}
				$sup = $adv->DespatchSupplierParty ?? null;
				$a = $sup->Address ?? null;
				$lines = array();
				$detailList = IsnetClient::toList($adv->DespatchAdviceDetails ?? null, 'DespatchAdviceDetail');
				if (empty($detailList)) {
					// The paged search omits line details; fetch them per document unless already stored.
					$res = $this->db->query('SELECT lines_json FROM '.MAIN_DB_PREFIX.self::TABLE." WHERE ettn = '".$this->db->escape($ettn)."' AND entity = ".((int) $conf->entity));
					$prev = $res ? $this->db->fetch_object($res) : null;
					if (!$prev || strlen((string) $prev->lines_json) < 5) {
						$one = $client->searchDespatchAdvice(array('Ettn' => $ettn, 'ResultSet' => IsnetClient::despatchResultSet(array('IsDespatchAdviceDetailIncluded'))), 'Incoming');
						$detailList = !empty($one) ? IsnetClient::toList($one[0]->DespatchAdviceDetails ?? null, 'DespatchAdviceDetail') : array();
					}
				}
				foreach ($detailList as $d) {
					$lines[] = array(
						'name' => (string) ($d->Product->ProductName ?? ''),
						'code' => (string) ($d->Product->ProductCode ?? ($d->Product->ExternalProductCode ?? '')),
						'unit' => (string) ($d->Product->MeasureUnit ?? ''),
						'unit_price' => (float) ($d->Product->UnitPrice ?? 0),
						'qty' => (float) ($d->SentQuantity ?? 0),
						'line_ext' => 0, 'vat_rate' => 0, 'vat_amount' => 0, 'discount' => 0,
						'note' => (string) ($d->Note ?? ''),
					);
				}
				$vals = array(
					'invoice_number' => (string) ($adv->DespatchAdviceNumber ?? ''),
					'invoice_date' => self::dateOrNull($adv->DespatchAdviceDate ?? ''),
					'scenario' => (string) ($adv->DespatchAdviceScenarioType ?? ''),
					'invoice_type' => (string) ($adv->DespatchAdviceType ?? ''),
					'sender_tax_code' => preg_replace('/\D+/', '', (string) ($sup->ReceiverTaxCode ?? '')),
					'sender_name' => (string) ($sup->ReceiverName ?? ''),
					'sender_address' => (string) ($a->BoulevardAveneuStreetName ?? ''),
					'sender_town' => (string) ($a->TownName ?? ''),
					'sender_city' => (string) ($a->CityName ?? ''),
					'currency_code' => (string) ($adv->CurrencyCode ?? 'TRY'),
					'total_payable' => (float) ($adv->Shipment->TotalValueAmount ?? 0),
					'order_number' => (string) ($adv->OrderNumber ?? ''),
					'status' => (string) ($adv->Status ?? ''),
					'date_received' => self::dateOrNull($adv->DespatchAdviceCreationDate ?? ''),
					'date_sync' => dol_now(),
				);
				if (!empty($lines)) {
					$vals['lines_json'] = json_encode($lines, JSON_UNESCAPED_UNICODE);
				}
				$set = array();
				foreach ($vals as $k => $v) {
					if (in_array($k, array('invoice_date', 'date_received', 'date_sync'), true)) {
						$set[] = $k.' = '.($v ? "'".$this->db->idate($v)."'" : 'NULL');
					} elseif (is_float($v)) {
						$set[] = $k.' = '.$v;
					} else {
						$set[] = $k." = '".$this->db->escape($v)."'";
					}
				}
				$res = $this->db->query('SELECT rowid FROM '.MAIN_DB_PREFIX.self::TABLE." WHERE ettn = '".$this->db->escape($ettn)."' AND entity = ".((int) $conf->entity));
				$existing = $res ? $this->db->fetch_object($res) : null;
				$sql = $existing
					? 'UPDATE '.MAIN_DB_PREFIX.self::TABLE.' SET '.implode(', ', $set).' WHERE rowid = '.((int) $existing->rowid)
					: 'INSERT INTO '.MAIN_DB_PREFIX.self::TABLE." SET entity = ".((int) $conf->entity).", ettn = '".$this->db->escape($ettn)."', doc_kind = 'DESPATCH', date_creation = '".$this->db->idate(dol_now())."', ".implode(', ', $set);
				if ($this->db->query($sql)) {
					$n++;
				}
			}
			$page++;
		} while (count($list) === 50 && $page <= 20);
		$this->output = $n.' incoming despatch advices synced';
		return $n;
	}

	/**
	 * Acknowledge an incoming despatch advice: every line received in full.
	 *
	 * @return int >0 ok, <0 error
	 */
	public function sendReceipt(User $user)
	{
		global $conf, $mysoc, $langs;
		$langs->load('isnetefatura@isnetefatura');
		if ($this->doc_kind !== 'DESPATCH') {
			$this->error = 'IsnetErrNotDespatch';
			return -1;
		}
		$client = new IsnetClient($this->db);
		$inbox = $client->resolveDespatchReceiver($this->sender_tax_code);
		if ($inbox === null) {
			$this->error = $client->error ?: 'IsnetErrDespatchReceiverNotRegistered';
			return -1;
		}
		$details = array();
		$n = 0;
		foreach ($this->lines() as $l) {
			$n++;
			$details[] = array(
				'Product' => array('ExternalProductCode' => $l['code'] ?: ('L'.$n), 'MeasureUnit' => $l['unit'] ?: 'C62', 'ProductCode' => $l['code'] ?: ('L'.$n), 'ProductName' => $l['name'] ?: $l['code'], 'UnitPrice' => (float) $l['unit_price']),
				'ReceivedDate' => dol_print_date(dol_now(), '%Y-%m-%dT%H:%M:%S', 'tzserver'),
				'ReceivedQuantity' => (float) $l['qty'],
				'RelatedDespatchLineNumber' => (string) $n,
			);
		}
		$receipt = array(
			'CurrencyCode' => $this->currency_code ?: $conf->currency,
			'DeliveryCustomerParty' => array('ReceiverName' => trim((string) $mysoc->name), 'ReceiverTaxCode' => $client->getCompanyTaxCode()),
			'DespatchSupplierParty' => array('ReceiverName' => $this->sender_name, 'ReceiverTaxCode' => $this->sender_tax_code),
			'ExternalReceiptAdviceCode' => 'RA-'.$this->invoice_number,
			'ReceiptAdviceDate' => dol_print_date(dol_now(), '%Y-%m-%dT%H:%M:%S', 'tzserver'),
			'ReceiptAdviceDetails' => array('ReceiptAdviceDetail' => $details),
			'ReceiptAdviceScenarioType' => 'TEMELIRSALIYE',
			'ReceiptAdviceType' => 'SEVK',
			'ReceiverInboxTag' => $inbox['inbox_tag'],
			'RelatedDespatchAdviceEttn' => $this->ettn,
			'Shipment' => array('Delivery' => array('ActualDespatchDate' => dol_print_date(dol_now(), '%Y-%m-%dT%H:%M:%S', 'tzserver'))),
		);
		$r = $client->sendReceiptAdvice(array($receipt));
		if ($client->error !== '' || empty($r)) {
			$this->error = $client->error ?: 'empty response';
			return -1;
		}
		$number = (string) ($r[0]->ReceiptAdviceNumber ?? '');
		$this->response_status = 'ALINDI';
		$this->db->query('UPDATE '.MAIN_DB_PREFIX.self::TABLE." SET response_status = 'ALINDI' WHERE rowid = ".((int) $this->id));
		IsnetAudit::logSecurity($this->db, 'ISNET_RECEIPT', 'Receipt advice '.$number.' sent for incoming despatch '.$this->invoice_number.' ('.$this->sender_tax_code.')', $user);
		return 1;
	}

	/**
	 * Cron entry point.
	 */
	public function cronSyncIncoming($days = 30)
	{
		if (!getDolGlobalInt('ISNETEFATURA_INCOMING_ENABLED', 1)) {
			$this->output = 'incoming sync disabled';
			return 0;
		}
		$r = $this->sync((int) $days);
		$out = $this->output;
		if ($r >= 0 && getDolGlobalInt('ISNETEFATURA_DESPATCH_ENABLED', 1) && isModEnabled('expedition')) {
			$r2 = $this->syncDespatch((int) $days);
			$out .= '; '.$this->output;
			if ($r2 < 0) {
				$r = -1;
			}
		}
		if ($r >= 0) {
			self::vendors($this->db, true);
		}
		self::recordHealth($this->db, $r >= 0, $r < 0 ? $this->error : '');
		$this->output = $out;
		return $r < 0 ? -1 : 0;
	}

	/* ------------------------------------------------------------------ connection health */

	/**
	 * Remember the outcome of a sync and e-mail the configured address when the integrator has
	 * been unreachable for ISNETEFATURA_ALERT_AFTER consecutive runs, and once more when it recovers.
	 *
	 * @param bool   $ok
	 * @param string $error
	 */
	public static function recordHealth($db, $ok, $error = '')
	{
		global $conf;
		require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
		$entity = $conf->entity;
		$fails = getDolGlobalInt('ISNETEFATURA_SYNC_FAILS');
		$alerted = getDolGlobalInt('ISNETEFATURA_SYNC_ALERTED');
		if ($ok) {
			dolibarr_set_const($db, 'ISNETEFATURA_SYNC_LAST_OK', (string) dol_now(), 'chaine', 0, '', $entity);
			if ($fails > 0) {
				dolibarr_set_const($db, 'ISNETEFATURA_SYNC_FAILS', '0', 'chaine', 0, '', $entity);
			}
			if ($alerted) {
				dolibarr_set_const($db, 'ISNETEFATURA_SYNC_ALERTED', '0', 'chaine', 0, '', $entity);
				self::sendAlert($db, true, '');
			}
			return;
		}
		$fails++;
		dolibarr_set_const($db, 'ISNETEFATURA_SYNC_FAILS', (string) $fails, 'chaine', 0, '', $entity);
		dolibarr_set_const($db, 'ISNETEFATURA_SYNC_LAST_ERROR', dol_trunc($error, 1000, 'right', 'UTF-8', 1), 'chaine', 0, '', $entity);
		dolibarr_set_const($db, 'ISNETEFATURA_SYNC_LAST_ERROR_DATE', (string) dol_now(), 'chaine', 0, '', $entity);
		if (!$alerted && $fails >= max(1, getDolGlobalInt('ISNETEFATURA_ALERT_AFTER', 3))) {
			if (self::sendAlert($db, false, $error)) {
				dolibarr_set_const($db, 'ISNETEFATURA_SYNC_ALERTED', '1', 'chaine', 0, '', $entity);
			}
		}
	}

	/**
	 * @return bool true when a message was sent
	 */
	private static function sendAlert($db, $recovered, $error)
	{
		global $langs, $mysoc;
		$to = trim(getDolGlobalString('ISNETEFATURA_ALERT_EMAIL'));
		if ($to === '') {
			return false;
		}
		require_once DOL_DOCUMENT_ROOT.'/core/class/CMailFile.class.php';
		$langs->load('isnetefatura@isnetefatura');
		$company = is_object($mysoc) && !empty($mysoc->name) ? $mysoc->name : getDolGlobalString('MAIN_INFO_SOCIETE_NOM');
		$lastOk = getDolGlobalInt('ISNETEFATURA_SYNC_LAST_OK');
		$since = $lastOk ? dol_print_date($lastOk, 'dayhour', 'tzserver') : '-';
		if ($recovered) {
			$subject = $langs->transnoentities('IsnetAlertRecoveredSubject', $company);
			$body = $langs->transnoentities('IsnetAlertRecoveredBody');
		} else {
			$subject = $langs->transnoentities('IsnetAlertSubject', $company);
			$body = $langs->transnoentities('IsnetAlertBody', getDolGlobalInt('ISNETEFATURA_SYNC_FAILS'), $since, $error);
		}
		$body .= "\n\n".dol_buildpath('/isnetefatura/incoming.php', 2);
		$from = getDolGlobalString('MAIN_MAIL_EMAIL_FROM', 'robot@domain.com');
		$mail = new CMailFile($subject, $to, $from, $body, array(), array(), array(), '', '', 0, 0, '', '', '', '', 'standard');
		$ok = (bool) $mail->sendfile();
		dol_syslog('IsnetIncoming::sendAlert '.($recovered ? 'recovered' : 'failing').' to '.$to.' '.($ok ? 'sent' : 'FAILED '.$mail->error), $ok ? LOG_NOTICE : LOG_ERR);
		return $ok;
	}

	/* ------------------------------------------------------------------ third party */

	/**
	 * Find (or create) the supplier third party for this document's sender.
	 *
	 * @return int fk_soc or <0
	 */
	public function resolveSupplier(User $user)
	{
		require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
		if ($this->fk_soc > 0) {
			return $this->fk_soc;
		}
		$vkn = $this->sender_tax_code;
		if ($vkn !== '') {
			$sql = 'SELECT rowid FROM '.MAIN_DB_PREFIX."societe WHERE entity IN (".getEntity('societe').") AND REPLACE(REPLACE(siren, ' ', ''), '-', '') = '".$this->db->escape($vkn)."' ORDER BY fournisseur DESC, rowid ASC LIMIT 1";
			$res = $this->db->query($sql);
			$o = $res ? $this->db->fetch_object($res) : null;
			if ($o) {
				$this->setSupplier((int) $o->rowid);
				return $this->fk_soc;
			}
		}
		if (!getDolGlobalInt('ISNETEFATURA_INCOMING_AUTOCREATE_SUPPLIER', 1)) {
			$this->error = 'IsnetErrIncomingNoSupplier';
			return -1;
		}
		$soc = new Societe($this->db);
		$soc->name = $this->sender_name ?: $vkn;
		$soc->idprof1 = $vkn;
		$soc->idprof2 = $this->sender_tax_office;
		$soc->address = $this->sender_address;
		$soc->town = $this->sender_town;
		$soc->zip = $this->sender_zip;
		$soc->email = $this->sender_email;
		$soc->country_id = (int) getCountry(getDolGlobalString('ISNETEFATURA_DOMESTIC_COUNTRY', 'TR'), 3);
		$soc->fournisseur = 1;
		$soc->client = 0;
		$soc->code_fournisseur = '-1';
		$soc->code_client = '-1';
		if ($this->sender_city !== '') {
			$res = $this->db->query('SELECT d.rowid FROM '.MAIN_DB_PREFIX.'c_departements d JOIN '.MAIN_DB_PREFIX.'c_regions r ON r.code_region = d.fk_region WHERE r.fk_pays = '.((int) $soc->country_id)." AND d.nom = '".$this->db->escape($this->sender_city)."' LIMIT 1");
			$o = $res ? $this->db->fetch_object($res) : null;
			if ($o) {
				$soc->state_id = (int) $o->rowid;
			}
		}
		$id = $soc->create($user);
		if ($id <= 0) {
			$this->error = $soc->error;
			return -1;
		}
		$this->setSupplier($id);
		return $id;
	}

	private function setSupplier($id)
	{
		$this->fk_soc = (int) $id;
		$this->db->query('UPDATE '.MAIN_DB_PREFIX.self::TABLE.' SET fk_soc = '.((int) $id).' WHERE rowid = '.((int) $this->id));
	}

	/* ------------------------------------------------------------------ supplier invoice */

	/**
	 * Create a draft supplier invoice from this document.
	 *
	 * @return int supplier invoice id or <0
	 */
	public function createSupplierInvoice(User $user)
	{
		global $conf, $langs;
		require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.facture.class.php';
		$langs->load('isnetefatura@isnetefatura');

		if ($this->fk_facture_fourn > 0) {
			$this->error = 'IsnetErrIncomingAlreadyLinked';
			return -1;
		}
		if ($this->resolveSupplier($user) <= 0) {
			return -1;
		}
		$lines = $this->lines();
		if (empty($lines)) {
			$this->error = 'IsnetErrIncomingNoLines';
			return -1;
		}

		$foreign = $this->currency_code !== '' && $this->currency_code !== $conf->currency && $this->cross_rate > 0;

		$inv = new FactureFournisseur($this->db);
		$inv->socid = $this->fk_soc;
		$inv->type = FactureFournisseur::TYPE_STANDARD;
		$inv->ref_supplier = $this->invoice_number;
		$inv->date = $this->invoice_date ?: dol_now();
		$inv->date_echeance = $this->due_date ?: $inv->date;
		$inv->note_public = 'e-Fatura '.$this->invoice_number.' / ETTN '.$this->ettn;
		$inv->note_private = $this->order_number !== '' ? 'Sipariş: '.$this->order_number : '';
		if ($foreign) {
			$inv->multicurrency_code = $this->currency_code;
			$inv->multicurrency_tx = round(1 / $this->cross_rate, 8);
		}
		$this->db->begin();
		$id = $inv->create($user);
		if ($id <= 0) {
			$this->db->rollback();
			$this->error = $inv->error;
			return -1;
		}
		foreach ($lines as $l) {
			$pu = (float) $l['unit_price'];
			$qty = (float) $l['qty'];
			$remise = 0.0;
			if ($l['discount'] > 0 && $pu * $qty > 0) {
				$remise = round($l['discount'] / ($pu * $qty) * 100, 4);
			}
			$desc = trim($l['name'].($l['note'] !== '' ? "\n".$l['note'] : ''));
			$r = $inv->addline($desc, $foreign ? 0 : $pu, (float) $l['vat_rate'], 0, 0, $qty, 0, $remise, 0, 0, 0, 0, 'HT', 0, -1, 1, array(), null, 0, $foreign ? $pu : 0, (string) $l['code']);
			if ($r < 0) {
				$this->db->rollback();
				$this->error = $inv->error;
				return -1;
			}
		}
		$this->db->query('UPDATE '.MAIN_DB_PREFIX.self::TABLE.' SET fk_facture_fourn = '.((int) $id).' WHERE rowid = '.((int) $this->id));
		$this->fk_facture_fourn = (int) $id;
		$this->db->commit();

		$this->storePdf($inv);
		IsnetAudit::logObject($this->db, $inv, IsnetAudit::CODE_IMPORTED, 'e-Fatura '.$this->invoice_number.' '.$langs->transnoentities('IsnetIncomingImported'), 'ETTN '.$this->ettn.' / '.$this->sender_name.' ('.$this->sender_tax_code.')', $user);
		return (int) $id;
	}

	/**
	 * Attach the integrator's PDF of the incoming document to the supplier invoice.
	 */
	public function storePdf(FactureFournisseur $inv = null)
	{
		global $conf;
		if ($inv === null) {
			require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.facture.class.php';
			$inv = new FactureFournisseur($this->db);
			if ($this->fk_facture_fourn <= 0 || $inv->fetch($this->fk_facture_fourn) <= 0) {
				return 0;
			}
		}
		$pdf = $this->rendering('pdf');
		if ($pdf === '') {
			return 0;
		}
		$dir = $conf->fournisseur->facture->dir_output.'/'.get_exdir($inv->id, 2, 0, 0, $inv, 'invoice_supplier').dol_sanitizeFileName($inv->ref);
		dol_mkdir($dir);
		$path = $dir.'/'.dol_sanitizeFileName($this->invoice_number ?: $this->ettn).'-efatura.pdf';
		if (file_put_contents($path, $pdf) === false) {
			return -1;
		}
		dolChmod($path);
		return 1;
	}

	/**
	 * The integrator's rendering of the document (HTML view or PDF), so it can be read before import.
	 * Both are asked for in one call and kept under the module's document dir: documents never change
	 * after issue, and later views must not depend on the integrator being reachable.
	 *
	 * @param  string $format 'html' | 'pdf'
	 * @return string Content, '' when unavailable (see $this->error)
	 */
	public function rendering($format = 'html')
	{
		global $conf;
		$format = $format === 'pdf' ? 'pdf' : 'html';
		if ($this->ettn === '' || $this->doc_kind !== 'INVOICE') {
			return '';
		}
		$dir = (!empty($conf->isnetefatura->dir_output) ? $conf->isnetefatura->dir_output : DOL_DATA_ROOT.'/isnetefatura').'/incoming';
		$base = $dir.'/'.dol_sanitizeFileName($this->ettn);
		if (is_readable($base.'.'.$format) && filesize($base.'.'.$format) > 0) {
			return (string) file_get_contents($base.'.'.$format);
		}

		$client = new IsnetClient($this->db);
		$list = $client->searchInvoice(array('Ettn' => $this->ettn, 'ResultSet' => IsnetClient::resultSet(array('IsHtmlIncluded', 'IsPDFIncluded'))), 'Incoming');
		if ($client->error !== '') {
			$this->error = $client->error;
			return '';
		}
		$got = array('html' => (string) ($list[0]->InvoiceHtml ?? ''), 'pdf' => (string) ($list[0]->InvoicePdf ?? ''));
		if ($got['pdf'] !== '' && strpos($got['pdf'], '%PDF') !== 0 && base64_decode($got['pdf'], true) !== false) {
			$got['pdf'] = base64_decode($got['pdf']);
		}
		if (strpos($got['pdf'], '%PDF') !== 0) {
			$got['pdf'] = '';
		}
		if ($got['html'] !== '' && stripos($got['html'], '<') === false && base64_decode($got['html'], true) !== false) {
			$got['html'] = base64_decode($got['html']);
		}
		dol_mkdir($dir);
		foreach ($got as $ext => $content) {
			if ($content !== '' && file_put_contents($base.'.'.$ext, $content) !== false) {
				dolChmod($base.'.'.$ext);
			}
		}
		if ($got[$format] === '') {
			$this->error = 'IsnetRenderingNotAvailable';
		}
		return $got[$format];
	}

	/* ------------------------------------------------------------------ reply */

	/**
	 * Accept or reject an incoming document. Commercial invoices get a GİB application
	 * response (within 8 days); basic invoices only change the integrator-side state.
	 *
	 * @param  string $answer 'Kabul' | 'Red'
	 * @return int >0 ok, <0 error
	 */
	public function reply($answer, $description = '')
	{
		$client = new IsnetClient($this->db);
		$answer = strtoupper($answer) === 'RED' ? 'RED' : 'KABUL';
		if ($this->scenario === 'TICARIFATURA') {
			$req = array(
				'CompanyTaxCode' => $client->getCompanyTaxCode(),
				'InvoiceReplies' => array('InvoiceReply' => array(array(
					'InvoiceETTN' => $this->ettn,
					'InvoiceResponse' => $answer,
					'InvoiceResponseDescription' => $description !== '' ? $description : $answer,
				))),
			);
			$r = $client->call(IsnetClient::SERVICE_INVOICE, 'SendInvoiceReply', $req);
		} else {
			$req = array(
				'BaseInvoiceReplies' => array('BaseInvoiceReply' => array(array(
					'BaseInvoiceResponse' => $answer,
					'InvoiceETTN' => $this->ettn,
				))),
				'CompanyTaxCode' => $client->getCompanyTaxCode(),
			);
			$r = $client->call(IsnetClient::SERVICE_INVOICE, 'UpdateInvoiceState', $req);
		}
		if ($r === null || $client->error !== '') {
			$this->error = $client->error;
			return -1;
		}
		$this->response_status = $answer;
		$this->db->query('UPDATE '.MAIN_DB_PREFIX.self::TABLE." SET response_status = '".$this->db->escape($answer)."' WHERE rowid = ".((int) $this->id));
		if ($this->fk_facture_fourn > 0) {
			require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.facture.class.php';
			$ff = new FactureFournisseur($this->db);
			if ($ff->fetch($this->fk_facture_fourn) > 0) {
				IsnetAudit::logObject($this->db, $ff, IsnetAudit::CODE_REPLY, 'e-Fatura '.$this->invoice_number.': '.$answer, 'ETTN '.$this->ettn.($description !== '' ? "
".$description : ''));
			}
		} else {
			IsnetAudit::logSecurity($this->db, 'ISNET_REPLY', 'Incoming e-Fatura '.$this->invoice_number.' ('.$this->sender_tax_code.') answered: '.$answer.($description !== '' ? ' - '.$description : ''));
		}
		return 1;
	}
}
