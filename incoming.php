<?php
/* Copyright (C) 2026 M. Burak Şentürk <https://buraksenturk.net>
 * Licensed under GPL-3.0-or-later with attribution terms. See COPYING and ATTRIBUTION.md.
 *
 * Incoming supplier e-Fatura / e-İrsaliye documents: unit lookup, filters, list, detail, mass actions.
 */

// Load Dolibarr environment
$res = 0;
// Try main.inc.php into web root known defined into CONTEXT_DOCUMENT_ROOT (not always defined)
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
}
// Try main.inc.php into web root detected using web root calculated from SCRIPT_FILENAME
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);
$i = strlen($tmp) - 1;
$j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) {
	$i--;
	$j--;
}
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1))."/main.inc.php")) {
	$res = @include substr($tmp, 0, ($i + 1))."/main.inc.php";
}
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php")) {
	$res = @include dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php";
}
// Try main.inc.php using relative path
if (!$res && file_exists("../main.inc.php")) {
	$res = @include "../main.inc.php";
}
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.facture.class.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
dol_include_once('/isnetefatura/class/isnetincoming.class.php');

$langs->loadLangs(array('bills', 'companies', 'suppliers', 'isnetefatura@isnetefatura'));

if (!$user->hasRight('isnetefatura', 'read')) {
	accessforbidden();
}
$canAct = $user->hasRight('isnetefatura', 'send') && $user->hasRight('fournisseur', 'facture', 'creer');

$action = GETPOST('action', 'aZ09');
$massaction = GETPOST('massaction', 'alpha');
$confirm = GETPOST('confirm', 'alpha');
$id = GETPOSTINT('id');
$toselect = GETPOST('toselect', 'array:int');
$kind = GETPOST('kind', 'aZ09') === 'DESPATCH' ? 'DESPATCH' : 'INVOICE';
$isDespatch = ($kind === 'DESPATCH');

$limit = GETPOSTINT('limit') ?: getDolGlobalInt('MAIN_SIZE_LISTE_LIMIT', 25);
$sortfield = GETPOST('sortfield', 'aZ09comma') ?: 'invoice_date';
$sortorder = GETPOST('sortorder', 'aZ09comma') ?: 'DESC';
$page = max(0, GETPOSTISSET('pageplusone') ? (GETPOSTINT('pageplusone') - 1) : GETPOSTINT('page'));

$vendors = IsnetIncoming::vendors($db);
$staticIncoming = new IsnetIncoming($db);

/* ------------------------------------------------------------------ filters */

// Filters live in the URL; a bare menu click restores the last ones used in this session.
$sessionKey = 'isnetefatura_incoming_'.$kind;
$defaultFilters = array('date_start' => dol_get_first_hour(dol_now() - 30 * 86400));
if (GETPOST('button_removefilter_x', 'alpha') || GETPOST('button_removefilter.x', 'alpha') || GETPOST('button_removefilter', 'alpha')) {
	$filters = $defaultFilters;
} elseif (GETPOSTISSET('search_submitted')) {
	$filters = array(
		'search' => GETPOST('search_all', 'alphanohtml'),
		'number' => GETPOST('search_number', 'alphanohtml'),
		'sender' => GETPOST('search_sender', 'alphanohtml'),
		'taxcode' => GETPOST('search_taxcode', 'alphanohtml'),
		'ref' => GETPOST('search_ref', 'alphanohtml'),
		'scenario' => GETPOST('search_scenario', 'aZ09'),
		'type' => GETPOST('search_type', 'aZ09'),
		'status' => GETPOST('search_status', 'aZ09'),
		'detail_status' => GETPOST('search_detail_status', 'aZ09'),
		'approval' => GETPOST('search_approval', 'aZ09'),
		'currency' => GETPOST('search_currency', 'aZ09'),
		'vendor' => GETPOST('search_vendor', 'alphanohtml'),
		'unlinked' => GETPOSTINT('search_unlinked'),
		'amount_min' => GETPOST('search_amount_min', 'alphanohtml'),
		'amount_max' => GETPOST('search_amount_max', 'alphanohtml'),
		'date_start' => GETPOSTDATE('search_date_start'),
		'date_end' => GETPOSTDATE('search_date_end', 'end'),
		'received_start' => GETPOSTDATE('search_received_start'),
		'received_end' => GETPOSTDATE('search_received_end', 'end'),
	);
} elseif (!empty($_SESSION[$sessionKey]) && is_array($_SESSION[$sessionKey])) {
	$filters = $_SESSION[$sessionKey];
} else {
	$filters = $defaultFilters;
}
// The sender name filter only kicks in from 3 characters on.
if (isset($filters['sender']) && dol_strlen(trim($filters['sender'])) < 3) {
	$filters['sender'] = '';
}
$filters = array_filter($filters, function ($v) {
	return $v !== '' && $v !== null && $v !== 0 && $v !== false;
});
$_SESSION[$sessionKey] = $filters;
$filters['kind'] = $kind;

/**
 * URL parameters reproducing the current filters.
 */
function isnetIncomingParams(array $filters, $kind)
{
	$map = array('search' => 'search_all', 'number' => 'search_number', 'sender' => 'search_sender', 'taxcode' => 'search_taxcode', 'ref' => 'search_ref',
		'scenario' => 'search_scenario', 'type' => 'search_type', 'status' => 'search_status', 'detail_status' => 'search_detail_status', 'approval' => 'search_approval',
		'currency' => 'search_currency', 'vendor' => 'search_vendor', 'unlinked' => 'search_unlinked', 'amount_min' => 'search_amount_min', 'amount_max' => 'search_amount_max');
	$p = '&kind='.$kind.'&search_submitted=1';
	foreach ($map as $k => $name) {
		if (isset($filters[$k]) && $filters[$k] !== '') {
			$p .= '&'.$name.'='.urlencode((string) $filters[$k]);
		}
	}
	foreach (array('date_start' => 'search_date_start', 'date_end' => 'search_date_end', 'received_start' => 'search_received_start', 'received_end' => 'search_received_end') as $k => $name) {
		if (!empty($filters[$k])) {
			$p .= '&'.$name.'day='.dol_print_date($filters[$k], '%d').'&'.$name.'month='.dol_print_date($filters[$k], '%m').'&'.$name.'year='.dol_print_date($filters[$k], '%Y');
		}
	}
	return $p;
}

