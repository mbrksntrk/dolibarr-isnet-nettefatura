<?php
/* Copyright (C) 2026 M. Burak Şentürk <https://buraksenturk.net>
 * Licensed under GPL-3.0-or-later with attribution terms. See COPYING and ATTRIBUTION.md.
 */

require_once DOL_DOCUMENT_ROOT.'/expedition/class/expedition.class.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
dol_include_once('/isnetefatura/class/isnetclient.class.php');
dol_include_once('/isnetefatura/class/isnetdocument.class.php');
dol_include_once('/isnetefatura/class/isnetinvoicemapper.class.php');
dol_include_once('/isnetefatura/class/isnetaudit.class.php');

/**
 * e-İrsaliye: Dolibarr shipment (Expedition) → İşNet DespatchAdvice, submission and status.
 */
class IsnetDespatch
{
	public $db;
	public $error = '';
	public $errors = array();
	public $problems = array();
	/** @var IsnetDocument|null */
	public $document = null;

	private $client;
	private $mapper;

	public function __construct($db)
	{
		$this->db = $db;
		$this->client = new IsnetClient($db);
		$this->mapper = new IsnetInvoiceMapper($db);
	}

	public function getClient()
	{
		return $this->client;
	}

	private function fail($key, $detail = '')
	{
		global $langs;
		$langs->load('isnetefatura@isnetefatura');
		$msg = $langs->trans($key).($detail !== '' ? ' — '.$detail : '');
		$this->errors[] = $msg;
		$this->error = $msg;
		return -1;
	}

	private function extra(Expedition $exp, $key, $default = '')
	{
		$v = $exp->array_options['options_'.$key] ?? '';
		return ($v === '' || $v === null) ? $default : $v;
	}

	public static function finalRef(Expedition $exp)
	{
		return !empty($exp->newref) ? $exp->newref : $exp->ref;
	}

	/* ------------------------------------------------------------------ transport data */

	/**
	 * Vehicle / driver / carrier for this shipment: extra fields first, module defaults second.
	 */
	public function transport(Expedition $exp)
	{
		return array(
			'plate' => strtoupper(preg_replace('/\s+/', '', $this->extra($exp, 'isnet_plate', getDolGlobalString('ISNETEFATURA_DESPATCH_PLATE')))),
			'trailer' => strtoupper(preg_replace('/\s+/', '', $this->extra($exp, 'isnet_trailer_plate', getDolGlobalString('ISNETEFATURA_DESPATCH_TRAILER')))),
			'driver_first' => trim($this->extra($exp, 'isnet_driver_first', getDolGlobalString('ISNETEFATURA_DESPATCH_DRIVER_FIRST'))),
			'driver_last' => trim($this->extra($exp, 'isnet_driver_last', getDolGlobalString('ISNETEFATURA_DESPATCH_DRIVER_LAST'))),
			'driver_tckn' => IsnetInvoiceMapper::digitsOnly($this->extra($exp, 'isnet_driver_tckn', getDolGlobalString('ISNETEFATURA_DESPATCH_DRIVER_TCKN'))),
			'carrier_name' => trim($this->extra($exp, 'isnet_carrier_name', getDolGlobalString('ISNETEFATURA_DESPATCH_CARRIER_NAME'))),
			'carrier_vkn' => IsnetInvoiceMapper::digitsOnly($this->extra($exp, 'isnet_carrier_vkn', getDolGlobalString('ISNETEFATURA_DESPATCH_CARRIER_VKN'))),
		);
	}

	private function billableLines(Expedition $exp)
	{
		$out = array();
		foreach ($exp->lines as $line) {
			if ((float) $line->qty_shipped <= 0 && (float) $line->qty <= 0) {
				continue;
			}
			$out[] = $line;
		}
		return $out;
	}

	/* ------------------------------------------------------------------ preflight */

	public function preflight(Expedition $exp, Societe $soc)
	{
		$this->problems = array();
		if (!$this->mapper->isDomestic($soc)) {
			$this->problems[] = 'IsnetErrDespatchForeign';
		}
		if (!IsnetInvoiceMapper::isValidTaxCode($soc->idprof1)) {
			$this->problems[] = 'IsnetErrReceiverTaxCode';
		}
		if (trim((string) $soc->address) === '' || trim((string) $soc->town) === '') {
			$this->problems[] = 'IsnetErrReceiverAddress';
		}
		$codes = $this->mapper->resolveCodes($soc);
		if ($codes['city_code'] === '') {
			$this->problems[] = 'IsnetErrReceiverCityUnresolved:'.trim((string) $soc->town);
		}
		$t = $this->transport($exp);
		if ($t['plate'] === '') {
			$this->problems[] = 'IsnetErrDespatchPlate';
		}
		if ($t['driver_first'] === '' || $t['driver_last'] === '' || strlen($t['driver_tckn']) !== 11) {
			$this->problems[] = 'IsnetErrDespatchDriver';
		}
		if ($t['carrier_vkn'] !== '' && !IsnetInvoiceMapper::isValidTaxCode($t['carrier_vkn'])) {
			$this->problems[] = 'IsnetErrDespatchCarrier';
		}
		if (empty($this->billableLines($exp))) {
			$this->problems[] = 'IsnetErrNoLines';
		}
		return empty($this->problems);
	}

