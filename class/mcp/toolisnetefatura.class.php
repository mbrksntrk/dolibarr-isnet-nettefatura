<?php
/* Copyright (C) 2026 M. Burak Şentürk <https://buraksenturk.net>
 * Licensed under GPL-3.0-or-later with attribution terms. See COPYING and ATTRIBUTION.md.
 */

require_once DOL_DOCUMENT_ROOT.'/ai/class/mcptool.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
dol_include_once('/isnetefatura/class/isnetsender.class.php');
dol_include_once('/isnetefatura/class/isnetincoming.class.php');
dol_include_once('/isnetefatura/class/isnetdocument.class.php');

/**
 * MCP tools exposed by the İşNet e-Fatura module (Dolibarr AI module, hook 'addMcpTools').
 * Every tool runs with the rights of the MCP user; write tools require the module's 'send' right.
 */
class ToolIsnetEfatura extends McpTool
{
	public function getCategories(): array
	{
		return ['billing'];
	}

	public function getDefinitions(): array
	{
		$invoiceRef = [
			"invoice_id" => ["type" => "integer", "description" => "Dolibarr customer invoice id. Provide this or invoice_ref."],
			"invoice_ref" => ["type" => "string", "description" => "Customer invoice reference, e.g. FA2609-0004 or (PROV6)."],
		];
		return [
			[
				"name" => "isnet_invoice_status",
				"description" => "e-Fatura / e-Arşiv status of a customer invoice at the İşNet integrator: receiver decision (e-Fatura taxpayer or e-Arşiv), pre-flight problems that would block sending, and every send attempt with integrator number, ETTN, status, outcome and error. Read-only. Use for questions like 'was invoice X sent to GİB?', 'why did it fail?'.",
				"inputSchema" => ["type" => "object", "properties" => $invoiceRef],
			],
			[
				"name" => "isnet_send_invoice",
				"description" => "Send a VALIDATED customer invoice to İşNet as e-Fatura or e-Arşiv (decision automatic). Refuses drafts and invoices already sent successfully unless force=true (force uses a new external code and creates a NEW e-document — never use it when a previous attempt may have succeeded). Ask the user for confirmation before calling.",
				"inputSchema" => [
					"type" => "object",
					"properties" => $invoiceRef + ["force" => ["type" => "boolean", "description" => "Re-send even if an accepted attempt exists. Default false."]],
				],
			],
			[
				"name" => "isnet_refresh_status",
				"description" => "Query İşNet for the current status of the latest e-document of a customer invoice (GİB delivery, receiver acceptance/rejection, e-Arşiv report) and store it. Also downloads the integrator PDF when the document becomes final.",
				"inputSchema" => ["type" => "object", "properties" => $invoiceRef],
			],
			[
				"name" => "isnet_list_documents",
				"description" => "List e-documents (e-Fatura, e-Arşiv, e-İrsaliye) sent through İşNet, filtered by outcome. Outcomes: 'fail' (integrator refused / error, needs attention), 'pending' (sent, waiting for GİB or receiver), 'ok' (completed), 'cancelled', 'issued' (created, report pending), 'all'. Use for 'which invoices could not be sent this month?'.",
				"inputSchema" => [
					"type" => "object",
					"properties" => [
						"outcome" => ["type" => "string", "enum" => ["fail", "pending", "ok", "issued", "cancelled", "all"], "description" => "Default 'fail'."],
						"days" => ["type" => "integer", "description" => "Look back this many days (default 30)."],
						"limit" => ["type" => "integer", "description" => "Max rows (default 50)."],
					],
				],
			],
			[
				"name" => "isnet_check_taxpayer",
				"description" => "Check at İşNet/GİB whether a tax number (VKN 10 digits / TCKN 11 digits) or a third party is a registered e-Fatura taxpayer and list its inbox tags (posta kutusu). Non-taxpayers receive e-Arşiv. Costs one integrator query.",
				"inputSchema" => [
					"type" => "object",
					"properties" => [
						"tax_code" => ["type" => "string", "description" => "VKN/TCKN."],
						"thirdparty_id" => ["type" => "integer", "description" => "Dolibarr third party id (its Prof ID 1 is used)."],
					],
				],
			],
			[
				"name" => "isnet_list_incoming",
				"description" => "List incoming e-Faturas (from suppliers) or incoming e-İrsaliyes synced from İşNet: sender, number, date, totals, scenario, İşNet status, our reply, and the linked Dolibarr supplier invoice if imported. Use unlinked=true for 'which incoming invoices are not yet booked?'.",
				"inputSchema" => [
					"type" => "object",
					"properties" => [
						"kind" => ["type" => "string", "enum" => ["INVOICE", "DESPATCH"], "description" => "Default INVOICE."],
						"unlinked" => ["type" => "boolean", "description" => "Only documents not yet imported as supplier invoice."],
						"search" => ["type" => "string", "description" => "Filter by sender name, VKN or document number."],
						"limit" => ["type" => "integer", "description" => "Default 50."],
					],
				],
			],
			[
				"name" => "isnet_sync_incoming",
				"description" => "Fetch new incoming e-Faturas (and e-İrsaliyes when enabled) from İşNet now instead of waiting for the hourly job.",
				"inputSchema" => ["type" => "object", "properties" => ["days" => ["type" => "integer", "description" => "Look back window in days (default: module setting, 30)."]]],
			],
			[
				"name" => "isnet_import_incoming",
				"description" => "Create a DRAFT Dolibarr supplier invoice from an incoming e-Fatura (lines, VAT, currency, İşNet PDF attached; supplier auto-created from VKN when allowed by setup). Ask the user for confirmation before calling.",
				"inputSchema" => ["type" => "object", "properties" => ["incoming_id" => ["type" => "integer", "description" => "Id from isnet_list_incoming."]], "required" => ["incoming_id"]],
			],
			[
				"name" => "isnet_reply_incoming",
				"description" => "Send the GİB application response for an incoming commercial (TICARIFATURA) e-Fatura: KABUL (accept) or RED (reject, reason required). Irreversible — ask the user for confirmation before calling.",
				"inputSchema" => [
					"type" => "object",
					"properties" => [
						"incoming_id" => ["type" => "integer"],
						"answer" => ["type" => "string", "enum" => ["KABUL", "RED"]],
						"reason" => ["type" => "string", "description" => "Rejection reason (required for RED)."],
					],
					"required" => ["incoming_id", "answer"],
				],
			],
			[
				"name" => "isnet_balance",
				"description" => "İşNet connection summary: environment (test/prod), sender VKN, remaining document credits (kontör) and health check.",
				"inputSchema" => ["type" => "object", "properties" => new stdClass()],
			],
		];
	}

