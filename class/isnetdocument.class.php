<?php
/* Copyright (C) 2026 M. Burak Şentürk <https://buraksenturk.net>
 * Licensed under GPL-3.0-or-later with attribution terms. See COPYING and ATTRIBUTION.md.
 */

/**
 * One row per submission attempt of a Dolibarr invoice to the integrator.
 */
class IsnetDocument
{
	const TABLE = 'isnetefatura_document';

	const TYPE_EFATURA = 'EFATURA';
	const TYPE_EARSIV = 'EARSIV';
	const TYPE_IRSALIYE = 'EIRSALIYE';

	const ELEMENT_INVOICE = 'facture';
	const ELEMENT_SHIPMENT = 'shipping';

	// Local lifecycle (integrator status is stored separately in ->status)
	const STATE_SENDING = 'SENDING';
	const STATE_SENT = 'SENT';
	const STATE_ERROR = 'ERROR';

	public $db;
	public $error = '';

	public $id = 0;
	public $entity = 1;
	public $fk_facture = 0;
	public $element_type = self::ELEMENT_INVOICE;
	public $doc_type = '';
	public $scenario = '';
	public $invoice_type = '';
	public $external_code = '';
	public $ettn = '';
	public $invoice_number = '';
	public $receiver_tax_code = '';
	public $receiver_inbox_tag = '';
	public $status = '';
	public $detail_status = '';
	public $last_error = '';
	public $request_xml = '';
	public $response_xml = '';
	public $date_sent = null;
	public $date_checked = null;
	public $fk_user_send = 0;
	public $date_creation = null;

	// Filled by fetchList() only (source object summary for list screens)
	public $obj_ref = '';
	public $obj_socid = 0;
	public $soc_name = '';

	public function __construct($db)
	{
		$this->db = $db;
	}

	private function hydrate($obj)
	{
		$this->id = (int) $obj->rowid;
		$this->entity = (int) $obj->entity;
		$this->fk_facture = (int) $obj->fk_facture;
		$this->element_type = (string) ($obj->element_type ?? self::ELEMENT_INVOICE);
		$this->doc_type = (string) $obj->doc_type;
		$this->scenario = (string) $obj->scenario;
		$this->invoice_type = (string) $obj->invoice_type;
		$this->external_code = (string) $obj->external_code;
		$this->ettn = (string) $obj->ettn;
		$this->invoice_number = (string) $obj->invoice_number;
		$this->receiver_tax_code = (string) $obj->receiver_tax_code;
		$this->receiver_inbox_tag = (string) $obj->receiver_inbox_tag;
		$this->status = (string) $obj->status;
		$this->detail_status = (string) $obj->detail_status;
		$this->last_error = (string) $obj->last_error;
		$this->request_xml = (string) $obj->request_xml;
		$this->response_xml = (string) $obj->response_xml;
		$this->date_sent = $this->db->jdate($obj->date_sent);
		$this->date_checked = $this->db->jdate($obj->date_checked);
		$this->fk_user_send = (int) $obj->fk_user_send;
		$this->date_creation = $this->db->jdate($obj->date_creation);
	}

	public function fetch($id)
	{
		$sql = 'SELECT * FROM '.MAIN_DB_PREFIX.self::TABLE.' WHERE rowid = '.((int) $id);
		$res = $this->db->query($sql);
		if (!$res) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$obj = $this->db->fetch_object($res);
		if (!$obj) {
			return 0;
		}
		$this->hydrate($obj);
		return 1;
	}

	/**
	 * @return IsnetDocument[] newest first
	 */
	public function fetchAllForInvoice($fk_facture, $element = self::ELEMENT_INVOICE)
	{
		$list = array();
		$sql = 'SELECT * FROM '.MAIN_DB_PREFIX.self::TABLE
			." WHERE fk_facture = ".((int) $fk_facture)." AND element_type = '".$this->db->escape($element)."'"
			.' AND entity IN ('.getEntity('invoice').')'
			.' ORDER BY rowid DESC';
		$res = $this->db->query($sql);
		if (!$res) {
			$this->error = $this->db->lasterror();
			return $list;
		}
		while ($obj = $this->db->fetch_object($res)) {
			$d = new IsnetDocument($this->db);
			$d->hydrate($obj);
			$list[] = $d;
		}
		return $list;
	}

