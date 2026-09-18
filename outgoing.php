<?php
/* Copyright (C) 2026 M. Burak Şentürk <https://buraksenturk.net>
 * Licensed under GPL-3.0-or-later with attribution terms. See COPYING and ATTRIBUTION.md.
 *
 * List of documents sent to the integrator (e-Fatura / e-Arşiv and e-İrsaliye).
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
require_once DOL_DOCUMENT_ROOT.'/expedition/class/expedition.class.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
dol_include_once('/isnetefatura/class/isnetsender.class.php');
dol_include_once('/isnetefatura/class/isnetdespatch.class.php');

$langs->loadLangs(array('bills', 'sendings', 'companies', 'isnetefatura@isnetefatura'));

if (!$user->hasRight('isnetefatura', 'read')) {
	accessforbidden();
}
$canSend = $user->hasRight('isnetefatura', 'send');

$action = GETPOST('action', 'aZ09');
$docid = GETPOSTINT('docid');
$search = GETPOST('search', 'alphanohtml');
$outcome = GETPOST('outcome', 'aZ09');
$kind = (GETPOST('kind', 'aZ09') === 'DESPATCH' ? 'DESPATCH' : 'INVOICE');
$page = max(0, GETPOSTINT('page'));
$limit = getDolGlobalInt('MAIN_SIZE_LISTE_LIMIT', 25);

$isDespatch = ($kind === 'DESPATCH');
$element = $isDespatch ? IsnetDocument::ELEMENT_SHIPMENT : IsnetDocument::ELEMENT_INVOICE;
$sender = $isDespatch ? new IsnetDespatch($db) : new IsnetSender($db);

$baseUrl = $_SERVER['PHP_SELF'].'?kind='.$kind.($outcome !== '' ? '&outcome='.$outcome : '').($search !== '' ? '&search='.urlencode($search) : '');
$backUrl = $baseUrl.($page > 0 ? '&page='.$page : '');

/**
 * Card of the Dolibarr object behind a submission, on the module's own tab.
 */
function isnetSourceUrl($isDespatch, $objid)
{
	return dol_buildpath('/isnetefatura/'.($isDespatch ? 'despatch.php' : 'card.php'), 1).'?id='.((int) $objid);
}

/* ------------------------------------------------------------------ actions */

if ($action === 'refresh' && $docid > 0) {
	$doc = new IsnetDocument($db);
	if ($doc->fetch($docid) > 0 && $doc->element_type === $element) {
		$r = $sender->refreshStatus($doc);
		setEventMessages($r < 0 ? $sender->error : $langs->trans('IsnetStatusRefreshed', $doc->status ?: '-'), null, $r < 0 ? 'errors' : 'mesgs');
	}
	header('Location: '.$backUrl);
	exit;
}

if ($action === 'refreshpending' && $canSend) {
	$pending = (new IsnetDocument($db))->fetchList(array('element' => $element, 'outcome' => 'pending', 'limit' => 50));
	$n = 0;
	foreach ($pending as $doc) {
		if ($sender->refreshStatus($doc) > 0) {
			$n++;
		}
	}
	setEventMessages($langs->trans('IsnetRefreshedCount', $n), null, 'mesgs');
	header('Location: '.$backUrl);
	exit;
}

if ($action === 'storepdf' && $docid > 0 && $canSend) {
	$doc = new IsnetDocument($db);
	if ($doc->fetch($docid) > 0 && $doc->element_type === $element) {
		$r = $sender->storePdf($doc);
		setEventMessages($r > 0 ? $langs->trans('IsnetPdfStored') : $langs->trans('IsnetPdfNotAvailable'), null, $r > 0 ? 'mesgs' : 'warnings');
	}
	header('Location: '.$backUrl);
	exit;
}

/* ------------------------------------------------------------------ view */

$total = 0;
$list = (new IsnetDocument($db))->fetchList(array(
	'element' => $element,
	'search' => $search,
	'outcome' => $outcome,
	'limit' => $limit,
	'offset' => $page * $limit,
), $total);

$title = $langs->trans($isDespatch ? 'IsnetOutgoingDespatchTitle' : 'IsnetOutgoingTitle');
llxHeader('', $title);

$morehtml = '';
if ($canSend) {
	$morehtml = '<a class="butAction" href="'.$baseUrl.'&action=refreshpending&token='.newToken().'">'.$langs->trans('IsnetRefreshPending').'</a>';
}
print load_fiche_titre($title, $morehtml, $isDespatch ? 'dolly' : 'bill');

print '<div class="tabBar">';
print '<a class="'.(!$isDespatch ? 'butAction' : 'butActionSmall').'" href="'.$_SERVER['PHP_SELF'].'?kind=INVOICE">'.$langs->trans('IsnetOutgoingTitle').'</a> ';
print '<a class="'.($isDespatch ? 'butAction' : 'butActionSmall').'" href="'.$_SERVER['PHP_SELF'].'?kind=DESPATCH">'.$langs->trans('IsnetOutgoingDespatchTitle').'</a>';
print '</div><br>';

