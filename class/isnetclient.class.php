<?php
/* Copyright (C) 2026 M. Burak Şentürk <https://buraksenturk.net>
 * Licensed under GPL-3.0-or-later with attribution terms. See COPYING and ATTRIBUTION.md.
 */

/**
 * Thin SOAP wrapper around İşNet e-Fatura web services (WCF, SOAP 1.1, IP+VKN auth).
 *
 * WCF DataContract serialization requires child elements in the order declared by the
 * WSDL (alphabetical). PHP's SoapClient in WSDL mode re-orders associative arrays to
 * match the schema, so callers may pass arrays in any order.
 */
class IsnetClient
{
	const SERVICE_INVOICE = 'InvoiceService';
	const SERVICE_ADDRESSBOOK = 'AddressBookService';

	public $db;
	public $error = '';
	public $errors = array();

	/** @var string Last raw SOAP request (only kept when debug is on) */
	public $lastRequest = '';
	/** @var string Last raw SOAP response (only kept when debug is on) */
	public $lastResponse = '';

	private $clients = array();
	private $env;
	private $timeout;
	private $debug;

	public function __construct($db)
	{
		$this->db = $db;
		$this->env = getDolGlobalString('ISNETEFATURA_ENV', 'test') === 'prod' ? 'prod' : 'test';
		$this->timeout = max(10, getDolGlobalInt('ISNETEFATURA_TIMEOUT', 60));
		$this->debug = getDolGlobalInt('ISNETEFATURA_DEBUG', 0) > 0;
	}

	public function getEnv()
	{
		return $this->env;
	}

	/**
	 * VKN the module acts on behalf of. Falls back to the Dolibarr company Prof ID 1.
	 */
	public function getCompanyTaxCode()
	{
		if ($this->env === 'test') {
			$testVkn = trim(getDolGlobalString('ISNETEFATURA_TEST_COMPANY_VKN'));
			if ($testVkn !== '') {
				return $testVkn;
			}
		}
		$vkn = trim(getDolGlobalString('ISNETEFATURA_COMPANY_VKN'));
		if ($vkn === '') {
			$vkn = trim(getDolGlobalString('MAIN_INFO_SIREN'));
		}
		return $vkn;
	}

	public function getVendorNumber()
	{
		return trim(getDolGlobalString('ISNETEFATURA_VENDOR_NUMBER'));
	}

	public function getServiceUrl($service)
	{
		$key = 'ISNETEFATURA_URL_'.($service === self::SERVICE_ADDRESSBOOK ? 'ADDRESSBOOK' : 'INVOICE').'_'.strtoupper($this->env);
		return trim(getDolGlobalString($key));
	}

	/**
	 * @return SoapClient|null
	 */
	private function getSoapClient($service)
	{
		if (isset($this->clients[$service])) {
			return $this->clients[$service];
		}

		$url = $this->getServiceUrl($service);
		if ($url === '') {
			$this->error = 'ISNETEFATURA_URL_NOT_SET:'.$service.':'.$this->env;
			return null;
		}

		$context = stream_context_create(array(
			'http' => array('timeout' => $this->timeout),
			'ssl' => array('verify_peer' => true, 'verify_peer_name' => true),
		));

		$options = array(
			'soap_version' => SOAP_1_1,
			// The WSDL lists an http port first; force the https endpoint.
			'location' => $url,
			'cache_wsdl' => $this->debug ? WSDL_CACHE_NONE : WSDL_CACHE_DISK,
			'connection_timeout' => $this->timeout,
			'stream_context' => $context,
			'trace' => $this->debug,
			'exceptions' => true,
			'encoding' => 'UTF-8',
			'user_agent' => 'Dolibarr-IsnetEfatura/0.1',
		);

		try {
			$this->clients[$service] = new SoapClient($url.'?wsdl', $options);
		} catch (SoapFault $e) {
			$this->error = 'SOAP_INIT_FAILED: '.$e->getMessage();
			dol_syslog(__METHOD__.' '.$this->error, LOG_ERR);
			return null;
		}
		return $this->clients[$service];
	}

