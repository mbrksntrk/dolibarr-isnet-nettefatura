<?php
/* Copyright (C) 2026 M. Burak Şentürk <https://buraksenturk.net>
 * Licensed under GPL-3.0-or-later with attribution terms. See COPYING and ATTRIBUTION.md.
 */

require_once DOL_DOCUMENT_ROOT.'/core/triggers/dolibarrtriggers.class.php';

/**
 * "Validate + send": submits customer invoices (e-Fatura / e-Arşiv) and shipments
 * (e-İrsaliye) to the integrator as part of their validation.
 */
class InterfaceAutosend extends DolibarrTriggers
{
	public function __construct($db)
	{
		$this->db = $db;
		$this->name = preg_replace('/^Interface/i', '', get_class($this));
		$this->family = 'isnetefatura';
		$this->description = 'Sends validated customer invoices and shipments to İşNet as e-Fatura / e-Arşiv / e-İrsaliye.';
		$this->version = self::VERSIONS['prod'];
		$this->picto = 'bill';
	}

	/**
	 * @return int <0 blocks the validation (transaction is rolled back), 0 or >0 lets it through
	 */
	public function runTrigger($action, $object, User $user, Translate $langs, Conf $conf)
	{
		if (!isModEnabled('isnetefatura') || !is_object($object)) {
			return 0;
		}
		if ($action === 'BILL_VALIDATE' && $object->element === 'facture') {
			if (!getDolGlobalInt('ISNETEFATURA_AUTOSEND_ON_VALIDATE', 1)) {
				return 0;
			}
			dol_include_once('/isnetefatura/class/isnetsender.class.php');
			$sender = new IsnetSender($this->db);
			$res = $sender->send($object, $user);
			return $this->outcome($res, $sender, $langs, 'ISNETEFATURA_BLOCK_VALIDATE_ON_ERROR');
		}
		if ($action === 'SHIPPING_VALIDATE' && $object->element === 'shipping') {
			if (!getDolGlobalInt('ISNETEFATURA_DESPATCH_ENABLED', 1) || !getDolGlobalInt('ISNETEFATURA_DESPATCH_AUTOSEND', 1)) {
				return 0;
			}
			dol_include_once('/isnetefatura/class/isnetdespatch.class.php');
			$sender = new IsnetDespatch($this->db);
			$res = $sender->send($object, $user);
			return $this->outcome($res, $sender, $langs, 'ISNETEFATURA_DESPATCH_BLOCK_ON_ERROR');
		}
		return 0;
	}

	private function outcome($res, $sender, Translate $langs, $blockConst)
	{
		$langs->load('isnetefatura@isnetefatura');
		if ($res > 0) {
			$doc = $sender->document;
			setEventMessages($langs->trans('IsnetSentOk', $doc->doc_type, $doc->invoice_number ?: '-'), null, 'mesgs');
			return 1;
		}
		$msgs = $sender->errors ?: array($sender->error);
		if (getDolGlobalInt($blockConst, 0) > 0) {
			$this->errors = array_merge(array($langs->trans('IsnetValidateBlocked')), $msgs);
			$this->error = implode(' — ', $this->errors);
			return -1;
		}
		setEventMessages($langs->trans('IsnetSentFailedButValidated'), $msgs, 'warnings');
		return 0;
	}
}
