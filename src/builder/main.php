<?php

use dpe\builder\FailList;
use dpe\builder\IpeData;
use dpe\builder\IterTools;
use dpe\builder\JobMatrix;
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

function getBundledExtensions(Target $target): array
{
	$targetStr = $target->toString();
	$filename = __DIR__ . "/../../data/$targetStr/bundled-extensions";
	if (!file_exists($filename)) {
		throw new RuntimeException("Missing bundled extensions data. Run make data/bundled-extensions-" . $targetStr);
	}
	$data = file_get_contents($filename);
	if ($data === false) {
		throw new RuntimeException("Failed to read bundled extensions data from $filename");
	}
	return explode(" ", trim($data));
}

function isExtensionBundled(string $extension, Target $target): bool
{
	return in_array($extension, getBundledExtensions($target), true);
}

function targetFromConf(array $conf): Target
{
	return new Target(
		phpVersion: $conf['php'],
		osId: $conf['os'][0],
		osVersion: $conf['os'][1],
	);
}

function matrix(string $extension, array $phpVersions, array $osTargets, array $platforms): void
{
	global $pecl, $ipeData, $config;

	$phpVersions = array_filter($phpVersions, fn($v) => $ipeData->isPhpVersionSupported($extension, $v));

	$m = new JobMatrix([
		'ext_version' => ['bundled'],
		'php' => $phpVersions,
		'os' => $osTargets,
		'platform' => $platforms,
	]);
	$bundledTargets = [];
	$allBundled = true;
	$someBundled = false;
	foreach ($m->configs() as $conf) {
		$target = targetFromConf($conf);
		$bundled = isExtensionBundled($extension, $target);
		if ($bundled) {
			$someBundled = true;
		} else {
			$allBundled = false;
		}
		$bundledTargets[$target->toString()] = $bundled;
	}

	if (!$allBundled) {
		try {
			$extVersions = $pecl->getStableVersions($extension);
			$m = $m->withVars(['ext_version' => [...$m->vars['ext_version'], ...$extVersions]]);
		} catch (RuntimeException $e) {
			if ($e->getCode() === 404) {
				if (!$ipeData->isExtensionSupported($extension)) {
					throw new RuntimeException("Unknown or unsupported extension $extension", $e);
				}

				if (!$someBundled) {
					error_log("Warning: Extension $extension is not in PECL and not bundled.");
				}
			} else {
				throw $e;
			}
		}
		foreach ($bundledTargets as $targetStr => $bundled) {
			if ($bundled) {
				$target = Target::fromString($targetStr);
				foreach ($extVersions as $extVersion) {
					$m = $m->exclude([
						'ext_version' => $extVersion,
						'php' => $target->phpVersion,
						'os' => [$target->osId, $target->osVersion],
					]);
				}
			}
		}
	}

	if (!$someBundled) {
		$m = $m->exclude(['ext_version' => 'bundled']);
	}

	$m = FailList::fromFile(
		__DIR__ . '/../../data/fail-list.tsv',
		$extension,
	)->filterMatrix($m);

	/// FIXME: Hard-coded quirk, build a generic solution
	$versionLevel = 2;
	if ($extension === 'timezonedb') {
		$versionLevel = 1;
	}
	$m = $m->withVars(['ext_version' =>
		VersionTools::getLatestVersionsWithLevel(
			$m->vars['ext_version'], $versionLevel
		)
	]);

	foreach ($ipeData->getSpecialRequirements($extension) as $req) {
		foreach ($m->configs() as $c) {
			if (!$req->test($c['os'][0], $c['os'][1], $c['php'])) {
				$m = $m->exclude($c);
			}
		}
	}

	foreach ($m->vars['ext_version'] as $extVersion) {
		if ($extVersion === 'bundled') {
			continue;
		}
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

	if (! $config->ignoreExistingImages) {
		$m = excludeBuiltConfigs($extension, $m);
	}
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

	$m = $m->implode('platform', ',');

	echo json_encode(
		[
			'matrix' => $m->toArray(),
			'count' => count($m),
		],
		JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
	);
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

		$name = $config->imageName($target, $ext, $conf['ext_version']);
		$tag = $config->imageTag($target, $ext, $conf['ext_version']);
		$platform = $conf['platform'];
		if ($registry->hasImage($config->imageNamespace, $name, $tag, $platform)) {
			$builtConfigs[] = $conf;
		}
	}

	foreach ($builtConfigs as $conf) {
		$matrix = $matrix->exclude($conf);
	}

	return $matrix;
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
	$exts = array_filter($exts, static function ($e) use ($ipeData) {
		foreach ($ipeData->getSpecialRequirements($e) as $r) {
			if (! $r->negated && $r->zts) {
				error_log("Skipping $e because it requires ZTS which is not supported");
				return false;
			}
		}
		return true;
	});
	sort($exts);
	echo json_encode($exts, JSON_PRETTY_PRINT);
	echo "\n";
}

