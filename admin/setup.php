<?php
/* Copyright (C) 2026 M. Burak Şentürk <https://buraksenturk.net>
 * Licensed under GPL-3.0-or-later with attribution terms. See COPYING and ATTRIBUTION.md.
 */

$res = 0;
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
dol_include_once('/isnetefatura/class/isnetclient.class.php');
dol_include_once('/isnetefatura/class/isnetaudit.class.php');

$langs->loadLangs(array('admin', 'isnetefatura@isnetefatura'));

if (!$user->admin && !$user->hasRight('isnetefatura', 'setup')) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');

require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';
$bankOptions = array('0' => $langs->trans('IsnetBankFromInvoice'));
$sqlb = 'SELECT rowid, label, iban_prefix FROM '.MAIN_DB_PREFIX.'bank_account WHERE entity IN ('.getEntity('bank_account').') AND clos = 0 ORDER BY label';
$resb = $db->query($sqlb);
if ($resb) {
	while ($ob = $db->fetch_object($resb)) {
		$bankOptions[(string) $ob->rowid] = $ob->label.($ob->iban_prefix ? ' ('.$ob->iban_prefix.')' : '');
	}
}

$sections = array(
	'IsnetSectionConnection' => array(
		'ISNETEFATURA_ENV' => array('type' => 'select', 'options' => array('test' => 'Test', 'prod' => 'Prod')),
		'ISNETEFATURA_COMPANY_VKN' => array('type' => 'text', 'size' => 12),
		'ISNETEFATURA_TEST_COMPANY_VKN' => array('type' => 'text', 'size' => 12),
		'ISNETEFATURA_VENDOR_NUMBER' => array('type' => 'text', 'size' => 12),
		'ISNETEFATURA_URL_INVOICE_TEST' => array('type' => 'url'),
		'ISNETEFATURA_URL_ADDRESSBOOK_TEST' => array('type' => 'url'),
		'ISNETEFATURA_URL_INVOICE_PROD' => array('type' => 'url'),
		'ISNETEFATURA_URL_ADDRESSBOOK_PROD' => array('type' => 'url'),
		'ISNETEFATURA_TIMEOUT' => array('type' => 'int', 'size' => 4),
		'ISNETEFATURA_DEBUG' => array('type' => 'yesno'),
		'ISNETEFATURA_CRON_USER_ID' => array('type' => 'int', 'size' => 4, 'min' => 1),
	),
	'IsnetSectionBehaviour' => array(
		'ISNETEFATURA_AUTOSEND_ON_VALIDATE' => array('type' => 'yesno'),
		'ISNETEFATURA_BLOCK_VALIDATE_ON_ERROR' => array('type' => 'yesno'),
		'ISNETEFATURA_TAXPAYER_LOOKUP' => array('type' => 'select', 'options' => array('always' => $langs->trans('IsnetLookupAlways'), 'cache' => $langs->trans('IsnetLookupCache'), 'manual' => $langs->trans('IsnetLookupManual'))),
		'ISNETEFATURA_TAXPAYER_CACHE_DAYS' => array('type' => 'int', 'size' => 4, 'min' => 0),
		'ISNETEFATURA_REQUIRE_TAX_OFFICE' => array('type' => 'yesno'),
		'ISNETEFATURA_DOMESTIC_COUNTRY' => array('type' => 'text', 'size' => 3),
	),
	'IsnetSectionInvoiceDefaults' => array(
		'ISNETEFATURA_DEFAULT_SCENARIO' => array('type' => 'select', 'options' => array('TICARIFATURA' => 'TICARIFATURA', 'TEMELFATURA' => 'TEMELFATURA')),
		'ISNETEFATURA_DEFAULT_UNIT' => array('type' => 'text', 'size' => 6),
		'ISNETEFATURA_BANK_ACCOUNT' => array('type' => 'select', 'options' => $bankOptions),
		'ISNETEFATURA_ZERO_VAT_EXEMPTION_CODE' => array('type' => 'text', 'size' => 6),
		'ISNETEFATURA_ZERO_VAT_EXEMPTION_REASON' => array('type' => 'text', 'size' => 60),
		'ISNETEFATURA_EARSIV_SEND_MAIL' => array('type' => 'yesno'),
		'ISNETEFATURA_NOTE_PREFIX' => array('type' => 'text', 'size' => 60),
		'ISNETEFATURA_STORE_PDF' => array('type' => 'yesno'),
		'ISNETEFATURA_PROFID_LABELS' => array('type' => 'yesno'),
		'ISNETEFATURA_ATTACH_PDF' => array('type' => 'yesno'),
		'ISNETEFATURA_WEB_ADDRESS' => array('type' => 'text', 'size' => 40),
		'ISNETEFATURA_BALANCE_WARN' => array('type' => 'int', 'size' => 6, 'min' => 0),
	),
	'IsnetSectionIncoming' => array(
		'ISNETEFATURA_INCOMING_ENABLED' => array('type' => 'yesno'),
		'ISNETEFATURA_INCOMING_DAYS' => array('type' => 'int', 'size' => 4, 'min' => 1),
		'ISNETEFATURA_INCOMING_AUTOCREATE_SUPPLIER' => array('type' => 'yesno'),
	),
	'IsnetSectionDespatch' => array(
		'ISNETEFATURA_DESPATCH_ENABLED' => array('type' => 'yesno'),
		'ISNETEFATURA_DESPATCH_AUTOSEND' => array('type' => 'yesno'),
		'ISNETEFATURA_DESPATCH_BLOCK_ON_ERROR' => array('type' => 'yesno'),
		'ISNETEFATURA_DESPATCH_PLATE' => array('type' => 'text', 'size' => 12),
		'ISNETEFATURA_DESPATCH_TRAILER' => array('type' => 'text', 'size' => 12),
		'ISNETEFATURA_DESPATCH_DRIVER_FIRST' => array('type' => 'text', 'size' => 20),
		'ISNETEFATURA_DESPATCH_DRIVER_LAST' => array('type' => 'text', 'size' => 20),
		'ISNETEFATURA_DESPATCH_DRIVER_TCKN' => array('type' => 'text', 'size' => 12),
		'ISNETEFATURA_DESPATCH_CARRIER_NAME' => array('type' => 'text', 'size' => 40),
		'ISNETEFATURA_DESPATCH_CARRIER_VKN' => array('type' => 'text', 'size' => 12),
	),
	'IsnetSectionExport' => array(
		'ISNETEFATURA_EXPORT_RECEIVER_NAME' => array('type' => 'text', 'size' => 40),
		'ISNETEFATURA_EXPORT_RECEIVER_VKN' => array('type' => 'text', 'size' => 12),
		'ISNETEFATURA_EXPORT_RECEIVER_INBOX' => array('type' => 'text', 'size' => 40),
		'ISNETEFATURA_EXPORT_EXEMPTION_CODE' => array('type' => 'text', 'size' => 6),
		'ISNETEFATURA_EXPORT_EXEMPTION_REASON' => array('type' => 'text', 'size' => 40),
		'ISNETEFATURA_EXPORT_SERVICE_EXEMPTION_CODE' => array('type' => 'text', 'size' => 6),
		'ISNETEFATURA_EXPORT_SERVICE_EXEMPTION_REASON' => array('type' => 'text', 'size' => 40),
		'ISNETEFATURA_EXPORT_TRANSPORT_MODE' => array('type' => 'text', 'size' => 3),
		'ISNETEFATURA_EXPORT_PACKAGE_TYPE' => array('type' => 'text', 'size' => 4),
		'ISNETEFATURA_FOREIGN_TAX_CODE' => array('type' => 'text', 'size' => 12),
	),
);
$fields = array();
foreach ($sections as $s) {
	$fields += $s;
}

