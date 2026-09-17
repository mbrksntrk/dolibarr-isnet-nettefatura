<?php
/* Copyright (C) 2026 M. Burak Şentürk <https://buraksenturk.net>
 * Licensed under GPL-3.0-or-later with attribution terms. See COPYING and ATTRIBUTION.md.
 */

require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
dol_include_once('/isnetefatura/class/isnetcoderesolver.class.php');

/**
 * Builds the İşNet Invoice / ArchiveInvoice payload from a Dolibarr customer invoice.
 *
 * Every business rule lives in the preflight() step so that a failing invoice is
 * rejected with a human-readable list of problems before anything is sent.
 */
class IsnetInvoiceMapper
{
	public $db;
	/** @var string[] Problems found by preflight(); each is a translation key optionally followed by ':detail' */
	public $problems = array();
	/** @var string[] Non-blocking remarks from preflight() (same format) */
	public $warnings = array();

	private $resolver;

	/** Dolibarr c_units.code → UN/ECE Rec.20 code accepted by GİB */
	const UNIT_MAP = array(
		'U' => 'C62', 'P' => 'C62', 'SET' => 'SET',
		'KG' => 'KGM', 'G' => 'GRM', 'MG' => 'MGM', 'T' => 'TNE', 'LB' => 'LBR', 'OZ' => 'ONZ',
		'M' => 'MTR', 'DM' => 'DMT', 'CM' => 'CMT', 'MM' => 'MMT', 'FT' => 'FOT', 'IN' => 'INH',
		'M2' => 'MTK', 'CM2' => 'CMK', 'MM2' => 'MMK',
		'M3' => 'MTQ', 'DM3' => 'DMQ', 'CM3' => 'CMQ', 'L' => 'LTR', 'GAL' => 'GLL',
		'S' => 'SEC', 'MI' => 'MIN', 'H' => 'HUR', 'D' => 'DAY', 'W' => 'WEE', 'MO' => 'MON', 'Y' => 'ANN',
	);

	/** GİB KDV tevkifat kodları → (varsayılan oran %, açıklama). Oranlar tebliğle değişebilir; fatura bazında düzenlenebilir. */
	/** special_code used by the TRTevkifat module on its withholding line (see modules/trtevkifat). */
	const TEVKIFAT_LINE_SPECIAL_CODE = 99;

	const TEVKIFAT_CODES = array(
		'601' => array(40, 'Yapım işleri ile bu işlerle birlikte ifa edilen mühendislik-mimarlık ve etüt-proje hizmetleri'),
		'602' => array(90, 'Etüt, plan-proje, danışmanlık, denetim ve benzeri hizmetler'),
		'603' => array(70, 'Makine, teçhizat, demirbaş ve taşıtlara ait tadil, bakım ve onarım hizmetleri'),
		'604' => array(50, 'Yemek servis hizmeti'),
		'605' => array(50, 'Organizasyon hizmeti'),
		'606' => array(90, 'İşgücü temin hizmetleri'),
		'607' => array(90, 'Özel güvenlik hizmeti'),
		'608' => array(90, 'Yapı denetim hizmetleri'),
		'609' => array(70, 'Fason olarak yaptırılan tekstil ve konfeksiyon işleri, çanta ve ayakkabı dikim işleri ve bu işlere aracılık hizmetleri'),
		'610' => array(90, 'Turistik mağazalara verilen müşteri bulma / götürme hizmetleri'),
		'611' => array(90, 'Spor kulüplerinin yayın, reklam ve isim hakkı gelirlerine konu işlemleri'),
		'612' => array(90, 'Temizlik hizmeti'),
		'613' => array(90, 'Çevre ve bahçe bakım hizmetleri'),
		'614' => array(50, 'Servis taşımacılığı hizmeti'),
		'615' => array(70, 'Her türlü baskı ve basım hizmetleri'),
		'616' => array(50, 'Diğer hizmetler (5018 sayılı Kanuna ekli cetveller kapsamındaki idare, kurum ve kuruluşlara)'),
		'617' => array(70, 'Hurda metalden elde edilen külçe teslimleri'),
		'618' => array(70, 'Hurda metalden elde edilenler dışındaki bakır, çinko, alüminyum ve kurşun külçe teslimleri'),
		'619' => array(70, 'Bakır, çinko, alüminyum ve kurşun ürünlerinin teslimi'),
		'620' => array(70, 'İstisnadan vazgeçenlerin hurda ve atık teslimi'),
		'621' => array(90, 'Metal, plastik, lastik, kauçuk, kâğıt ve cam hurda ve atıklardan elde edilen hammadde teslimi'),
		'622' => array(90, 'Pamuk, tiftik, yün ve yapağı ile ham post ve deri teslimleri'),
		'623' => array(50, 'Ağaç ve orman ürünleri teslimi'),
		'624' => array(20, 'Yük taşımacılığı hizmeti'),
		'625' => array(30, 'Ticari reklam hizmetleri'),
		'626' => array(20, 'Diğer teslimler'),
		'627' => array(50, 'Demir-çelik ürünlerinin teslimi'),
	);

	public function __construct($db)
	{
		$this->db = $db;
		$this->resolver = new IsnetCodeResolver($db);
	}

