<?php
/* Copyright (C) 2026 M. Burak Şentürk <https://buraksenturk.net>
 * Licensed under GPL-3.0-or-later with attribution terms. See COPYING and ATTRIBUTION.md.
 *
 * Incoming supplier e-Fatura documents.
 */

$res = 0;
if (!$res && file_exists("../main.inc.php")) {
	$res = @include "../main.inc.php";
}
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
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
$id = GETPOSTINT('id');
$search = GETPOST('search', 'alphanohtml');
$onlyUnlinked = GETPOSTINT('unlinked');
$kind = GETPOST('kind', 'aZ09') === 'DESPATCH' ? 'DESPATCH' : 'INVOICE';
$backUrl = $_SERVER['PHP_SELF'].'?kind='.$kind.'&unlinked='.$onlyUnlinked.($search !== '' ? '&search='.urlencode($search) : '');

/* ------------------------------------------------------------------ actions */

if ($action === 'sync' && $canAct) {
	$s = new IsnetIncoming($db);
	$n = $kind === 'DESPATCH' ? $s->syncDespatch(getDolGlobalInt('ISNETEFATURA_INCOMING_DAYS', 30)) : $s->sync(getDolGlobalInt('ISNETEFATURA_INCOMING_DAYS', 30));
	if ($n < 0) {
		setEventMessages($s->error, null, 'errors');
	} else {
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

/* ------------------------------------------------------------------ view */

$form = new Form($db);
llxHeader('', $langs->trans('IsnetIncomingTitle'));

$list = (new IsnetIncoming($db))->fetchAll(array('search' => $search, 'unlinked' => $onlyUnlinked, 'kind' => $kind));

$morehtml = '';
if ($canAct) {
	$morehtml = '<a class="butAction" href="'.$_SERVER['PHP_SELF'].'?action=sync&token='.newToken().'&kind='.$kind.'&unlinked='.$onlyUnlinked.'">'.$langs->trans('IsnetIncomingSync').'</a>';
}
print load_fiche_titre($langs->trans('IsnetIncomingTitle'), $morehtml, 'supplier_invoice');
print '<div class="tabBar"><a class="'.($kind === 'INVOICE' ? 'butAction' : 'butActionSmall').'" href="'.$_SERVER['PHP_SELF'].'?kind=INVOICE">'.$langs->trans('IsnetIncomingInvoices').'</a> <a class="'.($kind === 'DESPATCH' ? 'butAction' : 'butActionSmall').'" href="'.$_SERVER['PHP_SELF'].'?kind=DESPATCH">'.$langs->trans('IsnetIncomingDespatches').'</a></div><br>';
print '<span class="opacitymedium">'.$langs->trans('IsnetIncomingDesc', getDolGlobalInt('ISNETEFATURA_INCOMING_DAYS', 30)).'</span><br><br>';

print '<form method="GET" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="kind" value="'.$kind.'"><input type="text" class="flat" name="search" placeholder="'.$langs->trans('Search').'" value="'.dol_escape_htmltag($search).'"> ';
print '<label><input type="checkbox" name="unlinked" value="1"'.($onlyUnlinked ? ' checked' : '').'> '.$langs->trans('IsnetIncomingOnlyUnlinked').'</label> ';
print '<input type="submit" class="button small" value="'.$langs->trans('Search').'">';
print '</form><br>';

if ($action === 'askreject' && $id > 0) {
	print $form->formconfirm($_SERVER['PHP_SELF'].'?id='.$id.'&unlinked='.$onlyUnlinked, $langs->trans('IsnetIncomingReject'), $langs->trans('IsnetIncomingRejectText'), 'reject',
		array(array('type' => 'text', 'name' => 'reason', 'label' => $langs->trans('IsnetIncomingRejectReason'), 'value' => '', 'size' => 60)), '', 1);
}

print '<div class="div-table-responsive"><table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<td>'.$langs->trans('Date').'</td><td>'.$langs->trans('IsnetInvoiceNumber').'</td><td>'.$langs->trans('Supplier').'</td>';
print '<td>'.$langs->trans('IsnetScenario').'</td><td class="right">'.$langs->trans('AmountHT').'</td><td class="right">'.$langs->trans('VAT').'</td><td class="right">'.$langs->trans('AmountTTC').'</td>';
print '<td>'.$langs->trans('Status').'</td><td>'.$langs->trans('IsnetIncomingReply').'</td><td>'.$langs->trans('SupplierInvoice').'</td><td></td>';
print '</tr>';
if (empty($list)) {
	print '<tr class="oddeven"><td colspan="11" class="opacitymedium">'.$langs->trans('IsnetIncomingEmpty').'</td></tr>';
}
foreach ($list as $d) {
	print '<tr class="oddeven">';
	print '<td>'.dol_print_date($d->invoice_date, 'day').'</td>';
	print '<td>'.dol_escape_htmltag($d->invoice_number).'<br><span class="small opacitymedium">'.dol_escape_htmltag(substr($d->ettn, 0, 8)).'…</span></td>';
	print '<td>';
	if ($d->fk_soc > 0) {
		$soc = new Societe($db);
		if ($soc->fetch($d->fk_soc) > 0) {
			print $soc->getNomUrl(1);
		}
	} else {
		print dol_escape_htmltag($d->sender_name).'<br><span class="small opacitymedium">'.dol_escape_htmltag($d->sender_tax_code).'</span>';
	}
	print '</td>';
	print '<td>'.dol_escape_htmltag($d->scenario).'<br><span class="small opacitymedium">'.dol_escape_htmltag($d->invoice_type).'</span></td>';
	print '<td class="right">'.price($d->total_line_ext, 0, $langs, 1, -1, -1, $d->currency_code).'</td>';
	print '<td class="right">'.price($d->total_vat, 0, $langs, 1, -1, -1, $d->currency_code).'</td>';
	print '<td class="right">'.price($d->total_payable, 0, $langs, 1, -1, -1, $d->currency_code).'</td>';
	print '<td><span class="small">'.dol_escape_htmltag($d->status).'</span></td>';
	print '<td>'.($d->response_status !== '' ? '<span class="badge badge-status'.($d->response_status === 'RED' ? '8' : '4').'">'.dol_escape_htmltag($d->response_status).'</span>' : '').'</td>';
	print '<td>';
	if ($d->fk_facture_fourn > 0) {
		$ff = new FactureFournisseur($db);
		if ($ff->fetch($d->fk_facture_fourn) > 0) {
			print $ff->getNomUrl(1);
		}
	}
	print '</td>';
	print '<td class="nowraponall right">';
	if ($canAct) {
		if ($kind === 'DESPATCH') {
			if ($d->response_status === '') {
				print '<a class="butActionSmall" href="'.$_SERVER['PHP_SELF'].'?action=receipt&id='.$d->id.'&kind=DESPATCH&token='.newToken().'">'.$langs->trans('IsnetSendReceipt').'</a>';
			}
		} elseif ($d->fk_facture_fourn <= 0) {
			print '<a class="butActionSmall" href="'.$_SERVER['PHP_SELF'].'?action=import&id='.$d->id.'&unlinked='.$onlyUnlinked.'&token='.newToken().'">'.$langs->trans('IsnetIncomingImport').'</a> ';
		}
		if ($d->response_status === '' && $d->scenario === 'TICARIFATURA') {
			print '<a class="reposition" href="'.$_SERVER['PHP_SELF'].'?action=accept&id='.$d->id.'&unlinked='.$onlyUnlinked.'&token='.newToken().'">'.img_picto($langs->trans('IsnetIncomingAccept'), 'check').'</a> ';
			print '<a class="reposition" href="'.$_SERVER['PHP_SELF'].'?action=askreject&id='.$d->id.'&unlinked='.$onlyUnlinked.'&token='.newToken().'">'.img_picto($langs->trans('IsnetIncomingReject'), 'error').'</a>';
		}
	}
	print '</td>';
	print '</tr>';
}
print '</table></div>';

llxFooter();
$db->close();