	/**
	 * Generic call. Wraps the request in the WCF "request" parameter and unwraps
	 * the "<Operation>Result" envelope.
	 *
	 * @param  string     $service   self::SERVICE_*
	 * @param  string     $operation SOAP operation name
	 * @param  array|null $request   Request payload (null for parameterless operations)
	 * @return object|null           Result object (has ->Result and ->ErrorMessage) or null on transport error
	 */
	public function call($service, $operation, $request = null)
	{
		$this->error = '';
		$client = $this->getSoapClient($service);
		if (!$client) {
			return null;
		}

		$params = $request === null ? array() : array('request' => $request);
		dol_syslog(__METHOD__.' '.$service.'::'.$operation.' env='.$this->env, LOG_DEBUG);

		try {
			$response = $client->__soapCall($operation, array($params));
		} catch (SoapFault $e) {
			$this->error = 'SOAP_FAULT: '.$e->getMessage();
			dol_syslog(__METHOD__.' '.$operation.' '.$this->error, LOG_ERR);
			$this->captureTrace($client);
			return null;
		}
		$this->captureTrace($client);

		$resultProp = $operation.'Result';
		$result = (is_object($response) && isset($response->$resultProp)) ? $response->$resultProp : $response;

		if (is_object($result) && isset($result->Result) && $result->Result === 'Failed') {
			$this->error = 'ISNET_FAILED: '.(isset($result->ErrorMessage) ? $result->ErrorMessage : 'unknown');
			dol_syslog(__METHOD__.' '.$operation.' '.$this->error, LOG_WARNING);
		}
		return $result;
	}

	private function captureTrace($client)
	{
		if (!$this->debug) {
			return;
		}
		$this->lastRequest = (string) $client->__getLastRequest();
		$this->lastResponse = (string) $client->__getLastResponse();
		dol_syslog('IsnetClient REQUEST: '.$this->lastRequest, LOG_DEBUG);
		dol_syslog('IsnetClient RESPONSE: '.$this->lastResponse, LOG_DEBUG);
	}

	/**
	 * WCF ArrayOfX comes back as stdClass{ X: item | item[] } or as null. Normalize to a list.
	 */
	public static function toList($arrayOf, $itemName)
	{
		if (!is_object($arrayOf) || !isset($arrayOf->$itemName)) {
			return array();
		}
		$items = $arrayOf->$itemName;
		return is_array($items) ? $items : array($items);
	}

	/**
	 * Format a Dolibarr timestamp as WCF dateTime (no timezone suffix; İşNet treats it as local TR time).
	 */
	public static function dateTime($timestamp)
	{
		return dol_print_date($timestamp, '%Y-%m-%dT%H:%M:%S', 'tzserver');
	}


	/* ---------------------------------------------------------------- AddressBook */

	/**
	 * Returns true when the service answers, false otherwise.
	 */
	public function healthCheck()
	{
		$r = $this->call(self::SERVICE_INVOICE, 'HealthCheck');
		return $r !== null && $this->error === '';
	}

	/**
	 * Look up a registered e-Fatura taxpayer by VKN/TCKN.
	 *
	 * @param  string $taxCode VKN (10 digits) or TCKN (11 digits)
	 * @return array           List of TaxPayer objects (empty = not an e-Fatura taxpayer → e-Arşiv)
	 */
	public function getTaxPayer($taxCode)
	{
		$r = $this->call(self::SERVICE_ADDRESSBOOK, 'GetTaxPayer', array(
			'TaxPayerTaxCode' => trim($taxCode),
		));
		if ($r === null) {
			return array();
		}
		// İşNet reports "no such taxpayer" as Result=Failed; that is a valid answer, not an error.
		if ($this->error !== '' && preg_match('/bulunamad/iu', $this->error)) {
			$this->error = '';
			return array();
		}
		if ($this->error !== '') {
			return array();
		}
		return self::toList($r->TaxPayers ?? null, 'TaxPayer');
	}