	public function execute(string $toolName, array $args)
	{
		global $langs;
		$langs->loadLangs(['bills', 'isnetefatura@isnetefatura']);
		if (!isModEnabled('isnetefatura')) {
			return ["error" => "İşNet e-Fatura module is not enabled."];
		}
		if (!$this->user->hasRight('isnetefatura', 'read')) {
			return ["error" => "Permission denied: isnetefatura read"];
		}
		switch ($toolName) {
			case 'isnet_invoice_status':
				return $this->invoiceStatus($args);
			case 'isnet_send_invoice':
				return $this->sendInvoice($args);
			case 'isnet_refresh_status':
				return $this->refreshStatus($args);
			case 'isnet_list_documents':
				return $this->listDocuments($args);
			case 'isnet_check_taxpayer':
				return $this->checkTaxpayer($args);
			case 'isnet_list_incoming':
				return $this->listIncoming($args);
			case 'isnet_sync_incoming':
				return $this->syncIncoming($args);
			case 'isnet_import_incoming':
				return $this->importIncoming($args);
			case 'isnet_reply_incoming':
				return $this->replyIncoming($args);
			case 'isnet_balance':
				return $this->balance();
		}
		return ["error" => "Tool function '$toolName' not found."];
	}

	// ------------------------------------------------------------------ helpers

	/** @return Facture|array */
	private function resolveInvoice(array $args)
	{
		if (!$this->user->hasRight('facture', 'lire')) {
			return ["error" => "Permission denied: invoice read"];
		}
		$id = (int) ($args['invoice_id'] ?? 0);
		$ref = trim((string) ($args['invoice_ref'] ?? ''));
		$inv = new Facture($this->db);
		$r = $id > 0 ? $inv->fetch($id) : ($ref !== '' ? $inv->fetch(0, $ref) : 0);
		if ($r <= 0) {
			return ["error" => "Customer invoice not found (give invoice_id or invoice_ref)."];
		}
		$inv->fetch_thirdparty();
		$inv->fetch_optionals();
		if ($inv->thirdparty) {
			$inv->thirdparty->fetch_optionals();
		}
		return $inv;
	}

	private function canSend()
	{
		return $this->user->hasRight('isnetefatura', 'send');
	}

	private function translateProblems(array $list)
	{
		global $langs;
		$out = [];
		foreach ($list as $p) {
			$parts = explode(':', $p, 2);
			$out[] = $langs->transnoentitiesnoconv($parts[0]).(isset($parts[1]) ? ' ('.$parts[1].')' : '');
		}
		return $out;
	}