$testResult = null;

if ($action === 'update') {
	$error = 0;
	$db->begin();
	$changed = array();
	foreach ($fields as $code => $def) {
		$val = trim(GETPOST($code, $def['type'] === 'url' ? 'alpha' : 'alphanohtml'));
		if ($def['type'] === 'int') {
			$val = (string) max($def['min'] ?? 10, (int) $val);
		}
		if ($def['type'] === 'yesno') {
			$val = GETPOST($code, 'int') ? '1' : '0';
		}
		if (getDolGlobalString($code) !== $val) {
			$changed[] = $code.': "'.getDolGlobalString($code).'" -> "'.$val.'"';
		}
		if (dolibarr_set_const($db, $code, $val, 'chaine', 0, '', $conf->entity) < 0) {
			$error++;
		}
	}
	if ($error) {
		$db->rollback();
		setEventMessages($langs->trans('Error'), null, 'errors');
	} else {
		$db->commit();
		if (!empty($changed)) {
			IsnetAudit::logSecurity($db, 'ISNET_SETUP', 'İşNet e-Fatura settings changed: '.implode('; ', $changed));
		}
		setEventMessages($langs->trans('SetupSaved'), null, 'mesgs');
	}
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}

if ($action === 'refreshcodes') {
	dol_include_once('/isnetefatura/class/isnetcoderesolver.class.php');
	$resolver = new IsnetCodeResolver($db);
	$n = $resolver->refresh();
	if ($n > 0) {
		setEventMessages($langs->trans('IsnetCodeCacheRefreshed', $n), null, 'mesgs');
	} else {
		setEventMessages($resolver->error, null, 'errors');
	}
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}