	/* ------------------------------------------------------------------ payload */

	private function companyParty()
	{
		global $conf, $mysoc;
		$addr = array(
			'BoulevardAveneuStreetName' => trim((string) $mysoc->address),
			'TownName' => trim((string) $mysoc->town),
			'CityName' => !empty($mysoc->state_id) ? (string) getState($mysoc->state_id, 0) : trim((string) $mysoc->town),
		);
		$soc = new Societe($this->db);
		$soc->state_id = $mysoc->state_id;
		$soc->state_code = $mysoc->state_code ?? '';
		$soc->town = $mysoc->town;
		$soc->idprof2 = $mysoc->idprof2;
		$codes = $this->mapper->resolveCodes($soc);
		if ($codes['city_code'] !== '') {
			$addr['CityCode'] = (float) $codes['city_code'];
		}
		if ($codes['town']) {
			$addr['TownCode'] = (float) $codes['town']['code'];
		}
		if ($codes['tax_office']) {
			$addr['TaxOfficeCode'] = (float) $codes['tax_office']['code'];
			$addr['TaxOfficeName'] = $codes['tax_office']['name'];
		}
		$zip = IsnetInvoiceMapper::digitsOnly($mysoc->zip);
		if ($zip !== '') {
			$addr['PostalCode'] = (float) $zip;
		}
		return array(
			'Address' => $addr,
			'ReceiverName' => trim((string) $mysoc->name),
			'ReceiverTaxCode' => $this->client->getCompanyTaxCode(),
		);
	}

	private function dateTime($ts)
	{
		return dol_print_date($ts, '%Y-%m-%dT%H:%M:%S', 'tzserver');
	}

	public function toDespatchAdvice(Expedition $exp, Societe $soc, $inboxTag, $externalCode = '')
	{
		global $conf;
		$t = $this->transport($exp);
		$currency = $conf->currency;
		$when = !empty($exp->date_delivery) ? $exp->date_delivery : (!empty($exp->date_shipping) ? $exp->date_shipping : dol_now());
		if ($when < dol_now() - 86400 * 30 || $when > dol_now() + 86400 * 7) {
			$when = dol_now();
		}

		$details = array();
		$total = 0.0;
		$n = 0;
		foreach ($this->billableLines($exp) as $line) {
			$n++;
			$qty = (float) ($line->qty_shipped > 0 ? $line->qty_shipped : $line->qty);
			$unit = (float) ($line->subprice ?? 0);
			if ($unit <= 0 && $qty > 0 && !empty($line->total_ht)) {
				$unit = round((float) $line->total_ht / $qty, 4);
			}
			$total += $unit * $qty;
			$name = trim((string) ($line->product_label ?: $line->libelle ?: $line->desc ?: $line->product_ref));
			$details[] = array(
				'CurrencyCode' => $currency,
				'Product' => array(
					'ExternalProductCode' => (string) ($line->product_ref ?: ('L'.$n)),
					'MeasureUnit' => $this->mapper->unitCode($line->fk_unit ?? 0),
					'ProductCode' => (string) ($line->product_ref ?: ''),
					'ProductName' => dol_trunc($name, 250, 'right', 'UTF-8', 1),
					'UnitPrice' => $unit,
				),
				'RelatedOrderLineNumber' => (string) $n,
				'SentQuantity' => $qty,
			);
		}

		$stage = array(
			'DriverPersonList' => array('Person' => array(array(
				'FamilyName' => $t['driver_last'],
				'FirstName' => $t['driver_first'],
				'NationalityId' => $t['driver_tckn'],
				'Title' => 'Şoför',
			))),
			'ShipmentTransportMeans' => array('LicensePlateId' => $t['plate']),
		);
		$shipment = array(
			'Delivery' => array('ActualDespatchDate' => $this->dateTime($when)),
			'ShipmentStageList' => array('DespatchAdviceShipmentStage' => array($stage)),
			'TotalValueAmount' => round($total, 2),
		);
		if ($t['carrier_vkn'] !== '') {
			$shipment['Delivery']['CarrierParty'] = array(
				'ReceiverName' => $t['carrier_name'] ?: $t['carrier_vkn'],
				'ReceiverTaxCode' => $t['carrier_vkn'],
			);
		}
		if ($t['trailer'] !== '') {
			$shipment['TransportEquipmentPlateList'] = array('string' => array($t['trailer']));
		}

		$p = array(
			'CurrencyCode' => $currency,
			'DeliveryCustomerParty' => $this->mapper->receiver($soc),
			'DespatchAdviceDate' => $this->dateTime($when),
			'DespatchAdviceDetails' => array('DespatchAdviceDetail' => $details),
			'DespatchAdviceScenarioType' => 'TEMELIRSALIYE',
			'DespatchAdviceType' => 'SEVK',
			'DespatchSupplierParty' => $this->companyParty(),
			'ExternalDespatchAdviceCode' => $externalCode !== '' ? $externalCode : self::finalRef($exp),
			'ReceiverInboxTag' => $inboxTag,
			'Shipment' => $shipment,
		);
		if (!empty($exp->ref_customer)) {
			$p['OrderNumber'] = dol_trunc($exp->ref_customer, 50, 'right', 'UTF-8', 1);
			$p['OrderDate'] = $this->dateTime($exp->date_creation ?: $when);
		}
		$notes = array();
		$prefix = trim(getDolGlobalString('ISNETEFATURA_NOTE_PREFIX'));
		if ($prefix !== '') {
			$notes[] = $prefix;
		}
		if (!empty($exp->tracking_number)) {
			$notes[] = 'Takip no: '.$exp->tracking_number;
		}
		$pub = trim(dol_string_nohtmltag((string) $exp->note_public));
		if ($pub !== '') {
			$notes[] = dol_trunc($pub, 500, 'right', 'UTF-8', 1);
		}
		if (!empty($notes)) {
			$p['Notes'] = array('string' => $notes);
		}
		return $p;
	}

