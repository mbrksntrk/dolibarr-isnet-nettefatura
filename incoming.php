<?php
/* Copyright (C) 2026 M. Burak Şentürk <https://buraksenturk.net>
 * Licensed under GPL-3.0-or-later with attribution terms. See COPYING and ATTRIBUTION.md.
 *
 * Incoming supplier e-Fatura / e-İrsaliye documents: unit lookup, filters, list, detail, mass actions.
 */

// The document view loads in its own request (iframe): it must not rotate the CSRF token of the page around it
if (in_array($_GET['action'] ?? '', array('displaydoc', 'displaypdf'), true)) {
	define('NOTOKENRENEWAL', 1);
}

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
	// Drop-downs post -1 for their empty choice: an untouched drop-down is not a filter.
	$choice = function ($name, $check = 'aZ09') {
		$v = trim((string) GETPOST($name, $check));
		return $v === '-1' ? '' : $v;
	};
	$filters = array(
		'search' => trim(GETPOST('search_all', 'alphanohtml')),
		'number' => trim(GETPOST('search_number', 'alphanohtml')),
		'sender' => trim(GETPOST('search_sender', 'alphanohtml')),
		'taxcode' => trim(GETPOST('search_taxcode', 'alphanohtml')),
		'ref' => trim(GETPOST('search_ref', 'alphanohtml')),
		'scenario' => $choice('search_scenario'),
		'type' => $choice('search_type'),
		'status' => $choice('search_status'),
		'detail_status' => $choice('search_detail_status'),
		'approval' => $choice('search_approval'),
		'currency' => $choice('search_currency'),
		'vendor' => $choice('search_vendor', 'alphanohtml'),
		'unlinked' => in_array(GETPOSTINT('search_unlinked'), array(1, 2), true) ? GETPOSTINT('search_unlinked') : 0,
		'amount_min' => trim(GETPOST('search_amount_min', 'alphanohtml')),
		'amount_max' => trim(GETPOST('search_amount_max', 'alphanohtml')),
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
// Also cleans filters saved in the session by earlier versions, where untouched drop-downs were kept as -1.
$filters = array_filter($filters, function ($v) {
	return $v !== '' && $v !== null && $v !== 0 && $v !== false && (string) $v !== '-1';
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

// The document as the sender issued it, readable before import. The HTML comes from the sender's
// own XSLT, so it is served sandboxed: no scripts, no requests out, no access to this session.
if (($action === 'displaydoc' || $action === 'displaypdf') && $id > 0) {
	// The first view waits on the integrator: release the session so the rest of the UI is not blocked meanwhile
	session_write_close();
	$doc = new IsnetIncoming($db);
	$format = $action === 'displaypdf' ? 'pdf' : 'html';
	$content = $doc->fetch($id) > 0 ? $doc->rendering($format) : '';
	if ($format === 'pdf' && $content !== '') {
		header('Content-Type: application/pdf');
		header('Content-Disposition: '.(GETPOSTINT('inline') ? 'inline' : 'attachment').'; filename="'.dol_sanitizeFileName($doc->invoice_number ?: $doc->ettn).'.pdf"');
		header('Content-Length: '.strlen($content));
		print $content;
		$db->close();
		exit;
	}
	// No PDF falls through to the same plain message the HTML view shows (the session is closed: no flash message)
	header('Content-Type: text/html; charset=UTF-8');
	header("Content-Security-Policy: sandbox; default-src 'none'; img-src data:; style-src 'unsafe-inline'; font-src data:");
	header('X-Content-Type-Options: nosniff');
	header('Referrer-Policy: no-referrer');
	if ($content === '') {
		print '<!DOCTYPE html><html><body style="font-family: sans-serif; color: #666; padding: 2em;">';
		print dol_escape_htmltag($langs->transnoentities('IsnetRenderingFailed', $langs->transnoentities($doc->error ?: 'IsnetRenderingNotAvailable')));
		print '</body></html>';
	} else {
		print $content;
	}
	$db->close();
	exit;
}

if ($action === 'confirm_move' && $confirm === 'yes' && $id > 0 && $canAct) {
	$doc = new IsnetIncoming($db);
	$to = GETPOST('to_vendor', 'alphanohtml');
	if ($doc->fetch($id) > 0 && isset($vendors[$to])) {
		$r = $doc->moveToVendor($to, $user);
		setEventMessages($r > 0 ? $langs->trans('IsnetUnitMoved', $vendors[$to]) : $doc->error, null, $r > 0 ? 'mesgs' : 'errors');
	}
	header('Location: '.$backUrl.'&action=locate&locate_id='.$id.'&token='.newToken());
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

$head = array(
	array($_SERVER['PHP_SELF'].'?kind=INVOICE', $langs->trans('IsnetIncomingInvoices'), 'INVOICE'),
	array($_SERVER['PHP_SELF'].'?kind=DESPATCH', $langs->trans('IsnetIncomingDespatches'), 'DESPATCH'),
);
print dol_get_fiche_head($head, $kind, '', -1, '');

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

/* ---------------------------------------------- filters */

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

// Active filter chips, each with a link that drops just that filter
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
		$text = str_replace('_', ' ', (string) $v);
	}
	$badges[] = '<span class="isnet-chip">'.dol_escape_htmltag($labels[$k] ?? $k).': <b>'.dol_escape_htmltag(dol_trunc($text, 40)).'</b><a href="'.$_SERVER['PHP_SELF'].'?'.ltrim(isnetIncomingParams($without, $kind), '&').'" title="'.$langs->trans('RemoveFilter').'">&times;</a></span>';
}
// The date filter removed on purpose: say so, instead of silently falling back to the default
if (empty($filters['date_start']) && empty($filters['date_end']) && GETPOSTISSET('search_submitted')) {
	$badges[] = '<span class="isnet-chip">'.$langs->trans('IsnetInvoiceDateRange').': <b>'.$langs->trans('All').'</b></span>';
}
// Filters behind "more filters": that section opens by itself when one of them is in use
$advancedKeys = array('number', 'sender', 'taxcode', 'ref', 'scenario', 'type', 'status', 'detail_status', 'currency', 'vendor', 'amount_min', 'amount_max', 'received_start', 'received_end');
$nAdvanced = count(array_intersect_key($filters, array_flip($advancedKeys)));

$opt = function ($values, $translatePrefix = '') use ($langs) {
	$out = array();
	foreach ($values as $v) {
		$out[$v] = $translatePrefix !== '' && $langs->trans($translatePrefix.$v) !== $translatePrefix.$v ? $langs->trans($translatePrefix.$v) : str_replace('_', ' ', $v);
	}
	return $out;
};
$field = function ($label, $html, $css = '') {
	return '<div class="isnet-field'.($css !== '' ? ' '.$css : '').'"><span class="isnet-label">'.$label.'</span>'.$html.'</div>';
};
// Plain drop-downs: a select2 combo built inside a closed section ends up zero wide
$select = function ($name, $options, $key) use ($form, $filters, $langs) {
	return $form->selectarray($name, $options, $filters[$key] ?? '', 1, 0, 0, '', 0, 0, 0, '', 'isnet-input', 0, '', 0, 0);
};
$text = function ($name, $key, $more = '') use ($filters) {
	return '<input type="text" class="flat isnet-input" name="'.$name.'" value="'.dol_escape_htmltag($filters[$key] ?? '').'"'.$more.'>';
};
$range = function ($prefix, $startKey, $endKey) use ($form, $filters) {
	return '<span class="isnet-range">'.$form->selectDate($filters[$startKey] ?? -1, $prefix.'_start', 0, 0, 1, 'isnetfilter', 1, 0).'<span class="opacitymedium">–</span>'.$form->selectDate($filters[$endKey] ?? -1, $prefix.'_end', 0, 0, 1, 'isnetfilter', 1, 0).'</span>';
};

print '<form method="GET" action="'.$_SERVER['PHP_SELF'].'" name="isnetfilter" class="isnet-panel isnet-filter">';
print '<input type="hidden" name="kind" value="'.$kind.'"><input type="hidden" name="search_submitted" value="1">';
print '<input type="hidden" name="sortfield" value="'.dol_escape_htmltag($sortfield).'"><input type="hidden" name="sortorder" value="'.dol_escape_htmltag($sortorder).'">';
if ($limit != getDolGlobalInt('MAIN_SIZE_LISTE_LIMIT', 25)) {
	print '<input type="hidden" name="limit" value="'.((int) $limit).'">';
}

// Everyday filters, always in view
print '<div class="isnet-grid">';
print $field($langs->trans('Search'), '<input type="search" class="flat isnet-input" name="search_all" placeholder="'.$langs->trans('IsnetSearchAllHint').'" value="'.dol_escape_htmltag($filters['search'] ?? '').'">', 'isnet-span2');
print $field($langs->trans('IsnetInvoiceDateRange'), $range('search_date', 'date_start', 'date_end'), 'isnet-span2');
if (!$isDespatch) {
	print $field($langs->trans('IsnetApproval'), $select('search_approval', $approvalOptions, 'approval'));
	print $field($langs->trans('IsnetTransfer'), $select('search_unlinked', $unlinkedOptions, 'unlinked'));
}
print '</div>';

// Everything else
print '<details class="isnet-adv"'.($nAdvanced ? ' open' : '').'><summary>'.$langs->trans('IsnetMoreFilters').($nAdvanced ? ' <span class="badge badge-info">'.$nAdvanced.'</span>' : '').'</summary>';
print '<div class="isnet-grid">';
print $field($langs->trans('IsnetInvoiceNumber'), $text('search_number', 'number'));
$senderInput = $text('search_sender', 'sender', ' list="isnet_senders" minlength="3" placeholder="'.$langs->trans('IsnetSenderNameHint').'"').'<datalist id="isnet_senders">';
foreach ($staticIncoming->distinctValues('sender_name', $kind) as $s) {
	$senderInput .= '<option value="'.dol_escape_htmltag($s).'">';
}
$senderInput .= '</datalist>';
print $field($langs->trans('IsnetSenderName'), $senderInput, 'isnet-span2');
print $field($langs->trans('IsnetSenderVkn'), $text('search_taxcode', 'taxcode', ' inputmode="numeric"'));
print $field($langs->trans('IsnetScenario'), $select('search_scenario', $opt($staticIncoming->distinctValues('scenario', $kind)), 'scenario'));
print $field($langs->trans('IsnetInvoiceTypeLabel'), $select('search_type', $opt($staticIncoming->distinctValues('invoice_type', $kind)), 'type'));
print $field($langs->trans('Status'), $select('search_status', $opt($staticIncoming->distinctValues('status', $kind), 'IsnetSt_'), 'status'));
$detailStatuses = $staticIncoming->distinctValues('detail_status', $kind);
if (!empty($detailStatuses)) {
	print $field($langs->trans('IsnetDetailStatus'), $select('search_detail_status', $opt($detailStatuses, 'IsnetSt_'), 'detail_status'));
}
print $field($langs->trans('IsnetPayable'), '<span class="isnet-range">'.$text('search_amount_min', 'amount_min', ' inputmode="decimal" placeholder="'.$langs->trans('IsnetPayableMin').'"').'<span class="opacitymedium">–</span>'.$text('search_amount_max', 'amount_max', ' inputmode="decimal" placeholder="'.$langs->trans('IsnetPayableMax').'"').'</span>');
if (!$isDespatch) {
	print $field($langs->trans('IsnetSourceInvoice'), $text('search_ref', 'ref'));
}
$currencies = $staticIncoming->distinctValues('currency_code', $kind);
if (count($currencies) > 1) {
	print $field($langs->trans('Currency'), $select('search_currency', array_combine($currencies, $currencies), 'currency'));
}
if (count($vendors) > 1) {
	$vopts = array();
	foreach ($vendors as $num => $vname) {
		$vopts[$num] = $num.' - '.$vname;
	}
	print $field($langs->trans('IsnetUnit'), $select('search_vendor', $vopts, 'vendor'));
}
print $field($langs->trans('IsnetReceivedRange'), $range('search_received', 'received_start', 'received_end'), 'isnet-span2');
print '</div></details>';

// Active filters on the left, buttons on the right
print '<div class="isnet-filter-foot">';
print '<div class="isnet-chips">'.implode('', $badges).'</div>';
print '<div class="isnet-buttons">';
print '<button type="submit" class="button button-cancel small" name="button_removefilter" value="1" title="'.$langs->trans('IsnetResetFiltersHint').'">'.img_picto('', 'eraser', 'class="pictofixedwidth"').$langs->trans('IsnetResetFilters').'</button>';
print '<button type="submit" class="button small">'.img_picto('', 'search', 'class="pictofixedwidth"').$langs->trans('Search').'</button>';
print '</div></div>';
print '</form>';

/* ---------------------------------------------- unit lookup */

if (!$isDespatch) {
	print '<details class="isnet-panel isnet-adv isnet-lookup"'.($locateDone ? ' open' : '').'><summary>'.$langs->trans('IsnetUnitLookup').'</summary>';
	print '<form method="GET" action="'.$_SERVER['PHP_SELF'].'" class="isnet-grid">';
	print '<input type="hidden" name="kind" value="INVOICE"><input type="hidden" name="action" value="locate"><input type="hidden" name="token" value="'.newToken().'">';
	print $field($langs->trans('IsnetSenderVkn'), '<input type="text" class="flat isnet-input" name="locate_taxcode" inputmode="numeric" value="'.dol_escape_htmltag($locateTax).'">');
	print $field($langs->trans('IsnetInvoiceNumber'), '<input type="text" class="flat isnet-input" name="locate_number" value="'.dol_escape_htmltag($locateNo).'" required>');
	$unitText = '';
	if ($located) {
		$vn = $located->vendorNumber($vendors);
		$unitText = $vn !== '' ? $vn.(isset($vendors[$vn]) ? ' - '.$vendors[$vn] : '') : $langs->transnoentities('IsnetUnitMain');
	} elseif ($locateDone) {
		$unitText = $langs->transnoentities('IsnetUnitNotFound');
	}
	print $field($langs->trans('IsnetUnitCurrent'), '<input type="text" class="flat isnet-input" readonly value="'.dol_escape_htmltag($unitText).'">');
	print '<div class="isnet-field isnet-field-button"><button type="submit" class="button small">'.img_picto('', 'search', 'class="pictofixedwidth"').$langs->trans('IsnetUnitLookupButton').'</button></div>';
	print '</form>';
	if ($located) {
		print '<div class="isnet-located">';
		print '<a href="'.$_SERVER['PHP_SELF'].'?kind=INVOICE&action=view&id='.$located->id.'#isnet-detail"><b>'.dol_escape_htmltag($located->invoice_number).'</b></a> · '.dol_print_date($located->invoice_date, 'day').' · '.dol_escape_htmltag($located->sender_name).' · '.price($located->total_payable, 0, $langs, 1, -1, -1, $located->currency_code);
		if ($canAct && count($vendors) > 1) {
			print '<form method="GET" action="'.$_SERVER['PHP_SELF'].'" class="isnet-inline">';
			print '<input type="hidden" name="kind" value="INVOICE"><input type="hidden" name="action" value="askmove"><input type="hidden" name="id" value="'.$located->id.'"><input type="hidden" name="token" value="'.newToken().'">';
			$opts = array();
			foreach ($vendors as $num => $vname) {
				if ($num !== $located->vendorNumber($vendors)) {
					$opts[$num] = $num.' - '.$vname;
				}
			}
			print $form->selectarray('to_vendor', $opts, '', 0, 0, 0, '', 0, 0, 0, '', 'minwidth200', 0);
			print '<button type="submit" class="button small">'.$langs->trans('IsnetUnitMove').'</button></form>';
		}
		print '</div>';
	}
	print '</details>';
}

/* ---------------------------------------------- detail / preview panel */

if ($action === 'view' && $id > 0) {
	$d = new IsnetIncoming($db);
	if ($d->fetch($id) > 0) {
		$isInvoice = ($d->doc_kind === 'INVOICE');
		print '<div class="isnet-panel isnet-detail" id="isnet-detail">';

		// Title and the things one can do with the document
		print '<div class="isnet-detail-head">';
		print '<div><span class="isnet-detail-no">'.dol_escape_htmltag($d->invoice_number).'</span> <span class="opacitymedium">'.dol_print_date($d->invoice_date, 'day').'</span><br>';
		print dol_escape_htmltag($d->sender_name).' <span class="opacitymedium">('.dol_escape_htmltag($d->sender_tax_code).')</span></div>';
		print '<div class="isnet-detail-actions">';
		if ($isInvoice) {
			print '<div class="isnet-group">';
			print '<a class="button small" href="'.$_SERVER['PHP_SELF'].'?kind=INVOICE&action=displaypdf&id='.$d->id.'&token='.newToken().'">'.img_picto('', 'pdf', 'class="pictofixedwidth"').$langs->trans('IsnetDownloadPdf').'</a>';
			print '</div>';
			if ($canAct) {
				print '<div class="isnet-group">';
				if ($d->fk_facture_fourn > 0) {
					$ff = new FactureFournisseur($db);
					if ($ff->fetch($d->fk_facture_fourn) > 0) {
						print $ff->getNomUrl(1);
					}
				} else {
					print '<a class="button small" href="'.$backUrl.'&action=import&id='.$d->id.'&token='.newToken().'">'.img_picto('', 'bill', 'class="pictofixedwidth"').$langs->trans('IsnetIncomingImport').'</a>';
				}
				print '</div>';
				if ($d->canReply() && $d->scenario === 'TICARIFATURA') {
					print '<div class="isnet-group">';
					print '<a class="button small" href="'.$backUrl.'&action=accept&id='.$d->id.'&token='.newToken().'">'.img_picto('', 'check', 'class="pictofixedwidth"').$langs->trans('IsnetIncomingAccept').'</a>';
					print '<a class="button small button-cancel" href="'.$backUrl.'&action=askreject&id='.$d->id.'&token='.newToken().'">'.img_picto('', 'error', 'class="pictofixedwidth"').$langs->trans('IsnetIncomingReject').'</a>';
					print '</div>';
				}
			}
		}
		print '<a class="isnet-close" href="'.$backUrl.'" title="'.$langs->trans('Close').'">&times;</a>';
		print '</div></div>';

		// Key facts in two columns
		$vn = $d->vendorNumber($vendors);
		$st = $d->approvalState();
		$info = array(
			$langs->trans('IsnetPayable') => '<b>'.price($d->total_payable, 0, $langs, 1, -1, -1, $d->currency_code).'</b> <span class="opacitymedium">('.$langs->trans('AmountHT').' '.price($d->total_line_ext, 0, $langs, 1, -1, -1, $d->currency_code).' + '.$langs->trans('VAT').' '.price($d->total_vat, 0, $langs, 1, -1, -1, $d->currency_code).')</span>',
			$langs->trans('IsnetScenario') => dol_escape_htmltag(str_replace('_', ' ', $d->scenario.' / '.$d->invoice_type)),
			$langs->trans('Status') => ($st !== '' ? dol_escape_htmltag($langs->trans('IsnetApproval_'.$st)).' · ' : '').'<span class="opacitymedium">'.dol_escape_htmltag(str_replace('_', ' ', trim($d->status.' '.$d->detail_status))).'</span>',
			$langs->trans('DateDue') => dol_print_date($d->due_date, 'day'),
			$langs->trans('IsnetReceivedDate') => dol_print_date($d->date_received, 'dayhour'),
			$langs->trans('IsnetSourceInvoice') => dol_escape_htmltag($d->billing_reference),
			$langs->trans('IsnetOrderNumber') => dol_escape_htmltag($d->order_number),
			$langs->trans('IsnetUnit') => dol_escape_htmltag($vn !== '' ? $vn.(isset($vendors[$vn]) ? ' - '.$vendors[$vn] : '') : ''),
			$langs->trans('Address') => dol_escape_htmltag(trim($d->sender_address.' '.$d->sender_zip.' '.$d->sender_town.' / '.$d->sender_city, ' /').($d->sender_tax_office !== '' ? ' · '.$d->sender_tax_office : '')),
			'ETTN' => '<span class="small">'.dol_escape_htmltag($d->ettn).'</span>',
		);
		$info = array_filter($info, function ($v) {
			$t = trim(strip_tags($v));
			return $t !== '' && $t !== '0';
		});
		print '<dl class="isnet-facts">';
		foreach ($info as $k => $v) {
			print '<div><dt>'.$k.'</dt><dd>'.$v.'</dd></div>';
		}
		print '</dl>';

		// The document itself as the sender issued it (integrator's HTML view, kept locally after the first look)
		if ($isInvoice) {
			print '<div class="isnet-doc-wrap"><div class="isnet-doc-loading">'.$langs->trans('IsnetDocumentLoading').'</div>';
			print '<iframe class="isnet-doc" sandbox="" referrerpolicy="no-referrer" title="'.dol_escape_htmltag($d->invoice_number).'" src="'.$_SERVER['PHP_SELF'].'?kind=INVOICE&action=displaydoc&id='.$d->id.'"></iframe></div>';
			print '<div class="small opacitymedium paddingtop">'.$langs->trans('IsnetDocumentHint').' <a href="'.$_SERVER['PHP_SELF'].'?kind=INVOICE&action=displaypdf&inline=1&id='.$d->id.'&token='.newToken().'" target="_blank" rel="noopener">'.$langs->trans('IsnetOpenPdf').'</a>';
			print ' · <a href="'.$_SERVER['PHP_SELF'].'?kind=INVOICE&action=viewer&id='.$d->id.'&token='.newToken().'" target="_blank" rel="noopener">'.$langs->trans('IsnetViewDocument').'</a></div>';
		}

		// Lines as received in the list data (the document view above shows them too)
		$lines = $d->lines();
		if (!empty($lines)) {
			print '<details class="isnet-lines"'.($isInvoice ? '' : ' open').'><summary>'.$langs->trans('IsnetLinesCount', count($lines)).'</summary>';
			print '<table class="noborder centpercent"><tr class="liste_titre"><td>'.$langs->trans('Description').'</td><td class="right">'.$langs->trans('Qty').'</td><td>'.$langs->trans('Unit').'</td><td class="right">'.$langs->trans('PriceUHT').'</td><td class="right">'.$langs->trans('VAT').' %</td><td class="right">'.$langs->trans('Discount').'</td><td class="right">'.$langs->trans('AmountHT').'</td></tr>';
			foreach ($lines as $l) {
				print '<tr class="oddeven"><td>'.dol_escape_htmltag($l['name']).($l['note'] !== '' ? '<br><span class="small opacitymedium">'.dol_escape_htmltag($l['note']).'</span>' : '').'</td>';
				print '<td class="right">'.price($l['qty'], 0, $langs, 0, -1).'</td><td>'.dol_escape_htmltag($l['unit']).'</td>';
				print '<td class="right">'.price($l['unit_price'], 0, $langs, 0, -1, -1, $d->currency_code).'</td><td class="right">'.price($l['vat_rate'], 0, $langs, 0, -1).'</td>';
				print '<td class="right">'.($l['discount'] > 0 ? price($l['discount'], 0, $langs, 0, -1, -1, $d->currency_code) : '').'</td><td class="right">'.price($l['line_ext'], 0, $langs, 0, -1, -1, $d->currency_code).'</td></tr>';
			}
			print '</table></details>';
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
if ($showVendor) {
	print_liste_field_titre('IsnetUnit', $_SERVER['PHP_SELF'], 'company_vendor_number', '', $param, '', $sortfield, $sortorder);
}
print_liste_field_titre('IsnetPayable', $_SERVER['PHP_SELF'], 'total_payable', '', $param, '', $sortfield, $sortorder, 'right ');
print_liste_field_titre('Status', $_SERVER['PHP_SELF'], 'status', '', $param, '', $sortfield, $sortorder);
print_liste_field_titre('', $_SERVER['PHP_SELF'], '', '', $param, '', $sortfield, $sortorder);
print '</tr>';
$ncols = 6 + ($showVendor ? 1 : 0) + (!empty($arrayofmassactions) ? 1 : 0);

if (empty($list)) {
	print '<tr class="oddeven"><td colspan="'.$ncols.'"><span class="opacitymedium">'.$langs->trans($total === 0 && empty($badges) ? 'IsnetIncomingEmpty' : 'NoRecordFound').'</span></td></tr>';
}

$socCache = array();
$approvalBadge = array('pending' => 'badge-status1', 'accepted' => 'badge-status4', 'auto' => 'badge-status4', 'rejected' => 'badge-status8', 'returned' => 'badge-status8');
foreach ($list as $d) {
	print '<tr class="oddeven'.($action === 'view' && $id === $d->id ? ' highlight' : '').'">';
	if (!empty($arrayofmassactions)) {
		print '<td class="center"><input id="cb'.$d->id.'" class="flat checkforselect" type="checkbox" name="toselect[]" value="'.$d->id.'"></td>';
	}
	print '<td class="nowraponall">'.dol_print_date($d->invoice_date, 'day');
	if ($d->date_received) {
		print '<br><span class="small opacitymedium" title="'.$langs->trans('IsnetReceivedDate').'">'.dol_print_date($d->date_received, 'dayhour').'</span>';
	}
	print '</td>';
	print '<td class="nowraponall"><a href="'.$backUrl.'&action=view&id='.$d->id.'#isnet-detail" title="'.$langs->trans('IsnetPreview').'">'.dol_escape_htmltag($d->invoice_number).'</a>';
	print '<br><span class="small opacitymedium" title="ETTN '.dol_escape_htmltag($d->ettn).'">'.dol_escape_htmltag(str_replace('_', ' ', $d->scenario.($d->invoice_type !== '' ? ' · '.$d->invoice_type : ''))).'</span>';
	if ($d->billing_reference !== '') {
		print '<br><span class="small" title="'.$langs->trans('IsnetSourceInvoice').'">↳ '.dol_escape_htmltag($d->billing_reference).'</span>';
	}
	print '</td>';
	print '<td class="tdoverflowmax180">';
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
	if ($showVendor) {
		$vn = $d->vendorNumber($vendors);
		print '<td class="tdoverflowmax150" title="'.dol_escape_htmltag($vendors[$vn] ?? '').'">'.dol_escape_htmltag($vn !== '' ? ($vendors[$vn] ?? $vn) : '').'</td>';
	}
	print '<td class="right nowraponall"><b>'.price($d->total_payable, 0, $langs, 1, -1, -1, $d->currency_code).'</b>';
	print '<br><span class="small opacitymedium" title="'.$langs->trans('AmountHT').' / '.$langs->trans('VAT').'">'.price($d->total_line_ext, 0, $langs, 1, -1, -1).' + '.price($d->total_vat, 0, $langs, 1, -1, -1).'</span></td>';
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
	print '<td class="nowraponall right"><div class="isnet-rowact">';
	print '<a class="isnet-show" href="'.$backUrl.'&action=view&id='.$d->id.'#isnet-detail" title="'.$langs->trans('IsnetPreview').'">'.img_picto('', 'eye', 'class="pictofixedwidth"').$langs->trans('IsnetShow').'</a>';
	if ($d->fk_facture_fourn > 0) {
		$ff = new FactureFournisseur($db);
		if ($ff->fetch($d->fk_facture_fourn) > 0) {
			print '<span title="'.$langs->trans('SupplierInvoice').'">'.$ff->getNomUrl(1).'</span>';
		}
	}
	if ($canAct) {
		if ($isDespatch) {
			if ($d->response_status === '') {
				print '<a class="butActionSmall" href="'.$backUrl.'&action=receipt&id='.$d->id.'&token='.newToken().'">'.$langs->trans('IsnetSendReceipt').'</a>';
			}
		} else {
			if ($d->fk_facture_fourn <= 0) {
				print '<a class="butActionSmall" href="'.$backUrl.'&action=import&id='.$d->id.'&token='.newToken().'">'.$langs->trans('IsnetIncomingImport').'</a>';
			}
			if ($d->canReply() && $d->scenario === 'TICARIFATURA') {
				print '<a class="reposition" href="'.$backUrl.'&action=accept&id='.$d->id.'&token='.newToken().'">'.img_picto($langs->trans('IsnetIncomingAccept'), 'check').'</a>';
				print '<a class="reposition" href="'.$backUrl.'&action=askreject&id='.$d->id.'&token='.newToken().'">'.img_picto($langs->trans('IsnetIncomingReject'), 'error').'</a>';
			}
		}
	}
	print '</div></td>';
	print '</tr>';
}

// Totals of every matching row (not only this page), per currency
if ($total > 0) {
	$lead = 3 + ($showVendor ? 1 : 0) + (!empty($arrayofmassactions) ? 1 : 0);
	foreach ($staticIncoming->totalsByCurrency($filters) as $cur => $t) {
		print '<tr class="liste_total"><td colspan="'.$lead.'" class="right">'.$langs->trans('Total').' '.dol_escape_htmltag($cur).' <span class="opacitymedium">('.$t['n'].')</span></td>';
		print '<td class="right nowraponall"><b>'.price($t['ttc'], 0, $langs, 1, -1, -1, $cur).'</b>';
		print '<br><span class="small opacitymedium">'.price($t['ht'], 0, $langs, 1, -1, -1).' + '.price($t['vat'], 0, $langs, 1, -1, -1).'</span></td>';
		print '<td colspan="2"></td></tr>';
	}
}
print '</table></div>';
print '</form>';

print dol_get_fiche_end(-1);

print '<style>
.page-incoming .isnet-panel { border: 1px solid var(--cds-border-subtle-01, var(--colortopbordertitle1, #ddd)); border-radius: var(--cs-radius, 8px); padding: 14px 16px; margin: 10px 0 14px 0; background: var(--cds-layer-01, var(--colorbacklineimpair1, #fff)); }
.isnet-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); gap: 12px 16px; align-items: end; }
.isnet-span2 { grid-column: span 2; }
.isnet-field { display: flex; flex-direction: column; gap: 4px; min-width: 0; }
.isnet-label { font-size: var(--cds-label-01-font-size, 0.85em); color: var(--cds-text-secondary, inherit); opacity: var(--cs-label-opacity, 0.7); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.isnet-field .isnet-input, .isnet-field select.isnet-input { width: 100%; max-width: none; box-sizing: border-box; margin: 0; }
.isnet-range { display: flex; align-items: center; gap: 6px; min-width: 0; }
.isnet-range > .isnet-input { flex: 1 1 0; min-width: 0; }
.isnet-range .divfordateinput { display: flex; align-items: center; gap: 4px; flex: 1 1 0; min-width: 0; }
.isnet-range .divfordateinput input { flex: 1 1 auto; width: 100%; min-width: 0; max-width: none; box-sizing: border-box; }
.isnet-adv { margin-top: 12px; }
.isnet-adv > summary { cursor: pointer; width: fit-content; color: var(--cds-link-primary, var(--colortextlink, #0a1464)); user-select: none; }
.isnet-adv[open] > summary { margin-bottom: 12px; }
.isnet-adv > .isnet-grid { padding-top: 12px; border-top: 1px dashed var(--cds-border-subtle-01, var(--colortopbordertitle1, #ddd)); }
.page-incoming .isnet-lookup { padding-top: 10px; padding-bottom: 10px; }
.isnet-lookup .isnet-adv, .isnet-lookup.isnet-adv { margin-top: 0; }
.isnet-field-button { justify-content: flex-end; }
.isnet-located { margin-top: 12px; display: flex; flex-wrap: wrap; align-items: center; gap: 8px 12px; }
.isnet-inline { display: inline-flex; gap: 6px; align-items: center; }
.isnet-filter-foot { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 10px; margin-top: 14px; }
.isnet-chips { display: flex; flex-wrap: wrap; gap: 6px; min-width: 0; }
.isnet-chip { display: inline-flex; align-items: center; padding: 3px 4px 3px 10px; border-radius: 14px; background: var(--cds-tag-background-gray, var(--colorbacktitle1, #eef0f4)); color: var(--cds-tag-color-gray, inherit); font-size: var(--cds-label-01-font-size, 0.9em); }
.isnet-chip a { padding: 0 6px; margin-left: 4px; font-weight: bold; text-decoration: none; opacity: 0.6; }
.isnet-chip a:hover { opacity: 1; }
.isnet-buttons { display: flex; gap: 8px; margin-left: auto; }
.isnet-buttons .button, .isnet-detail-actions .button { margin: 0; }
.isnet-detail { position: relative; }
.isnet-detail-head { padding-right: 28px; display: flex; justify-content: space-between; align-items: flex-start; gap: 12px 20px; flex-wrap: wrap; }
.isnet-detail-no { font-size: 1.25em; font-weight: bold; }
.isnet-detail-actions { display: flex; align-items: center; flex-wrap: wrap; gap: 10px 20px; }
.isnet-group { display: flex; align-items: center; gap: 8px; }
.isnet-group + .isnet-group { padding-left: 20px; border-left: 1px solid var(--cds-border-subtle-01, var(--colortopbordertitle1, #ddd)); }
.isnet-close { position: absolute; top: 10px; right: 12px; font-size: 1.6em; line-height: 1; padding: 0 4px; text-decoration: none; opacity: 0.6; }
.isnet-close:hover { opacity: 1; }
.isnet-facts { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 10px 24px; margin: 14px 0; }
.isnet-facts dt { font-size: var(--cds-label-01-font-size, 0.85em); color: var(--cds-text-secondary, inherit); opacity: var(--cs-label-opacity, 0.7); }
.isnet-facts dd { margin: 2px 0 0 0; overflow-wrap: anywhere; }
.isnet-doc-wrap { position: relative; border: 1px solid var(--cds-border-subtle-01, var(--colortopbordertitle1, #ddd)); border-radius: var(--cs-radius, 6px); background: #fff; overflow: hidden; }
.isnet-doc-loading { position: absolute; top: 40px; left: 0; right: 0; text-align: center; color: var(--cds-text-helper, #888); }
.isnet-doc { position: relative; display: block; width: 100%; height: 80vh; min-height: 480px; border: 0; background: transparent; }
.isnet-lines { margin-top: 12px; }
.isnet-lines > summary { cursor: pointer; padding: 4px 0; color: var(--cds-link-primary, var(--colortextlink, #0a1464)); }
.page-incoming .div-table-responsive { width: 0; min-width: 100%; }
.page-incoming .isnet-panel, .page-incoming .isnet-filter { width: 0; min-width: 100%; box-sizing: border-box; }
.isnet-rowact { display: inline-flex; align-items: center; justify-content: flex-end; gap: 16px; }
.isnet-rowact .butActionSmall { margin: 0; }
.isnet-show { white-space: nowrap; }
@media (max-width: 767px) {
	.isnet-grid { grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); }
	.isnet-span2 { grid-column: 1 / -1; }
	.isnet-detail-actions { width: 100%; }
	.isnet-group + .isnet-group { padding-left: 0; border-left: 0; }
	.isnet-buttons { width: 100%; }
	.isnet-buttons .button { flex: 1; }
	.isnet-doc { height: 70vh; }
}
</style>';

llxFooter();
$db->close();