	/**
	 * The attempt that actually reached the integrator (has an ETTN), if any.
	 */
	public static function fetchAccepted($db, $fk_facture, $element = self::ELEMENT_INVOICE)
	{
		$sql = 'SELECT * FROM '.MAIN_DB_PREFIX.self::TABLE
			." WHERE fk_facture = ".((int) $fk_facture)." AND element_type = '".$db->escape($element)."'"
			." AND ettn IS NOT NULL AND ettn <> ''"
			.' ORDER BY rowid DESC LIMIT 1';
		$res = $db->query($sql);
		if (!$res) {
			return null;
		}
		$obj = $db->fetch_object($res);
		if (!$obj) {
			return null;
		}
		$d = new IsnetDocument($db);
		$d->hydrate($obj);
		return $d;
	}

	public function create($user)
	{
		global $conf;
		$now = dol_now();
		$this->entity = $conf->entity;
		$this->date_creation = $now;
		$this->fk_user_send = $user ? (int) $user->id : 0;

		$sql = 'INSERT INTO '.MAIN_DB_PREFIX.self::TABLE
			.' (entity, fk_facture, element_type, doc_type, scenario, invoice_type, external_code, receiver_tax_code, receiver_inbox_tag, status, fk_user_send, date_creation)'
			.' VALUES ('
			.((int) $this->entity).', '
			.((int) $this->fk_facture).", '"
			.$this->db->escape($this->element_type)."', '"
			.$this->db->escape($this->doc_type)."', '"
			.$this->db->escape($this->scenario)."', '"
			.$this->db->escape($this->invoice_type)."', '"
			.$this->db->escape($this->external_code)."', '"
			.$this->db->escape($this->receiver_tax_code)."', '"
			.$this->db->escape($this->receiver_inbox_tag)."', '"
			.$this->db->escape($this->status)."', "
			.((int) $this->fk_user_send).", '"
			.$this->db->idate($now)."')";
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$this->id = $this->db->last_insert_id(MAIN_DB_PREFIX.self::TABLE);
		return $this->id;
	}

	public function update()
	{
		$sql = 'UPDATE '.MAIN_DB_PREFIX.self::TABLE.' SET '
			."ettn = ".($this->ettn !== '' ? "'".$this->db->escape($this->ettn)."'" : 'NULL').', '
			."invoice_number = ".($this->invoice_number !== '' ? "'".$this->db->escape($this->invoice_number)."'" : 'NULL').', '
			."receiver_inbox_tag = '".$this->db->escape($this->receiver_inbox_tag)."', "
			."status = '".$this->db->escape($this->status)."', "
			."detail_status = '".$this->db->escape($this->detail_status)."', "
			."last_error = ".($this->last_error !== '' ? "'".$this->db->escape($this->last_error)."'" : 'NULL').', '
			."request_xml = ".($this->request_xml !== '' ? "'".$this->db->escape($this->request_xml)."'" : 'NULL').', '
			."response_xml = ".($this->response_xml !== '' ? "'".$this->db->escape($this->response_xml)."'" : 'NULL').', '
			."date_sent = ".($this->date_sent ? "'".$this->db->idate($this->date_sent)."'" : 'NULL').', '
			."date_checked = ".($this->date_checked ? "'".$this->db->idate($this->date_checked)."'" : 'NULL')
			.' WHERE rowid = '.((int) $this->id);
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		return 1;
	}

	public function isAccepted()
	{
		return $this->ettn !== '';
	}

	/**
	 * Integrator states after which nothing will change any more.
	 */
	const FINAL_OK = array(
		'Basariyla_Tamamlandi', 'Alici_Kabul_Etti', 'Otomatik_Alici_Kabul_Etti', 'Onaylandi', 'Otomatik_Onaylandi',
		'Gib_Raporu_Kabul_Etti',
	);
	const FINAL_FAIL = array(
		'Gib_Tarafinda_Hata_Olustu', 'Sistem_Hatasi', 'Reddedildi', 'Alici_Reddetti', 'Alici_Iade_Etti', 'Iade_Edildi',
		'Silindi', 'Gibe_Gonderilirken_Sistem_Hatasi_Olustu', 'Fatura_Iptale_Konu_Edildi',
		'Dokuman_Bulunan_Adrese_Gonderilemedi', 'Hedeften_Sistem_Yaniti_Basarisiz_Geldi',
	);
	/**
	 * States that end the story only where no application response (uygulama yanıtı) is due:
	 * in TEMELFATURA the receiver's system response is the last step, while TİCARİFATURA,
	 * KAMU and İHRACAT still await the receiver's accept/reject.
	 */
	const FINAL_OK_WITHOUT_APP_RESPONSE = array('Zarf_Basariyla_Islendi');
	const SCENARIOS_WITHOUT_APP_RESPONSE = array('TEMELFATURA');

