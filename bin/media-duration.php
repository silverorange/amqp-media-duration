#!/bin/env php
<?php

ini_set('display_errors', '1');
error_reporting(E_ALL | E_STRICT);

require_once 'Site/SiteAMQPCommandLine.php';
require_once 'Site/SiteCommandLineLogger.php';
require_once 'AMQP/MediaDuration.php';

$dir = '@data-dir@/@package-name@/data';
if ($dir[0] == '@') {
	$dir = __DIR__ . '/../data';
}

$parser = SiteAMQPCommandLine::fromXMLFile(
	$dir . '/media-duration.xml'
);

$logger = new SiteCommandLineLogger($parser);
$app = new AMQP_MediaDuration(
	'media-duration',
	$parser,
	$logger,
	$dir . '/media-duration.ini'
);
$app();

?>