	/**
	 * Withholding (tevkifat) code and rate for this invoice, or null when it is not a withholding invoice.
	 *
	 * @return array|null array('code' => '6xx', 'rate' => percent)
	 */
	public function tevkifat(Facture $inv)
	{
		$code = trim($this->extra($inv, 'isnet_tevkifat_code'));
		$rate = (float) $this->extra($inv, 'isnet_tevkifat_rate', 0);
		if ($code === '') {
			// Fallback: withholding applied with the TRTevkifat module (same GİB code set)
			$code = trim($this->extra($inv, 'trtevkifat_code'));
			$rate = (float) $this->extra($inv, 'trtevkifat_rate', 0);
		}
		if ($code === '') {
			return null;
		}
		if ($rate <= 0 && isset(self::TEVKIFAT_CODES[$code])) {
			$rate = (float) self::TEVKIFAT_CODES[$code][0];
		}
		return array('code' => $code, 'rate' => $rate);
	}

	/**
	 * Credit notes carry negative amounts in Dolibarr; the integrator wants positive figures on an IADE document.
	 */
	private function sign(Facture $inv)
	{
		return (int) $inv->type === Facture::TYPE_CREDIT_NOTE ? -1 : 1;
	}

	/* ------------------------------------------------------------------ helpers */

	/**
	 * GİB codes for the third party's province, district and tax office.
	 *
	 * @return array city_code, town (array|null), tax_office (array|null)
	 */
	public function resolveCodes(Societe $soc)
	{
		$stateCode = (string) ($soc->state_code ?? '');
		$stateName = (string) ($soc->state ?? '');
		if ($stateCode === '' && !empty($soc->state_id)) {
			$st = getState($soc->state_id, 'all');
			if (is_array($st)) {
				$stateCode = (string) ($st['code'] ?? '');
				$stateName = (string) ($st['label'] ?? '');
			}
		}
		$city = $this->resolver->cityCode($stateCode, $stateName);
		return array(
			'city_code' => $city,
			'town' => $city !== '' ? $this->resolver->town($city, $soc->town) : null,
			'tax_office' => $this->resolver->taxOffice($soc->idprof2, $city),
		);
	}

	public static function digitsOnly($s)
	{
		return preg_replace('/\D+/', '', (string) $s);
	}

	public static function isValidTaxCode($s)
	{
		$d = self::digitsOnly($s);
		return strlen($d) === 10 || strlen($d) === 11;
	}

	/**
	 * Final ref: during BILL_VALIDATE the object still carries the PROV ref.
	 */
	public static function finalRef(Facture $inv)
	{
		return !empty($inv->newref) ? $inv->newref : $inv->ref;
	}

	public function unitCode($fk_unit)
	{
		static $cache = array();
		$default = getDolGlobalString('ISNETEFATURA_DEFAULT_UNIT', 'C62');
		if (empty($fk_unit)) {
			return $default;
		}
		if (!isset($cache[$fk_unit])) {
			$cache[$fk_unit] = $default;
			$res = $this->db->query('SELECT code FROM '.MAIN_DB_PREFIX.'c_units WHERE rowid = '.((int) $fk_unit));
			if ($res && ($obj = $this->db->fetch_object($res))) {
				$code = strtoupper($obj->code);
				$cache[$fk_unit] = self::UNIT_MAP[$code] ?? $default;
			}
		}
		return $cache[$fk_unit];
	}

	private function extra(Facture $inv, $key, $default = '')
	{
		$v = $inv->array_options['options_'.$key] ?? '';
		return ($v === '' || $v === null) ? $default : $v;
	}

	/* ------------------------------------------------------------------ decisions */

	public function isDomestic(Societe $soc)
	{
		$domestic = getDolGlobalString('ISNETEFATURA_DOMESTIC_COUNTRY', 'TR');
		return strtoupper((string) $soc->country_code) === strtoupper($domestic);
	}

	/**
	 * How a foreign-customer invoice leaves the country.
	 *
	 * @return string 'none' (domestic) | 'goods' (IHRACAT to customs) | 'service' (foreign e-Arşiv, exempt) | 'mixed' (not allowed)
	 */
	public function exportKind(Facture $inv, Societe $soc)
	{
		if ($this->isDomestic($soc)) {
			return 'none';
		}
		if ($this->extra($inv, 'isnet_scenario') === 'IHRACAT') {
			return 'goods';
		}
		$goods = 0;
		$services = 0;
		foreach ($this->billableLines($inv) as $line) {
			if ((int) $line->product_type === 1) {
				$services++;
			} else {
				$goods++;
			}
		}
		if ($goods > 0 && $services > 0) {
			return 'mixed';
		}
		return $goods > 0 ? 'goods' : 'service';
	}

	public function isExportGoods(Facture $inv, Societe $soc)
	{
		return $this->exportKind($inv, $soc) === 'goods';
	}

	public function scenario(Facture $inv, Societe $soc)
	{
		$s = $this->extra($inv, 'isnet_scenario');
		if ($s !== '') {
			return $s;
		}
		if ($this->exportKind($inv, $soc) === 'goods') {
			return 'IHRACAT';
		}
		// GİB accepts return invoices in the basic scenario only (no accept/reject flow).
		if ((int) $inv->type === Facture::TYPE_CREDIT_NOTE) {
			return 'TEMELFATURA';
		}
		return getDolGlobalString('ISNETEFATURA_DEFAULT_SCENARIO', 'TICARIFATURA');
	}

	public function invoiceType(Facture $inv, Societe $soc = null)
	{
		$t = $this->extra($inv, 'isnet_invoice_type');
		if ($t !== '') {
			return $t;
		}
		if ((int) $inv->type === Facture::TYPE_CREDIT_NOTE) {
			return $this->tevkifat($inv) ? 'TEVKIFATIADE' : 'IADE';
		}
		if ($soc !== null && !$this->isDomestic($soc)) {
			return 'ISTISNA';
		}
		if ($this->tevkifat($inv)) {
			return 'TEVKIFAT';
		}
		return 'SATIS';
	}