$param = isnetIncomingParams($filters, $kind);
if ($limit != getDolGlobalInt('MAIN_SIZE_LISTE_LIMIT', 25)) {
	$param .= '&limit='.$limit;
}
$backUrl = $_SERVER['PHP_SELF'].'?'.ltrim($param, '&').'&sortfield='.urlencode($sortfield).'&sortorder='.urlencode($sortorder).($page > 0 ? '&page='.$page : '');

/* ------------------------------------------------------------------ actions */

if ($action === 'sync' && $canAct) {
	$s = new IsnetIncoming($db);
	$n = $isDespatch ? $s->syncDespatch(getDolGlobalInt('ISNETEFATURA_INCOMING_DAYS', 30)) : $s->sync(getDolGlobalInt('ISNETEFATURA_INCOMING_DAYS', 30));
	if ($n < 0) {
		IsnetIncoming::recordHealth($db, false, $s->error);
		setEventMessages($s->error, null, 'errors');
	} else {
		IsnetIncoming::recordHealth($db, true);
		IsnetIncoming::vendors($db, true);
		setEventMessages($langs->trans('IsnetIncomingSynced', $n), null, 'mesgs');
	}
	header('Location: '.$backUrl);
	exit;
}

if ($action === 'import' && $id > 0 && $canAct) {
	$doc = new IsnetIncoming($db);
	if ($doc->fetch($id) > 0) {
		$r = $doc->createSupplierInvoice($user);
		if ($r > 0) {
			setEventMessages($langs->trans('IsnetIncomingImported'), null, 'mesgs');
			header('Location: '.DOL_URL_ROOT.'/fourn/facture/card.php?id='.$r);
			exit;
		}
		setEventMessages($langs->transnoentities($doc->error), null, 'errors');
	}
	header('Location: '.$backUrl);
	exit;
}

if ($action === 'linksupplier' && $id > 0 && $canAct) {
	$doc = new IsnetIncoming($db);
	if ($doc->fetch($id) > 0) {
		$r = $doc->resolveSupplier($user);
		setEventMessages($r > 0 ? $langs->trans('IsnetIncomingSupplierLinked') : $langs->transnoentities($doc->error), null, $r > 0 ? 'mesgs' : 'errors');
	}
	header('Location: '.$backUrl);
	exit;
}

if ($action === 'receipt' && $id > 0 && $canAct) {
	$doc = new IsnetIncoming($db);
	if ($doc->fetch($id) > 0) {
		$r = $doc->sendReceipt($user);
		setEventMessages($r > 0 ? $langs->trans('IsnetReceiptSent') : $langs->transnoentities($doc->error), null, $r > 0 ? 'mesgs' : 'errors');
	}
	header('Location: '.$backUrl);
	exit;
}

if (($action === 'accept' || $action === 'reject') && $id > 0 && $canAct) {
	$doc = new IsnetIncoming($db);
	if ($doc->fetch($id) > 0) {
		$r = $doc->reply($action === 'accept' ? 'KABUL' : 'RED', GETPOST('reason', 'alphanohtml'));
		if ($r > 0) {
			setEventMessages($langs->trans('IsnetIncomingReplied', $doc->response_status), null, 'mesgs');
		} else {
			setEventMessages($doc->error, null, 'errors');
		}
	}
	header('Location: '.$backUrl);
	exit;
}

if ($action === 'viewer' && $id > 0) {
	$doc = new IsnetIncoming($db);
	if ($doc->fetch($id) > 0) {
		$client = new IsnetClient($db);
		$url = $client->getDocumentViewerLink($doc->ettn, 'EInvoice', 'Incoming');
		if ($url !== '') {
			header('Location: '.$url);
			exit;
		}
		setEventMessages($client->error ?: $langs->trans('IsnetPdfNotAvailable'), null, 'errors');
	}
	header('Location: '.$backUrl);
	exit;
}

if ($action === 'confirm_move' && $confirm === 'yes' && $id > 0 && $canAct) {
	$doc = new IsnetIncoming($db);
	$to = GETPOST('to_vendor', 'alphanohtml');
	if ($doc->fetch($id) > 0 && isset($vendors[$to])) {
		$r = $doc->moveToVendor($to, $user);
		setEventMessages($r > 0 ? $langs->trans('IsnetUnitMoved', $vendors[$to]) : $doc->error, null, $r > 0 ? 'mesgs' : 'errors');
	}
	header('Location: '.$backUrl.'&action=locate&locate_id='.$id);
	exit;
}

// Unit lookup ("Fatura birim sorgula")
$locateTax = GETPOST('locate_taxcode', 'alphanohtml');
$locateNo = GETPOST('locate_number', 'alphanohtml');
$located = null;
$locateDone = false;
if ($action === 'locate') {
	$locateDone = true;
	$doc = new IsnetIncoming($db);
	if (GETPOSTINT('locate_id') > 0) {
		$r = $doc->fetch(GETPOSTINT('locate_id'));
	} else {
		$r = $doc->locate($locateTax, $locateNo);
	}
	if ($r > 0) {
		$located = $doc;
		$locateTax = $doc->sender_tax_code;
		$locateNo = $doc->invoice_number;
	} elseif ($r < 0) {
		setEventMessages($doc->error, null, 'errors');
	}
}

