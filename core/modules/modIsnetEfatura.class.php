<?php
/* Copyright (C) 2026 M. Burak Şentürk <https://buraksenturk.net>
 * Licensed under GPL-3.0-or-later with attribution terms. See COPYING and ATTRIBUTION.md.
 */

include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

class modIsnetEfatura extends DolibarrModules
{
	public function __construct($db)
	{
		global $conf, $langs;

		$this->db = $db;
		$this->numero = 500130;
		$this->rights_class = 'isnetefatura';
		$this->family = 'financial';
		$this->module_position = '90';
		$this->name = preg_replace('/^mod/i', '', get_class($this));
		$this->description = 'İşNet e-Fatura / e-Arşiv entegrasyonu (Türkiye GİB özel entegratör)';
		$this->descriptionlong = 'Dolibarr müşteri faturalarını İşNet SOAP web servisi üzerinden e-Fatura veya e-Arşiv olarak GİB\'e iletir.';
		$this->editor_name = 'M. Burak Şentürk';
		$this->editor_url = 'https://buraksenturk.net';
		$this->version = '0.4.0';
		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);
		$this->picto = 'bill';

		$this->module_parts = array(
			'triggers' => 1,
			'hooks' => array('aimcp'),
		);

		$this->dirs = array('/isnetefatura/temp');
		$this->config_page_url = array('setup.php@isnetefatura');

		$this->depends = array('modFacture', 'modSociete');
		$this->requiredby = array();
		$this->conflictwith = array();
		$this->langfiles = array('isnetefatura@isnetefatura');
		$this->phpmin = array(8, 1);
		$this->need_dolibarr_version = array(20, 0);