	private function docToArray(IsnetDocument $d, $ref = null)
	{
		return [
			"document_id" => (int) $d->id,
			"object" => $d->element_type,
			"object_id" => (int) $d->fk_facture,
			"ref" => $ref,
			"doc_type" => $d->doc_type,
			"scenario" => $d->scenario,
			"invoice_type" => $d->invoice_type,
			"external_code" => $d->external_code,
			"integrator_number" => $d->invoice_number ?: null,
			"ettn" => $d->ettn ?: null,
			"status" => $d->status ?: null,
			"detail_status" => $d->detail_status ?: null,
			"outcome" => $d->outcome(),
			"error" => $d->last_error ?: null,
			"date_sent" => $d->date_sent ? dol_print_date($d->date_sent, 'dayhourrfc') : null,
			"date_checked" => $d->date_checked ? dol_print_date($d->date_checked, 'dayhourrfc') : null,
		];
	}

	// ------------------------------------------------------------------ tools

	private function invoiceStatus(array $args)
	{
		$inv = $this->resolveInvoice($args);
		if (is_array($inv)) {
			return $inv;
		}
		dol_include_once('/isnetefatura/class/isnetinvoicemapper.class.php');
		$mapper = new IsnetInvoiceMapper($this->db);
		$soc = $inv->thirdparty;
		$mapper->preflight($inv, $soc);
		$sender = new IsnetSender($this->db);
		$docs = (new IsnetDocument($this->db))->fetchAllForInvoice($inv->id);
		$attempts = [];
		foreach ($docs as $d) {
			$attempts[] = $this->docToArray($d, $inv->ref);
		}
		$opt = $soc->array_options ?? [];
		return [
			"invoice_id" => (int) $inv->id,
			"ref" => $inv->ref,
			"invoice_status" => (int) $inv->statut,
			"is_validated" => (int) $inv->statut >= Facture::STATUS_VALIDATED,
			"thirdparty" => ["id" => (int) $soc->id, "name" => $soc->name, "tax_code" => $soc->idprof1, "tax_office" => $soc->idprof2,
				"efatura_taxpayer_cached" => ($opt['options_isnet_status'] ?? '') === '1', "inbox_tag_cached" => $opt['options_isnet_inbox_tag'] ?? null],
			"scenario" => $mapper->scenario($inv, $soc),
			"invoice_type" => $mapper->invoiceType($inv, $soc),
			"edocument_number" => $inv->array_options['options_isnet_number'] ?? null,
			"edocument_status" => $inv->array_options['options_isnet_state'] ?? null,
			"can_send" => empty($mapper->problems),
			"preflight_problems" => $this->translateProblems($mapper->problems),
			"preflight_warnings" => $this->translateProblems($mapper->warnings),
			"attempts" => $attempts,
			"last_outcome" => $docs ? end($docs)->outcome() : null,
			"link" => dol_buildpath('/isnetefatura/card.php', 2).'?id='.$inv->id,
		];
	}

	private function sendInvoice(array $args)
	{
		if (!$this->canSend()) {
			return ["error" => "Permission denied: isnetefatura send"];
		}
		$inv = $this->resolveInvoice($args);
		if (is_array($inv)) {
			return $inv;
		}
		global $langs;
		if ((int) $inv->statut < Facture::STATUS_VALIDATED) {
			return ["error" => $langs->transnoentitiesnoconv('IsnetErrInvoiceNotValidated')];
		}
		$sender = new IsnetSender($this->db);
		$r = $sender->send($inv, $this->user, !empty($args['force']));
		if ($r <= 0) {
			return ["success" => false, "errors" => $this->translateProblems($sender->errors ?: [$sender->error])];
		}
		$d = $sender->document;
		return ["success" => true, "reconciled" => $r === 2, "document" => $d ? $this->docToArray($d, $inv->ref) : null];
	}

	private function refreshStatus(array $args)
	{
		$inv = $this->resolveInvoice($args);
		if (is_array($inv)) {
			return $inv;
		}
		$docs = (new IsnetDocument($this->db))->fetchAllForInvoice($inv->id);
		if (!$docs) {
			return ["error" => "No e-document for this invoice."];
		}
		$d = end($docs);
		$sender = new IsnetSender($this->db);
		$r = $sender->refreshStatus($d);
		if ($r < 0) {
			return ["error" => $sender->error];
		}
		$d->fetch($d->id);
		return ["updated" => $r === 1, "document" => $this->docToArray($d, $inv->ref)];
	}