// Mass actions
if ($canAct && !empty($toselect) && in_array($massaction, array('import', 'linksupplier'), true)) {
	$ok = 0;
	$errs = array();
	foreach ($toselect as $tid) {
		$doc = new IsnetIncoming($db);
		if ($doc->fetch((int) $tid) <= 0 || $doc->doc_kind !== 'INVOICE') {
			continue;
		}
		if ($massaction === 'import') {
			if ($doc->fk_facture_fourn > 0) {
				continue;
			}
			$r = $doc->createSupplierInvoice($user);
		} else {
			$r = $doc->resolveSupplier($user);
		}
		if ($r > 0) {
			$ok++;
		} else {
			$errs[] = $doc->invoice_number.': '.$langs->transnoentities($doc->error);
		}
	}
	setEventMessages($langs->trans('IsnetMassDone', $ok), $errs, empty($errs) ? 'mesgs' : 'warnings');
	header('Location: '.$backUrl);
	exit;
}
if ($canAct && $action === 'confirm_massaccept' && $confirm === 'yes') {
	$ids = array_filter(array_map('intval', explode(',', GETPOST('ids', 'alphanohtml'))));
	$ok = 0;
	$errs = array();
	foreach ($ids as $tid) {
		$doc = new IsnetIncoming($db);
		if ($doc->fetch($tid) <= 0 || !$doc->canReply()) {
			continue;
		}
		if ($doc->reply('KABUL') > 0) {
			$ok++;
		} else {
			$errs[] = $doc->invoice_number.': '.$doc->error;
		}
	}
	setEventMessages($langs->trans('IsnetMassDone', $ok), $errs, empty($errs) ? 'mesgs' : 'warnings');
	header('Location: '.$backUrl);
	exit;
}

// CSV export of every row matching the filters
if ($action === 'export') {
	$total = 0;
	$rows = $staticIncoming->fetchList($filters, $total, 0, 0, $sortfield, $sortorder);
	$name = 'gelen-'.strtolower($kind).'-'.dol_print_date(dol_now(), '%Y%m%d-%H%M').'.csv';
	header('Content-Type: text/csv; charset=UTF-8');
	header('Content-Disposition: attachment; filename="'.$name.'"');
	$out = fopen('php://output', 'w');
	fwrite($out, "\xEF\xBB\xBF");
	fputcsv($out, array('ETTN', $langs->transnoentities('IsnetInvoiceNumber'), $langs->transnoentities('Date'), $langs->transnoentities('IsnetReceivedDate'),
		$langs->transnoentities('IsnetSenderVkn'), $langs->transnoentities('Supplier'), $langs->transnoentities('IsnetScenario'), $langs->transnoentities('Type'),
		$langs->transnoentities('AmountHT'), $langs->transnoentities('VAT'), $langs->transnoentities('IsnetPayable'), $langs->transnoentities('Currency'),
		$langs->transnoentities('Status'), $langs->transnoentities('IsnetApproval'), $langs->transnoentities('IsnetSourceInvoice'), $langs->transnoentities('IsnetUnit'),
		$langs->transnoentities('SupplierInvoice')), ';');
	foreach ($rows as $d) {
		$st = $d->approvalState();
		fputcsv($out, array($d->ettn, $d->invoice_number, dol_print_date($d->invoice_date, '%Y-%m-%d'), dol_print_date($d->date_received, '%Y-%m-%d %H:%M'),
			$d->sender_tax_code, $d->sender_name, $d->scenario, $d->invoice_type,
			price2num($d->total_line_ext, 'MT'), price2num($d->total_vat, 'MT'), price2num($d->total_payable, 'MT'), $d->currency_code,
			$d->status, $st !== '' ? $langs->transnoentities('IsnetApproval_'.$st) : '', $d->billing_reference, $d->vendorNumber($vendors),
			$d->fk_facture_fourn > 0 ? $d->fk_facture_fourn : ''), ';');
	}
	fclose($out);
	$db->close();
	exit;
}

/* ------------------------------------------------------------------ view */

$form = new Form($db);
$title = $langs->trans($isDespatch ? 'IsnetIncomingDespatches' : 'IsnetIncomingInvoices');
llxHeader('', $title, '', '', 0, 0, '', '', '', 'mod-isnetefatura page-incoming');

$total = 0;
$list = $staticIncoming->fetchList($filters, $total, $limit, $page * $limit, $sortfield, $sortorder);
if ($page > 0 && empty($list) && $total > 0) {
	$page = 0;
	$list = $staticIncoming->fetchList($filters, $total, $limit, 0, $sortfield, $sortorder);
}

$morehtml = '';
if ($canAct) {
	$morehtml = '<a class="butAction" href="'.$backUrl.'&action=sync&token='.newToken().'">'.img_picto('', 'refresh', 'class="pictofixedwidth"').$langs->trans('IsnetIncomingSync').'</a>';
}
print load_fiche_titre($title, $morehtml, $isDespatch ? 'dolly' : 'supplier_invoice');

print '<div class="tabBar tabBarWithBottom">';
print '<a class="'.(!$isDespatch ? 'butAction' : 'butActionSmall').'" href="'.$_SERVER['PHP_SELF'].'?kind=INVOICE">'.$langs->trans('IsnetIncomingInvoices').'</a> ';
print '<a class="'.($isDespatch ? 'butAction' : 'butActionSmall').'" href="'.$_SERVER['PHP_SELF'].'?kind=DESPATCH">'.$langs->trans('IsnetIncomingDespatches').'</a>';
print ' &nbsp; <span class="opacitymedium">'.dol_escape_htmltag(getDolGlobalString('MAIN_INFO_SOCIETE_NOM')).'</span>';
print '</div>';

// Connection health
$lastOk = getDolGlobalInt('ISNETEFATURA_SYNC_LAST_OK');
$lastErrAt = getDolGlobalInt('ISNETEFATURA_SYNC_LAST_ERROR_DATE');
if ($lastErrAt > $lastOk) {
	print info_admin($langs->trans('IsnetHealthFailing', $lastOk ? dol_print_date($lastOk, 'dayhour') : '-', dol_print_date($lastErrAt, 'dayhour'), getDolGlobalInt('ISNETEFATURA_SYNC_FAILS')).'<br><span class="small">'.dol_escape_htmltag(getDolGlobalString('ISNETEFATURA_SYNC_LAST_ERROR')).'</span>', 0, 0, 'error', '');
} elseif ($lastOk && getDolGlobalInt('ISNETEFATURA_INCOMING_ENABLED', 1) && dol_now() - $lastOk > 86400) {
	print info_admin($langs->trans('IsnetHealthStale', dol_print_date($lastOk, 'dayhour')), 0, 0, 'warning', '');
} elseif ($lastOk) {
	print '<div class="opacitymedium small paddingbottom">'.img_picto('', 'tick', 'class="pictofixedwidth"').$langs->trans('IsnetHealthOk', dol_print_date($lastOk, 'dayhour')).'</div>';
}