if ($action === 'uploadxslt' && !empty($_FILES['xsltfile']['tmp_name'])) {
	$kind = GETPOST('xslt_kind', 'aZ09') === 'EARSIV' ? 'EARSIV' : 'EFATURA';
	$dir = DOL_DATA_ROOT.'/isnetefatura';
	dol_mkdir($dir);
	$name = strtolower($kind).'-template.xslt';
	$content = file_get_contents($_FILES['xsltfile']['tmp_name']);
	if (stripos($content, '<xsl:stylesheet') === false && stripos($content, '<xsl:transform') === false) {
		setEventMessages($langs->trans('IsnetXsltInvalid'), null, 'errors');
	} elseif (file_put_contents($dir.'/'.$name, $content) === false) {
		setEventMessages($langs->trans('ErrorFailedToSaveFile'), null, 'errors');
	} else {
		dolChmod($dir.'/'.$name);
		dolibarr_set_const($db, 'ISNETEFATURA_XSLT_'.$kind, $name, 'chaine', 0, '', $conf->entity);
		IsnetAudit::logSecurity($db, 'ISNET_SETUP', 'XSLT template uploaded for '.$kind.': '.$name.' ('.strlen($content).' bytes)');
		setEventMessages($langs->trans('IsnetXsltSaved', $name), null, 'mesgs');
	}
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}

if ($action === 'removexslt') {
	$kind = GETPOST('xslt_kind', 'aZ09') === 'EARSIV' ? 'EARSIV' : 'EFATURA';
	dolibarr_set_const($db, 'ISNETEFATURA_XSLT_'.$kind, '', 'chaine', 0, '', $conf->entity);
	IsnetAudit::logSecurity($db, 'ISNET_SETUP', 'XSLT template removed for '.$kind);
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}

if ($action === 'testconnection') {
	$client = new IsnetClient($db);
	$testVkn = trim(GETPOST('test_vkn', 'alphanohtml'));
	$testResult = array('env' => $client->getEnv(), 'company_vkn' => $client->getCompanyTaxCode());

	$testResult['health'] = $client->healthCheck();
	$testResult['health_error'] = $client->error;
	$testResult['balance'] = $client->getCompanyBalance();
	$testResult['vendors'] = $client->getCompanyVendors();

	if ($testVkn !== '') {
		$testResult['lookup_vkn'] = $testVkn;
		$testResult['lookup'] = $client->resolveReceiver($testVkn);
		$testResult['lookup_error'] = $client->error;
	}
}

$help_url = '';
llxHeader('', $langs->trans('IsnetEfaturaSetup'), $help_url);

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans('BackToModuleList').'</a>';
print load_fiche_titre($langs->trans('IsnetEfaturaSetup'), $linkback, 'title_setup');

print '<span class="opacitymedium">'.$langs->trans('IsnetEfaturaSetupDesc').'</span><br><br>';

print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="update">';

foreach ($sections as $sectionKey => $sectionFields) {
	print '<table class="noborder centpercent">';
	print '<tr class="liste_titre"><td class="titlefieldmiddle">'.$langs->trans($sectionKey).'</td><td>'.$langs->trans('Value').'</td><td></td></tr>';

	foreach ($sectionFields as $code => $def) {
		$current = getDolGlobalString($code);
		print '<tr class="oddeven"><td>'.$langs->trans($code).'</td><td>';
		if ($def['type'] === 'select') {
			print '<select class="flat" name="'.$code.'">';
			foreach ($def['options'] as $k => $label) {
				print '<option value="'.dol_escape_htmltag($k).'"'.($current === (string) $k ? ' selected' : '').'>'.dol_escape_htmltag($label).'</option>';
			}
			print '</select>';
		} elseif ($def['type'] === 'yesno') {
			print '<input type="checkbox" name="'.$code.'" value="1"'.($current === '1' ? ' checked' : '').'>';
		} elseif ($def['type'] === 'url') {
			print '<input type="text" class="flat minwidth500" name="'.$code.'" value="'.dol_escape_htmltag($current).'">';
		} else {
			print '<input type="text" class="flat" size="'.($def['size'] ?? 30).'" name="'.$code.'" value="'.dol_escape_htmltag($current).'">';
		}
		print '</td><td class="opacitymedium small">'.$langs->trans($code.'Help').'</td></tr>';
	}
	print '</table><br>';
}

print '<div class="center"><input type="submit" class="button button-save" value="'.$langs->trans('Save').'"></div>';
print '</form>';