	private function transportMode(Facture $inv)
	{
		return $this->extra($inv, 'isnet_transport_mode', getDolGlobalString('ISNETEFATURA_EXPORT_TRANSPORT_MODE', '3'));
	}

	private function packageType(Facture $inv)
	{
		return strtoupper($this->extra($inv, 'isnet_package_type', getDolGlobalString('ISNETEFATURA_EXPORT_PACKAGE_TYPE', 'PK')));
	}

	private function incotermCode(Facture $inv)
	{
		// Facture::label_incoterms holds the long description, not the 3-letter code.
		if (!empty($inv->fk_incoterms)) {
			$res = $this->db->query('SELECT code FROM '.MAIN_DB_PREFIX.'c_incoterms WHERE rowid = '.((int) $inv->fk_incoterms));
			if ($res && ($o = $this->db->fetch_object($res))) {
				return strtoupper($o->code);
			}
		}
		return '';
	}

	private function countryName(Societe $soc)
	{
		if (empty($soc->country_id)) {
			return '';
		}
		$en = new Translate('', $GLOBALS['conf']);
		$en->setDefaultLang('en_US');
		$en->load('dict');
		return mb_strtoupper((string) getCountry($soc->country_id, 0, $this->db, $en), 'UTF-8');
	}

	private function productExtra($fk_product, $key)
	{
		static $cache = array();
		if (empty($fk_product)) {
			return '';
		}
		$ck = $fk_product.':'.$key;
		if (!isset($cache[$ck])) {
			$cache[$ck] = '';
			$res = $this->db->query('SELECT '.$this->db->sanitize($key).' AS v FROM '.MAIN_DB_PREFIX.'product_extrafields WHERE fk_object = '.((int) $fk_product));
			if ($res && ($o = $this->db->fetch_object($res))) {
				$cache[$ck] = trim((string) $o->v);
			}
		}
		return $cache[$ck];
	}

	private function usesForeignCurrency(Facture $inv)
	{
		global $conf;
		return !empty($inv->multicurrency_code) && $inv->multicurrency_code !== $conf->currency && !empty($inv->multicurrency_tx);
	}

	private function currency(Facture $inv)
	{
		global $conf;
		return $this->usesForeignCurrency($inv) ? $inv->multicurrency_code : $conf->currency;
	}

	private function exemption(Facture $inv, Societe $soc = null)
	{
		$kind = $soc ? $this->exportKind($inv, $soc) : 'none';
		$defaults = array(
			'goods' => array('ISNETEFATURA_EXPORT_EXEMPTION_CODE', 'ISNETEFATURA_EXPORT_EXEMPTION_REASON'),
			'service' => array('ISNETEFATURA_EXPORT_SERVICE_EXEMPTION_CODE', 'ISNETEFATURA_EXPORT_SERVICE_EXEMPTION_REASON'),
		);
		list($codeKey, $reasonKey) = $defaults[$kind] ?? array('ISNETEFATURA_ZERO_VAT_EXEMPTION_CODE', 'ISNETEFATURA_ZERO_VAT_EXEMPTION_REASON');
		return array(
			'code' => $this->extra($inv, 'isnet_exemption_code', getDolGlobalString($codeKey)),
			'reason' => $this->extra($inv, 'isnet_exemption_reason', getDolGlobalString($reasonKey)),
		);
	}

	private function billableLines(Facture $inv)
	{
		$out = array();
		foreach ($inv->lines as $line) {
			// 9 = title/subtotal pseudo-lines; qty 0 lines carry no fiscal meaning
			if ((int) $line->product_type === 9 || (float) $line->qty == 0) {
				continue;
			}
			// Withholding line booked by the TRTevkifat module: reported through WitholdingTaxes, never as a product line
			if ((int) $line->special_code === self::TEVKIFAT_LINE_SPECIAL_CODE) {
				continue;
			}
			$out[] = $line;
		}
		return $out;
	}

	/* ------------------------------------------------------------------ preflight */

