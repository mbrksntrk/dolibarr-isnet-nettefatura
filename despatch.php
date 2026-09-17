<?php
/* Copyright (C) 2026 M. Burak Şentürk <https://buraksenturk.net>
 * Licensed under GPL-3.0-or-later with attribution terms. See COPYING and ATTRIBUTION.md.
 *
 * "e-İrsaliye" tab on the shipment card.
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

require_once DOL_DOCUMENT_ROOT.'/expedition/class/expedition.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/expedition.lib.php';
dol_include_once('/isnetefatura/class/isnetdespatch.class.php');

$langs->loadLangs(array('sendings', 'companies', 'isnetefatura@isnetefatura'));

$id = GETPOSTINT('id');
$action = GETPOST('action', 'aZ09');
$confirm = GETPOST('confirm', 'alpha');
$docid = GETPOSTINT('docid');

if (!$user->hasRight('isnetefatura', 'read')) {
	accessforbidden();
}
$object = new Expedition($db);
if ($id <= 0 || $object->fetch($id) <= 0) {
	dol_print_error($db, 'Shipment not found');
	exit;
}
$object->fetch_thirdparty();
$object->fetch_optionals();
$object->thirdparty->fetch_optionals();
$result = restrictedArea($user, 'expedition', $object->id);

$sender = new IsnetDespatch($db);
$canSend = $user->hasRight('isnetefatura', 'send');

if ($action === 'confirm_send' && $confirm === 'yes' && $canSend) {
	if ((int) $object->statut < Expedition::STATUS_VALIDATED) {
		setEventMessages($langs->trans('IsnetErrShipmentNotValidated'), null, 'errors');
	} else {
		$res = $sender->send($object, $user, GETPOSTINT('force') > 0);
		if ($res > 0) {
			setEventMessages($langs->trans('IsnetSentOk', 'EIRSALIYE', $sender->document->invoice_number ?: '-'), null, 'mesgs');
		} else {
			setEventMessages($langs->trans('IsnetSendFailed'), $sender->errors, 'errors');
		}
	}
	header('Location: '.$_SERVER['PHP_SELF'].'?id='.$object->id);
	exit;
}
if ($action === 'refresh' && $docid > 0) {
	$doc = new IsnetDocument($db);
	if ($doc->fetch($docid) > 0 && $doc->fk_facture == $object->id) {
		$r = $sender->refreshStatus($doc);
		setEventMessages($r < 0 ? $sender->error : $langs->trans('IsnetStatusRefreshed', $doc->status ?: '-'), null, $r < 0 ? 'errors' : 'mesgs');
	}
	header('Location: '.$_SERVER['PHP_SELF'].'?id='.$object->id);
	exit;
}
if ($action === 'storepdf' && $docid > 0 && $canSend) {
	$doc = new IsnetDocument($db);
	if ($doc->fetch($docid) > 0 && $doc->fk_facture == $object->id) {
		$r = $sender->storePdf($doc, $object);
		setEventMessages($r > 0 ? $langs->trans('IsnetPdfStored') : $langs->trans('IsnetPdfNotAvailable'), null, $r > 0 ? 'mesgs' : 'warnings');
	}
	header('Location: '.$_SERVER['PHP_SELF'].'?id='.$object->id);
	exit;
}

$form = new Form($db);
llxHeader('', $langs->trans('IsnetIrsaliyeTab').' - '.$object->ref);
$head = shipping_prepare_head($object);
print dol_get_fiche_head($head, 'isnetefatura', $langs->trans('Shipment'), -1, 'dolly');
$linkback = '<a href="'.DOL_URL_ROOT.'/expedition/list.php?restore_lastsearch_values=1">'.$langs->trans('BackToList').'</a>';
dol_banner_tab($object, 'id', $linkback, 1, 'rowid', 'ref');
print '<div class="fichecenter"><div class="underbanner clearboth"></div>';

$accepted = IsnetDocument::fetchAccepted($db, $object->id, IsnetDocument::ELEMENT_SHIPMENT);
if ($accepted && !$accepted->isFinal() && (dol_now() - (int) $accepted->date_checked) > 600) {
	$sender->refreshStatus($accepted);
}
$attempts = (new IsnetDocument($db))->fetchAllForInvoice($object->id, IsnetDocument::ELEMENT_SHIPMENT);
$soc = $object->thirdparty;
$sender->preflight($object, $soc);
$t = $sender->transport($object);

print '<table class="border tableforfield centpercent">';
print '<tr><td class="titlefield">'.$langs->trans('IsnetReceiverTaxCode').'</td><td>'.dol_escape_htmltag($soc->idprof1).'</td></tr>';
print '<tr><td>'.$langs->trans('IsnetEfPlate').'</td><td>'.dol_escape_htmltag($t['plate']).($t['trailer'] !== '' ? ' / '.dol_escape_htmltag($t['trailer']) : '').'</td></tr>';
print '<tr><td>'.$langs->trans('IsnetEfDriver').'</td><td>'.dol_escape_htmltag(trim($t['driver_first'].' '.$t['driver_last'])).' <span class="opacitymedium">'.dol_escape_htmltag($t['driver_tckn']).'</span></td></tr>';
print '<tr><td>'.$langs->trans('IsnetEfCarrier').'</td><td>'.($t['carrier_vkn'] !== '' ? dol_escape_htmltag($t['carrier_name'].' ('.$t['carrier_vkn'].')') : '<span class="opacitymedium">'.$langs->trans('IsnetOwnVehicle').'</span>').'</td></tr>';
print '<tr><td>'.$langs->trans('IsnetEfaturaEnv').'</td><td>'.$sender->getClient()->getEnv().'</td></tr>';
print '</table>';
if (!empty($sender->problems)) {
	print '<div class="warning">'.$langs->trans('IsnetPreflightProblems').'<ul>';
	foreach ($sender->problems as $p) {
		$parts = explode(':', $p, 2);
		print '<li>'.$langs->trans($parts[0]).(isset($parts[1]) ? ' ('.dol_escape_htmltag($parts[1]).')' : '').'</li>';
	}
	print '</ul></div>';
}
print '</div>';
print dol_get_fiche_end();

if ($action === 'send' || $action === 'resend') {
	$force = $action === 'resend' ? 1 : 0;
	print $form->formconfirm($_SERVER['PHP_SELF'].'?id='.$object->id.'&force='.$force, $langs->trans($force ? 'IsnetConfirmResendTitle' : 'IsnetConfirmSendTitle'), $langs->trans($force ? 'IsnetConfirmResendText' : 'IsnetConfirmSendText', $sender->getClient()->getEnv()), 'confirm_send', '', 0, 1);
}

print '<div class="tabsAction">';
if ($canSend && (int) $object->statut >= Expedition::STATUS_VALIDATED) {
	if (!$accepted) {
		print dolGetButtonAction('', $langs->trans('IsnetSendDespatch'), 'default', $_SERVER['PHP_SELF'].'?id='.$object->id.'&action=send&token='.newToken(), '', empty($sender->problems));
	} elseif (in_array($accepted->outcome(), array('fail', 'error'), true)) {
		print dolGetButtonAction('', $langs->trans('IsnetResend'), 'delete', $_SERVER['PHP_SELF'].'?id='.$object->id.'&action=resend&token='.newToken(), '', 1);
	} else {
		print dolGetButtonAction($langs->trans('IsnetResendOnlyAfterFailure'), $langs->trans('IsnetResend'), 'delete', '#', '', 0);
	}
} elseif ((int) $object->statut < Expedition::STATUS_VALIDATED) {
	print dolGetButtonAction($langs->trans('IsnetErrShipmentNotValidated'), $langs->trans('IsnetSendDespatch'), 'default', '#', '', 0);
}
print '</div>';

print load_fiche_titre($langs->trans('IsnetAttempts'), '', '');
print '<div class="div-table-responsive"><table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans('Date').'</td><td>'.$langs->trans('IsnetDespatchNumber').'</td><td>ETTN</td><td>'.$langs->trans('Status').'</td><td>'.$langs->trans('IsnetLastError').'</td><td>'.$langs->trans('User').'</td><td></td></tr>';
if (empty($attempts)) {
	print '<tr class="oddeven"><td colspan="7" class="opacitymedium">'.$langs->trans('IsnetNoAttempts').'</td></tr>';
}
$cls = array('ok' => 'badge-status4', 'issued' => 'badge-status4', 'fail' => 'badge-status8', 'cancelled' => 'badge-status9', 'pending' => 'badge-status1', 'error' => 'badge-status8');
foreach ($attempts as $d) {
	$o = $d->outcome();
	print '<tr class="oddeven">';
	print '<td>'.dol_print_date($d->date_sent ?: $d->date_creation, 'dayhour').'</td>';
	print '<td>'.dol_escape_htmltag($d->invoice_number).'</td><td class="small">'.dol_escape_htmltag($d->ettn).'</td>';
	print '<td><span class="badge '.$cls[$o].'">'.$langs->trans('IsnetOutcome'.ucfirst($o)).'</span><br><span class="small opacitymedium">'.dol_escape_htmltag(trim($d->status.' / '.$d->detail_status, ' /')).'</span></td>';
	print '<td class="small">'.dol_escape_htmltag(dol_trunc($d->last_error, 120)).'</td>';
	$u = new User($db);
	print '<td>'.($d->fk_user_send > 0 && $u->fetch($d->fk_user_send) > 0 ? $u->getNomUrl(-1) : '').'</td>';
	print '<td class="nowraponall right">';
	if ($d->isAccepted()) {
		print '<a class="reposition" href="'.$_SERVER['PHP_SELF'].'?id='.$object->id.'&action=refresh&docid='.$d->id.'&token='.newToken().'">'.img_picto($langs->trans('IsnetRefreshStatus'), 'refresh').'</a> ';
		if ($canSend) {
			print '<a class="reposition" href="'.$_SERVER['PHP_SELF'].'?id='.$object->id.'&action=storepdf&docid='.$d->id.'&token='.newToken().'">'.img_picto($langs->trans('IsnetStorePdf'), file_exists(IsnetDocument::pdfPath($object, $d->doc_type)) ? 'pdf' : 'download').'</a>';
		}
	}
	print '</td></tr>';
}
print '</table></div>';
llxFooter();
$db->close();