dol_include_once('/isnetefatura/class/isnetcoderesolver.class.php');
$resolver = new IsnetCodeResolver($db);
print load_fiche_titre($langs->trans('IsnetXsltTitle'), '', '');
print '<span class="opacitymedium">'.$langs->trans('IsnetXsltDesc').'</span><br><br>';
foreach (array('EFATURA', 'EARSIV') as $kind) {
	$current = getDolGlobalString('ISNETEFATURA_XSLT_'.$kind);
	print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" enctype="multipart/form-data">';
	print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="xslt_kind" value="'.$kind.'">';
	print '<b>'.$kind.':</b> ';
	if ($current !== '') {
		print dol_escape_htmltag($current).' <button class="button small" name="action" value="removexslt">'.$langs->trans('Remove').'</button> ';
	} else {
		print '<span class="opacitymedium">'.$langs->trans('IsnetXsltNone').'</span> ';
	}
	print '<input type="file" name="xsltfile" accept=".xslt,.xsl,.xml"> <button class="button small" name="action" value="uploadxslt">'.$langs->trans('Upload').'</button>';
	print '</form>';
}
print '<br>';

print load_fiche_titre($langs->trans('IsnetCodeCache'), '', '');
$cnt = $resolver->count();
print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="refreshcodes">';
print $cnt > 0
	? $langs->trans('IsnetCodeCacheStatus', $cnt, dol_print_date($resolver->lastRefresh(), 'dayhour'))
	: $langs->trans('IsnetCodeCacheEmpty');
print ' <input type="submit" class="button small" value="'.$langs->trans('IsnetCodeCacheRefresh').'">';
print '</form><br>';

print load_fiche_titre($langs->trans('IsnetEfaturaConnectionTest'), '', '');

print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="testconnection">';
print $langs->trans('IsnetEfaturaTestVkn').': <input type="text" class="flat" size="12" name="test_vkn" value="'.dol_escape_htmltag(GETPOST('test_vkn', 'alphanohtml')).'"> ';
print '<input type="submit" class="button" value="'.$langs->trans('IsnetEfaturaRunTest').'">';
print '</form>';

if ($testResult !== null) {
	print '<br><table class="noborder centpercent">';
	print '<tr class="liste_titre"><td colspan="2">'.$langs->trans('Result').' ('.$langs->trans('IsnetEfaturaEnv').': <b>'.$testResult['env'].'</b>, VKN: <b>'.dol_escape_htmltag($testResult['company_vkn']).'</b>)</td></tr>';

	print '<tr class="oddeven"><td class="titlefield">HealthCheck</td><td>';
	if ($testResult['health']) {
		print img_picto('', 'tick').' '.$langs->trans('IsnetEfaturaServiceUp');
	} else {
		print img_picto('', 'error').' '.dol_escape_htmltag($testResult['health_error']);
	}
	print '</td></tr>';
	if ($testResult['balance'] !== null) {
		$warn = getDolGlobalInt('ISNETEFATURA_BALANCE_WARN', 0);
		print '<tr class="oddeven"><td>'.$langs->trans('IsnetBalance').'</td><td>'.($warn > 0 && $testResult['balance'] < $warn ? img_picto('', 'warning').' ' : '').number_format($testResult['balance'], 0, ',', '.').'</td></tr>';
	}
	if (!empty($testResult['vendors'])) {
		print '<tr class="oddeven"><td>'.$langs->trans('IsnetVendors').'</td><td>';
		foreach ($testResult['vendors'] as $num => $name) {
			print dol_escape_htmltag($num).' = '.dol_escape_htmltag($name).'<br>';
		}
		print '</td></tr>';
	}

	if (isset($testResult['lookup_vkn'])) {
		print '<tr class="oddeven"><td>GetTaxPayer('.dol_escape_htmltag($testResult['lookup_vkn']).')</td><td>';
		if ($testResult['lookup_error'] !== '') {
			print img_picto('', 'error').' '.dol_escape_htmltag($testResult['lookup_error']);
		} elseif ($testResult['lookup'] === null) {
			print img_picto('', 'warning').' '.$langs->trans('IsnetEfaturaNotRegistered');
		} else {
			print img_picto('', 'tick').' '.$langs->trans('IsnetEfaturaRegistered').'<br>';
			print '<b>'.dol_escape_htmltag($testResult['lookup']['name']).'</b><br>';
			print 'Inbox: '.dol_escape_htmltag(implode(', ', $testResult['lookup']['inbox_tags'])).'<br>';
			print $langs->trans('IsnetEfaturaRegisteredSince').': '.dol_escape_htmltag($testResult['lookup']['registered']);
		}
		print '</td></tr>';
	}
	print '</table>';
}

llxFooter();
$db->close();