// Confirmation dialogs
if ($action === 'askreject' && $id > 0) {
	print $form->formconfirm($backUrl.'&id='.$id, $langs->trans('IsnetIncomingReject'), $langs->trans('IsnetIncomingRejectText'), 'reject',
		array(array('type' => 'text', 'name' => 'reason', 'label' => $langs->trans('IsnetIncomingRejectReason'), 'value' => '', 'size' => 60)), '', 1);
}
if ($massaction === 'accept' && !empty($toselect)) {
	print $form->formconfirm($backUrl.'&ids='.implode(',', array_map('intval', $toselect)), $langs->trans('IsnetIncomingAccept'), $langs->trans('IsnetMassAcceptConfirm', count($toselect)), 'confirm_massaccept', '', 0, 1);
}
if ($action === 'askmove' && $id > 0 && isset($vendors[GETPOST('to_vendor', 'alphanohtml')])) {
	$to = GETPOST('to_vendor', 'alphanohtml');
	print $form->formconfirm($backUrl.'&id='.$id.'&to_vendor='.urlencode($to), $langs->trans('IsnetUnitMove'), $langs->trans('IsnetUnitMoveConfirm', $vendors[$to]), 'confirm_move', '', 0, 1);
}

/* ---------------------------------------------- card: unit lookup */

if (!$isDespatch) {
	print '<details class="isnet-card"'.($locateDone ? ' open' : '').'><summary>'.img_picto('', 'search', 'class="pictofixedwidth"').$langs->trans('IsnetUnitLookup').'</summary>';
	print '<form method="GET" action="'.$_SERVER['PHP_SELF'].'" class="isnet-card-body">';
	print '<input type="hidden" name="kind" value="INVOICE"><input type="hidden" name="action" value="locate"><input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="text" class="flat maxwidth150" name="locate_taxcode" placeholder="'.$langs->trans('IsnetSenderVkn').'" value="'.dol_escape_htmltag($locateTax).'"> ';
	print '<input type="text" class="flat maxwidth200" name="locate_number" placeholder="'.$langs->trans('IsnetInvoiceNumber').'" value="'.dol_escape_htmltag($locateNo).'" required> ';
	$unitText = '';
	if ($located) {
		$vn = $located->vendorNumber($vendors);
		$unitText = $vn !== '' ? $vn.(isset($vendors[$vn]) ? ' - '.$vendors[$vn] : '') : $langs->transnoentities('IsnetUnitMain');
	} elseif ($locateDone) {
		$unitText = $langs->transnoentities('IsnetUnitNotFound');
	}
	print '<input type="text" class="flat minwidth200" readonly placeholder="'.$langs->trans('IsnetUnitCurrent').'" value="'.dol_escape_htmltag($unitText).'"> ';
	print '<button type="submit" class="button small">'.img_picto('', 'search', 'class="pictofixedwidth"').$langs->trans('IsnetUnitLookupButton').'</button>';
	print '</form>';
	if ($located) {
		print '<div class="isnet-card-body">';
		print '<b>'.dol_escape_htmltag($located->invoice_number).'</b> · '.dol_print_date($located->invoice_date, 'day').' · '.dol_escape_htmltag($located->sender_name).' · '.price($located->total_payable, 0, $langs, 1, -1, -1, $located->currency_code);
		print ' · <span class="small">'.dol_escape_htmltag($located->status).'</span>';
		print ' · <a href="'.$_SERVER['PHP_SELF'].'?kind=INVOICE&action=detail&id='.$located->id.'">'.$langs->trans('IsnetDetail').'</a>';
		if ($canAct && count($vendors) > 1) {
			print '<form method="GET" action="'.$_SERVER['PHP_SELF'].'" class="inline-block marginleftonly">';
			print '<input type="hidden" name="kind" value="INVOICE"><input type="hidden" name="action" value="askmove"><input type="hidden" name="id" value="'.$located->id.'"><input type="hidden" name="token" value="'.newToken().'">';
			$opts = array();
			foreach ($vendors as $num => $vname) {
				if ($num !== $located->vendorNumber($vendors)) {
					$opts[$num] = $num.' - '.$vname;
				}
			}
			print $form->selectarray('to_vendor', $opts, '', 0, 0, 0, '', 0, 0, 0, '', 'minwidth200');
			print ' <button type="submit" class="button small">'.$langs->trans('IsnetUnitMove').'</button></form>';
		}
		print '</div>';
	}
	print '</details>';
}

/* ---------------------------------------------- card: filters */

$labels = array(
	'search' => $langs->trans('Search'), 'number' => $langs->trans('IsnetInvoiceNumber'), 'sender' => $langs->trans('IsnetSenderName'), 'taxcode' => $langs->trans('IsnetSenderVkn'),
	'ref' => $langs->trans('IsnetSourceInvoice'), 'scenario' => $langs->trans('IsnetScenario'), 'type' => $langs->trans('IsnetInvoiceTypeLabel'), 'status' => $langs->trans('Status'),
	'detail_status' => $langs->trans('IsnetDetailStatus'), 'approval' => $langs->trans('IsnetApproval'), 'currency' => $langs->trans('Currency'), 'vendor' => $langs->trans('IsnetUnit'),
	'unlinked' => $langs->trans('IsnetTransfer'), 'amount_min' => $langs->trans('IsnetPayableMin'), 'amount_max' => $langs->trans('IsnetPayableMax'),
	'date_start' => $langs->trans('IsnetInvoiceDateRange'), 'received_start' => $langs->trans('IsnetReceivedRange'),
);
$approvalOptions = array();
foreach (array('pending', 'expiring', 'accepted', 'auto', 'rejected', 'returned') as $k) {
	$approvalOptions[$k] = $langs->trans('IsnetApproval_'.$k);
}
$unlinkedOptions = array(1 => $langs->trans('IsnetTransferNo'), 2 => $langs->trans('IsnetTransferYes'));

