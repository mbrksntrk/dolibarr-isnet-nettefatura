<?php
/* Copyright (C) 2026 M. Burak Şentürk <https://buraksenturk.net>
 * Licensed under GPL-3.0-or-later with attribution terms. See COPYING and ATTRIBUTION.md.
 *
 * "e-Fatura" tab on the customer invoice card.
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

require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/invoice.lib.php';
dol_include_once('/isnetefatura/class/isnetsender.class.php');
dol_include_once('/isnetefatura/class/isnetaudit.class.php');

$langs->loadLangs(array('bills', 'companies', 'isnetefatura@isnetefatura'));

$id = GETPOSTINT('id');
$action = GETPOST('action', 'aZ09');
$confirm = GETPOST('confirm', 'alpha');
$docid = GETPOSTINT('docid');

if (!$user->hasRight('isnetefatura', 'read')) {
	accessforbidden();
}

$object = new Facture($db);
if ($id <= 0 || $object->fetch($id) <= 0) {
	dol_print_error($db, 'Invoice not found');
	exit;
}
$object->fetch_thirdparty();
$object->fetch_optionals();
$object->thirdparty->fetch_optionals();

$result = restrictedArea($user, 'facture', $object->id);

$sender = new IsnetSender($db);
$canSend = $user->hasRight('isnetefatura', 'send');

/* ------------------------------------------------------------------ actions */