	/* ------------------------------------------------------------------ send */

	/**
	 * @return int >0 ok, <0 error (see ->errors)
	 */
	public function send(Expedition $exp, User $user, $force = false)
	{
		global $langs;
		$this->error = '';
		$this->errors = array();
		$this->document = null;
		$langs->load('isnetefatura@isnetefatura');

		if (!getDolGlobalInt('ISNETEFATURA_DESPATCH_ENABLED', 1)) {
			return $this->fail('IsnetErrDespatchDisabled');
		}
		if (empty($exp->lines)) {
			$exp->fetch_lines();
		}
		if (empty($exp->thirdparty)) {
			$exp->fetch_thirdparty();
		}
		$soc = $exp->thirdparty;
		if (empty($exp->array_options)) {
			$exp->fetch_optionals();
		}

		$accepted = IsnetDocument::fetchAccepted($this->db, $exp->id, IsnetDocument::ELEMENT_SHIPMENT);
		if ($accepted && !$force) {
			return $this->fail('IsnetErrAlreadySent', $accepted->invoice_number.' / '.$accepted->ettn);
		}
		if (!$this->preflight($exp, $soc)) {
			foreach ($this->problems as $p) {
				$parts = explode(':', $p, 2);
				$this->errors[] = $langs->trans($parts[0]).(isset($parts[1]) ? ' ('.$parts[1].')' : '');
			}
			$this->error = implode('; ', $this->errors);
			return -1;
		}

		$receiver = $this->client->resolveDespatchReceiver($soc->idprof1);
		if ($this->client->error !== '') {
			return $this->fail('IsnetErrLookupFailed', $this->client->error);
		}
		if ($receiver === null) {
			return $this->fail('IsnetErrDespatchReceiverNotRegistered');
		}
		$pinned = (string) ($soc->array_options['options_isnet_despatch_inbox'] ?? '');
		$inbox = ($pinned !== '' && in_array($pinned, $receiver['inbox_tags'], true)) ? $pinned : $receiver['inbox_tag'];

		$ref = self::finalRef($exp);
		if ($force && $accepted) {
			$ref .= '-R'.(count((new IsnetDocument($this->db))->fetchAllForInvoice($exp->id, IsnetDocument::ELEMENT_SHIPMENT)) + 1);
		}

		$doc = new IsnetDocument($this->db);
		$doc->fk_facture = $exp->id;
		$doc->element_type = IsnetDocument::ELEMENT_SHIPMENT;
		$doc->doc_type = IsnetDocument::TYPE_IRSALIYE;
		$doc->scenario = 'TEMELIRSALIYE';
		$doc->invoice_type = 'SEVK';
		$doc->external_code = $ref;
		$doc->receiver_tax_code = IsnetInvoiceMapper::digitsOnly($soc->idprof1);
		$doc->receiver_inbox_tag = $inbox;
		$doc->status = IsnetDocument::STATE_SENDING;
		if ($doc->create($user) <= 0) {
			return $this->fail('IsnetErrDb', $doc->error);
		}
		$this->document = $doc;

		$payload = $this->toDespatchAdvice($exp, $soc, $inbox, $ref);
		$returns = $this->client->sendDespatchAdvice(array($payload));
		$doc->request_xml = $this->client->lastRequest;
		$doc->response_xml = $this->client->lastResponse;

		if ($this->client->error !== '' || empty($returns)) {
			$doc->status = IsnetDocument::STATE_ERROR;
			$doc->last_error = $this->client->error ?: 'Empty response';
			$doc->update();
			IsnetAudit::logObject($this->db, $exp, IsnetAudit::CODE_FAILED, $langs->transnoentities('IsnetAuditFailed', 'EIRSALIYE'), $doc->last_error, $user);
			return $this->fail('IsnetErrSendFailed', $doc->last_error);
		}
		$ret = $returns[0];
		$doc->ettn = (string) ($ret->Ettn ?? '');
		$doc->invoice_number = (string) ($ret->DespatchAdviceNumber ?? '');
		$doc->status = IsnetDocument::STATE_SENT;
		$doc->date_sent = dol_now();
		$doc->last_error = '';
		$doc->update();
		IsnetAudit::logObject($this->db, $exp, IsnetAudit::CODE_SENT, $langs->transnoentities('IsnetAuditSent', 'EIRSALIYE', $doc->invoice_number), 'ETTN '.$doc->ettn.' / '.$inbox.' / '.$this->client->getEnv(), $user);
		return 1;
	}