	private function listDocuments(array $args)
	{
		$outcome = $args['outcome'] ?? 'fail';
		$days = max(1, (int) ($args['days'] ?? 30));
		$limit = max(1, min(200, (int) ($args['limit'] ?? 50)));
		$since = $this->db->idate(dol_now() - $days * 86400);
		$sql = "SELECT d.rowid, d.element_type, d.fk_facture, f.ref AS fref, e.ref AS eref FROM ".MAIN_DB_PREFIX."isnetefatura_document d";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."facture f ON (d.element_type = 'facture' AND f.rowid = d.fk_facture)";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."expedition e ON (d.element_type = 'shipping' AND e.rowid = d.fk_facture)";
		$sql .= " WHERE d.entity IN (".getEntity('invoice').") AND d.date_creation >= '".$since."' ORDER BY d.rowid DESC LIMIT 500";
		$res = $this->db->query($sql);
		if (!$res) {
			return ["error" => $this->db->lasterror()];
		}
		$rows = [];
		$latest = [];
		while ($o = $this->db->fetch_object($res)) {
			$key = $o->element_type.'#'.$o->fk_facture;
			if (isset($latest[$key])) {
				continue; // only the latest attempt per object
			}
			$latest[$key] = true;
			$d = new IsnetDocument($this->db);
			if ($d->fetch($o->rowid) <= 0) {
				continue;
			}
			$oc = $d->outcome();
			$match = $outcome === 'all' || $oc === $outcome || ($outcome === 'fail' && in_array($oc, ['fail', 'error'], true));
			if (!$match) {
				continue;
			}
			$rows[] = $this->docToArray($d, $o->fref ?: $o->eref);
			if (count($rows) >= $limit) {
				break;
			}
		}
		return ["outcome_filter" => $outcome, "days" => $days, "count" => count($rows), "documents" => $rows];
	}

	private function checkTaxpayer(array $args)
	{
		$tax = preg_replace('/\D/', '', (string) ($args['tax_code'] ?? ''));
		$name = null;
		if ($tax === '' && !empty($args['thirdparty_id'])) {
			require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
			$soc = new Societe($this->db);
			if ($soc->fetch((int) $args['thirdparty_id']) <= 0) {
				return ["error" => "Third party not found."];
			}
			$tax = preg_replace('/\D/', '', (string) $soc->idprof1);
			$name = $soc->name;
		}
		if (strlen($tax) !== 10 && strlen($tax) !== 11) {
			return ["error" => "Tax code must be 10 (VKN) or 11 (TCKN) digits."];
		}
		$client = new IsnetClient($this->db);
		$r = $client->resolveReceiver($tax);
		$despatch = getDolGlobalInt('ISNETEFATURA_DESPATCH_ENABLED', 1) ? $client->resolveDespatchReceiver($tax) : false;
		if ($r === null) {
			return ["tax_code" => $tax, "thirdparty" => $name, "efatura_taxpayer" => false, "document_type" => "EARSIV", "note" => $client->error ?: "Not registered; e-Arşiv applies."];
		}
		return [
			"tax_code" => $tax, "thirdparty" => $name, "efatura_taxpayer" => true, "document_type" => "EFATURA",
			"registered_name" => $r['name'], "inbox_tag" => $r['inbox_tag'], "inbox_tags" => $r['inbox_tags'], "registered_since" => $r['registered'], "taxpayer_type" => $r['type'],
			"eirsaliye_taxpayer" => $despatch === false ? null : $despatch !== null, "eirsaliye_inbox_tag" => $despatch['inbox_tag'] ?? null,
		];
	}

	private function listIncoming(array $args)
	{
		if (!$this->user->hasRight('fournisseur', 'facture', 'lire')) {
			return ["error" => "Permission denied: supplier invoice read"];
		}
		$limit = max(1, min(200, (int) ($args['limit'] ?? 50)));
		$list = (new IsnetIncoming($this->db))->fetchAll(['kind' => ($args['kind'] ?? 'INVOICE') === 'DESPATCH' ? 'DESPATCH' : 'INVOICE', 'unlinked' => !empty($args['unlinked']), 'search' => (string) ($args['search'] ?? '')], $limit);
		$rows = [];
		foreach ($list as $x) {
			$rows[] = [
				"incoming_id" => (int) $x->id, "kind" => $x->doc_kind, "number" => $x->invoice_number, "ettn" => $x->ettn,
				"date" => $x->invoice_date ? dol_print_date($x->invoice_date, 'dayrfc') : null, "due_date" => $x->due_date ? dol_print_date($x->due_date, 'dayrfc') : null,
				"sender" => $x->sender_name, "sender_tax_code" => $x->sender_tax_code, "scenario" => $x->scenario, "invoice_type" => $x->invoice_type,
				"currency" => $x->currency_code, "total_ht" => (float) $x->total_line_ext, "total_vat" => (float) $x->total_vat, "total_payable" => (float) $x->total_payable,
				"isnet_status" => $x->status, "our_reply" => $x->response_status ?: null, "thirdparty_id" => (int) $x->fk_soc ?: null,
				"supplier_invoice_id" => (int) $x->fk_facture_fourn ?: null, "imported" => (int) $x->fk_facture_fourn > 0,
			];
		}
		return ["count" => count($rows), "incoming" => $rows, "link" => dol_buildpath('/isnetefatura/incoming.php', 2)];
	}