if ($action === 'confirm_send' && $confirm === 'yes' && $canSend) {
	if ((int) $object->statut < Facture::STATUS_VALIDATED) {
		setEventMessages($langs->trans('IsnetErrInvoiceNotValidated'), null, 'errors');
	} else {
		$force = GETPOSTINT('force') > 0;
		$res = $sender->send($object, $user, $force);
		if ($res > 0) {
			$d = $sender->document;
			setEventMessages($langs->trans($res === 2 ? 'IsnetReconciled' : 'IsnetSentOk', $d->doc_type, $d->invoice_number ?: '-'), null, 'mesgs');
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
		if ($r < 0) {
			setEventMessages($sender->error, null, 'errors');
		} else {
			setEventMessages($langs->trans('IsnetStatusRefreshed', $doc->status ?: '-'), null, 'mesgs');
		}
	}
	header('Location: '.$_SERVER['PHP_SELF'].'?id='.$object->id);
	exit;
}

if ($action === 'storepdf' && $docid > 0 && $canSend) {
	$doc = new IsnetDocument($db);
	if ($doc->fetch($docid) > 0 && $doc->fk_facture == $object->id) {
		$r = $sender->storePdf($doc, $object);
		if ($r > 0) {
			setEventMessages($langs->trans('IsnetPdfStored'), null, 'mesgs');
		} elseif ($r === 0) {
			setEventMessages($langs->trans('IsnetPdfNotAvailable'), null, 'warnings');
		} else {
			setEventMessages($sender->error, null, 'errors');
		}
	}
	header('Location: '.$_SERVER['PHP_SELF'].'?id='.$object->id);
	exit;
}

if ($action === 'confirm_cancelearsiv' && $confirm === 'yes' && $docid > 0 && $canSend) {
	$doc = new IsnetDocument($db);
	if ($doc->fetch($docid) > 0 && $doc->fk_facture == $object->id && $doc->isAccepted() && $doc->doc_type === IsnetDocument::TYPE_EARSIV) {
		$reason = trim(GETPOST('reason', 'alphanohtml')) ?: $langs->transnoentities('IsnetEarsivCancelDefaultReason');
		if ($sender->getClient()->cancelArchiveInvoice($doc->ettn, $reason)) {
			$doc->status = 'Silindi';
			$doc->last_error = '';
			$doc->date_checked = dol_now();
			$doc->update();
			$sender->syncInvoiceFields($object, $doc);
			IsnetAudit::logObject($db, $object, IsnetAudit::CODE_CANCELLED, $langs->transnoentities('IsnetEarsivCancelled', $doc->invoice_number), 'ETTN '.$doc->ettn."
".$reason, $user);
			setEventMessages($langs->trans('IsnetEarsivCancelled', $doc->invoice_number), null, 'mesgs');
		} else {
			setEventMessages($sender->getClient()->error, null, 'errors');
		}
	}
	header('Location: '.$_SERVER['PHP_SELF'].'?id='.$object->id);
	exit;
}

if ($action === 'confirm_resendmail' && $confirm === 'yes' && $docid > 0 && $canSend) {
	$doc = new IsnetDocument($db);
	$email = trim(GETPOST('email', 'alphanohtml'));
	if ($doc->fetch($docid) > 0 && $doc->fk_facture == $object->id && $doc->isAccepted() && $doc->doc_type === IsnetDocument::TYPE_EARSIV && isValidEmail($email)) {
		if ($sender->getClient()->sendArchiveInvoiceMail($doc->ettn, $email)) {
			IsnetAudit::logObject($db, $object, IsnetAudit::CODE_MAIL, $langs->transnoentities('IsnetEarsivMailSent', $email), $doc->invoice_number.' / ETTN '.$doc->ettn, $user);
			setEventMessages($langs->trans('IsnetEarsivMailSent', $email), null, 'mesgs');
		} else {
			setEventMessages($sender->getClient()->error, null, 'errors');
		}
	} else {
		setEventMessages($langs->trans('ErrorBadEMail', $email), null, 'errors');
	}
	header('Location: '.$_SERVER['PHP_SELF'].'?id='.$object->id);
	exit;
}

if ($action === 'viewdoc' && $docid > 0) {
	$doc = new IsnetDocument($db);
	if ($doc->fetch($docid) > 0 && $doc->fk_facture == $object->id && $doc->isAccepted()) {
		$url = $sender->getClient()->getDocumentViewerLink($doc->ettn, $doc->doc_type === IsnetDocument::TYPE_EARSIV ? 'EArchiveInvoice' : 'EInvoice');
		if ($url !== '') {
			header('Location: '.$url);
			exit;
		}
		setEventMessages($sender->getClient()->error ?: $langs->trans('IsnetNoDocumentLink'), null, 'errors');
	}
	header('Location: '.$_SERVER['PHP_SELF'].'?id='.$object->id);
	exit;
}

/* ------------------------------------------------------------------ view */

$form = new Form($db);
$title = $langs->trans('IsnetEfaturaTab').' - '.$object->ref;
llxHeader('', $title);

$head = facture_prepare_head($object);
print dol_get_fiche_head($head, 'isnetefatura', $langs->trans('InvoiceCustomer'), -1, 'bill');

$linkback = '<a href="'.DOL_URL_ROOT.'/compta/facture/list.php?restore_lastsearch_values=1">'.$langs->trans('BackToList').'</a>';
dol_banner_tab($object, 'id', $linkback, 1, 'rowid', 'ref', '', '', 0, '', '', 1);

print '<div class="fichecenter"><div class="underbanner clearboth"></div>';

$accepted = IsnetDocument::fetchAccepted($db, $object->id);
if ($accepted && !$accepted->isFinal() && (dol_now() - (int) $accepted->date_checked) > 600) {
	$sender->refreshStatus($accepted);
}
$attempts = (new IsnetDocument($db))->fetchAllForInvoice($object->id);
$soc = $object->thirdparty;

// Receiver summary
$mapper = new IsnetInvoiceMapper($db);
$mapper->preflight($object, $soc);

print '<table class="border tableforfield centpercent">';
print '<tr><td class="titlefield">'.$langs->trans('IsnetReceiverTaxCode').'</td><td>'.dol_escape_htmltag($soc->idprof1).'</td></tr>';
print '<tr><td>'.$langs->trans('IsnetReceiverTaxOffice').'</td><td>'.dol_escape_htmltag($soc->idprof2).'</td></tr>';
$st = (string) ($soc->array_options['options_isnet_status'] ?? '0');
$stLabel = array('0' => 'IsnetEfStatusUnknown', '1' => 'IsnetEfStatusEfatura', '2' => 'IsnetEfStatusEarsiv');
print '<tr><td>'.$langs->trans('IsnetEfStatus').'</td><td>'.$langs->trans($stLabel[$st] ?? 'IsnetEfStatusUnknown');
if (!empty($soc->array_options['options_isnet_checked'])) {
	print ' <span class="opacitymedium">('.dol_print_date($soc->array_options['options_isnet_checked'], 'dayhour').')</span>';
}
print '</td></tr>';
print '<tr><td>'.$langs->trans('IsnetEfScenario').'</td><td>'.dol_escape_htmltag($mapper->scenario($object, $soc)).' / '.dol_escape_htmltag($mapper->invoiceType($object, $soc)).'</td></tr>';
print '<tr><td>'.$langs->trans('IsnetEfaturaEnv').'</td><td>'.$sender->getClient()->getEnv().'</td></tr>';
print '</table>';

if (!empty($mapper->problems)) {
	print '<div class="warning">'.$langs->trans('IsnetPreflightProblems').'<ul>';
	foreach ($mapper->problems as $p) {
		$parts = explode(':', $p, 2);
		print '<li>'.$langs->trans($parts[0]).(isset($parts[1]) ? ' ('.dol_escape_htmltag($parts[1]).')' : '').'</li>';
	}
	print '</ul></div>';
}
if (!empty($mapper->warnings)) {
	print '<div class="info">'.$langs->trans('IsnetPreflightWarnings').'<ul>';
	foreach ($mapper->warnings as $p) {
		$parts = explode(':', $p, 2);
		print '<li>'.$langs->trans($parts[0]).(isset($parts[1]) ? ' ('.dol_escape_htmltag($parts[1]).')' : '').'</li>';
	}
	print '</ul></div>';
}

print '</div>';
print dol_get_fiche_end();

// Confirm dialogs
if ($action === 'send' || $action === 'resend') {
	$force = $action === 'resend' ? 1 : 0;
	print $form->formconfirm(
		$_SERVER['PHP_SELF'].'?id='.$object->id.'&force='.$force,
		$langs->trans($force ? 'IsnetConfirmResendTitle' : 'IsnetConfirmSendTitle'),
		$langs->trans($force ? 'IsnetConfirmResendText' : 'IsnetConfirmSendText', $sender->getClient()->getEnv()),
		'confirm_send', '', 0, 1
	);
}
if ($action === 'cancelearsiv' && $docid > 0) {
	print $form->formconfirm($_SERVER['PHP_SELF'].'?id='.$object->id.'&docid='.$docid, $langs->trans('IsnetEarsivCancelTitle'), $langs->trans('IsnetEarsivCancelText'), 'confirm_cancelearsiv',
		array(array('type' => 'text', 'name' => 'reason', 'label' => $langs->trans('IsnetEarsivCancelReason'), 'value' => '', 'size' => 60)), '', 1);
}
if ($action === 'resendmail' && $docid > 0) {
	print $form->formconfirm($_SERVER['PHP_SELF'].'?id='.$object->id.'&docid='.$docid, $langs->trans('IsnetEarsivMailTitle'), $langs->trans('IsnetEarsivMailText'), 'confirm_resendmail',
		array(array('type' => 'text', 'name' => 'email', 'label' => $langs->trans('Email'), 'value' => $soc->email, 'size' => 40)), '', 1);
}

// Buttons
print '<div class="tabsAction">';
if ($canSend && (int) $object->statut >= Facture::STATUS_VALIDATED) {
	if (!$accepted) {
		print dolGetButtonAction('', $langs->trans('IsnetSend'), 'default', $_SERVER['PHP_SELF'].'?id='.$object->id.'&action=send&token='.newToken(), '', empty($mapper->problems));
	} else {
		print dolGetButtonAction('', $langs->trans('IsnetResend'), 'delete', $_SERVER['PHP_SELF'].'?id='.$object->id.'&action=resend&token='.newToken(), '', 1);
	}
} elseif ((int) $object->statut < Facture::STATUS_VALIDATED) {
	print dolGetButtonAction($langs->trans('IsnetErrInvoiceNotValidated'), $langs->trans('IsnetSend'), 'default', '#', '', 0);
}
print '</div>';

// Attempts
print load_fiche_titre($langs->trans('IsnetAttempts'), '', '');
print '<div class="div-table-responsive"><table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<td>'.$langs->trans('Date').'</td><td>'.$langs->trans('Type').'</td><td>'.$langs->trans('IsnetScenario').'</td>';
print '<td>'.$langs->trans('IsnetInvoiceNumber').'</td><td>ETTN</td><td>'.$langs->trans('Status').'</td><td>'.$langs->trans('IsnetLastError').'</td><td>'.$langs->trans('User').'</td><td></td>';
print '</tr>';
if (empty($attempts)) {
	print '<tr class="oddeven"><td colspan="9" class="opacitymedium">'.$langs->trans('IsnetNoAttempts').'</td></tr>';
}
foreach ($attempts as $d) {
	print '<tr class="oddeven">';
	print '<td>'.dol_print_date($d->date_sent ?: $d->date_creation, 'dayhour').'</td>';
	print '<td>'.dol_escape_htmltag($d->doc_type).'</td>';
	print '<td>'.dol_escape_htmltag(trim($d->scenario.' '.$d->invoice_type)).'</td>';
	print '<td>'.dol_escape_htmltag($d->invoice_number).'</td>';
	print '<td class="small">'.dol_escape_htmltag($d->ettn).'</td>';
	$state = $d->getStateLabel();
	$outcome = $d->outcome();
	$cls = array('ok' => 'badge-status4', 'issued' => 'badge-status4', 'fail' => 'badge-status8', 'cancelled' => 'badge-status9', 'pending' => 'badge-status1', 'error' => 'badge-status8');
	print '<td><span class="badge '.$cls[$outcome].'" title="'.dol_escape_htmltag($state).'">'.$langs->trans('IsnetOutcome'.ucfirst($outcome)).'</span>';
	print '<br><span class="small opacitymedium">'.dol_escape_htmltag($state);
	if ($d->detail_status !== '' && $d->detail_status !== $state) {
		print ' / '.dol_escape_htmltag($d->detail_status);
	}
	print '</span></td>';
	print '<td class="small">'.dol_escape_htmltag(dol_trunc($d->last_error, 120)).'</td>';
	$u = new User($db);
	if ($d->fk_user_send > 0 && $u->fetch($d->fk_user_send) > 0) {
		print '<td>'.$u->getNomUrl(-1).'</td>';
	} else {
		print '<td></td>';
	}
	print '<td class="nowraponall right">';
	if ($d->isAccepted()) {
		print '<a class="reposition" href="'.$_SERVER['PHP_SELF'].'?id='.$object->id.'&action=refresh&docid='.$d->id.'&token='.newToken().'">'.img_picto($langs->trans('IsnetRefreshStatus'), 'refresh').'</a> ';
		print '<a href="'.$_SERVER['PHP_SELF'].'?id='.$object->id.'&action=viewdoc&docid='.$d->id.'&token='.newToken().'" target="_blank">'.img_picto($langs->trans('IsnetViewDocument'), 'url').'</a> ';
		if ($canSend) {
			$stored = file_exists(IsnetDocument::pdfPath($object, $d->doc_type));
			print '<a class="reposition" href="'.$_SERVER['PHP_SELF'].'?id='.$object->id.'&action=storepdf&docid='.$d->id.'&token='.newToken().'">'.img_picto($langs->trans('IsnetStorePdf'), $stored ? 'pdf' : 'download').'</a>';
			if ($d->doc_type === IsnetDocument::TYPE_EARSIV && !in_array($d->outcome(), array('fail', 'cancelled'), true)) {
				print ' <a class="reposition" href="'.$_SERVER['PHP_SELF'].'?id='.$object->id.'&action=resendmail&docid='.$d->id.'&token='.newToken().'">'.img_picto($langs->trans('IsnetEarsivMailTitle'), 'email').'</a>';
				print ' <a class="reposition" href="'.$_SERVER['PHP_SELF'].'?id='.$object->id.'&action=cancelearsiv&docid='.$d->id.'&token='.newToken().'">'.img_picto($langs->trans('IsnetEarsivCancelTitle'), 'delete').'</a>';
			}
		}
	}
	print '</td>';
	print '</tr>';
}
print '</table></div>';

llxFooter();
$db->close();