// Active filter badges, each with a link that drops just that filter
$badges = array();
foreach ($filters as $k => $v) {
	if ($k === 'kind' || $k === 'date_end' || $k === 'received_end') {
		continue;
	}
	$without = $filters;
	unset($without[$k]);
	if ($k === 'date_start' || $k === 'received_start') {
		$endKey = $k === 'date_start' ? 'date_end' : 'received_end';
		unset($without[$endKey]);
		$text = dol_print_date($v, 'day').' - '.(!empty($filters[$endKey]) ? dol_print_date($filters[$endKey], 'day') : $langs->trans('Today'));
	} elseif ($k === 'approval') {
		$text = $approvalOptions[$v] ?? $v;
	} elseif ($k === 'unlinked') {
		$text = $unlinkedOptions[$v] ?? $v;
	} elseif ($k === 'vendor') {
		$text = $v.(isset($vendors[$v]) ? ' - '.$vendors[$v] : '');
	} else {
		$text = (string) $v;
	}
	$badges[] = '<span class="badge badge-secondary isnet-badge">'.dol_escape_htmltag($labels[$k] ?? $k).': '.dol_escape_htmltag(dol_trunc($text, 40)).' <a href="'.$_SERVER['PHP_SELF'].'?'.ltrim(isnetIncomingParams($without, $kind), '&').'" title="'.$langs->trans('RemoveFilter').'">&times;</a></span>';
}
// Remove the date filter on purpose: keep an explicit "all dates" state instead of falling back to the default
if (empty($filters['date_start']) && empty($filters['date_end']) && GETPOSTISSET('search_submitted')) {
	$badges[] = '<span class="badge badge-secondary isnet-badge">'.$langs->trans('IsnetInvoiceDateRange').': '.$langs->trans('All').'</span>';
}

print '<details class="isnet-card" open><summary>'.img_picto('', 'filter', 'class="pictofixedwidth"').$langs->trans('IsnetFilters').' <span class="opacitymedium">('.count($badges).')</span></summary>';
if (!empty($badges)) {
	print '<div class="isnet-card-body">'.implode(' ', $badges).'</div>';
}
print '<form method="GET" action="'.$_SERVER['PHP_SELF'].'" name="isnetfilter" class="isnet-card-body">';
print '<input type="hidden" name="kind" value="'.$kind.'"><input type="hidden" name="search_submitted" value="1">';
print '<input type="hidden" name="sortfield" value="'.dol_escape_htmltag($sortfield).'"><input type="hidden" name="sortorder" value="'.dol_escape_htmltag($sortorder).'">';

$opt = function ($values, $translatePrefix = '') use ($langs) {
	$out = array();
	foreach ($values as $v) {
		$out[$v] = $translatePrefix !== '' && $langs->trans($translatePrefix.$v) !== $translatePrefix.$v ? $langs->trans($translatePrefix.$v) : str_replace('_', ' ', $v);
	}
	return $out;
};
$row = function ($label, $html) {
	return '<tr><td class="titlefieldmiddle">'.$label.'</td><td>'.$html.'</td></tr>';
};

print '<div class="fichecenter"><div class="fichehalfleft"><table class="border tableforfield centpercent">';
print $row($langs->trans('IsnetApproval'), $form->selectarray('search_approval', $approvalOptions, $filters['approval'] ?? '', 1, 0, 0, '', 0, 0, 0, '', 'minwidth200'));
print $row($langs->trans('Status'), $form->selectarray('search_status', $opt($staticIncoming->distinctValues('status', $kind), 'IsnetSt_'), $filters['status'] ?? '', 1, 0, 0, '', 0, 0, 0, '', 'minwidth200'));
$detailStatuses = $staticIncoming->distinctValues('detail_status', $kind);
if (!empty($detailStatuses)) {
	print $row($langs->trans('IsnetDetailStatus'), $form->selectarray('search_detail_status', $opt($detailStatuses, 'IsnetSt_'), $filters['detail_status'] ?? '', 1, 0, 0, '', 0, 0, 0, '', 'minwidth200'));
}
print $row($langs->trans('IsnetInvoiceNumber'), '<input type="text" class="flat minwidth200" name="search_number" placeholder="'.$langs->trans('IsnetInvoiceNumber').'" value="'.dol_escape_htmltag($filters['number'] ?? '').'">');
print $row($langs->trans('IsnetScenario'), $form->selectarray('search_scenario', $opt($staticIncoming->distinctValues('scenario', $kind)), $filters['scenario'] ?? '', 1, 0, 0, '', 0, 0, 0, '', 'minwidth200'));
$senders = $staticIncoming->distinctValues('sender_name', $kind);
$senderInput = '<input type="text" class="flat minwidth300" name="search_sender" list="isnet_senders" minlength="3" placeholder="'.$langs->trans('IsnetSenderNameHint').'" value="'.dol_escape_htmltag($filters['sender'] ?? '').'">';
$senderInput .= '<datalist id="isnet_senders">';
foreach ($senders as $s) {
	$senderInput .= '<option value="'.dol_escape_htmltag($s).'">';
}
$senderInput .= '</datalist>';
print $row($langs->trans('IsnetSenderName'), $senderInput);
print $row($langs->trans('IsnetSenderVkn'), '<input type="text" class="flat maxwidth150" name="search_taxcode" inputmode="numeric" placeholder="'.$langs->trans('IsnetSenderVkn').'" value="'.dol_escape_htmltag($filters['taxcode'] ?? '').'">');
print $row($langs->trans('IsnetTransfer'), $form->selectarray('search_unlinked', $unlinkedOptions, $filters['unlinked'] ?? '', 1, 0, 0, '', 0, 0, 0, '', 'minwidth200'));
print '</table></div><div class="fichehalfright"><table class="border tableforfield centpercent">';
print $row($langs->trans('IsnetInvoiceTypeLabel'), $form->selectarray('search_type', $opt($staticIncoming->distinctValues('invoice_type', $kind)), $filters['type'] ?? '', 1, 0, 0, '', 0, 0, 0, '', 'minwidth200'));
print $row($langs->trans('IsnetInvoiceDateRange'), $form->selectDate($filters['date_start'] ?? -1, 'search_date_start', 0, 0, 1, 'isnetfilter', 1, 0).' - '.$form->selectDate($filters['date_end'] ?? -1, 'search_date_end', 0, 0, 1, 'isnetfilter', 1, 0));
print $row($langs->trans('IsnetReceivedRange'), $form->selectDate($filters['received_start'] ?? -1, 'search_received_start', 0, 0, 1, 'isnetfilter', 1, 0).' - '.$form->selectDate($filters['received_end'] ?? -1, 'search_received_end', 0, 0, 1, 'isnetfilter', 1, 0));
print $row($langs->trans('IsnetPayable'), '<input type="text" class="flat maxwidth100" name="search_amount_min" inputmode="decimal" placeholder="'.$langs->trans('IsnetPayableMin').'" value="'.dol_escape_htmltag($filters['amount_min'] ?? '').'"> - <input type="text" class="flat maxwidth100" name="search_amount_max" inputmode="decimal" placeholder="'.$langs->trans('IsnetPayableMax').'" value="'.dol_escape_htmltag($filters['amount_max'] ?? '').'">');
print $row($langs->trans('IsnetSourceInvoice'), '<input type="text" class="flat minwidth200" name="search_ref" placeholder="'.$langs->trans('IsnetSourceInvoice').'" value="'.dol_escape_htmltag($filters['ref'] ?? '').'">');
$currencies = $staticIncoming->distinctValues('currency_code', $kind);
if (count($currencies) > 1) {
	print $row($langs->trans('Currency'), $form->selectarray('search_currency', array_combine($currencies, $currencies), $filters['currency'] ?? '', 1, 0, 0, '', 0, 0, 0, '', 'maxwidth100'));
}
if (count($vendors) > 1) {
	$vopts = array();
	foreach ($vendors as $num => $vname) {
		$vopts[$num] = $num.' - '.$vname;
	}
	print $row($langs->trans('IsnetUnit'), $form->selectarray('search_vendor', $vopts, $filters['vendor'] ?? '', 1, 0, 0, '', 0, 0, 0, '', 'minwidth200'));
}
print $row($langs->trans('Search'), '<input type="text" class="flat minwidth200" name="search_all" placeholder="'.$langs->trans('IsnetSearchAllHint').'" value="'.dol_escape_htmltag($filters['search'] ?? '').'">');
print '</table></div></div><div class="clearboth"></div>';
print '<div class="right paddingtop">';
print '<button type="submit" class="button small" name="button_removefilter" value="1">'.img_picto('', 'eraser', 'class="pictofixedwidth"').$langs->trans('RemoveFilter').'</button> ';
print '<button type="submit" class="button">'.img_picto('', 'search', 'class="pictofixedwidth"').$langs->trans('Search').'</button>';
print '</div></form></details>';