	private function syncIncoming(array $args)
	{
		if (!$this->canSend()) {
			return ["error" => "Permission denied: isnetefatura send"];
		}
		$days = (int) ($args['days'] ?? 0) ?: getDolGlobalInt('ISNETEFATURA_INCOMING_DAYS', 30);
		$inc = new IsnetIncoming($this->db);
		$n = $inc->sync($days);
		$out = ["days" => $days, "invoices_synced" => $n, "error" => $n < 0 ? $inc->error : null];
		if (getDolGlobalInt('ISNETEFATURA_DESPATCH_ENABLED', 1)) {
			$m = $inc->syncDespatch($days);
			$out["despatches_synced"] = $m;
			if ($m < 0) {
				$out["despatch_error"] = $inc->error;
			}
		}
		return $out;
	}

	private function importIncoming(array $args)
	{
		if (!$this->canSend() || !$this->user->hasRight('fournisseur', 'facture', 'creer')) {
			return ["error" => "Permission denied: isnetefatura send + supplier invoice create"];
		}
		$inc = new IsnetIncoming($this->db);
		if ($inc->fetch((int) ($args['incoming_id'] ?? 0)) <= 0) {
			return ["error" => "Incoming document not found."];
		}
		if ((int) $inc->fk_facture_fourn > 0) {
			return ["error" => "Already imported as supplier invoice id ".$inc->fk_facture_fourn."."];
		}
		$id = $inc->createSupplierInvoice($this->user);
		if ($id <= 0) {
			return ["error" => $inc->error];
		}
		return ["success" => true, "supplier_invoice_id" => $id, "link" => DOL_MAIN_URL_ROOT.'/fourn/facture/card.php?id='.$id, "note" => "Draft created; check product mapping and accounting codes, then validate."];
	}

	private function replyIncoming(array $args)
	{
		if (!$this->canSend()) {
			return ["error" => "Permission denied: isnetefatura send"];
		}
		$inc = new IsnetIncoming($this->db);
		if ($inc->fetch((int) ($args['incoming_id'] ?? 0)) <= 0) {
			return ["error" => "Incoming document not found."];
		}
		$answer = strtoupper((string) ($args['answer'] ?? ''));
		if ($answer === 'RED' && trim((string) ($args['reason'] ?? '')) === '') {
			return ["error" => "A reason is required for RED."];
		}
		if ($inc->scenario !== 'TICARIFATURA') {
			return ["error" => "Only commercial (TICARIFATURA) e-Faturas take an application response; this one is ".$inc->scenario."."];
		}
		$r = $inc->reply($answer, (string) ($args['reason'] ?? ''));
		if ($r <= 0) {
			return ["error" => $inc->error];
		}
		return ["success" => true, "number" => $inc->invoice_number, "reply" => $answer];
	}

	private function balance()
	{
		$client = new IsnetClient($this->db);
		$health = method_exists($client, 'healthCheck') ? $client->healthCheck() : null;
		$healthError = $client->error;
		$balance = $client->getCompanyBalance();
		return [
			"environment" => $client->getEnv(),
			"sender_tax_code" => $client->getCompanyTaxCode(),
			"vendor_number" => $client->getVendorNumber() ?: null,
			"health_ok" => $health,
			"health_error" => $healthError ?: null,
			"credits" => $balance,
			"credits_warn_threshold" => getDolGlobalInt('ISNETEFATURA_BALANCE_WARN', 100),
			"low_credits" => $balance !== null && getDolGlobalInt('ISNETEFATURA_BALANCE_WARN', 100) > 0 && $balance < getDolGlobalInt('ISNETEFATURA_BALANCE_WARN', 100),
		];
	}
}