	/**
	 * Pick the inbox tag to address a taxpayer. GİB taxpayers usually expose one
	 * "defaultpk" tag; test accounts expose dozens. Prefer defaultpk, else the first.
	 */
	public static function pickInboxTag(array $tags)
	{
		foreach ($tags as $t) {
			if (stripos($t, 'defaultpk@') !== false) {
				return $t;
			}
		}
		return $tags[0] ?? '';
	}

	/**
	 * Convenience: is this VKN an e-Fatura taxpayer, and which inbox tag should we use?
	 * Check $this->error afterwards: null + empty error = not registered (→ e-Arşiv),
	 * null + error = lookup failed (do not send).
	 *
	 * @return array|null  array('name'=>, 'inbox_tag'=>, 'inbox_tags'=>, 'registered'=>, 'type'=>) or null
	 */
	public function resolveReceiver($taxCode)
	{
		$list = $this->getTaxPayer($taxCode);
		if (empty($list)) {
			return null;
		}
		$tp = $list[0];
		$inbox = self::toList($tp->InboxTagList ?? null, 'string');
		return array(
			'name' => $tp->TaxPayerName ?? '',
			'inbox_tag' => self::pickInboxTag($inbox),
			'inbox_tags' => $inbox,
			'registered' => isset($tp->RegistrationDate) ? $tp->RegistrationDate : '',
			'type' => $tp->Type ?? '',
		);
	}


	/* ---------------------------------------------------------------- Invoice */

	/**
	 * Send one or more e-Fatura invoices (object based; İşNet builds the UBL and assigns the number).
	 *
	 * @param  array $invoices List of Invoice arrays (see docs/isnet/wsdl/InvoiceService.types.txt)
	 * @return array           List of InvoiceReturn objects {Ettn, ExternalInvoiceCode, InvoiceNumber}
	 */
	public function sendInvoice(array $invoices)
	{
		$request = array(
			'CompanyTaxCode' => $this->getCompanyTaxCode(),
			'Invoices' => array('Invoice' => array_values($invoices)),
		);
		if ($this->getVendorNumber() !== '') {
			$request['CompanyVendorNumber'] = $this->getVendorNumber();
		}
		$r = $this->call(self::SERVICE_INVOICE, 'SendInvoice', $request);
		if ($r === null || $this->error !== '') {
			return array();
		}
		return self::toList($r->Invoices ?? null, 'InvoiceReturn');
	}

	/**
	 * Send one or more e-Arşiv invoices (receiver is not an e-Fatura taxpayer).
	 *
	 * @param  array $invoices List of ArchiveInvoice arrays
	 * @return array           List of ArchiveInvoiceReturn objects {Ettn, ExternalArchiveInvoiceCode, ArchiveInvoiceNumber}
	 */
	public function sendArchiveInvoice(array $invoices)
	{
		$request = array(
			'CompanyTaxCode' => $this->getCompanyTaxCode(),
			'ArchiveInvoices' => array('ArchiveInvoice' => array_values($invoices)),
		);
		if ($this->getVendorNumber() !== '') {
			$request['CompanyVendorNumber'] = $this->getVendorNumber();
		}
		$r = $this->call(self::SERVICE_INVOICE, 'SendArchiveInvoice', $request);
		if ($r === null || $this->error !== '') {
			return array();
		}
		return self::toList($r->ArchiveInvoices ?? null, 'ArchiveInvoiceReturn');
	}

	/* ---------------------------------------------------------------- e-İrsaliye */

	/**
	 * Registered e-İrsaliye taxpayer lookup (separate registry from e-Fatura).
	 *
	 * @return array|null array('name','inbox_tag','inbox_tags') or null when not registered
	 */
	public function resolveDespatchReceiver($taxCode)
	{
		$r = $this->call(self::SERVICE_ADDRESSBOOK, 'GetDespatchTaxPayer', array('TaxPayerTaxCode' => trim($taxCode)));
		if ($r === null) {
			return null;
		}
		if ($this->error !== '' && preg_match('/bulunamad/iu', $this->error)) {
			$this->error = '';
			return null;
		}
		if ($this->error !== '') {
			return null;
		}
		$list = self::toList($r->TaxPayers ?? null, 'TaxPayer');
		if (empty($list)) {
			return null;
		}
		$inbox = self::toList($list[0]->InboxTagList ?? null, 'string');
		return array('name' => (string) ($list[0]->TaxPayerName ?? ''), 'inbox_tag' => self::pickInboxTag($inbox), 'inbox_tags' => $inbox);
	}