	/**
	 * @return string 'ok' (final) | 'issued' (signed & transmitted, GİB report/response pending) | 'fail' | 'pending' | 'error' (never reached the integrator)
	 */
	public function outcome()
	{
		if (!$this->isAccepted()) {
			return $this->last_error !== '' ? 'error' : 'pending';
		}
		if ($this->status === 'Silindi') {
			return 'cancelled';
		}
		$final = $this->finalState();
		if ($final !== '') {
			return $final;
		}
		if ($this->pdfReady()) {
			return 'issued';
		}
		return 'pending';
	}

	public function isFinal()
	{
		$o = $this->outcome();
		return $o === 'ok' || $o === 'fail' || $o === 'cancelled';
	}

	/**
	 * The integrator has signed the document, so its PDF can be fetched even while GİB
	 * reporting is still pending (e-Arşiv is reported in daily batches).
	 */
	public function pdfReady()
	{
		if (!$this->isAccepted() || $this->finalState() === 'fail') {
			return false;
		}
		if ($this->doc_type === self::TYPE_EARSIV) {
			return $this->status === 'Fatura_Olusturuldu' || $this->finalState() === 'ok';
		}
		return in_array($this->status, array('Gibe_Iletildi', 'Alici_Kabul_Etti', 'Otomatik_Alici_Kabul_Etti', 'Alici_Reddetti', 'Alici_Iade_Etti'), true)
			|| $this->finalState() === 'ok';
	}

	/**
	 *  string 'ok' | 'fail' | '' (not final)
	 */
	public function finalState()
	{
		$states = array($this->status, $this->detail_status);
		foreach ($states as $s) {
			if (in_array($s, self::FINAL_FAIL, true)) {
				return 'fail';
			}
		}
		foreach ($states as $s) {
			if (in_array($s, self::FINAL_OK, true)) {
				return 'ok';
			}
		}
		if (in_array($this->scenario, self::SCENARIOS_WITHOUT_APP_RESPONSE, true)) {
			foreach ($states as $s) {
				if (in_array($s, self::FINAL_OK_WITHOUT_APP_RESPONSE, true)) {
					return 'ok';
				}
			}
		}
		return '';
	}

	/**
	 * Path of the integrator PDF stored next to the invoice's own documents, or '' if not stored.
	 */
	public static function pdfPath($obj, $docType)
	{
		global $conf;
		$ref = dol_sanitizeFileName($obj->ref);
		if (($obj->element ?? '') === 'shipping') {
			$dir = $conf->expedition->dir_output.'/sending';
		} else {
			$dir = $conf->facture->multidir_output[$obj->entity ?: $conf->entity] ?? $conf->facture->dir_output;
		}
		return $dir.'/'.$ref.'/'.$ref.'-'.strtolower($docType).'.pdf';
	}

	/**
	 * Badge-friendly summary of where this document stands.
	 */
	public function getStateLabel()
	{
		if ($this->ettn !== '') {
			return $this->status !== '' ? $this->status : self::STATE_SENT;
		}
		return $this->last_error !== '' ? self::STATE_ERROR : $this->status;
	}

	/**
	 * Accepted documents that are not final yet and were not checked in the last $minAgeMinutes.
	 *
	 * @return IsnetDocument[]
	 */
	public static function fetchPendingForRefresh($db, $minAgeMinutes = 30, $limit = 50)
	{
		$list = array();
		$cutoff = $db->idate(dol_now() - $minAgeMinutes * 60);
		$sql = 'SELECT * FROM '.MAIN_DB_PREFIX.self::TABLE
			." WHERE ettn IS NOT NULL AND ettn <> ''"
			." AND (date_checked IS NULL OR date_checked < '".$cutoff."')"
			.' ORDER BY date_checked ASC, rowid ASC LIMIT '.((int) $limit * 3);
		$res = $db->query($sql);
		if (!$res) {
			return $list;
		}
		while ($obj = $db->fetch_object($res)) {
			$d = new IsnetDocument($db);
			$d->hydrate($obj);
			if ($d->isFinal()) {
				continue;
			}
			$list[] = $d;
			if (count($list) >= $limit) {
				break;
			}
		}
		return $list;
	}

