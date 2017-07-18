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

$autoloader = null;
foreach ($autoloadPaths as $path) {
    if (file_exists($path)) {
        $autoloader = $path;
        break;
    }
}

if ($autoloader === null) {
    $stderr = fopen('php://stderr', 'w');
    fwrite(
        $stderr,
        'Unable to find composer autoloader. Make sure dependencies are ' .
        'installed by running "composer install" before running this script.' .
        PHP_EOL
    );
    fclose($stderr);
    exit(1);
} else {
    require_once $autoloader;
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
