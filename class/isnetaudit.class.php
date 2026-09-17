<?php
/* Copyright (C) 2026 M. Burak Şentürk <https://buraksenturk.net>
 * Licensed under GPL-3.0-or-later with attribution terms. See COPYING and ATTRIBUTION.md.
 */

/**
 * Audit trail for module operations.
 *
 *  - Document operations (send, status change, cancel, mail, reply, import) are written as
 *    automatic agenda events linked to the Dolibarr object, so they appear on the object's
 *    "Events" tab and in the agenda like every other Dolibarr auto-event.
 *  - Configuration changes are written to the security audit log (Setup → Security → Audit).
 *  - Everything is also traced in dolibarr.log through dol_syslog.
 */
class IsnetAudit
{
	const CODE_SENT = 'AC_ISNET_SENT';
	const CODE_FAILED = 'AC_ISNET_FAILED';
	const CODE_RECONCILED = 'AC_ISNET_RECONCILED';
	const CODE_STATUS = 'AC_ISNET_STATUS';
	const CODE_CANCELLED = 'AC_ISNET_CANCELLED';
	const CODE_MAIL = 'AC_ISNET_MAIL';
	const CODE_PDF = 'AC_ISNET_PDF';
	const CODE_REPLY = 'AC_ISNET_REPLY';
	const CODE_IMPORTED = 'AC_ISNET_IMPORTED';

	/**
	 * Record an operation on a Dolibarr object (invoice, supplier invoice, shipment...).
	 *
	 * @param  CommonObject $object  Object with ->id, ->element, ->socid
	 * @param  string       $code    One of self::CODE_*
	 * @param  string       $label   Short human-readable label (already translated)
	 * @param  string       $note    Details (document number, ETTN, error text...)
	 * @param  User|null    $user    Acting user; null for cron
	 * @return int                   Action id or <0
	 */
	public static function logObject($db, $object, $code, $label, $note = '', $user = null)
	{
		global $conf, $langs;
		if (!isModEnabled('agenda')) {
			dol_syslog('IsnetAudit '.$code.' '.$label.' '.$note, LOG_INFO);
			return 0;
		}
		require_once DOL_DOCUMENT_ROOT.'/comm/action/class/actioncomm.class.php';
		if ($user === null) {
			$user = new User($db);
			$user->fetch(getDolGlobalInt('ISNETEFATURA_CRON_USER_ID', 0) ?: 1);
		}
		$ac = new ActionComm($db);
		$ac->type_code = 'AC_OTH_AUTO';
		$ac->code = $code;
		$ac->label = dol_trunc($label, 250, 'right', 'UTF-8', 1);
		$ac->note_private = $note;
		$ac->datep = dol_now();
		$ac->datef = $ac->datep;
		$ac->percentage = -1;
		$ac->socid = (int) ($object->socid ?? ($object->thirdparty->id ?? 0));
		$ac->fk_element = (int) $object->id;
		$ac->elementtype = self::elementType($object);
		$ac->userownerid = (int) $user->id;
		$ac->authorid = (int) $user->id;
		$ac->fk_project = (int) ($object->fk_project ?? 0);
		$id = $ac->create($user);
		if ($id <= 0) {
			dol_syslog('IsnetAudit::logObject failed: '.$ac->error, LOG_WARNING);
		}
		dol_syslog('IsnetAudit '.$code.' '.$ac->elementtype.'#'.$object->id.' '.$label.' '.$note, LOG_INFO);
		return $id;
	}

	private static function elementType($object)
	{
		$el = (string) ($object->element ?? '');
		// Agenda uses the legacy element names for these objects.
		$map = array('facture' => 'invoice', 'invoice_supplier' => 'invoice_supplier', 'facture_fournisseur' => 'invoice_supplier', 'expedition' => 'shipping', 'shipping' => 'shipping');
		return $map[$el] ?? $el;
	}

	/**
	 * Record a configuration or security-relevant change in the audit log.
	 *
	 * @param string $type        Short event type, e.g. 'ISNET_SETUP', 'ISNET_ENV'
	 * @param string $description What changed
	 */
	public static function logSecurity($db, $type, $description, $actingUser = null)
	{
		global $user;
		require_once DOL_DOCUMENT_ROOT.'/core/class/events.class.php';
		$u = $actingUser ?: $user;
		$e = new Events($db);
		$e->type = $type;
		$e->dateevent = dol_now();
		$e->label = dol_trunc($description, 250, 'right', 'UTF-8', 1);
		$e->description = $description;
		$e->user_agent = (string) ($_SERVER['HTTP_USER_AGENT'] ?? 'cli');
		$e->ip = getUserRemoteIP() ?: '127.0.0.1';
		$r = $e->create($u);
		dol_syslog('IsnetAudit '.$type.' '.$description, LOG_NOTICE);
		return $r;
	}
}