		$this->const = array(
			// Connection
			array('ISNETEFATURA_ENV', 'chaine', 'test', 'test | prod', 0, 'current', 0),
			array('ISNETEFATURA_URL_INVOICE_TEST', 'chaine', 'https://einvoiceservicetest.isnet.net.tr/InvoiceService/ServiceContract/InvoiceService.svc', '', 0, 'current', 0),
			array('ISNETEFATURA_URL_ADDRESSBOOK_TEST', 'chaine', 'https://einvoiceservicetest.isnet.net.tr/AddressBookService/ServiceContract/AddressBookService.svc', '', 0, 'current', 0),
			array('ISNETEFATURA_URL_INVOICE_PROD', 'chaine', '', '', 0, 'current', 0),
			array('ISNETEFATURA_URL_ADDRESSBOOK_PROD', 'chaine', '', '', 0, 'current', 0),
			array('ISNETEFATURA_COMPANY_VKN', 'chaine', '', 'Boş ise MAIN_INFO_SIREN kullanılır', 0, 'current', 0),
			array('ISNETEFATURA_TEST_COMPANY_VKN', 'chaine', '', 'Test ortamında gönderici olarak kullanılacak VKN', 0, 'current', 0),
			array('ISNETEFATURA_VENDOR_NUMBER', 'chaine', '', 'CompanyVendorNumber (bayi/şube), opsiyonel', 0, 'current', 0),
			array('ISNETEFATURA_TIMEOUT', 'chaine', '60', 'SOAP zaman aşımı (sn)', 0, 'current', 0),
			array('ISNETEFATURA_DEBUG', 'chaine', '0', '1 ise tam SOAP XML dolibarr.log\'a yazılır', 0, 'current', 0),
			array('ISNETEFATURA_CRON_USER_ID', 'chaine', '1', 'Cron kaynakli denetim kayitlarinda kullanilacak kullanici id', 0, 'current', 0),
			// Behaviour
			array('ISNETEFATURA_AUTOSEND_ON_VALIDATE', 'chaine', '1', 'Fatura onaylanınca otomatik gönder', 0, 'current', 0),
			array('ISNETEFATURA_BLOCK_VALIDATE_ON_ERROR', 'chaine', '0', 'Gönderim hatasında onayı geri al', 0, 'current', 0),
			array('ISNETEFATURA_TAXPAYER_LOOKUP', 'chaine', 'cache', 'always | cache | manual', 0, 'current', 0),
			array('ISNETEFATURA_TAXPAYER_CACHE_DAYS', 'chaine', '7', 'Mükellef sorgusu önbellek süresi (gün)', 0, 'current', 0),
			array('ISNETEFATURA_REQUIRE_TAX_OFFICE', 'chaine', '1', 'VKN\'li alıcıda vergi dairesi zorunlu', 0, 'current', 0),
			array('ISNETEFATURA_DOMESTIC_COUNTRY', 'chaine', 'TR', 'Yurtiçi sayılan ülke kodu', 0, 'current', 0),
			// Invoice defaults
			array('ISNETEFATURA_DEFAULT_SCENARIO', 'chaine', 'TICARIFATURA', 'TEMELFATURA | TICARIFATURA', 0, 'current', 0),
			array('ISNETEFATURA_DEFAULT_UNIT', 'chaine', 'C62', 'Birim eşleşmeyince kullanılacak UN/ECE kodu', 0, 'current', 0),
			array('ISNETEFATURA_BANK_ACCOUNT', 'chaine', '0', 'IBAN basılacak banka hesabı (0 = faturanın hesabı)', 0, 'current', 0),
			array('ISNETEFATURA_ZERO_VAT_EXEMPTION_CODE', 'chaine', '', 'KDV %0 satırlar için varsayılan istisna kodu', 0, 'current', 0),
			array('ISNETEFATURA_ZERO_VAT_EXEMPTION_REASON', 'chaine', '', 'KDV %0 satırlar için varsayılan istisna açıklaması', 0, 'current', 0),
			array('ISNETEFATURA_EARSIV_SEND_MAIL', 'chaine', '1', 'e-Arşiv faturayı alıcıya e-posta ile gönder', 0, 'current', 0),
			array('ISNETEFATURA_NOTE_PREFIX', 'chaine', '', 'Her faturaya eklenecek sabit not', 0, 'current', 0),
			// Incoming
			array('ISNETEFATURA_INCOMING_ENABLED', 'chaine', '1', 'Gelen e-Faturaları senkronize et', 0, 'current', 0),
			array('ISNETEFATURA_INCOMING_DAYS', 'chaine', '30', 'Gelen fatura geriye dönük gün', 0, 'current', 0),
			array('ISNETEFATURA_INCOMING_AUTOCREATE_SUPPLIER', 'chaine', '1', 'Tanınmayan gönderici için tedarikçi carisi oluştur', 0, 'current', 0),
			// Export
			array('ISNETEFATURA_EXPORT_RECEIVER_NAME', 'chaine', 'Ticaret Bakanlığı- Bilgi Teknolojileri Genel Müdürlüğü', 'İhracat senaryosunda alıcı (GTB)', 0, 'current', 0),
			array('ISNETEFATURA_EXPORT_RECEIVER_VKN', 'chaine', '1460415308', 'GTB VKN', 0, 'current', 0),
			array('ISNETEFATURA_EXPORT_RECEIVER_INBOX', 'chaine', 'urn:mail:ihracatpk@gtb.gov.tr', 'GTB posta kutusu', 0, 'current', 0),
			array('ISNETEFATURA_EXPORT_EXEMPTION_CODE', 'chaine', '301', 'Mal ihracatı istisna kodu', 0, 'current', 0),
			array('ISNETEFATURA_EXPORT_EXEMPTION_REASON', 'chaine', 'Mal İhracatı', '', 0, 'current', 0),
			array('ISNETEFATURA_EXPORT_SERVICE_EXEMPTION_CODE', 'chaine', '302', 'Hizmet ihracatı istisna kodu', 0, 'current', 0),
			array('ISNETEFATURA_EXPORT_SERVICE_EXEMPTION_REASON', 'chaine', 'Hizmet İhracatı', '', 0, 'current', 0),
			array('ISNETEFATURA_EXPORT_TRANSPORT_MODE', 'chaine', '3', 'UN/ECE Rec.19: 1 deniz, 2 demiryolu, 3 karayolu, 4 hava, 5 posta', 0, 'current', 0),
			array('ISNETEFATURA_EXPORT_PACKAGE_TYPE', 'chaine', 'PK', 'UN/ECE Rec.21 kap cinsi', 0, 'current', 0),
			array('ISNETEFATURA_FOREIGN_TAX_CODE', 'chaine', '1111111111', 'VKN\'si olmayan yurtdışı alıcılar için GİB sabiti', 0, 'current', 0),
			// e-İrsaliye
			array('ISNETEFATURA_DESPATCH_ENABLED', 'chaine', '1', 'e-İrsaliye özelliği', 0, 'current', 0),
			array('ISNETEFATURA_DESPATCH_AUTOSEND', 'chaine', '1', 'Sevkiyat onaylanınca e-İrsaliye gönder', 0, 'current', 0),
			array('ISNETEFATURA_DESPATCH_BLOCK_ON_ERROR', 'chaine', '0', 'Gönderim hatasında sevkiyat onayını engelle', 0, 'current', 0),
			array('ISNETEFATURA_DESPATCH_PLATE', 'chaine', '', 'Varsayılan araç plakası', 0, 'current', 0),
			array('ISNETEFATURA_DESPATCH_TRAILER', 'chaine', '', 'Varsayılan dorse plakası', 0, 'current', 0),
			array('ISNETEFATURA_DESPATCH_DRIVER_FIRST', 'chaine', '', 'Varsayılan sürücü adı', 0, 'current', 0),
			array('ISNETEFATURA_DESPATCH_DRIVER_LAST', 'chaine', '', 'Varsayılan sürücü soyadı', 0, 'current', 0),
			array('ISNETEFATURA_DESPATCH_DRIVER_TCKN', 'chaine', '', 'Varsayılan sürücü TCKN', 0, 'current', 0),
			array('ISNETEFATURA_DESPATCH_CARRIER_NAME', 'chaine', '', 'Varsayılan taşıyıcı firma', 0, 'current', 0),
			array('ISNETEFATURA_DESPATCH_CARRIER_VKN', 'chaine', '', 'Varsayılan taşıyıcı VKN', 0, 'current', 0),
			// Document extras
			array('ISNETEFATURA_ATTACH_PDF', 'chaine', '0', 'Dolibarr fatura PDF dosyasini e-belgeye ek olarak gonder', 0, 'current', 0),
			array('ISNETEFATURA_XSLT_EFATURA', 'chaine', '', 'e-Fatura görüntü şablonu dosyası (documents/isnetefatura/)', 0, 'current', 0),
			array('ISNETEFATURA_XSLT_EARSIV', 'chaine', '', 'e-Arşiv görüntü şablonu dosyası', 0, 'current', 0),
			array('ISNETEFATURA_WEB_ADDRESS', 'chaine', '', 'İnternet satışlarında web adresi', 0, 'current', 0),
			array('ISNETEFATURA_BALANCE_WARN', 'chaine', '100', 'Kontör bu sayının altına inince uyar (0 = kapalı)', 0, 'current', 0),
			array('ISNETEFATURA_STORE_PDF', 'chaine', '1', 'Nihai duruma gelince entegratör PDF\'ini fatura belgelerine kaydet', 0, 'current', 0),
			array('ISNETEFATURA_PROFID_LABELS', 'chaine', '1', 'Cari/şirket Prof ID etiketlerini Vergi No / Vergi Dairesi / MERSİS / Ticaret Sicil yap', 0, 'current', 0),
		);