	/* ------------------------------------------------------------------ status / pdf */

	public function refreshStatus(IsnetDocument $doc)
	{
		$this->error = '';
		if (!$doc->isAccepted()) {
			return 0;
		}
		$before = $doc->status.'/'.$doc->detail_status;
		$list = $this->client->searchDespatchAdvice(array('Ettn' => $doc->ettn));
		if ($this->client->error !== '') {
			$this->error = $this->client->error;
			return -1;
		}
		if (empty($list)) {
			return 0;
		}
		$r = $list[0];
		$doc->status = (string) ($r->Status ?? $doc->status);
		$doc->detail_status = (string) ($r->DetailStatus ?? '');
		$doc->last_error = preg_match('/Hata|Reddedildi|Gonderilemedi|Basarisiz/i', $doc->status.' '.$doc->detail_status) ? trim((string) ($r->SystemResponseDescription ?? '')) : '';
		if (!empty($r->DespatchAdviceNumber)) {
			$doc->invoice_number = (string) $r->DespatchAdviceNumber;
		}
		$doc->date_checked = dol_now();
		if ($doc->update() < 0) {
			return -1;
		}
		$exp = new Expedition($this->db);
		if ($exp->fetch($doc->fk_facture) > 0) {
			if ($before !== $doc->status.'/'.$doc->detail_status && in_array($doc->outcome(), array('ok', 'fail', 'issued'), true)) {
				global $langs;
				$langs->load('isnetefatura@isnetefatura');
				IsnetAudit::logObject($this->db, $exp, IsnetAudit::CODE_STATUS, $langs->transnoentities('IsnetAuditStatus', 'EIRSALIYE', $doc->invoice_number, $langs->transnoentities('IsnetOutcome'.ucfirst($doc->outcome()))), $doc->status.' / '.$doc->detail_status);
			}
			if ($doc->pdfReady() && getDolGlobalInt('ISNETEFATURA_STORE_PDF', 1) && !file_exists(IsnetDocument::pdfPath($exp, $doc->doc_type))) {
				$this->storePdf($doc, $exp);
			}
		}
		return 1;
	}

	public function storePdf(IsnetDocument $doc, Expedition $exp = null)
	{
		if ($exp === null) {
			$exp = new Expedition($this->db);
			if ($exp->fetch($doc->fk_facture) <= 0) {
				return -1;
			}
		}
		$list = $this->client->searchDespatchAdvice(array('Ettn' => $doc->ettn, 'ResultSet' => IsnetClient::despatchResultSet(array('IsPDFIncluded'))));
		$pdf = (string) ($list[0]->DespatchAdvicePdf ?? '');
		if ($pdf !== '' && strpos($pdf, '%PDF') !== 0 && base64_decode($pdf, true) !== false) {
			$pdf = base64_decode($pdf);
		}
		if (strpos($pdf, '%PDF') !== 0) {
			return 0;
		}
		$path = IsnetDocument::pdfPath($exp, $doc->doc_type);
		dol_mkdir(dirname($path));
		if (file_put_contents($path, $pdf) === false) {
			return -1;
		}
		dolChmod($path);
		IsnetAudit::logObject($this->db, $exp, IsnetAudit::CODE_PDF, 'e-İrsaliye PDF: '.basename($path), $doc->invoice_number.' / ETTN '.$doc->ettn);
		return 1;
	}
}