	/**
	 * @return bool true when the invoice can be sent; see ->problems otherwise
	 */
	public function preflight(Facture $inv, Societe $soc)
	{
		$this->problems = array();
		$this->warnings = array();

		if (in_array((int) $inv->type, array(Facture::TYPE_REPLACEMENT), true)) {
			$this->problems[] = 'IsnetErrReplacementNotSupported';
		}
		$domestic = $this->isDomestic($soc);
		$kind = $this->exportKind($inv, $soc);

		if ($domestic && !self::isValidTaxCode($soc->idprof1)) {
			$this->problems[] = 'IsnetErrReceiverTaxCode';
		}
		$taxCode = self::digitsOnly($soc->idprof1);
		$requireTaxOffice = $domestic && strlen($taxCode) === 10 && getDolGlobalInt('ISNETEFATURA_REQUIRE_TAX_OFFICE', 1);
		if ($requireTaxOffice && trim((string) $soc->idprof2) === '') {
			$this->problems[] = 'IsnetErrReceiverTaxOffice';
		}
		if (trim((string) $soc->address) === '' || trim((string) $soc->town) === '') {
			$this->problems[] = 'IsnetErrReceiverAddress';
		}

		if ($kind === 'mixed') {
			$this->problems[] = 'IsnetErrExportMixedLines';
		}
		if ($kind === 'goods') {
			if ($this->incotermCode($inv) === '') {
				$this->problems[] = 'IsnetErrExportIncoterm';
			}
			if ($this->transportMode($inv) === '' || $this->packageType($inv) === '') {
				$this->problems[] = 'IsnetErrExportShipment';
			}
			if (empty($soc->country_id)) {
				$this->problems[] = 'IsnetErrExportCountry';
			}
			foreach ($this->billableLines($inv) as $i => $line) {
				if ((float) $line->tva_tx != 0) {
					$this->problems[] = 'IsnetErrExportVat:'.($i + 1);
					break;
				}
			}
			foreach ($this->billableLines($inv) as $i => $line) {
				$gtip = self::digitsOnly($this->productExtra($line->fk_product, 'isnet_gtip'));
				if (strlen($gtip) !== 12) {
					$this->problems[] = 'IsnetErrExportGtip:'.($i + 1).' '.($line->product_ref ?: '');
				}
			}
		}
		if ($kind === 'service') {
			foreach ($this->billableLines($inv) as $i => $line) {
				if ((float) $line->tva_tx != 0) {
					$this->problems[] = 'IsnetErrExportVat:'.($i + 1);
					break;
				}
			}
		}

		if ($domestic) {
			$codes = $this->resolveCodes($soc);
			if ($codes['city_code'] === '') {
				$this->problems[] = 'IsnetErrReceiverCityUnresolved:'.trim((string) $soc->town);
			} elseif ($codes['town'] === null) {
				$this->warnings[] = 'IsnetWarnReceiverTownUnresolved:'.trim((string) $soc->town);
			}
			if (trim((string) $soc->idprof2) !== '' && $codes['tax_office'] === null) {
				if ($requireTaxOffice) {
					$this->problems[] = 'IsnetErrReceiverTaxOfficeUnresolved:'.trim((string) $soc->idprof2);
				} else {
					$this->warnings[] = 'IsnetWarnReceiverTaxOfficeUnresolved:'.trim((string) $soc->idprof2);
				}
			}
		}
		$lines = $this->billableLines($inv);
		if (empty($lines)) {
			$this->problems[] = 'IsnetErrNoLines';
		}
		$ex = $this->exemption($inv, $soc);
		foreach ($lines as $i => $line) {
			if ((float) $line->tva_tx == 0 && $ex['code'] === '') {
				$this->problems[] = 'IsnetErrZeroVatNeedsExemption:'.($i + 1);
				break;
			}
			if (trim((string) $line->desc) === '' && trim((string) $line->product_label) === '') {
				$this->problems[] = 'IsnetErrLineNoDescription:'.($i + 1);
			}
		}

		if ((int) $inv->type === Facture::TYPE_CREDIT_NOTE) {
			$src = $this->returnSource($inv);
			if ($src === null) {
				$this->problems[] = 'IsnetErrReturnSourceMissing';
			}
		}

		$tev = $this->tevkifat($inv);
		if ($tev !== null) {
			if (!isset(self::TEVKIFAT_CODES[$tev['code']]) && !preg_match('/^\d{3}$/', $tev['code'])) {
				$this->problems[] = 'IsnetErrTevkifatCode:'.$tev['code'];
			}
			if ($tev['rate'] <= 0 || $tev['rate'] > 100) {
				$this->problems[] = 'IsnetErrTevkifatRate:'.$tev['rate'];
			}
			if (!$domestic) {
				$this->problems[] = 'IsnetErrTevkifatForeign';
			}
			$hasVat = false;
			foreach ($lines as $line) {
				if ((float) $line->tva_tx > 0) {
					$hasVat = true;
				}
			}
			if (!$hasVat) {
				$this->problems[] = 'IsnetErrTevkifatNoVat';
			}
		}

		return empty($this->problems);
	}

	/**
	 * For credit notes: the accepted integrator record of the original invoice.
	 */
	private function returnSource(Facture $inv)
	{
		if (empty($inv->fk_facture_source)) {
			return null;
		}
		dol_include_once('/isnetefatura/class/isnetdocument.class.php');
		$doc = IsnetDocument::fetchAccepted($this->db, $inv->fk_facture_source);
		if (!$doc) {
			return null;
		}
		$orig = new Facture($this->db);
		if ($orig->fetch($inv->fk_facture_source) <= 0) {
			return null;
		}
		return array('doc' => $doc, 'invoice' => $orig);
	}

	/* ------------------------------------------------------------------ payload */

	/**
	 * Fixed receiver for goods exports: the Ministry of Trade's customs inbox.
	 */
	private function customsReceiver()
	{
		$addr = array(
			'BoulevardAveneuStreetName' => 'Üniversiteler Mahallesi Dumlupınar Bulvarı',
			'BuildingNumber' => '151',
			'CityName' => 'Ankara',
			'CityCode' => 6.0,
			'TownName' => 'ÇANKAYA',
			'PostalCode' => 6800.0,
		);
		$town = $this->resolver->town('06', 'Çankaya');
		if ($town) {
			$addr['TownCode'] = (float) $town['code'];
		}
		$to = $this->resolver->taxOffice('ULUS VERGİ DAİRESİ', '06');
		if ($to) {
			$addr['TaxOfficeCode'] = (float) $to['code'];
			$addr['TaxOfficeName'] = $to['name'];
		}
		return array(
			'Address' => $addr,
			'ExternalReceiverCode' => 'GTB',
			'ReceiverName' => getDolGlobalString('ISNETEFATURA_EXPORT_RECEIVER_NAME', 'Ticaret Bakanlığı- Bilgi Teknolojileri Genel Müdürlüğü'),
			'ReceiverTaxCode' => getDolGlobalString('ISNETEFATURA_EXPORT_RECEIVER_VKN', '1460415308'),
		);
	}

