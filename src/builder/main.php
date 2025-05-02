<?php

use dpe\builder\JobMatrix;
use dpe\builder\IpeData;
use dpe\builder\IterTools;
use dpe\builder\PeclClient;
use dpe\builder\VersionTools;
use dpe\common\Config;
use dpe\common\ExtRef;
use dpe\common\Target;

require __DIR__ . '/../../vendor/autoload.php';

$config = Config::fromEnv();
$pecl = new PeclClient();
$ipeData = new IpeData();
$registry = $config->getRegistryClient();

function generateTargets(
	string $extension,
	array  $phpVersions,
	array  $oss,
	array  $platforms,
): Generator
{
	global $config, $pecl, $ipeData, $registry;

	$extVersions = $pecl->getStableVersions($extension);

	foreach (IterTools::product(
		$phpVersions,
		$oss,
		$extVersions,
		$platforms,
	) as [$phpVersion, $os, $extVersion, $platform]) {
		$target = new Target(
			$phpVersion,
			$os[0],
			$os[1],
//			$platform,
		);
		if (!$ipeData->isSupported($extension, $target)) {
			error_log("$extension $target not supported by IPE");
			continue;
		}

		$extDeps = $pecl->phpDependencies($extension, $extVersion);
		if (!$extDeps->satisfiedBy($phpVersion)) {
			error_log("$extension $extVersion $target not supported by pecl deps");
			continue;
		}

		$image = $config->imageName($target, $extension, $extVersion);
		$tag = $config->imageTag($target, $extension, $extVersion);
		if ($registry->hasImage($config->imageNamespace, $image, $tag)) {
			error_log("$extension $target image already exists");
			continue;
		}

		yield [$extVersion, $target];
	}
}

function printConfigs(string $extension, array $phpVersions, array $osTargets, array $platforms): void
{
	global $config;
	foreach (generateTargets($extension, $phpVersions, $osTargets, $platforms) as [$extVersion, $target]) {
		error_log($extVersion . ' ' . $target->__toString() . ' as image ' . $config->imageRef($target, $extension, $extVersion));
	}
}

function matrix(string $extension, array $phpVersions, array $osTargets, array $platforms): void
{
	global $pecl, $ipeData;
	$extVersions = $pecl->getStableVersions($extension);

	$phpVersions = array_filter($phpVersions, fn($v) => $ipeData->isPhpVersionSupported($extension, $v));

	$m = new JobMatrix([
		'ext_version' => $extVersions,
		'php' => $phpVersions,
		'os' => $osTargets,
//		'platform' => $platforms,
	], []);

	foreach ($ipeData->getSpecialRequirements($extension) as $req) {
		foreach ($m->configs() as $c) {
			if (!$req->test($c['os'][0], $c['os'][1], $c['php'])) {
				$m = $m->exclude($c);
			}
		}
	}

	$m = excludeBuiltConfigs($extension, $m);
	$m = new JobMatrix(
		[
			...$m->vars,
			'os' => array_map(fn($os) => Target::osRef(...$os), $m->vars['os']),
		],
		array_map(
			fn($conf) => [...$conf, 'os' => Target::osRef(...$conf['os'])],
			$m->exclude
		)
	);

	$num = array_reduce($m->vars, fn($carry, $item) => $carry * count($item), 1) - count($m->exclude);
	error_log("Total: $num");

	echo $m->toJson();
}

function excludeBuiltConfigs(string $ext, JobMatrix $matrix): JobMatrix
{
	global $config, $registry;

	$builtConfigs = [];

	foreach ($matrix->configs() as $conf) {
		$target = new Target(
			phpVersion: $conf['php'],
			osId: $conf['os'][0],
			osVersion: $conf['os'][1],
//			platform: $conf['platform'],
		);
		$extRef = $ext . '-' . $conf['ext_version'];

		$name = $config->imageName($target, $ext, $conf['ext_version']);
		$tag = $config->imageTag($target, $ext, $conf['ext_version']);
		if ($registry->hasImage($config->imageNamespace, $name, $tag)) {
			$builtConfigs[] = $conf;
		}
	}

	foreach ($builtConfigs as $conf) {
		$matrix = $matrix->exclude($conf);
	}

	return $matrix;
}

