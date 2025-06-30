<?php

use dpe\builder\FailList;
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

function applyFailList(string $extension, JobMatrix $matrix): JobMatrix
{
	$fl = file_get_contents(__DIR__ . '/../../data/fail-list.json');
	$fl = json_decode($fl, true);
	if (!is_array($fl)) {
		throw new RuntimeException("Unexpected fail-list.yaml contents");
	}
	$fl = $fl[$extension] ?? [];

	$ext_versions = $matrix->vars['ext_version'] ?? [];

	$fl_v_expanded = [];
	foreach ($fl as $f) {
		$v = $f['ext_version'] ?? null;
		if (is_array($v)) {
			[$op, $cmpV] = $v;
			$op = match($op) {
				'<' => -1,
				'>' => 1,
				default => throw new RuntimeException("Unknown operator: $op"),
			};
			foreach ($ext_versions as $ext_version) {
				if (VersionTools::compare($ext_version, $cmpV) === $op) {
					$fl_v_expanded[] = [
						...$f,
						'ext_version' => $ext_version,
					];
				}
			}
		}
	}
	$fl = [...$fl, ...$fl_v_expanded];

	foreach ($fl as $f) {
		$matrix = $matrix->exclude($f);
	}
	return $matrix;
}

function matrix(string $extension, array $phpVersions, array $osTargets, array $platforms): void
{
	global $pecl, $ipeData;

	$bundled = false;
	try {
		$extVersions = $pecl->getStableVersions($extension);
	} catch (RuntimeException $e) {
		if ($e->getCode() === 404 && $ipeData->isExtensionSupported($extension)) {
			// Bundled extension not in PECL
			$bundled = true;
			$extVersions = ['bundled'];
		} else {
			throw $e;
		}
	}

	$phpVersions = array_filter($phpVersions, fn($v) => $ipeData->isPhpVersionSupported($extension, $v));

	$m = new JobMatrix([
		'ext_version' => $extVersions,
		'php' => $phpVersions,
		'os' => $osTargets,
//		'platform' => $platforms,
	], []);

	$m = $m->withVars(['ext_version' => VersionTools::getLatestPatchVersions($m->vars['ext_version'])]);
	$m = FailList::fromFile(
		__DIR__ . '/../../data/fail-list.tsv',
		$extension,
	)->filterMatrix($m);

	foreach ($ipeData->getSpecialRequirements($extension) as $req) {
		foreach ($m->configs() as $c) {
			if (!$req->test($c['os'][0], $c['os'][1], $c['php'])) {
				$m = $m->exclude($c);
			}
		}
	}

	if (!$bundled) {
		foreach ($extVersions as $extVersion) {
			$deps = $pecl->phpDependencies($extension, $extVersion);
			foreach ($phpVersions as $php) {
				if (!$deps->satisfiedBy($php)) {
					$m = $m->exclude([
						'ext_version' => $extVersion,
						'php' => $php,
					]);
				}
			}
		}
	}

	function osToString(array $conf): array
	{
		if (array_key_exists('os', $conf)) {
			return [...$conf, 'os' => Target::osRef(...$conf['os'])];
		}
		return $conf;
	}

	$m = excludeBuiltConfigs($extension, $m);
	$m = new JobMatrix(
		[
			...$m->vars,
			'os' => array_map(fn($os) => Target::osRef(...$os), $m->vars['os']),
		],
		array_map(
			fn($conf) => array_key_exists('os', $conf)
				? [...$conf, 'os' => Target::osRef(...$conf['os'])]
				: $conf
			,
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

function listExtensions(?array $phpVersions): void
{
	global $ipeData;
	$exts = [];
	if ($phpVersions) {
		foreach ($phpVersions as $v) {
			$exts = [...$exts, ...$ipeData->getSupportedExtensions($v)];
		}
	} else {
		$exts = $ipeData->getSupportedExtensions();
	}
	$exts = array_unique($exts);
	sort($exts);
	echo json_encode($exts, JSON_PRETTY_PRINT);
	echo "\n";
}

function main(): int
{
	global $argv;
	array_shift($argv);
	$cmd = array_shift($argv);

	if ($cmd === 'list-extensions') {
		$phpVersions = explode(",", array_shift($argv));
		listExtensions($phpVersions ?: null);
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

		if ($extRef->isBundled()) {
			$tags = ['bundled' => ['']];
		} else {
			$versions = (new PeclClient())->getStableVersions($extRef->name);
			$tags = VersionTools::getVersionTags($versions);
		}
		global $config;
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
