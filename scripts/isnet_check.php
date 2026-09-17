#!/usr/bin/env php
<?php
/* Copyright (C) 2026 M. Burak Şentürk <https://buraksenturk.net>
 * Licensed under GPL-3.0-or-later with attribution terms. See COPYING and ATTRIBUTION.md.
 *
 * CLI connectivity check:  php isnet_check.php [VKN]
 */

if (!defined('NOSESSION')) {
	define('NOSESSION', '1');
}
define('NOLOGIN', '1');
define('NOCSRFCHECK', '1');
define('NOTOKENRENEWAL', '1');

$sapi_type = php_sapi_name();
if (substr($sapi_type, 0, 3) == 'cgi') {
	echo "Error: You are using PHP for CGI. To execute this script from command line you must use PHP for CLI mode.\n";
	exit(1);
}

$path = __DIR__.'/';
$_SERVER['SERVER_NAME'] = 'localhost';
// Works both from htdocs/custom/isnetefatura/scripts and htdocs/isnetefatura/scripts
$res = 0;
if (file_exists($path.'../../../master.inc.php')) {
	$res = @include $path.'../../../master.inc.php';
}
if (!$res && file_exists($path.'../../master.inc.php')) {
	$res = @include $path.'../../master.inc.php';
}
if (!$res) {
	die("Include of master fails\n");
}
dol_include_once('/isnetefatura/class/isnetclient.class.php');

$vkn = $argv[1] ?? '';

$client = new IsnetClient($db);
echo "env            : ".$client->getEnv()."\n";
echo "company VKN    : ".$client->getCompanyTaxCode()."\n";
echo "invoice URL    : ".$client->getServiceUrl(IsnetClient::SERVICE_INVOICE)."\n";
echo "addressbook URL: ".$client->getServiceUrl(IsnetClient::SERVICE_ADDRESSBOOK)."\n\n";

echo "HealthCheck    : ";
echo $client->healthCheck() ? "OK\n" : "FAIL ".$client->error."\n";

if ($vkn !== '') {
	echo "GetTaxPayer($vkn): ";
	$r = $client->resolveReceiver($vkn);
	if ($client->error !== '') {
		echo "ERROR ".$client->error."\n";
	} elseif ($r === null) {
		echo "not registered → e-Arşiv\n";
	} else {
		echo "registered → e-Fatura\n";
		echo "  name      : ".$r['name']."\n";
		echo "  inbox tag : ".$r['inbox_tag']." (".count($r['inbox_tags'])." available)\n";
		echo "  since     : ".$r['registered']."\n";
	}
}
exit(0);