	/**
	 * @return array List of DespatchAdviceReturn {DespatchAdviceNumber, Ettn, ExternalDespatchAdviceCode}
	 */
	public function sendDespatchAdvice(array $advices)
	{
		$request = array('CompanyTaxCode' => $this->getCompanyTaxCode(), 'DespatchAdvices' => array('DespatchAdvice' => array_values($advices)));
		if ($this->getVendorNumber() !== '') {
			$request['CompanyVendorNumber'] = $this->getVendorNumber();
		}
		$r = $this->call(self::SERVICE_INVOICE, 'SendDespatchAdvice', $request);
		if ($r === null || $this->error !== '') {
			return array();
		}
		return self::toList($r->DespatchAdvices ?? null, 'DespatchAdviceReturn');
	}

	public static function despatchResultSet(array $include = array())
	{
		$set = array('IsArchiveIncluded' => false, 'IsAttachmentIncluded' => false, 'IsDespatchAdviceDetailIncluded' => false, 'IsExternalUrlIncluded' => false, 'IsHtmlIncluded' => false, 'IsPDFIncluded' => false, 'IsXMLIncluded' => false);
		foreach ($include as $k) {
			$set[$k] = true;
		}
		return $set;
	}

	/**
	 * @return array List of DespatchAdvice objects
	 */
	public function searchDespatchAdvice(array $filters, $direction = 'Outgoing')
	{
		$request = array_merge(array(
			'CompanyTaxCode' => $this->getCompanyTaxCode(),
			'DespatchAdviceDirection' => $direction,
			'PagingRequest' => array('PageNumber' => 1, 'RecordsPerPage' => 20),
			'ResultSet' => self::despatchResultSet(),
		), $filters);
		$r = $this->call(self::SERVICE_INVOICE, 'SearchDespatchAdvice', $request);
		if ($r === null || $this->error !== '') {
			return array();
		}
		return self::toList($r->DespatchAdvices ?? null, 'DespatchAdvice');
	}

	/**
	 * Acknowledge an incoming despatch advice (receipt advice).
	 *
	 * @return array List of ReceiptAdviceReturn
	 */
	public function sendReceiptAdvice(array $advices)
	{
		$request = array('CompanyTaxCode' => $this->getCompanyTaxCode(), 'ReceiptAdvices' => array('ReceiptAdvice' => array_values($advices)));
		$r = $this->call(self::SERVICE_INVOICE, 'SendReceiptAdvice', $request);
		if ($r === null || $this->error !== '') {
			return array();
		}
		return self::toList($r->ReceiptAdvices ?? null, 'ReceiptAdviceReturn');
	}

	/**
	 * Cancel an issued e-Arşiv document (allowed until GİB's daily report is accepted).
	 */
	public function cancelArchiveInvoice($ettn, $reason)
	{
		$r = $this->call(self::SERVICE_INVOICE, 'CancelArchiveInvoice', array(
			'ArchiveInvoiceList' => array('ArchiveInvoiceCancellation' => array(array(
				'CancellationReason' => $reason,
				'ETTN' => $ettn,
			))),
			'CompanyTaxCode' => $this->getCompanyTaxCode(),
		));
		return $r !== null && $this->error === '';
	}

	/**
	 * Re-send the e-Arşiv document e-mail to the customer.
	 */
	public function sendArchiveInvoiceMail($ettn, $email)
	{
		$r = $this->call(self::SERVICE_INVOICE, 'SendArchiveInvoiceMail', array(
			'CompanyTaxCode' => $this->getCompanyTaxCode(),
			'Email' => $email,
			'Ettn' => $ettn,
		));
		return $r !== null && $this->error === '';
	}