		if (!isModEnabled('isnetefatura')) {
			$conf->isnetefatura = new stdClass();
			$conf->isnetefatura->enabled = 0;
		}

		$this->tabs = array(
			'invoice:+isnetefatura:IsnetEfaturaTab:isnetefatura@isnetefatura:$user->hasRight("isnetefatura","read"):/isnetefatura/card.php?id=__ID__',
			'delivery:+isnetefatura:IsnetIrsaliyeTab:isnetefatura@isnetefatura:isModEnabled("expedition") && getDolGlobalInt("ISNETEFATURA_DESPATCH_ENABLED", 1) && $user->hasRight("isnetefatura","read"):/isnetefatura/despatch.php?id=__ID__',
		);
		$this->dictionaries = array();
		$this->boxes = array();
		$this->cronjobs = array(
			array(
				'label' => 'IsnetCronRefreshStatuses',
				'jobtype' => 'method',
				'class' => '/isnetefatura/class/isnetsender.class.php',
				'objectname' => 'IsnetSender',
				'method' => 'cronRefreshStatuses',
				'parameters' => '30,50',
				'comment' => 'Refresh integrator status of pending e-documents and store final PDFs',
				'frequency' => 1,
				'unitfrequency' => 3600,
				'status' => 1,
				'test' => 'isModEnabled("isnetefatura")',
				'priority' => 50,
			),
			array(
				'label' => 'IsnetCronSyncIncoming',
				'jobtype' => 'method',
				'class' => '/isnetefatura/class/isnetincoming.class.php',
				'objectname' => 'IsnetIncoming',
				'method' => 'cronSyncIncoming',
				'parameters' => '30',
				'comment' => 'Pull incoming supplier e-Fatura documents from the integrator',
				'frequency' => 1,
				'unitfrequency' => 3600,
				'status' => 1,
				'test' => 'isModEnabled("isnetefatura")',
				'priority' => 51,
			),
		);