/* ---------------------------------------------- detail panel */

if ($action === 'detail' && $id > 0) {
	$d = new IsnetIncoming($db);
	if ($d->fetch($id) > 0) {
		print '<div class="isnet-card isnet-detail">';
		print '<div class="isnet-card-title"><b>'.dol_escape_htmltag($d->invoice_number).'</b> · '.dol_escape_htmltag($d->sender_name).' <span class="opacitymedium">('.dol_escape_htmltag($d->sender_tax_code).')</span>';
		print '<a class="right" href="'.$backUrl.'">'.img_picto($langs->trans('Close'), 'close_title').'</a></div>';
		print '<table class="border tableforfield centpercent">';
		$vn = $d->vendorNumber($vendors);
		$info = array(
			'ETTN' => $d->ettn,
			$langs->trans('Date') => dol_print_date($d->invoice_date, 'day').' / '.$langs->trans('DateDue').': '.dol_print_date($d->due_date, 'day'),
			$langs->trans('IsnetReceivedDate') => dol_print_date($d->date_received, 'dayhour'),
			$langs->trans('IsnetScenario') => $d->scenario.' / '.$d->invoice_type,
			$langs->trans('Status') => trim($d->status.' '.$d->detail_status).($d->response_status !== '' ? ' / '.$d->response_status : ''),
			$langs->trans('IsnetSourceInvoice') => $d->billing_reference,
			$langs->trans('IsnetOrderNumber') => $d->order_number,
			$langs->trans('IsnetUnit') => $vn !== '' ? $vn.(isset($vendors[$vn]) ? ' - '.$vendors[$vn] : '') : '',
			$langs->trans('Address') => trim($d->sender_address.' '.$d->sender_zip.' '.$d->sender_town.' / '.$d->sender_city, ' /').($d->sender_tax_office !== '' ? ' · '.$d->sender_tax_office : ''),
		);
		foreach ($info as $k => $v) {
			if ((string) $v !== '') {
				print '<tr><td class="titlefieldmiddle">'.$k.'</td><td>'.dol_escape_htmltag($v).'</td></tr>';
			}
		}
		print '</table>';
		$lines = $d->lines();
		if (!empty($lines)) {
			print '<table class="noborder centpercent margintoponly"><tr class="liste_titre"><td>'.$langs->trans('Description').'</td><td class="right">'.$langs->trans('Qty').'</td><td>'.$langs->trans('Unit').'</td><td class="right">'.$langs->trans('PriceUHT').'</td><td class="right">'.$langs->trans('VAT').' %</td><td class="right">'.$langs->trans('Discount').'</td><td class="right">'.$langs->trans('AmountHT').'</td></tr>';
			foreach ($lines as $l) {
				print '<tr class="oddeven"><td>'.dol_escape_htmltag($l['name']).($l['note'] !== '' ? '<br><span class="small opacitymedium">'.dol_escape_htmltag($l['note']).'</span>' : '').'</td>';
				print '<td class="right">'.price($l['qty'], 0, $langs, 0, -1).'</td><td>'.dol_escape_htmltag($l['unit']).'</td>';
				print '<td class="right">'.price($l['unit_price'], 0, $langs, 0, -1, -1, $d->currency_code).'</td><td class="right">'.price($l['vat_rate'], 0, $langs, 0, -1).'</td>';
				print '<td class="right">'.($l['discount'] > 0 ? price($l['discount'], 0, $langs, 0, -1, -1, $d->currency_code) : '').'</td><td class="right">'.price($l['line_ext'], 0, $langs, 0, -1, -1, $d->currency_code).'</td></tr>';
			}
			print '<tr class="liste_total"><td colspan="6" class="right">'.$langs->trans('AmountHT').' / '.$langs->trans('VAT').' / '.$langs->trans('IsnetPayable').'</td><td class="right nowraponall">'.price($d->total_line_ext, 0, $langs, 1, -1, -1, $d->currency_code).'<br>'.price($d->total_vat, 0, $langs, 1, -1, -1, $d->currency_code).'<br><b>'.price($d->total_payable, 0, $langs, 1, -1, -1, $d->currency_code).'</b></td></tr>';
			print '</table>';
		}
		print '</div>';
	}
}