	/**
	 * The real foreign buyer, carried in ExportReceiver on customs invoices.
	 */
	private function exportReceiver(Societe $soc)
	{
		$isPerson = !empty($soc->particulier) || (isset($soc->typent_code) && $soc->typent_code === 'TE_PRIVATE');
		// UBL-TR requires both CitySubdivisionName (TownName) and CityName.
		$state = !empty($soc->state_id) ? (string) getState($soc->state_id, 0) : '';
		$r = array(
			'BoulevardAveneuStreetName' => trim((string) $soc->address),
			'CityName' => $state !== '' ? $state : trim((string) $soc->town),
			'CountryName' => $this->countryName($soc),
			'ExternalReceiverCode' => (string) ($soc->code_client ?: $soc->id),
			'OfficialReceiverName' => trim((string) $soc->name),
			'ReceiverName' => trim((string) $soc->name),
			'ReceiverType' => $isPerson ? 'Bireysel' : 'Kurumsal',
			'TownName' => trim((string) $soc->town),
		);
		if (trim((string) $soc->zip) !== '') {
			$r['PostalCode'] = trim((string) $soc->zip);
		}
		if (trim((string) $soc->idprof1) !== '') {
			$r['ReceiverTaxCode'] = trim((string) $soc->idprof1);
		}
		return $r;
	}

	/**
	 * Foreign receiver on a service export (e-Arşiv): no Turkish tax data, country appended to the address.
	 */
	private function foreignReceiver(Societe $soc)
	{
		$country = $this->countryName($soc);
		$addr = array(
			'BoulevardAveneuStreetName' => trim((string) $soc->address).($country !== '' ? ', '.$country : ''),
			'TownName' => trim((string) $soc->town),
			'CityName' => trim((string) $soc->town),
		);
		$state = !empty($soc->state_id) ? (string) getState($soc->state_id, 0) : '';
		if ($state !== '') {
			$addr['CityName'] = $state;
		}
		if (!empty($soc->email)) {
			$addr['EMail'] = $soc->email;
		}
		if (!empty($soc->phone)) {
			$addr['TelephoneNumber'] = $soc->phone;
		}
		$taxCode = self::digitsOnly($soc->idprof1);
		return array(
			'Address' => $addr,
			'ExternalReceiverCode' => (string) ($soc->code_client ?: $soc->id),
			'ReceiverName' => trim((string) $soc->name),
			'ReceiverTaxCode' => self::isValidTaxCode($taxCode) ? $taxCode : getDolGlobalString('ISNETEFATURA_FOREIGN_TAX_CODE', '1111111111'),
		);
	}

	public function receiver(Societe $soc)
	{
		$addr = array(
			'BoulevardAveneuStreetName' => trim((string) $soc->address),
			'TownName' => trim((string) $soc->town),
		);
		$state = !empty($soc->state_id) ? (string) getState($soc->state_id, 0) : '';
		$addr['CityName'] = $state !== '' ? $state : trim((string) $soc->town);

		// The integrator builds the UBL address from these codes; inline names alone are dropped.
		$codes = $this->resolveCodes($soc);
		if ($codes['city_code'] !== '') {
			$addr['CityCode'] = (float) $codes['city_code'];
		}
		if ($codes['town'] !== null) {
			$addr['TownCode'] = (float) $codes['town']['code'];
			$addr['TownName'] = $codes['town']['name'];
		}
		if ($codes['tax_office'] !== null) {
			$addr['TaxOfficeCode'] = (float) $codes['tax_office']['code'];
			$addr['TaxOfficeName'] = $codes['tax_office']['name'];
		} elseif (trim((string) $soc->idprof2) !== '') {
			$addr['TaxOfficeName'] = trim((string) $soc->idprof2);
		}
		$zip = self::digitsOnly($soc->zip);
		if ($zip !== '') {
			$addr['PostalCode'] = (float) $zip;
		}
		if (!empty($soc->email)) {
			$addr['EMail'] = $soc->email;
		}
		if (!empty($soc->phone)) {
			$addr['TelephoneNumber'] = $soc->phone;
		}
		if (!empty($soc->url)) {
			$addr['WebSite'] = $soc->url;
		}
		return array(
			'Address' => $addr,
			'ExternalReceiverCode' => (string) ($soc->code_client ?: $soc->id),
			'ReceiverName' => trim((string) $soc->name),
			'ReceiverTaxCode' => self::digitsOnly($soc->idprof1),
		);
	}

	/**
	 * Customs shipment block required on every line of a goods export.
	 */
	private function exportDelivery(Facture $inv, Societe $soc, $gtip)
	{
		$addr = array(
			'CityName' => trim((string) $soc->town),
			'CitySubdivisionName' => trim((string) $soc->town),
			'CountryName' => $this->countryName($soc),
			'StreetName' => trim((string) $soc->address),
		);
		$state = !empty($soc->state_id) ? (string) getState($soc->state_id, 0) : '';
		if ($state !== '') {
			$addr['CityName'] = $state;
		}
		if (trim((string) $soc->zip) !== '') {
			$addr['PostalCode'] = trim((string) $soc->zip);
		}
		$count = (int) $this->extra($inv, 'isnet_package_count', 1);
		return array('Delivery' => array(array(
			'DeliveryAddress' => $addr,
			'DeliveryTermCode' => $this->incotermCode($inv),
			'Shipment' => array(
				'GtipNoList' => array('string' => array($gtip)),
				'ShipmentPackageList' => array('ShipmentPackage' => array(array(
					'PackageId' => '1',
					'PackageQuantity' => (float) max(1, $count),
					'PackagingTypeCode' => $this->packageType($inv),
				))),
				'ShipmentStageList' => array('ShipmentStage' => array(array(
					'TransportModeCode' => $this->transportMode($inv),
				))),
			),
		)));
	}

