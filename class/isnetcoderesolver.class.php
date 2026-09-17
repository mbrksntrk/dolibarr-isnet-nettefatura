<?php
/* Copyright (C) 2026 M. Burak Şentürk <https://buraksenturk.net>
 * Licensed under GPL-3.0-or-later with attribution terms. See COPYING and ATTRIBUTION.md.
 */

dol_include_once('/isnetefatura/class/isnetclient.class.php');

/**
 * Resolves GİB city / town / tax office codes from the free-text values stored on
 * Dolibarr third parties, using a locally cached copy of the integrator's lists.
 *
 * The UBL built by the integrator only carries what these codes resolve to; names
 * sent inline are ignored, so an unresolved code means an empty field on the invoice.
 */
class IsnetCodeResolver
{
	const TABLE = 'isnetefatura_code';
	const TYPE_CITY = 'CITY';
	const TYPE_TOWN = 'TOWN';
	const TYPE_TAXOFFICE = 'TAXOFFICE';

	public $db;
	public $error = '';

	public function __construct($db)
	{
		$this->db = $db;
	}

	/* ------------------------------------------------------------------ normalisation */

	/**
	 * Upper-case, Turkish letters folded to ASCII, punctuation removed, "VD"/"V.D." expanded.
	 */
	public static function normalize($s)
	{
		$s = trim((string) $s);
		if ($s === '') {
			return '';
		}
		$s = mb_strtoupper($s, 'UTF-8');
		$s = strtr($s, array(
			'İ' => 'I', 'I' => 'I', 'Ş' => 'S', 'Ğ' => 'G', 'Ü' => 'U', 'Ö' => 'O', 'Ç' => 'C', 'Â' => 'A', 'Î' => 'I', 'Û' => 'U',
			'ı' => 'I', 'i' => 'I', 'ş' => 'S', 'ğ' => 'G', 'ü' => 'U', 'ö' => 'O', 'ç' => 'C', 'â' => 'A', 'î' => 'I', 'û' => 'U',
		));
		$s = preg_replace('/[^A-Z0-9 ]+/', ' ', $s);
		$s = preg_replace('/\bV\s*D\b/', 'VERGI DAIRESI', $s);
		$s = preg_replace('/\bVER\s*DAIRESI\b|\bVERGI\s*D\b/', 'VERGI DAIRESI', $s);
		return trim(preg_replace('/\s+/', ' ', $s));
	}

	/* ------------------------------------------------------------------ cache maintenance */

	public function count($type = null)
	{
		$sql = 'SELECT COUNT(*) AS n FROM '.MAIN_DB_PREFIX.self::TABLE;
		if ($type) {
			$sql .= " WHERE code_type = '".$this->db->escape($type)."'";
		}
		$res = $this->db->query($sql);
		$obj = $res ? $this->db->fetch_object($res) : null;
		return $obj ? (int) $obj->n : 0;
	}

	public function lastRefresh()
	{
		$res = $this->db->query('SELECT MAX(tms) AS t FROM '.MAIN_DB_PREFIX.self::TABLE);
		$obj = $res ? $this->db->fetch_object($res) : null;
		return ($obj && $obj->t) ? $this->db->jdate($obj->t) : 0;
	}

	public function isStale($maxAgeDays = 30)
	{
		if ($this->count() === 0) {
			return true;
		}
		$last = $this->lastRefresh();
		return $last === 0 || (dol_now() - $last) > $maxAgeDays * 86400;
	}

	/**
	 * Download all three lists from the integrator and replace the local cache.
	 *
	 * @return int >0 number of rows stored, <0 on error
	 */
	public function refresh()
	{
		$client = new IsnetClient($this->db);
		$rows = array();

		$r = $client->call(IsnetClient::SERVICE_ADDRESSBOOK, 'GetCityList');
		if ($r === null || $client->error !== '') {
			$this->error = 'GetCityList: '.$client->error;
			return -1;
		}
		foreach (IsnetClient::toList($r->CityCodeList ?? null, 'City') as $c) {
			$rows[] = array(self::TYPE_CITY, (string) $c->CityCode, (string) $c->CityCode, (string) $c->CityName);
		}

		$r = $client->call(IsnetClient::SERVICE_ADDRESSBOOK, 'GetTownList', array('CityCode' => ''));
		if ($r === null || $client->error !== '') {
			$this->error = 'GetTownList: '.$client->error;
			return -1;
		}
		foreach (IsnetClient::toList($r->TownCodeList ?? null, 'Town') as $t) {
			$rows[] = array(self::TYPE_TOWN, (string) $t->CityCode, (string) $t->TownCode, (string) $t->TownName);
		}

		$r = $client->call(IsnetClient::SERVICE_ADDRESSBOOK, 'GetTaxOfficeList');
		if ($r === null || $client->error !== '') {
			$this->error = 'GetTaxOfficeList: '.$client->error;
			return -1;
		}
		foreach (IsnetClient::toList($r->TaxOffice ?? null, 'TaxOffice') as $t) {
			$rows[] = array(self::TYPE_TAXOFFICE, (string) $t->CityCode, (string) $t->TaxCode, (string) $t->TaxOfficeName);
		}

		if (count($rows) < 100) {
			$this->error = 'Suspiciously small code lists ('.count($rows).' rows), cache not replaced';
			return -1;
		}

		$this->db->begin();
		if (!$this->db->query('DELETE FROM '.MAIN_DB_PREFIX.self::TABLE)) {
			$this->db->rollback();
			$this->error = $this->db->lasterror();
			return -1;
		}
		$n = 0;
		foreach ($rows as $row) {
			list($type, $city, $code, $name) = $row;
			if ($code === '' || $name === '') {
				continue;
			}
			$sql = 'INSERT INTO '.MAIN_DB_PREFIX.self::TABLE.' (code_type, city_code, code, name, name_norm) VALUES ('
				."'".$this->db->escape($type)."', "
				.($city !== '' ? "'".$this->db->escape($city)."'" : 'NULL').', '
				."'".$this->db->escape($code)."', "
				."'".$this->db->escape($name)."', "
				."'".$this->db->escape(self::normalize($name))."')";
			if (!$this->db->query($sql)) {
				// duplicates in the source lists are not fatal
				if ($this->db->lasterrno() !== 'DB_ERROR_RECORD_ALREADY_EXISTS') {
					$this->db->rollback();
					$this->error = $this->db->lasterror();
					return -1;
				}
				continue;
			}
			$n++;
		}
		$this->db->commit();
		dol_syslog(__METHOD__.' stored '.$n.' code rows', LOG_INFO);
		return $n;
	}