/* ---------------------------------------------- list */

$arrayofmassactions = array();
if ($canAct && !$isDespatch) {
	$arrayofmassactions = array(
		'import' => img_picto('', 'bill', 'class="pictofixedwidth"').$langs->trans('IsnetIncomingImport'),
		'linksupplier' => img_picto('', 'company', 'class="pictofixedwidth"').$langs->trans('IsnetIncomingLinkSupplier'),
		'accept' => img_picto('', 'check', 'class="pictofixedwidth"').$langs->trans('IsnetIncomingAccept'),
	);
}
$massactionbutton = $form->selectMassAction('', $arrayofmassactions);

print '<form method="POST" id="searchFormList" action="'.$_SERVER['PHP_SELF'].'?'.ltrim($param, '&').'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="sortfield" value="'.dol_escape_htmltag($sortfield).'"><input type="hidden" name="sortorder" value="'.dol_escape_htmltag($sortorder).'">';
print '<input type="hidden" name="page" value="'.$page.'">';

$exportLink = '<a class="btnTitle reposition" href="'.$_SERVER['PHP_SELF'].'?'.ltrim($param, '&').'&sortfield='.urlencode($sortfield).'&sortorder='.urlencode($sortorder).'&action=export&token='.newToken().'" title="'.$langs->trans('IsnetExportCsv').'">'.img_picto('', 'download', 'class="fa-lg"').'</a>';
print_barre_liste($langs->trans('IsnetResults'), $page, $_SERVER['PHP_SELF'], $param, $sortfield, $sortorder, $massactionbutton, count($list), $total, '', 0, $exportLink, '', $limit, 0, 0, 1);

$showVendor = count($vendors) > 1;
print '<div class="div-table-responsive"><table class="tagtable liste noborder centpercent">';
print '<tr class="liste_titre">';
if (!empty($arrayofmassactions)) {
	print '<td class="center maxwidthsearch">'.$form->showCheckAddButtons('checkforselect', 1).'</td>';
}
print_liste_field_titre('Date', $_SERVER['PHP_SELF'], 'invoice_date', '', $param, '', $sortfield, $sortorder);
print_liste_field_titre('IsnetInvoiceNumber', $_SERVER['PHP_SELF'], 'invoice_number', '', $param, '', $sortfield, $sortorder);
print_liste_field_titre('Supplier', $_SERVER['PHP_SELF'], 'sender_name', '', $param, '', $sortfield, $sortorder);
print_liste_field_titre('IsnetScenario', $_SERVER['PHP_SELF'], 'scenario', '', $param, '', $sortfield, $sortorder);
if ($showVendor) {
	print_liste_field_titre('IsnetUnit', $_SERVER['PHP_SELF'], 'company_vendor_number', '', $param, '', $sortfield, $sortorder);
}
print_liste_field_titre('AmountHT', $_SERVER['PHP_SELF'], 'total_line_ext', '', $param, '', $sortfield, $sortorder, 'right ');
print_liste_field_titre('VAT', $_SERVER['PHP_SELF'], 'total_vat', '', $param, '', $sortfield, $sortorder, 'right ');
print_liste_field_titre('IsnetPayable', $_SERVER['PHP_SELF'], 'total_payable', '', $param, '', $sortfield, $sortorder, 'right ');
print_liste_field_titre('Status', $_SERVER['PHP_SELF'], 'status', '', $param, '', $sortfield, $sortorder);
print_liste_field_titre('SupplierInvoice', $_SERVER['PHP_SELF'], '', '', $param, '', $sortfield, $sortorder);
print_liste_field_titre('', $_SERVER['PHP_SELF'], '', '', $param, '', $sortfield, $sortorder);
print '</tr>';
$ncols = 10 + ($showVendor ? 1 : 0) + (!empty($arrayofmassactions) ? 1 : 0);

if (empty($list)) {
	print '<tr class="oddeven"><td colspan="'.$ncols.'"><span class="opacitymedium">'.$langs->trans($total === 0 && empty($badges) ? 'IsnetIncomingEmpty' : 'NoRecordFound').'</span></td></tr>';
}