	private function lines(Facture $inv, $currency, Societe $soc)
	{
		$foreign = $this->usesForeignCurrency($inv);
		$ex = $this->exemption($inv, $soc);
		$exportGoods = $this->exportKind($inv, $soc) === 'goods';
		$tevkifat = $this->tevkifat($inv);
		$sign = $this->sign($inv);
		$out = array();
		$n = 0;
		foreach ($this->billableLines($inv) as $line) {
			$n++;
			$qty = (float) $line->qty;
			$unitPrice = $sign * ($foreign ? (float) $line->multicurrency_subprice : (float) $line->subprice);
			$lineHt = $sign * ($foreign ? (float) $line->multicurrency_total_ht : (float) $line->total_ht);
			$lineTva = $sign * ($foreign ? (float) $line->multicurrency_total_tva : (float) $line->total_tva);
			$gross = round($unitPrice * $qty, 4);
			$discountAmount = max(0, round($gross - $lineHt, 4));

			$name = trim((string) $line->product_label);
			$desc = trim(dol_string_nohtmltag((string) $line->desc));
			if ($name === '') {
				$name = $desc;
			}

			$detail = array(
				'CurrencyCode' => $currency,
				'LineExtensionAmount' => $lineHt,
				'LineId' => (string) $n,
				'Product' => array(
					'ExternalProductCode' => (string) ($line->product_ref ?: ('L'.$n)),
					'MeasureUnit' => $this->unitCode($line->fk_unit),
					'ProductCode' => (string) ($line->product_ref ?: ''),
					'ProductName' => dol_trunc($name, 250, 'right', 'UTF-8', 1),
					'UnitPrice' => $unitPrice,
				),
				'Quantity' => $qty,
				'VATAmount' => $lineTva,
				'VATRate' => (float) $line->tva_tx,
			);
			if ($desc !== '' && $desc !== $name) {
				$detail['Note'] = dol_trunc($desc, 500, 'right', 'UTF-8', 1);
			}
			if ((float) $line->remise_percent > 0) {
				$detail['DiscountRate'] = (float) $line->remise_percent;
				$detail['DiscountAmount'] = $discountAmount;
			}
			if ((float) $line->tva_tx == 0) {
				$detail['TaxExemptionReasonCode'] = $ex['code'];
				if ($ex['reason'] !== '') {
					$detail['TaxExemptionReason'] = $ex['reason'];
				}
			}
			$mensei = strtoupper($this->productExtra($line->fk_product, 'isnet_mensei'));
			if ($mensei !== '') {
				$detail['Mensei'] = $mensei;
			}
			if ($exportGoods) {
				$gtip = self::digitsOnly($this->productExtra($line->fk_product, 'isnet_gtip'));
				$detail['DeliveryList'] = $this->exportDelivery($inv, $soc, $gtip);
			}
			if ($tevkifat && $lineTva > 0) {
				$detail['WitholdingTaxes'] = array('Tax' => array(array(
					'TaxAmount' => round($lineTva * $tevkifat['rate'] / 100, 2),
					'TaxCode' => $tevkifat['code'],
					'TaxRate' => $tevkifat['rate'],
				)));
			}
			$out[] = $detail;
		}
		return $out;
	}

	private function totals(Facture $inv, array $lines)
	{
		$foreign = $this->usesForeignCurrency($inv);
		$discount = 0.0;
		$lineExt = 0.0;
		foreach ($lines as $l) {
			$discount += (float) ($l['DiscountAmount'] ?? 0);
			$lineExt += (float) $l['LineExtensionAmount'];
		}
		$sign = $this->sign($inv);
		// Sum billable lines rather than reading $inv->total_*: a TRTevkifat withholding line
		// already lowers the Dolibarr totals, while UBL wants gross totals and a separate payable.
		$ttc = $tva = 0.0;
		foreach ($this->billableLines($inv) as $line) {
			$ttc += $sign * ($foreign ? (float) $line->multicurrency_total_ttc : (float) $line->total_ttc);
			$tva += $sign * ($foreign ? (float) $line->multicurrency_total_tva : (float) $line->total_tva);
		}
		$withheld = 0.0;
		foreach ($lines as $l) {
			foreach ($l['WitholdingTaxes']['Tax'] ?? array() as $w) {
				$withheld += (float) $w['TaxAmount'];
			}
		}
		return array(
			'TotalDiscountAmount' => round($discount, 2),
			'TotalLineExtensionAmount' => round($lineExt, 2),
			'TotalPayableAmount' => round($ttc - $withheld, 2),
			'TotalTaxInclusiveAmount' => round($ttc, 2),
			'TotalVATAmount' => round($tva, 2),
		);
	}

	private function financialAccount(Facture $inv, $currency)
	{
		require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';
		$id = getDolGlobalInt('ISNETEFATURA_BANK_ACCOUNT', 0);
		if ($id <= 0) {
			$id = (int) $inv->fk_account;
		}
		if ($id <= 0) {
			return null;
		}
		$acc = new Account($this->db);
		if ($acc->fetch($id) <= 0 || trim((string) $acc->iban) === '') {
			return null;
		}
		return array('FinancialAccount' => array(array(
			'Currency' => $acc->currency_code ?: $currency,
			'Iban' => preg_replace('/\s+/', '', $acc->iban),
			'PaymentNote' => $acc->bank ?: '',
		)));
	}