	/**
	 * Paged list of submissions for the "sent documents" screens, joined with the source
	 * object so the list can show its ref and third party without fetching each one.
	 *
	 * $params: element, search, outcome (ok|issued|pending|fail|error), limit, offset
	 * Each returned document also carries ->obj_ref, ->obj_socid and ->soc_name.
	 *
	 * @return IsnetDocument[] newest first
	 */
	public function fetchList($params = array(), &$total = 0)
	{
		$list = array();
		$element = isset($params['element']) ? $params['element'] : self::ELEMENT_INVOICE;
		$table = ($element === self::ELEMENT_SHIPMENT ? 'expedition' : 'facture');
		$search = isset($params['search']) ? trim((string) $params['search']) : '';
		$outcome = isset($params['outcome']) ? (string) $params['outcome'] : '';
		$limit = isset($params['limit']) ? (int) $params['limit'] : 50;
		$offset = isset($params['offset']) ? (int) $params['offset'] : 0;

		$where = " WHERE d.element_type = '".$this->db->escape($element)."'"
			.' AND d.entity IN ('.getEntity('invoice').')';
		if ($search !== '') {
			$e = $this->db->escape($this->db->escapeforlike($search));
			$where .= " AND (d.invoice_number LIKE '%".$e."%' OR d.ettn LIKE '%".$e."%' OR d.external_code LIKE '%".$e."%'"
				." OR o.ref LIKE '%".$e."%' OR s.nom LIKE '%".$e."%')";
		}
		$where .= $this->outcomeSqlFilter($outcome);

		$from = ' FROM '.MAIN_DB_PREFIX.self::TABLE.' as d'
			.' LEFT JOIN '.MAIN_DB_PREFIX.$table.' as o ON o.rowid = d.fk_facture'
			.' LEFT JOIN '.MAIN_DB_PREFIX.'societe as s ON s.rowid = o.fk_soc';

		$res = $this->db->query('SELECT COUNT(*) as nb'.$from.$where);
		$total = $res ? (int) $this->db->fetch_object($res)->nb : 0;

		$sql = 'SELECT d.*, o.ref as obj_ref, o.fk_soc as obj_socid, s.nom as soc_name'.$from.$where
			.' ORDER BY d.rowid DESC'.$this->db->plimit($limit, $offset);
		$res = $this->db->query($sql);
		if (!$res) {
			$this->error = $this->db->lasterror();
			return $list;
		}
		while ($obj = $this->db->fetch_object($res)) {
			$d = new IsnetDocument($this->db);
			$d->hydrate($obj);
			$d->obj_ref = (string) $obj->obj_ref;
			$d->obj_socid = (int) $obj->obj_socid;
			$d->soc_name = (string) $obj->soc_name;
			$list[] = $d;
		}
		return $list;
	}

	/**
	 * SQL translation of outcome(), so the list can be filtered and paged in the database.
	 */
	private function outcomeSqlFilter($outcome)
	{
		$quote = function ($states) {
			return "'".implode("','", array_map(array($this->db, 'escape'), $states))."'";
		};
		$okIn = $quote(self::FINAL_OK);
		$failIn = $quote(self::FINAL_FAIL);
		$basicOkIn = $quote(self::FINAL_OK_WITHOUT_APP_RESPONSE);
		$noAppIn = $quote(self::SCENARIOS_WITHOUT_APP_RESPONSE);
		$hasEttn = "d.ettn IS NOT NULL AND d.ettn <> ''";
		$isOk = "((d.status IN (".$okIn.") OR d.detail_status IN (".$okIn."))"
			." OR (d.scenario IN (".$noAppIn.") AND (d.status IN (".$basicOkIn.") OR d.detail_status IN (".$basicOkIn."))))";
		$isFail = "(d.status IN (".$failIn.") OR d.detail_status IN (".$failIn."))";

		switch ($outcome) {
			case 'ok':
				return ' AND '.$hasEttn.' AND NOT '.$isFail.' AND '.$isOk;
			case 'fail':
				return ' AND '.$hasEttn.' AND '.$isFail;
			case 'pending':
				return ' AND '.$hasEttn.' AND NOT '.$isOk.' AND NOT '.$isFail;
			case 'error':
				return " AND (d.ettn IS NULL OR d.ettn = '')";
			default:
				return '';
		}
	}
}