function getImageDigests(ExtRef $extRef, Target $target, ?array $platforms, bool $excludePlatforms): array
{
	global $config, $registry;

	$image = $config->imagePath($target, $extRef->name, $extRef->version ?? '');
	$tag = $config->imageTag($target, $extRef->name, $extRef->version ?? '');
	$manifest = $registry->manifest($image, $tag);
	if (! $manifest) {
		throw new RuntimeException("Manifest not found for image $image:$tag");
	}
	$manifest = $manifest['manifests'];

	if ($platforms) {
		$filteredManifest = array_filter($manifest, static function ($m) use ($platforms, $excludePlatforms) {
			$manifestPlatform = $m['platform']['os'] . '/' . $m['platform']['architecture'];
			if ($manifestPlatform === 'unknown/unknown') {
				return false;
			}
			foreach ($platforms as $p) {
				$match = $manifestPlatform === $p;
				if ($excludePlatforms && $match) {
					return false;
				}
				if (!$excludePlatforms && $match) {
					return true;
				}
			}
			if ($excludePlatforms) {
				return true;
			} else {
				return false;
			}
		});

		$digests = array_map(static fn($manifest) => $manifest['digest'], $filteredManifest);
		// Add attestation manifests
		foreach ($manifest as $m) {
			$annotations = $m['annotations'] ?? [];
			if (($annotations['vnd.docker.reference.type'] ?? null) === 'attestation-manifest' &&
			    in_array($annotations['vnd.docker.reference.digest'] ?? null, $digests, true)
			) {
				$filteredManifest[] = $m;
			}
		}
		$manifest = $filteredManifest;
	}

	$digests = array_values(array_map(static fn ($m) => $m['digest'], $manifest));

	return $digests;
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

	if ($cmd === 'get-image-name') {
		global $config;
		$extRef = ExtRef::parse($extension);
		$target = array_shift($argv);
		$target = Target::fromString($target);
		echo $config->imageName($target, $extRef->name, $extRef->version ?? '') . "\n";
		return 0;
	}

	if ($cmd === 'get-image-ref') {
		global $config;
		$extRef = ExtRef::parse($extension);
		$target = array_shift($argv);
		$target = Target::fromString($target);
		echo $config->imageRef($target, $extRef->name, $extRef->version ?? '') . "\n";
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

	if ($cmd === 'get-image-digests') {
		$extRef = ExtRef::parse($extension);
		$target = array_shift($argv);
		$target = Target::fromString($target);
		$platforms = array_shift($argv);

		$excludePlatforms = false;
		if ($platforms) {
			if ($platforms[0] === '!') {
				$excludePlatforms = true;
				$platforms = substr($platforms, 1);
			}
			$platforms = explode(',', $platforms);
		}
		$digests = getImageDigests($extRef, $target, $platforms, $excludePlatforms);

		echo json_encode($digests, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
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