	/**
	 * Despatch notes referenced by the invoice: linked Dolibarr shipments plus the manual extra field.
	 */
	private function dispatchList(Facture $inv)
	{
		$out = array();
		$manualNo = trim($this->extra($inv, 'isnet_dispatch_number'));
		if ($manualNo !== '') {
			$d = $this->extra($inv, 'isnet_dispatch_date');
			$ts = $d ? (is_numeric($d) ? (int) $d : dol_stringtotime($d)) : $inv->date;
			$out[] = array('DispatchDate' => dol_print_date($ts, '%Y-%m-%d', 'tzserver').'T00:00:00', 'DispatchNumber' => $manualNo);
		}
		if (isModEnabled('expedition')) {
			$inv->fetchObjectLinked();
			foreach ((array) ($inv->linkedObjects['shipping'] ?? array()) as $exp) {
				if (empty($exp->ref) || (int) $exp->statut < 1) {
					continue;
				}
				$ts = !empty($exp->date_delivery) ? $exp->date_delivery : (!empty($exp->date_shipping) ? $exp->date_shipping : $exp->date_creation);
				$out[] = array('DispatchDate' => dol_print_date($ts ?: $inv->date, '%Y-%m-%d', 'tzserver').'T00:00:00', 'DispatchNumber' => $exp->ref);
			}
		}
		return $out;
	}

	/**
	 * Dolibarr's own generated invoice PDF, when the option is on and the file exists.
	 */
	private function attachments(Facture $inv)
	{
		global $conf;
		if (!getDolGlobalInt('ISNETEFATURA_ATTACH_PDF', 0)) {
			return array();
		}
		$ref = dol_sanitizeFileName(self::finalRef($inv));
		$dir = ($conf->facture->multidir_output[$inv->entity ?: $conf->entity] ?? $conf->facture->dir_output).'/'.$ref;
		$file = $dir.'/'.$ref.'.pdf';
		if (!is_readable($file) || filesize($file) > 5 * 1024 * 1024) {
			return array();
		}
		return array(array('FileContent' => file_get_contents($file), 'FileName' => $ref.'.pdf'));
	}

	/**
	 * Company-specific XSLT (branding) stored under documents/isnetefatura/, if configured.
	 */
	public static function xsltTemplate($docType)
	{
		global $conf;
		$name = getDolGlobalString($docType === 'EARSIV' ? 'ISNETEFATURA_XSLT_EARSIV' : 'ISNETEFATURA_XSLT_EFATURA');
		if ($name === '') {
			return '';
		}
		$file = DOL_DATA_ROOT.'/isnetefatura/'.basename($name);
		return is_readable($file) ? file_get_contents($file) : '';
	}

	private function publicReceiver(Societe $soc)
	{
		$codes = $this->resolveCodes($soc);
		$p = array(
			'PublicReceiverCountry' => 'Türkiye',
			'PublicReceiverTitle' => trim((string) $soc->name),
			'PublicReceiverVkn' => self::digitsOnly($soc->idprof1),
		);
		if ($codes['city_code'] !== '') {
			$p['PublicReceiverCity'] = (float) $codes['city_code'];
		}
		return $p;
	}

	private function webSellingInfo(Facture $inv)
	{
		if (!(int) $this->extra($inv, 'isnet_web_sale', 0)) {
			return null;
		}
		$w = array(
			'PaymentType' => $this->extra($inv, 'isnet_web_payment_type', 'DIGER'),
			'WebAddress' => getDolGlobalString('ISNETEFATURA_WEB_ADDRESS'),
		);
		foreach (array('isnet_web_payment_date' => 'PaymentDate', 'isnet_web_sending_date' => 'SendingDate') as $k => $field) {
			$d = $this->extra($inv, $k);
			if ($d !== '') {
				$ts = is_numeric($d) ? (int) $d : dol_stringtotime($d);
				$w[$field] = dol_print_date($ts, '%Y-%m-%d', 'tzserver').'T00:00:00';
			}
		}
		if (!isset($w['PaymentDate'])) {
			$w['PaymentDate'] = dol_print_date($inv->date, '%Y-%m-%d', 'tzserver').'T00:00:00';
		}
		$mediator = trim($this->extra($inv, 'isnet_web_mediator'));
		if ($mediator !== '') {
			$w['PaymentMediatorName'] = $mediator;
		}
		$carrier = trim($this->extra($inv, 'isnet_web_carrier'));
		if ($carrier !== '') {
			$w['Carrier'] = array('CarrierName' => $carrier, 'VknTckn' => self::digitsOnly($this->extra($inv, 'isnet_web_carrier_vkn')));
		}
		return $w;
	}

	private function notes(Facture $inv)
	{
		$notes = array();
		$prefix = trim(getDolGlobalString('ISNETEFATURA_NOTE_PREFIX'));
		if ($prefix !== '') {
			$notes[] = $prefix;
		}
		$pub = trim(dol_string_nohtmltag((string) $inv->note_public));
		if ($pub !== '') {
			foreach (preg_split('/\r\n|\r|\n/', $pub) as $ln) {
				$ln = trim($ln);
				if ($ln !== '') {
					$notes[] = dol_trunc($ln, 500, 'right', 'UTF-8', 1);
				}
			}
		}
		return $notes;
	}

	private function invoiceDateTime(Facture $inv)
	{
		$day = dol_print_date($inv->date, '%Y-%m-%d', 'tzserver');
		$today = dol_print_date(dol_now(), '%Y-%m-%d', 'tzserver');
		$time = $day === $today ? dol_print_date(dol_now(), '%H:%M:%S', 'tzserver') : '12:00:00';
		return $day.'T'.$time;
	}