$socCache = array();
$approvalBadge = array('pending' => 'badge-status1', 'accepted' => 'badge-status4', 'auto' => 'badge-status4', 'rejected' => 'badge-status8', 'returned' => 'badge-status8');
foreach ($list as $d) {
	print '<tr class="oddeven'.($action === 'detail' && $id === $d->id ? ' highlight' : '').'">';
	if (!empty($arrayofmassactions)) {
		print '<td class="center"><input id="cb'.$d->id.'" class="flat checkforselect" type="checkbox" name="toselect[]" value="'.$d->id.'"></td>';
	}
	print '<td class="nowraponall">'.dol_print_date($d->invoice_date, 'day');
	if ($d->date_received) {
		print '<br><span class="small opacitymedium" title="'.$langs->trans('IsnetReceivedDate').'">'.dol_print_date($d->date_received, 'dayhour').'</span>';
	}
	print '</td>';
	print '<td class="nowraponall"><a href="'.$backUrl.'&action=detail&id='.$d->id.'">'.dol_escape_htmltag($d->invoice_number).'</a>';
	print '<br><span class="small opacitymedium" title="'.dol_escape_htmltag($d->ettn).'">'.dol_escape_htmltag(substr($d->ettn, 0, 8)).'…</span>';
	if ($d->billing_reference !== '') {
		print '<br><span class="small" title="'.$langs->trans('IsnetSourceInvoice').'">↳ '.dol_escape_htmltag($d->billing_reference).'</span>';
	}
	print '</td>';
	print '<td class="tdoverflowmax250">';
	if ($d->fk_soc > 0) {
		if (!isset($socCache[$d->fk_soc])) {
			$socCache[$d->fk_soc] = new Societe($db);
			$socCache[$d->fk_soc]->fetch($d->fk_soc);
		}
		print $socCache[$d->fk_soc]->getNomUrl(1, 'supplier', 40);
	} else {
		print dol_escape_htmltag($d->sender_name);
		if ($canAct && !$isDespatch) {
			print ' <a class="reposition" href="'.$backUrl.'&action=linksupplier&id='.$d->id.'&token='.newToken().'" title="'.$langs->trans('IsnetIncomingLinkSupplier').'">'.img_picto('', 'add').'</a>';
		}
	}
	print '<br><span class="small opacitymedium">'.dol_escape_htmltag($d->sender_tax_code).'</span>';
	print '</td>';
	print '<td class="nowraponall">'.dol_escape_htmltag($d->scenario).'<br><span class="small opacitymedium">'.dol_escape_htmltag($d->invoice_type).'</span></td>';
	if ($showVendor) {
		$vn = $d->vendorNumber($vendors);
		print '<td class="tdoverflowmax150" title="'.dol_escape_htmltag($vendors[$vn] ?? '').'">'.dol_escape_htmltag($vn !== '' ? ($vendors[$vn] ?? $vn) : '').'</td>';
	}
	print '<td class="right nowraponall">'.price($d->total_line_ext, 0, $langs, 1, -1, -1, $d->currency_code).'</td>';
	print '<td class="right nowraponall">'.price($d->total_vat, 0, $langs, 1, -1, -1, $d->currency_code).'</td>';
	print '<td class="right nowraponall"><b>'.price($d->total_payable, 0, $langs, 1, -1, -1, $d->currency_code).'</b></td>';
	print '<td class="nowraponall">';
	$st = $d->approvalState();
	if ($st !== '') {
		$label = $langs->trans('IsnetApproval_'.$st);
		$left = $d->replyDaysLeft();
		if ($left !== null) {
			$label .= ' · '.($left >= 0 ? $langs->trans('IsnetDaysLeft', $left) : $langs->trans('IsnetReplyExpired'));
		}
		print '<span class="badge '.$approvalBadge[$st].($left !== null && $left <= 2 ? ' badge-status8' : '').'" title="'.dol_escape_htmltag($d->status).'">'.dol_escape_htmltag($label).'</span>';
	} else {
		print '<span class="small">'.dol_escape_htmltag(str_replace('_', ' ', $d->status)).'</span>';
	}
	print '</td>';
	print '<td class="nowraponall">';
	if ($d->fk_facture_fourn > 0) {
		$ff = new FactureFournisseur($db);
		if ($ff->fetch($d->fk_facture_fourn) > 0) {
			print $ff->getNomUrl(1);
		}
	}
	print '</td>';
	print '<td class="nowraponall right">';
	if (!$isDespatch) {
		print '<a class="reposition paddingright" href="'.$_SERVER['PHP_SELF'].'?kind=INVOICE&action=viewer&id='.$d->id.'&token='.newToken().'" target="_blank" rel="noopener" title="'.$langs->trans('IsnetViewDocument').'">'.img_picto('', 'eye').'</a>';
	}
	if ($canAct) {
		if ($isDespatch) {
			if ($d->response_status === '') {
				print '<a class="butActionSmall" href="'.$backUrl.'&action=receipt&id='.$d->id.'&token='.newToken().'">'.$langs->trans('IsnetSendReceipt').'</a>';
			}
		} else {
			if ($d->fk_facture_fourn <= 0) {
				print '<a class="butActionSmall" href="'.$backUrl.'&action=import&id='.$d->id.'&token='.newToken().'">'.$langs->trans('IsnetIncomingImport').'</a> ';
			}
			if ($d->canReply() && $d->scenario === 'TICARIFATURA') {
				print '<a class="reposition" href="'.$backUrl.'&action=accept&id='.$d->id.'&token='.newToken().'">'.img_picto($langs->trans('IsnetIncomingAccept'), 'check').'</a> ';
				print '<a class="reposition" href="'.$backUrl.'&action=askreject&id='.$d->id.'&token='.newToken().'">'.img_picto($langs->trans('IsnetIncomingReject'), 'error').'</a>';
			}
		}
	}
	print '</td>';
	print '</tr>';
}

// Totals of every matching row (not only this page), per currency
if ($total > 0) {
	$lead = 4 + ($showVendor ? 1 : 0) + (!empty($arrayofmassactions) ? 1 : 0);
	foreach ($staticIncoming->totalsByCurrency($filters) as $cur => $t) {
		print '<tr class="liste_total"><td colspan="'.$lead.'" class="right">'.$langs->trans('Total').' '.dol_escape_htmltag($cur).' <span class="opacitymedium">('.$t['n'].')</span></td>';
		print '<td class="right nowraponall">'.price($t['ht'], 0, $langs, 1, -1, -1, $cur).'</td>';
		print '<td class="right nowraponall">'.price($t['vat'], 0, $langs, 1, -1, -1, $cur).'</td>';
		print '<td class="right nowraponall"><b>'.price($t['ttc'], 0, $langs, 1, -1, -1, $cur).'</b></td>';
		print '<td colspan="3"></td></tr>';
	}
}
print '</table></div>';
print '</form>';

print '<style>
.isnet-card { border: 1px solid var(--colortopbordertitle1, #ccc); border-radius: 6px; margin: 10px 0; background: var(--colorbacklineimpair1, #fff); }
.isnet-card > summary { cursor: pointer; padding: 8px 12px; font-weight: bold; }
.isnet-card-body, .isnet-card-title { padding: 6px 12px 10px 12px; }
.isnet-detail { padding-bottom: 8px; }
.isnet-badge { margin: 2px 4px 2px 0; display: inline-block; }
.isnet-badge a { margin-left: 4px; font-weight: bold; }
</style>';

llxFooter();
$db->close();