print '<form method="GET" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="kind" value="'.$kind.'">';
print '<input type="text" class="flat" name="search" placeholder="'.$langs->trans('Search').'" value="'.dol_escape_htmltag($search).'"> ';
print '<select class="flat" name="outcome">';
$states = array(
	'' => $langs->trans('IsnetAllStates'),
	'pending' => $langs->trans('IsnetOutcomePending'),
	'ok' => $langs->trans('IsnetOutcomeOk'),
	'fail' => $langs->trans('IsnetOutcomeFail'),
	'error' => $langs->trans('IsnetOutcomeError'),
);
foreach ($states as $k => $label) {
	print '<option value="'.$k.'"'.($outcome === $k ? ' selected' : '').'>'.dol_escape_htmltag($label).'</option>';
}
print '</select> ';
print '<input type="submit" class="button small" value="'.$langs->trans('Search').'">';
print '</form><br>';

$nav = '';
if ($total > $limit) {
	$pages = (int) ceil($total / $limit);
	$nav = '<div class="right">';
	if ($page > 0) {
		$nav .= '<a href="'.$baseUrl.'&page='.($page - 1).'">'.img_previous().'</a> ';
	}
	$nav .= $langs->trans('Page').' '.($page + 1).'/'.$pages.' ';
	if ($page + 1 < $pages) {
		$nav .= '<a href="'.$baseUrl.'&page='.($page + 1).'">'.img_next().'</a>';
	}
	$nav .= '</div>';
}
print '<div class="opacitymedium">'.$langs->trans('IsnetOutgoingCount', $total).'</div>'.$nav;

print '<div class="div-table-responsive"><table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<td>'.$langs->trans('Date').'</td>';
print '<td>'.$langs->trans($isDespatch ? 'Sending' : 'Invoice').'</td>';
print '<td>'.$langs->trans('ThirdParty').'</td>';
print '<td>'.$langs->trans('Type').'</td>';
print '<td>'.$langs->trans($isDespatch ? 'IsnetDespatchNumber' : 'IsnetInvoiceNumber').'</td>';
print '<td>ETTN</td>';
print '<td>'.$langs->trans('Status').'</td>';
print '<td>'.$langs->trans('IsnetLastError').'</td>';
print '<td></td>';
print '</tr>';

if (empty($list)) {
	print '<tr class="oddeven"><td colspan="9" class="opacitymedium">'.$langs->trans('IsnetOutgoingEmpty').'</td></tr>';
}

$cls = array('ok' => 'badge-status4', 'issued' => 'badge-status4', 'fail' => 'badge-status8', 'cancelled' => 'badge-status9', 'pending' => 'badge-status1', 'error' => 'badge-status8');
foreach ($list as $d) {
	$o = $d->outcome();
	print '<tr class="oddeven">';
	print '<td class="nowraponall">'.dol_print_date($d->date_sent ?: $d->date_creation, 'dayhour').'</td>';
	print '<td class="nowraponall">'.($d->obj_ref !== '' ? '<a href="'.isnetSourceUrl($isDespatch, $d->fk_facture).'">'.dol_escape_htmltag($d->obj_ref).'</a>' : dol_escape_htmltag($d->external_code)).'</td>';
	print '<td>';
	if ($d->obj_socid > 0) {
		$soc = new Societe($db);
		if ($soc->fetch($d->obj_socid) > 0) {
			print $soc->getNomUrl(1, '', 24);
		}
	} else {
		print dol_escape_htmltag($d->soc_name);
	}
	print '</td>';
	print '<td class="nowraponall">'.dol_escape_htmltag($d->doc_type).'<br><span class="small opacitymedium">'.dol_escape_htmltag(trim($d->scenario.' '.$d->invoice_type)).'</span></td>';
	print '<td class="nowraponall">'.dol_escape_htmltag($d->invoice_number).'</td>';
	print '<td class="small">'.dol_escape_htmltag($d->ettn).'</td>';
	print '<td><span class="badge '.$cls[$o].'">'.$langs->trans('IsnetOutcome'.ucfirst($o)).'</span><br><span class="small opacitymedium">'.dol_escape_htmltag(trim($d->status.' / '.$d->detail_status, ' /')).'</span></td>';
	print '<td class="small">'.dol_escape_htmltag(dol_trunc($d->last_error, 80)).'</td>';
	print '<td class="nowraponall right">';
	if ($d->isAccepted()) {
		print '<a class="reposition" href="'.$baseUrl.'&page='.$page.'&action=refresh&docid='.$d->id.'&token='.newToken().'">'.img_picto($langs->trans('IsnetRefreshStatus'), 'refresh').'</a> ';
		if ($canSend && $d->pdfReady()) {
			print '<a class="reposition" href="'.$baseUrl.'&page='.$page.'&action=storepdf&docid='.$d->id.'&token='.newToken().'">'.img_picto($langs->trans('IsnetStorePdf'), 'pdf').'</a>';
		}
	}
	print '</td>';
	print '</tr>';
}
print '</table></div>';
print $nav;

llxFooter();
$db->close();