function allBuilt(string $ext, array $matrix): bool
{
	global $config, $registry;
	foreach (IterTools::product(...$matrix) as $job) {
		$target = new Target(
			phpVersion: $job['php'],
			osId: $job['os'][0],
			osVersion: $job['os'][1],
//			platform: $job['platform'],
		);
		$extRef = $ext . '-' . $job['ext_version'];
		if (!$registry->hasImage(
			$config->imageNamespace,
			$config->imageName($target, $ext, $job['ext_version']),
			$config->imageTag($target, $ext, $job['ext_version']),
		)) {
			return false;
		}
	}
	return true;
}

function dockerConfig(
	string $ext,
	array  $phpVersions,
	array  $oss,
	array  $platforms,
): array
{
	global $config;
	if (count($phpVersions) !== 1) {
		throw new InvalidArgumentException("Must specify exactly one PHP version");
	}
	if (count($oss) !== 1) {
		throw new InvalidArgumentException("Must specify exactly one OS");
	}
	if (count($platforms) !== 1) {
		throw new InvalidArgumentException("Must specify exactly one platform");
	}
	$php = $phpVersions[0];
	[$osId, $osVersion] = $oss[0];
	$osRef = Target::osRef($osId, $osVersion);
	$platform = $platforms[0];

	$extRef = ExtRef::parse($ext);

	$target = new Target($php, $osId, $osVersion, $platform);
	return [
		'variant' => $osId,
		'args' => [
			"EXT_NAME=$extRef->name",
			"EXT_VERSION=$extRef->version",
			"PHP_VERSION=$php",
			"OS_REF=$osRef",
		],
		'tags' => [
			$config->imageRef($target, $extRef->name, $extRef->version),
		],
	];
}

function printDockerConfig(string $extRef, array $phpVersions, array $oss, array $platforms)
{
	echo json_encode(
		dockerConfig($extRef, $phpVersions, $oss, $platforms),
		JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
	);
	echo "\n";
}

function dockerBuild(string $extRef, array $phpVersions, array $oss, array $platforms)
{
	$conf = dockerConfig($extRef, $phpVersions, $oss, $platforms);
}

function listExtensions(?string $phpVersion): void
{
	global $ipeData;
	$extensions = $ipeData->getSupportedExtensions($phpVersion);
	echo json_encode($extensions, JSON_PRETTY_PRINT);
	echo "\n";
}

function main(): int
{
	global $argv;
	array_shift($argv);
	$cmd = array_shift($argv);

	if ($cmd === 'list-extensions') {
		$phpVersions = explode(",", array_shift($argv));
		if ($phpVersions && count($phpVersions) > 1) {
			throw new InvalidArgumentException("Only one PHP version is supported");
		}
		listExtensions($phpVersions ? $phpVersions[0] : null);
		return 0;
	}

	$extension = array_shift($argv);

	if ($cmd === 'get-tag-versions') {
		$versions = (new PeclClient())->getStableVersions($extension);
		$tags = VersionTools::getVersionTags($versions);
		echo json_encode($tags, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
		echo "\n";
		return 0;
	}

	if ($cmd === 'get-image-refs') {
		$extRef = ExtRef::parse($extension);
		$target = array_shift($argv);
		$target = Target::fromString($target);
		global $config;
		$versions = (new PeclClient())->getStableVersions($extRef->name);
		$tags = VersionTools::getVersionTags($versions);
		$tags = array_map(
			fn($versions) => array_map(fn($v) => $config->imageRef($target, $extRef->name, $v), $versions),
			$tags,
		);
		if ($extRef->version) {
			$result = $tags[$extRef->version];
		} else {
			$result = $tags;
		}
		echo json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
		echo "\n";
		return 0;
	}

	$phpVersions = explode(",", array_shift($argv));
	$osTargets = explode(",", array_shift($argv));
	$osTargets = array_map([Target::class, "parseOsRef"], $osTargets);
	$platforms = explode(",", array_shift($argv));

	$args = [
		$extension,
		$phpVersions,
		$osTargets,
		$platforms,
	];

	match ($cmd) {
		'print-configs' => printConfigs(...$args),
		'matrix' => matrix(...$args),
		'docker-config' => printDockerConfig(...$args),
		'build' => dockerBuild(...$args),
		default => throw new InvalidArgumentException("Unknown command: $cmd"),
	};

	return 0;
}

main();