	/**
	 * Common part shared by e-Fatura and e-Arşiv payloads.
	 */
	private function base(Facture $inv, Societe $soc)
	{
		$currency = $this->currency($inv);
		$lines = $this->lines($inv, $currency, $soc);
		$kind = $this->exportKind($inv, $soc);

		if ($kind === 'goods') {
			$receiver = $this->customsReceiver();
		} elseif ($kind === 'service') {
			$receiver = $this->foreignReceiver($soc);
		} else {
			$receiver = $this->receiver($soc);
		}

		$p = array(
			'CurrencyCode' => $currency,
			'InvoiceDate' => $this->invoiceDateTime($inv),
			'InvoiceDetails' => array('InvoiceDetail' => $lines),
			'InvoiceType' => $this->invoiceType($inv, $soc),
			'Receiver' => $receiver,
		);
		if ($kind === 'goods') {
			$p['ExportReceiver'] = $this->exportReceiver($soc);
		}
		$p = array_merge($p, $this->totals($inv, $lines));

		if (!empty($inv->date_lim_reglement)) {
			$p['LastPaymentDate'] = dol_print_date($inv->date_lim_reglement, '%Y-%m-%d', 'tzserver').'T00:00:00';
		}
		if ($this->usesForeignCurrency($inv)) {
			// Dolibarr: 1 main = tx foreign. GİB: 1 foreign = X main.
			$p['CrossRate'] = round(1 / (float) $inv->multicurrency_tx, 4);
			$p['CrossRateDate'] = dol_print_date($inv->date, '%Y-%m-%d', 'tzserver').'T00:00:00';
		}
		$fa = $this->financialAccount($inv, $currency);
		if ($fa) {
			$p['FinancialAccount'] = $fa;
		}
		$notes = $this->notes($inv);
		if (!empty($notes)) {
			$p['Notes'] = array('string' => $notes);
		}
		$ex = $this->exemption($inv, $soc);
		if ($this->hasZeroVatLine($lines) && $ex['code'] !== '') {
			if ($ex['reason'] !== '') {
				$p['TaxExemptionReason'] = $ex['reason'];
			}
			$p['Exemptions'] = array('Exemption' => array(array(
				'TaxExemptionReasonCode' => $ex['code'],
				'TaxExemptionReasonName' => $ex['reason'],
			)));
		}
		if (!empty($inv->ref_client)) {
			$p['OrderNumber'] = dol_trunc($inv->ref_client, 50, 'right', 'UTF-8', 1);
			$p['OrderDate'] = dol_print_date($inv->date, '%Y-%m-%d', 'tzserver').'T00:00:00';
		}
		if ($this->invoiceType($inv, $soc) === 'IADE') {
			$src = $this->returnSource($inv);
			if ($src) {
				$p['ReturnInvoiceNumber'] = $src['doc']->invoice_number;
				$p['ReturnInvoiceDate'] = dol_print_date($src['invoice']->date, '%Y-%m-%d', 'tzserver').'T00:00:00';
			}
		}
		$dispatches = $this->dispatchList($inv);
		if (!empty($dispatches)) {
			$p['DispatchList'] = array('Dispatch' => $dispatches);
		}
		$attachments = $this->attachments($inv);
		if (!empty($attachments)) {
			$p['InvoiceAttachments'] = array('InvoiceAttachment' => $attachments);
		}
		return $p;
	}

	private function hasZeroVatLine(array $lines)
	{
		foreach ($lines as $l) {
			if ((float) $l['VATRate'] == 0) {
				return true;
			}
		}
		return false;
	}

	/**
	 * e-Fatura payload (receiver is a registered taxpayer).
	 */
	public function toEfatura(Facture $inv, Societe $soc, $inboxTag, $externalCode = '')
	{
		$p = $this->base($inv, $soc);
		$p['ExternalInvoiceCode'] = $externalCode !== '' ? $externalCode : self::finalRef($inv);
		$p['ScenarioType'] = $this->scenario($inv, $soc);
		$p['ReceiverInboxTag'] = $inboxTag;
		if ($p['ScenarioType'] === 'KAMU') {
			$p['PublicReceiver'] = $this->publicReceiver($soc);
		}
		$xslt = self::xsltTemplate('EFATURA');
		if ($xslt !== '') {
			$p['XsltTemplate'] = $xslt;
		}
		if ($this->invoiceType($inv, $soc) === 'IADE') {
			$src = $this->returnSource($inv);
			if ($src) {
				$p['ReturnInvoiceETTN'] = $src['doc']->ettn;
			}
		}
		return $p;
	}

	/**
	 * e-Arşiv payload (receiver is not a registered taxpayer).
	 */
	public function toEarsiv(Facture $inv, Societe $soc, $externalCode = '')
	{
		$p = $this->base($inv, $soc);
		// ArchiveInvoice uses ArrayOfArchiveInvoiceDetail; same fields, different element name.
		$p['InvoiceDetails'] = array('ArchiveInvoiceDetail' => $p['InvoiceDetails']['InvoiceDetail']);
		$p['ExternalArchiveInvoiceCode'] = $externalCode !== '' ? $externalCode : self::finalRef($inv);
		$p['SendMailAutomatically'] = getDolGlobalInt('ISNETEFATURA_EARSIV_SEND_MAIL', 1) > 0 && !empty($soc->email);
		$p['Receiver']['RecipientType'] = 'EARSIV';
		$p['Receiver']['SendingType'] = !empty($soc->email) ? 'ELEKTRONIK' : 'KAGIT';
		$web = $this->webSellingInfo($inv);
		if ($web !== null) {
			$p['WebSellingInfo'] = $web;
		}
		$xslt = self::xsltTemplate('EARSIV');
		if ($xslt !== '') {
			$p['XsltTemplate'] = $xslt;
		}
		return $p;
	}
}