	private function ensureFresh()
	{
		if ($this->count() === 0) {
			$this->refresh();
		}
	}

	/* ------------------------------------------------------------------ lookups */

	private function findOne($type, $cityCode, $nameNorm, $exact = true)
	{
		$sql = 'SELECT code, name FROM '.MAIN_DB_PREFIX.self::TABLE
			." WHERE code_type = '".$this->db->escape($type)."'";
		if ($cityCode !== null && $cityCode !== '') {
			$sql .= " AND city_code = '".$this->db->escape(self::padCity($cityCode))."'";
		}
		if ($exact) {
			$sql .= " AND name_norm = '".$this->db->escape($nameNorm)."'";
		} else {
			$sql .= " AND name_norm LIKE '".$this->db->escape($nameNorm)."%'";
		}
		$sql .= ' ORDER BY LENGTH(name_norm) LIMIT 2';
		$res = $this->db->query($sql);
		if (!$res) {
			return null;
		}
		$first = $this->db->fetch_object($res);
		if (!$first) {
			return null;
		}
		if (!$exact && $this->db->fetch_object($res)) {
			return null; // ambiguous prefix match
		}
		return $first;
	}

	public static function padCity($code)
	{
		$code = preg_replace('/\D+/', '', (string) $code);
		return $code === '' ? '' : str_pad($code, 2, '0', STR_PAD_LEFT);
	}

	/**
	 * @param  string $stateCode  Dolibarr state code e.g. "TR-34" (preferred, encoding-proof)
	 * @param  string $cityName   Fallback: province name
	 * @return string             Two-digit city code or ''
	 */
	public function cityCode($stateCode, $cityName = '')
	{
		if (preg_match('/^TR-(\d{2})$/i', (string) $stateCode, $m)) {
			return $m[1];
		}
		$norm = self::normalize($cityName);
		if ($norm === '') {
			return '';
		}
		$this->ensureFresh();
		$hit = $this->findOne(self::TYPE_CITY, null, $norm);
		return $hit ? self::padCity($hit->code) : '';
	}

	/**
	 * @return array|null array('code' => , 'name' => ) or null
	 */
	public function town($cityCode, $townName)
	{
		$norm = self::normalize($townName);
		if ($norm === '' || $cityCode === '') {
			return null;
		}
		$this->ensureFresh();
		$hit = $this->findOne(self::TYPE_TOWN, $cityCode, $norm) ?: $this->findOne(self::TYPE_TOWN, $cityCode, $norm, false);
		return $hit ? array('code' => $hit->code, 'name' => $hit->name) : null;
	}

	/**
	 * Accepts "MECİDİYEKÖY VERGİ DAİRESİ", "Mecidiyeköy VD", "MECIDIYEKOY" or the numeric code itself.
	 *
	 * @return array|null array('code' => , 'name' => ) or null
	 */
	public function taxOffice($taxOfficeText, $cityCode = '')
	{
		$raw = trim((string) $taxOfficeText);
		if ($raw === '') {
			return null;
		}
		$this->ensureFresh();

		if (preg_match('/^\d{4,6}$/', $raw)) {
			$res = $this->db->query('SELECT code, name FROM '.MAIN_DB_PREFIX.self::TABLE." WHERE code_type = 'TAXOFFICE' AND code = '".$this->db->escape($raw)."'");
			$hit = $res ? $this->db->fetch_object($res) : null;
			return $hit ? array('code' => $hit->code, 'name' => $hit->name) : null;
		}

		$norm = self::normalize($raw);
		$candidates = array($norm);
		if (strpos($norm, 'VERGI DAIRESI') === false) {
			$candidates[] = $norm.' VERGI DAIRESI';
		}
		foreach (array($cityCode, '') as $city) {
			foreach ($candidates as $cand) {
				$hit = $this->findOne(self::TYPE_TAXOFFICE, $city, $cand);
				if ($hit) {
					return array('code' => $hit->code, 'name' => $hit->name);
				}
			}
			// prefix ("MECIDIYEKOY" → "MECIDIYEKOY VERGI DAIRESI") only when unambiguous
			$hit = $this->findOne(self::TYPE_TAXOFFICE, $city, $norm, false);
			if ($hit) {
				return array('code' => $hit->code, 'name' => $hit->name);
			}
			if ($city === '') {
				break;
			}
		}
		return null;
	}
}
