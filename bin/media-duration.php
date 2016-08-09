#!/bin/env php
<?php

ini_set('display_errors', '1');
error_reporting(E_ALL | E_STRICT);

$autoloadPaths = array(
	// Try to load autoloader if this is the root project.
	__DIR__ . '/../vendor/autoload.php',

	// Try to load an autoloader if this is installed as a library for
	// another root project.
	__DIR__ . '/../../../autoload.php',
);

foreach ($autoloadPaths as $path) {
	if (file_exists($path)) {
		require_once $path;
		break;
	}
}

$parser = SiteAMQPCommandLine::fromXMLFile(
	__DIR__ . '/../data/media-duration.xml'
);

$logger = new SiteCommandLineLogger($parser);
$app = new AMQP_MediaDuration(
	'media-duration',
	$parser,
	$logger,
	__DIR__ . '/../data/media-duration.ini'
);
$app();

?>