		$this->rights = array();
		$r = 0;
		$this->rights[$r][0] = $this->numero + 1;
		$this->rights[$r][1] = 'e-Fatura durumlarını görüntüle';
		$this->rights[$r][4] = 'read';
		$r++;
		$this->rights[$r][0] = $this->numero + 2;
		$this->rights[$r][1] = 'e-Fatura gönder';
		$this->rights[$r][4] = 'send';
		$r++;
		$this->rights[$r][0] = $this->numero + 3;
		$this->rights[$r][1] = 'e-Fatura ayarlarını yönet';
		$this->rights[$r][4] = 'setup';

		$this->menu = array(
			array(
				'fk_menu' => 'fk_mainmenu=billing',
				'type' => 'left',
				'titre' => 'IsnetIncomingTitle',
				'mainmenu' => 'billing',
				'leftmenu' => 'isnetefatura_incoming',
				'url' => '/isnetefatura/incoming.php',
				'langs' => 'isnetefatura@isnetefatura',
				'position' => 1000,
				'enabled' => 'isModEnabled("isnetefatura") && getDolGlobalInt("ISNETEFATURA_INCOMING_ENABLED", 1)',
				'perms' => '$user->hasRight("isnetefatura", "read")',
				'target' => '',
				'user' => 2,
			),
		);
	}

	public function init($options = '')
	{
		$result = $this->_load_tables('/isnetefatura/sql/');
		if ($result < 0) {
			return -1;
		}
		$this->createExtraFields();
		$result = $this->_init(array(), $options);
		if ($result > 0 && getDolGlobalInt('ISNETEFATURA_PROFID_LABELS', 1)) {
			$this->installProfIdLabels();
		}
		return $result;
	}

	/**
	 * Dolibarr ships no Turkish Prof ID labels; register the convention used by this module
	 * through the core translation-override table (Setup → Translation).
	 */
	private function installProfIdLabels()
	{
		global $conf;
		require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
		$labels = array(
			'tr_TR' => array('ProfId1TR' => 'Vergi No / TCKN', 'ProfId2TR' => 'Vergi Dairesi', 'ProfId3TR' => 'MERSİS No', 'ProfId4TR' => 'Ticaret Sicil No'),
			'en_US' => array('ProfId1TR' => 'Tax No (VKN/TCKN)', 'ProfId2TR' => 'Tax Office', 'ProfId3TR' => 'MERSİS No', 'ProfId4TR' => 'Trade Registry No'),
		);
		foreach ($labels as $lang => $keys) {
			foreach ($keys as $key => $value) {
				$sql = 'SELECT rowid FROM '.MAIN_DB_PREFIX."overwrite_trans WHERE entity = ".((int) $conf->entity)." AND lang = '".$this->db->escape($lang)."' AND transkey = '".$this->db->escape($key)."'";
				$res = $this->db->query($sql);
				if ($res && $this->db->num_rows($res) > 0) {
					continue;
				}
				$this->db->query('INSERT INTO '.MAIN_DB_PREFIX."overwrite_trans (entity, lang, transkey, transvalue) VALUES (".((int) $conf->entity).", '".$this->db->escape($lang)."', '".$this->db->escape($key)."', '".$this->db->escape($value)."')");
			}
		}
		if (!getDolGlobalInt('MAIN_ENABLE_OVERWRITE_TRANSLATION')) {
			dolibarr_set_const($this->db, 'MAIN_ENABLE_OVERWRITE_TRANSLATION', '1', 'chaine', 0, '', $conf->entity);
		}
	}

	public function remove($options = '')
	{
		return $this->_remove(array(), $options);
	}

	/**
	 * Extra fields are kept on uninstall so data survives module upgrades.
	 */
	private function createExtraFields()
	{
		global $langs;
		require_once DOL_DOCUMENT_ROOT.'/core/class/extrafields.class.php';
		$ef = new ExtraFields($this->db);
		$lang = 'isnetefatura@isnetefatura';

		// Third party: taxpayer lookup cache
		$ef->addExtraField('isnet_status', 'IsnetEfStatus', 'select', 100, '', 'societe', 0, 0, '0',
			array('options' => array('0' => 'IsnetEfStatusUnknown', '1' => 'IsnetEfStatusEfatura', '2' => 'IsnetEfStatusEarsiv')),
			1, '', 1, 'IsnetEfStatusHelp', '', '', $lang, 'isModEnabled("isnetefatura")');
		$ef->addExtraField('isnet_inbox_tag', 'IsnetEfInboxTag', 'varchar', 101, 255, 'societe', 0, 0, '', '',
			1, '', 1, 'IsnetEfInboxTagHelp', '', '', $lang, 'isModEnabled("isnetefatura")');
		$ef->addExtraField('isnet_checked', 'IsnetEfChecked', 'datetime', 102, '', 'societe', 0, 0, '', '',
			1, '', 1, '', '', '', $lang, 'isModEnabled("isnetefatura")');

		// Invoice: per-document overrides
		$ef->addExtraField('isnet_scenario', 'IsnetEfScenario', 'select', 100, '', 'facture', 0, 0, '',
			array('options' => array('' => '', 'TEMELFATURA' => 'TEMELFATURA', 'TICARIFATURA' => 'TICARIFATURA', 'IHRACAT' => 'IHRACAT', 'KAMU' => 'KAMU')),
			1, '', 1, 'IsnetEfScenarioHelp', '', '', $lang, 'isModEnabled("isnetefatura")');
		$ef->addExtraField('isnet_invoice_type', 'IsnetEfInvoiceType', 'select', 101, '', 'facture', 0, 0, '',
			array('options' => array('' => '', 'SATIS' => 'SATIS', 'IADE' => 'IADE', 'ISTISNA' => 'ISTISNA', 'TEVKIFAT' => 'TEVKIFAT', 'OZELMATRAH' => 'OZELMATRAH', 'IHRACKAYITLI' => 'IHRACKAYITLI')),
			1, '', 1, 'IsnetEfInvoiceTypeHelp', '', '', $lang, 'isModEnabled("isnetefatura")');
		$ef->addExtraField('isnet_exemption_code', 'IsnetEfExemptionCode', 'varchar', 102, 8, 'facture', 0, 0, '', '',
			1, '', 1, 'IsnetEfExemptionCodeHelp', '', '', $lang, 'isModEnabled("isnetefatura")');
		$ef->addExtraField('isnet_exemption_reason', 'IsnetEfExemptionReason', 'varchar', 103, 255, 'facture', 0, 0, '', '',
			1, '', 1, '', '', '', $lang, 'isModEnabled("isnetefatura")');

		// Export: product customs data and per-invoice shipment details
		$ef->addExtraField('isnet_gtip', 'IsnetEfGtip', 'varchar', 100, 12, 'product', 0, 0, '', '',
			1, '', 1, 'IsnetEfGtipHelp', '', '', $lang, 'isModEnabled("isnetefatura")');
		$ef->addExtraField('isnet_mensei', 'IsnetEfMensei', 'varchar', 101, 2, 'product', 0, 0, 'TR', '',
			1, '', 1, 'IsnetEfMenseiHelp', '', '', $lang, 'isModEnabled("isnetefatura")');
		$ef->addExtraField('isnet_transport_mode', 'IsnetEfTransportMode', 'select', 104, '', 'facture', 0, 0, '',
			array('options' => array('' => '', '1' => 'IsnetTransportSea', '2' => 'IsnetTransportRail', '3' => 'IsnetTransportRoad', '4' => 'IsnetTransportAir', '5' => 'IsnetTransportPost', '7' => 'IsnetTransportFixed', '8' => 'IsnetTransportInland')),
			1, '', 1, 'IsnetEfTransportModeHelp', '', '', $lang, 'isModEnabled("isnetefatura")');
		$ef->addExtraField('isnet_package_type', 'IsnetEfPackageType', 'varchar', 105, 4, 'facture', 0, 0, '', '',
			1, '', 1, 'IsnetEfPackageTypeHelp', '', '', $lang, 'isModEnabled("isnetefatura")');
		$ef->addExtraField('isnet_package_count', 'IsnetEfPackageCount', 'int', 106, 6, 'facture', 0, 0, '', '',
			1, '', 1, 'IsnetEfPackageCountHelp', '', '', $lang, 'isModEnabled("isnetefatura")');

		// Withholding VAT (tevkifat)
		$tevOptions = array('' => '');
		dol_include_once('/isnetefatura/class/isnetinvoicemapper.class.php');
		foreach (IsnetInvoiceMapper::TEVKIFAT_CODES as $code => $def) {
			$tevOptions[$code] = $code.' - '.dol_trunc($def[1], 60).' ('.$def[0].'%)';
		}
		$ef->addExtraField('isnet_tevkifat_code', 'IsnetEfTevkifatCode', 'select', 107, '', 'facture', 0, 0, '',
			array('options' => $tevOptions),
			1, '', 1, 'IsnetEfTevkifatCodeHelp', '', '', $lang, 'isModEnabled("isnetefatura")');
		$ef->addExtraField('isnet_tevkifat_rate', 'IsnetEfTevkifatRate', 'double', 108, '5,2', 'facture', 0, 0, '', '',
			1, '', 1, 'IsnetEfTevkifatRateHelp', '', '', $lang, 'isModEnabled("isnetefatura")');

		// Paper despatch note referenced on the invoice (linked shipments are picked up automatically)
		$ef->addExtraField('isnet_dispatch_number', 'IsnetEfDispatchNumber', 'varchar', 109, 32, 'facture', 0, 0, '', '',
			1, '', 1, 'IsnetEfDispatchNumberHelp', '', '', $lang, 'isModEnabled("isnetefatura")');
		$ef->addExtraField('isnet_dispatch_date', 'IsnetEfDispatchDate', 'date', 110, '', 'facture', 0, 0, '', '',
			1, '', 1, '', '', '', $lang, 'isModEnabled("isnetefatura")');

		// e-Arşiv internet sale block (mandatory for online sales)
		$ef->addExtraField('isnet_web_sale', 'IsnetEfWebSale', 'boolean', 120, '', 'facture', 0, 0, '0', '',
			1, '', 1, 'IsnetEfWebSaleHelp', '', '', $lang, 'isModEnabled("isnetefatura")');
		$ef->addExtraField('isnet_web_payment_type', 'IsnetEfWebPaymentType', 'select', 121, '', 'facture', 0, 0, '',
			array('options' => array('' => '', 'KREDIKARTI_BANKAKARTI' => 'IsnetPayCard', 'EFT_HAVALE' => 'IsnetPayTransfer', 'KAPIDA_ODEME' => 'IsnetPayCod', 'ODEME_ARACISI' => 'IsnetPayMediator', 'DIGER' => 'IsnetPayOther')),
			1, '', 1, '', '', '', $lang, 'isModEnabled("isnetefatura")');
		$ef->addExtraField('isnet_web_payment_date', 'IsnetEfWebPaymentDate', 'date', 122, '', 'facture', 0, 0, '', '',
			1, '', 1, '', '', '', $lang, 'isModEnabled("isnetefatura")');
		$ef->addExtraField('isnet_web_mediator', 'IsnetEfWebMediator', 'varchar', 123, 128, 'facture', 0, 0, '', '',
			1, '', 1, 'IsnetEfWebMediatorHelp', '', '', $lang, 'isModEnabled("isnetefatura")');
		$ef->addExtraField('isnet_web_carrier', 'IsnetEfWebCarrier', 'varchar', 124, 128, 'facture', 0, 0, '', '',
			1, '', 1, 'IsnetEfWebCarrierHelp', '', '', $lang, 'isModEnabled("isnetefatura")');
		$ef->addExtraField('isnet_web_carrier_vkn', 'IsnetEfWebCarrierVkn', 'varchar', 125, 11, 'facture', 0, 0, '', '',
			1, '', 1, '', '', '', $lang, 'isModEnabled("isnetefatura")');
		$ef->addExtraField('isnet_web_sending_date', 'IsnetEfWebSendingDate', 'date', 126, '', 'facture', 0, 0, '', '',
			1, '', 1, 'IsnetEfWebSendingDateHelp', '', '', $lang, 'isModEnabled("isnetefatura")');

		// Shipment: e-İrsaliye transport data (module defaults apply when empty)
		if (isModEnabled('expedition')) {
			$ef->addExtraField('isnet_plate', 'IsnetEfPlate', 'varchar', 100, 12, 'expedition', 0, 0, '', '', 1, '', 1, 'IsnetEfPlateHelp', '', '', $lang, 'isModEnabled("isnetefatura")');
			$ef->addExtraField('isnet_trailer_plate', 'IsnetEfTrailerPlate', 'varchar', 101, 12, 'expedition', 0, 0, '', '', 1, '', 1, '', '', '', $lang, 'isModEnabled("isnetefatura")');
			$ef->addExtraField('isnet_driver_first', 'IsnetEfDriverFirst', 'varchar', 102, 64, 'expedition', 0, 0, '', '', 1, '', 1, '', '', '', $lang, 'isModEnabled("isnetefatura")');
			$ef->addExtraField('isnet_driver_last', 'IsnetEfDriverLast', 'varchar', 103, 64, 'expedition', 0, 0, '', '', 1, '', 1, '', '', '', $lang, 'isModEnabled("isnetefatura")');
			$ef->addExtraField('isnet_driver_tckn', 'IsnetEfDriverTckn', 'varchar', 104, 11, 'expedition', 0, 0, '', '', 1, '', 1, '', '', '', $lang, 'isModEnabled("isnetefatura")');
			$ef->addExtraField('isnet_carrier_name', 'IsnetEfCarrierName', 'varchar', 105, 128, 'expedition', 0, 0, '', '', 1, '', 1, 'IsnetEfCarrierNameHelp', '', '', $lang, 'isModEnabled("isnetefatura")');
			$ef->addExtraField('isnet_carrier_vkn', 'IsnetEfCarrierVkn', 'varchar', 106, 11, 'expedition', 0, 0, '', '', 1, '', 1, '', '', '', $lang, 'isModEnabled("isnetefatura")');
		}
		$ef->addExtraField('isnet_despatch_inbox', 'IsnetEfDespatchInbox', 'varchar', 103, 255, 'societe', 0, 0, '', '', 1, '', 1, 'IsnetEfDespatchInboxHelp', '', '', $lang, 'isModEnabled("isnetefatura")');

		// Invoice: read-only mirror of the e-document state, so it shows up in lists and filters
		$ef->addExtraField('isnet_number', 'IsnetEfNumber', 'varchar', 110, 32, 'facture', 0, 0, '', '',
			0, '0', 1, 'IsnetEfNumberHelp', '', '', $lang, 'isModEnabled("isnetefatura")');
		$ef->addExtraField('isnet_state', 'IsnetEfState', 'varchar', 111, 80, 'facture', 0, 0, '', '',
			0, '0', 1, 'IsnetEfStateHelp', '', '', $lang, 'isModEnabled("isnetefatura")');
	}
}
