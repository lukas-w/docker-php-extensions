<?php

use dpe\common\Config;
use dpe\common\DockerRegistryClient;
use dpe\common\Target;
use dpe\installer\DockerRegistryInstaller;
use dpe\installer\IpeInstaller;

$extensions = array_slice($argv, 1);

if (count($extensions) === 0) {
	echo "No extensions provided.\n";
	exit(1);
}

$prebuilt = new DockerRegistryInstaller(
	new DockerRegistryClient(
		"https://ghcr.io/",
		null,
	),
	"lukas-w/docker-php-ext",
	Target::host(),
	Config::fromEnv(),
);
$ipe = new IpeInstaller();