	/**
	 * Remaining document credits at the integrator, or null on error.
	 */
	public function getCompanyBalance()
	{
		$r = $this->call(self::SERVICE_INVOICE, 'GetCompanyBalance', array('CompanyTaxCode' => $this->getCompanyTaxCode()));
		if ($r === null || $this->error !== '') {
			return null;
		}
		return (int) ($r->Balance ?? 0);
	}

	/**
	 * Branches (vendor numbers) defined for the company at the integrator.
	 *
	 * @return array number => name
	 */
	public function getCompanyVendors()
	{
		$r = $this->call(self::SERVICE_INVOICE, 'GetCompanyVendor', array('CompanyTaxCode' => $this->getCompanyTaxCode()));
		$out = array();
		if ($r === null || $this->error !== '') {
			return $out;
		}
		foreach (self::toList($r->Vendors ?? null, 'CompanyVendor') as $v) {
			$out[(string) $v->companyVendorNumber] = (string) $v->companyVendorName;
		}
		return $out;
	}

	/**
	 * Search outgoing invoices. $filters keys follow SearchInvoiceRequest (e.g. Ettn, ExternalInvoiceCode).
	 *
	 * @return array List of Invoice objects
	 */
	public function searchInvoice(array $filters, $direction = 'Outgoing')
	{
		$request = array_merge(array(
			'CompanyTaxCode' => $this->getCompanyTaxCode(),
			'InvoiceDirection' => $direction,
			'PagingRequest' => array('PageNumber' => 1, 'RecordsPerPage' => 20),
			'ResultSet' => self::resultSet(),
		), $filters);
		$r = $this->call(self::SERVICE_INVOICE, 'SearchInvoice', $request);
		if ($r === null || $this->error !== '') {
			return array();
		}
		return self::toList($r->Invoices ?? null, 'Invoice');
	}

	/**
	 * Search e-Arşiv invoices. $filters keys follow SearchArchiveInvoiceRequest (e.g. Ettn, ExternalArchiveInvoiceCode).
	 *
	 * @return array List of ArchiveInvoice objects
	 */
	public function searchArchiveInvoice(array $filters)
	{
		$request = array_merge(array(
			'CompanyTaxCode' => $this->getCompanyTaxCode(),
			'PagingRequest' => array('PageNumber' => 1, 'RecordsPerPage' => 20),
			'ResultSet' => self::resultSet(),
		), $filters);
		$r = $this->call(self::SERVICE_INVOICE, 'SearchArchiveInvoice', $request);
		if ($r === null || $this->error !== '') {
			return array();
		}
		return self::toList($r->ArchiveInvoices ?? null, 'ArchiveInvoice');
	}

	/**
	 * InvoiceResultSet with every optional payload switched off except the given keys.
	 */
	public static function resultSet(array $include = array())
	{
		$set = array(
			'IsAdditionalTaxIncluded' => false, 'IsArchiveIncluded' => false, 'IsAttachmentIncluded' => false,
			'IsExternalUrlIncluded' => false, 'IsHtmlIncluded' => false, 'IsInvoiceDetailIncluded' => false,
			'IsPDFIncluded' => false, 'IsXMLIncluded' => false,
		);
		foreach ($include as $k) {
			$set[$k] = true;
		}
		return $set;
	}

	/**
	 * @param  string $ettn
	 * @param  string $docType 'EInvoice' | 'EArchiveInvoice'
	 * @return string URL or '' on error
	 */
	public function getDocumentViewerLink($ettn, $docType = 'EInvoice', $direction = 'Outgoing')
	{
		$r = $this->call(self::SERVICE_INVOICE, 'GetDocumentViewerLink', array(
			'CompanyTaxCode' => $this->getCompanyTaxCode(),
			'Ettn' => $ettn,
			'InvoiceDirection' => $direction,
			'InvoiceDocumentType' => $docType,
		));
		if ($r === null || $this->error !== '') {
			return '';
		}
		$url = (string) ($r->DocumentViewerLink ?? '');
		// The service hands out http://host:80/... but the viewer only answers on https.
		return preg_replace('#^http://([^/:]+)(:80)?/#i', 'https://$1/', $url);
	}
}
