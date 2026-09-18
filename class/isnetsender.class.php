<?php
/* Copyright (C) 2026 M. Burak Şentürk <https://buraksenturk.net>
 * Licensed under GPL-3.0-or-later with attribution terms. See COPYING and ATTRIBUTION.md.
 */

dol_include_once('/isnetefatura/class/isnetclient.class.php');
dol_include_once('/isnetefatura/class/isnetdocument.class.php');
dol_include_once('/isnetefatura/class/isnetinvoicemapper.class.php');
dol_include_once('/isnetefatura/class/isnetaudit.class.php');

/**
 * Orchestrates one submission: preflight → receiver resolution → payload → send → record.
 */
class IsnetSender
{
	public $db;
	public $error = '';
	/** @var string[] */
	public $errors = array();
	/** @var IsnetDocument|null The attempt record of the last send() call */
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
		$msg = $langs->trans($key);
		if ($detail !== '') {
			$msg .= ' — '.$detail;
		}
		$this->errors[] = $msg;
		$this->error = $msg;
		return -1;
	}

	/* ------------------------------------------------------------------ receiver resolution */

	/**
	 * Decide e-Fatura vs e-Arşiv for a third party according to ISNETEFATURA_TAXPAYER_LOOKUP.
	 *
	 * @return array|null array('type' => 'EFATURA'|'EARSIV', 'inbox_tag' => string, 'source' => 'lookup'|'cache'|'manual') or null on failure
	 */
	public function resolveReceiver(Societe $soc)
	{
		$mode = getDolGlobalString('ISNETEFATURA_TAXPAYER_LOOKUP', 'cache');
		$ttlDays = max(0, getDolGlobalInt('ISNETEFATURA_TAXPAYER_CACHE_DAYS', 7));

		$cachedStatus = (string) ($soc->array_options['options_isnet_status'] ?? '0');
		$cachedTag = (string) ($soc->array_options['options_isnet_inbox_tag'] ?? '');
		$checked = $soc->array_options['options_isnet_checked'] ?? '';
		$checkedTs = $checked ? (is_numeric($checked) ? (int) $checked : $this->db->jdate($checked)) : 0;
		$fresh = $checkedTs > 0 && (dol_now() - $checkedTs) < $ttlDays * 86400;

		if ($mode === 'manual') {
			if ($cachedStatus === '1') {
				if ($cachedTag === '') {
					$this->fail('IsnetErrManualNeedsInboxTag');
					return null;
				}
				return array('type' => IsnetDocument::TYPE_EFATURA, 'inbox_tag' => $cachedTag, 'source' => 'manual');
			}
			if ($cachedStatus === '2') {
				return array('type' => IsnetDocument::TYPE_EARSIV, 'inbox_tag' => '', 'source' => 'manual');
			}
			$this->fail('IsnetErrManualStatusUnknown');
			return null;
		}

		if ($mode === 'cache' && $fresh && $cachedStatus !== '0') {
			return array(
				'type' => $cachedStatus === '1' ? IsnetDocument::TYPE_EFATURA : IsnetDocument::TYPE_EARSIV,
				'inbox_tag' => $cachedStatus === '1' ? $cachedTag : '',
				'source' => 'cache',
			);
		}

		// always, or cache miss
		$r = $this->client->resolveReceiver($soc->idprof1);
		if ($this->client->error !== '') {
			$this->fail('IsnetErrLookupFailed', $this->client->error);
			return null;
		}
		$type = $r === null ? IsnetDocument::TYPE_EARSIV : IsnetDocument::TYPE_EFATURA;
		$tag = $r === null ? '' : $r['inbox_tag'];

		// A manually pinned inbox tag wins over the auto-picked one.
		if ($type === IsnetDocument::TYPE_EFATURA && $cachedTag !== '' && in_array($cachedTag, $r['inbox_tags'], true)) {
			$tag = $cachedTag;
		}

		$this->storeReceiverCache($soc, $type, $tag);
		return array('type' => $type, 'inbox_tag' => $tag, 'source' => 'lookup');
	}

	private function storeReceiverCache(Societe $soc, $type, $tag)
	{
		$soc->array_options['options_isnet_status'] = $type === IsnetDocument::TYPE_EFATURA ? '1' : '2';
		$soc->array_options['options_isnet_inbox_tag'] = $tag;
		$soc->array_options['options_isnet_checked'] = dol_now();
		$soc->insertExtraFields();
	}

	/* ------------------------------------------------------------------ send */

	/**
	 * Submit a validated customer invoice.
	 *
	 * @param  Facture $inv    Invoice with lines loaded
	 * @param  User    $user
	 * @param  bool    $force  Re-send even if an accepted attempt exists
	 * @return int             >0 ok, <0 error (see ->errors)
	 */
	public function send(Facture $inv, User $user, $force = false)
	{
		global $langs;
		$this->error = '';
		$this->errors = array();
		$this->document = null;
		$langs->load('isnetefatura@isnetefatura');

		if ($this->client->getCompanyTaxCode() === '') {
			return $this->fail('IsnetErrCompanyVknMissing');
		}
		if (empty($inv->lines)) {
			$inv->fetch_lines();
		}
		if (empty($inv->thirdparty)) {
			$inv->fetch_thirdparty();
		}
		$soc = $inv->thirdparty;
		if (empty($soc->array_options)) {
			$soc->fetch_optionals();
		}
		if (empty($inv->array_options)) {
			$inv->fetch_optionals();
		}

		$accepted = IsnetDocument::fetchAccepted($this->db, $inv->id);
		if ($accepted && !$force) {
			return $this->fail('IsnetErrAlreadySent', $accepted->invoice_number.' / '.$accepted->ettn);
		}

		if (!$this->mapper->preflight($inv, $soc)) {
			foreach ($this->mapper->problems as $p) {
				$parts = explode(':', $p, 2);
				$this->errors[] = $langs->trans($parts[0]).(isset($parts[1]) ? ' ('.$parts[1].')' : '');
			}
			$this->error = implode('; ', $this->errors);
			return -1;
		}

		$kind = $this->mapper->exportKind($inv, $soc);
		if ($kind === 'goods') {
			$receiver = array('type' => IsnetDocument::TYPE_EFATURA, 'inbox_tag' => getDolGlobalString('ISNETEFATURA_EXPORT_RECEIVER_INBOX', 'urn:mail:ihracatpk@gtb.gov.tr'), 'source' => 'export');
		} elseif ($kind === 'service') {
			$receiver = array('type' => IsnetDocument::TYPE_EARSIV, 'inbox_tag' => '', 'source' => 'export');
		} else {
			$receiver = $this->resolveReceiver($soc);
		}
		if ($receiver === null) {
			return -1;
		}
		// A seller cannot issue a return e-Fatura against its own sale: the taxpayer buyer issues it.
		// Returns from non-taxpayers are issued by the seller as e-Arşiv IADE.
		if ((int) $inv->type === Facture::TYPE_CREDIT_NOTE && $receiver['type'] === IsnetDocument::TYPE_EFATURA) {
			return $this->fail('IsnetErrCreditNoteEfatura');
		}

		$ref = IsnetInvoiceMapper::finalRef($inv);

		// The integrator "updates" a failed document that is resent under the same external code
		// but does not rebuild every block; a forced resend therefore gets a fresh code (REF-R2, -R3…).
		if ($force && $accepted) {
			$ref .= '-R'.(count((new IsnetDocument($this->db))->fetchAllForInvoice($inv->id)) + 1);
		}

		// Guard against duplicates when a previous attempt timed out after İşNet accepted it.
		// A forced resend, or a remote copy that already failed, must produce a fresh document.
		$existing = $force ? null : $this->findAtIntegrator($ref, $receiver['type']);
		if ($existing !== null && in_array($existing['status'], IsnetDocument::FINAL_FAIL, true)) {
			$existing = null;
		}
		if ($existing !== null) {
			$doc = $this->newAttempt($inv, $soc, $receiver, $ref, $user);
			$doc->ettn = $existing['ettn'];
			$doc->invoice_number = $existing['number'];
			$doc->status = (string) ($existing['status'] ?? IsnetDocument::STATE_SENT);
			$doc->date_sent = dol_now();
			$doc->last_error = '';
			$doc->update();
			$this->document = $doc;
			$this->syncInvoiceFields($inv, $doc);
			IsnetAudit::logObject($this->db, $inv, IsnetAudit::CODE_RECONCILED, $langs->transnoentities('IsnetAuditReconciled', $doc->doc_type, $doc->invoice_number), 'ETTN '.$doc->ettn, $user);
			return 2;
		}

		$doc = $this->newAttempt($inv, $soc, $receiver, $ref, $user);
		if ($doc->id <= 0) {
			return $this->fail('IsnetErrDb', $doc->error);
		}
		$this->document = $doc;

		if ($receiver['type'] === IsnetDocument::TYPE_EFATURA) {
			$payload = $this->mapper->toEfatura($inv, $soc, $receiver['inbox_tag'], $ref);
			$returns = $this->client->sendInvoice(array($payload));
			$numberKey = 'InvoiceNumber';
		} else {
			$payload = $this->mapper->toEarsiv($inv, $soc, $ref);
			$returns = $this->client->sendArchiveInvoice(array($payload));
			$numberKey = 'ArchiveInvoiceNumber';
		}

		$doc->request_xml = $this->client->lastRequest;
		$doc->response_xml = $this->client->lastResponse;

		if ($this->client->error !== '') {
			$doc->status = IsnetDocument::STATE_ERROR;
			$doc->last_error = $this->client->error;
			$doc->update();
			$this->syncInvoiceFields($inv, $doc);
			IsnetAudit::logObject($this->db, $inv, IsnetAudit::CODE_FAILED, $langs->transnoentities('IsnetAuditFailed', $receiver['type']), $this->client->error, $user);
			return $this->fail('IsnetErrSendFailed', $this->client->error);
		}
		if (empty($returns)) {
			$doc->status = IsnetDocument::STATE_ERROR;
			$doc->last_error = 'Empty response';
			$doc->update();
			$this->syncInvoiceFields($inv, $doc);
			return $this->fail('IsnetErrSendFailed', 'empty response');
		}

		$ret = $returns[0];
		$doc->ettn = (string) ($ret->Ettn ?? '');
		$doc->invoice_number = (string) ($ret->$numberKey ?? '');
		$doc->status = IsnetDocument::STATE_SENT;
		$doc->date_sent = dol_now();
		$doc->last_error = '';
		if ($doc->update() < 0) {
			return $this->fail('IsnetErrDb', $doc->error);
		}
		$this->syncInvoiceFields($inv, $doc);
		IsnetAudit::logObject($this->db, $inv, IsnetAudit::CODE_SENT, $langs->transnoentities('IsnetAuditSent', $receiver['type'], $doc->invoice_number), 'ETTN '.$doc->ettn.($receiver['inbox_tag'] !== '' ? ' / '.$receiver['inbox_tag'] : '').' / '.$this->client->getEnv(), $user);
		dol_syslog(__METHOD__.' invoice '.$ref.' sent as '.$receiver['type'].' number='.$doc->invoice_number.' ettn='.$doc->ettn, LOG_INFO);
		return 1;
	}

	private function newAttempt(Facture $inv, Societe $soc, array $receiver, $ref, User $user)
	{
		$doc = new IsnetDocument($this->db);
		$doc->fk_facture = $inv->id;
		$doc->doc_type = $receiver['type'];
		$doc->scenario = $receiver['type'] === IsnetDocument::TYPE_EFATURA ? $this->mapper->scenario($inv, $soc) : '';
		$doc->invoice_type = $this->mapper->invoiceType($inv, $soc);
		$doc->external_code = $ref;
		$doc->receiver_tax_code = IsnetInvoiceMapper::digitsOnly($soc->idprof1);
		$doc->receiver_inbox_tag = $receiver['inbox_tag'];
		$doc->status = IsnetDocument::STATE_SENDING;
		$doc->create($user);
		return $doc;
	}

	/**
	 * Ask the integrator whether it already holds a document with this external code.
	 *
	 * @return array|null array('ettn','number','status') or null
	 */
	private function findAtIntegrator($ref, $type)
	{
		if ($type === IsnetDocument::TYPE_EFATURA) {
			$list = $this->client->searchInvoice(array('ExternalInvoiceCode' => $ref));
			$numberKey = 'InvoiceNumber';
		} else {
			$list = $this->client->searchArchiveInvoice(array('ExternalArchiveInvoiceCode' => $ref));
			$numberKey = 'InvoiceNumber';
		}
		if ($this->client->error !== '' || empty($list)) {
			return null;
		}
		$inv = $list[0];
		if (empty($inv->ETTN)) {
			return null;
		}
		return array(
			'ettn' => (string) $inv->ETTN,
			'number' => (string) ($inv->$numberKey ?? ''),
			'status' => (string) ($inv->Status ?? ''),
		);
	}

	/* ------------------------------------------------------------------ status */

	/**
	 * Fetch the integrator record behind a document (by ETTN).
	 *
	 * @return object|null ArchiveInvoice or Invoice
	 */
	private function fetchRemote(IsnetDocument $doc, array $include = array())
	{
		if ($doc->doc_type === IsnetDocument::TYPE_EARSIV) {
			$list = $this->client->searchArchiveInvoice(array('Ettn' => $doc->ettn, 'ResultSet' => IsnetClient::resultSet($include)));
		} else {
			$list = $this->client->searchInvoice(array('Ettn' => $doc->ettn, 'ResultSet' => IsnetClient::resultSet($include)));
		}
		if ($this->client->error !== '') {
			$this->error = $this->client->error;
			return null;
		}
		return $list[0] ?? null;
	}

	/**
	 * Refresh integrator status for an accepted document; stores the PDF once the document is final.
	 *
	 * @return int 1 updated, 0 nothing to do, -1 error
	 */
	public function refreshStatus(IsnetDocument $doc)
	{
		$this->error = '';
		if (!$doc->isAccepted()) {
			return 0;
		}
		$before = $doc->status.'/'.$doc->detail_status;
		$remote = $this->fetchRemote($doc);
		if ($remote === null) {
			return $this->error !== '' ? -1 : 0;
		}

		if ($doc->doc_type === IsnetDocument::TYPE_EARSIV) {
			// e-Arşiv: creation status + GİB report status; mail delivery is informational.
			$doc->status = (string) ($remote->Status ?? $doc->status);
			$report = (string) ($remote->ReportSendingStatus ?? '');
			$mail = (string) ($remote->SendingStatus ?? '');
			$doc->detail_status = $report !== '' ? $report : $mail;
			if (!empty($remote->IsCanceled)) {
				$doc->status = 'Silindi';
			}
			$doc->last_error = (preg_match('/Hata/i', $report) || preg_match('/Hata/i', $mail)) ? trim($report.' '.$mail) : '';
		} else {
			$doc->status = (string) ($remote->Status ?? $doc->status);
			$doc->detail_status = (string) ($remote->DetailStatus ?? '');
			// SystemResponseDescription is also filled for transient states; keep it only for real failures.
			$doc->last_error = preg_match('/Hata|Reddedildi|Iade_Edildi|Gonderilemedi|Basarisiz/i', $doc->status.' '.$doc->detail_status)
				? trim((string) ($remote->SystemResponseDescription ?? ''))
				: '';
		}
		if (!empty($remote->InvoiceNumber)) {
			$doc->invoice_number = (string) $remote->InvoiceNumber;
		}
		$doc->date_checked = dol_now();
		if ($doc->update() < 0) {
			$this->error = $doc->error;
			return -1;
		}

		$inv = new Facture($this->db);
		if ($inv->fetch($doc->fk_facture) > 0) {
			$this->syncInvoiceFields($inv, $doc);
			if ($before !== $doc->status.'/'.$doc->detail_status && in_array($doc->outcome(), array('ok', 'fail', 'cancelled', 'issued'), true)) {
				global $langs;
				$langs->load('isnetefatura@isnetefatura');
				IsnetAudit::logObject($this->db, $inv, IsnetAudit::CODE_STATUS, $langs->transnoentities('IsnetAuditStatus', $doc->doc_type, $doc->invoice_number, $langs->transnoentities('IsnetOutcome'.ucfirst($doc->outcome()))), $doc->status.($doc->detail_status !== '' ? ' / '.$doc->detail_status : '').($doc->last_error !== '' ? "
".$doc->last_error : ''));
			}
			if ($doc->pdfReady() && getDolGlobalInt('ISNETEFATURA_STORE_PDF', 1) && !file_exists(IsnetDocument::pdfPath($inv, $doc->doc_type))) {
				$this->storePdf($doc, $inv);
			}
		}
		return 1;
	}

	/**
	 * Download the integrator's signed PDF into the invoice's document directory.
	 *
	 * @return int 1 stored, 0 not available, -1 error
	 */
	public function storePdf(IsnetDocument $doc, Facture $inv = null)
	{
		$this->error = '';
		if (!$doc->isAccepted()) {
			return 0;
		}
		if ($inv === null) {
			$inv = new Facture($this->db);
			if ($inv->fetch($doc->fk_facture) <= 0) {
				return -1;
			}
		}
		$remote = $this->fetchRemote($doc, array('IsPDFIncluded'));
		if ($remote === null) {
			return $this->error !== '' ? -1 : 0;
		}
		$pdf = (string) ($remote->InvoicePdf ?? '');
		if ($pdf === '') {
			return 0;
		}
		if (strpos($pdf, '%PDF') !== 0 && base64_decode($pdf, true) !== false) {
			$pdf = base64_decode($pdf);
		}
		if (strpos($pdf, '%PDF') !== 0) {
			$this->error = 'Unexpected PDF payload';
			return -1;
		}
		$path = IsnetDocument::pdfPath($inv, $doc->doc_type);
		dol_mkdir(dirname($path));
		if (file_put_contents($path, $pdf) === false) {
			$this->error = 'Cannot write '.$path;
			return -1;
		}
		dolChmod($path);
		IsnetAudit::logObject($this->db, $inv, IsnetAudit::CODE_PDF, 'e-belge PDF: '.basename($path), $doc->invoice_number.' / ETTN '.$doc->ettn);
		dol_syslog(__METHOD__.' stored '.$path.' ('.strlen($pdf).' bytes)', LOG_INFO);
		return 1;
	}

	/**
	 * Mirror the current e-document state onto the invoice's list-visible extra fields.
	 */
	public function syncInvoiceFields(Facture $inv, IsnetDocument $doc)
	{
		if (empty($inv->array_options)) {
			$inv->fetch_optionals();
		}
		global $langs;
		$langs->load('isnetefatura@isnetefatura');
		$raw = $doc->isAccepted() ? ($doc->detail_status !== '' ? $doc->detail_status : $doc->status) : ($doc->last_error !== '' ? IsnetDocument::STATE_ERROR : $doc->status);
		$inv->array_options['options_isnet_number'] = $doc->invoice_number;
		$inv->array_options['options_isnet_state'] = $langs->transnoentitiesnoconv('IsnetOutcome'.ucfirst($doc->outcome())).' ('.$doc->doc_type.': '.$raw.')';
		$inv->insertExtraFields();
	}

	/* ------------------------------------------------------------------ cron */

	public $output = '';

	/**
	 * Scheduled job: refresh every accepted, non-final document.
	 *
	 * @return int 0 ok, <0 error (Dolibarr cron contract)
	 */
	public function cronRefreshStatuses($minAgeMinutes = 30, $limit = 50)
	{
		$this->output = '';
		$docs = IsnetDocument::fetchPendingForRefresh($this->db, (int) $minAgeMinutes, (int) $limit);
		$ok = 0;
		$fail = 0;
		$despatch = null;
		foreach ($docs as $doc) {
			if ($doc->element_type === IsnetDocument::ELEMENT_SHIPMENT) {
				if ($despatch === null) {
					dol_include_once('/isnetefatura/class/isnetdespatch.class.php');
					$despatch = new IsnetDespatch($this->db);
				}
				$r = $despatch->refreshStatus($doc);
				if ($r >= 0) {
					$ok++;
				} else {
					$fail++;
				}
				continue;
			}
			if ($this->refreshStatus($doc) >= 0) {
				$ok++;
			} else {
				$fail++;
				dol_syslog(__METHOD__.' doc '.$doc->id.' '.$this->error, LOG_WARNING);
			}
		}
		$this->output = count($docs).' checked, '.$ok.' refreshed, '.$fail.' failed';
		return $fail > 0 ? -1 : 0;
	}
}
