<?php

namespace dpe\builder;

class VersionTools
{
	public static function normalize(string $version): string
	{
		// Normalize version strings like version_compare does
		$version = str_replace(['_', '-', '+'], '.', $version);
		// Insert dots before and after any non-number
		$version = preg_replace(
			'/(?<=\d)(?![\d\.])(?!$)|(?<!^)(?<![\d\.])(?=[\d])/',
			'.',
			$version
		);
		return $version;
	}

	public static function components(string $version): array
	{
		return explode('.', self::normalize($version));
	}

	public static function compare(string $a, string $b, ?string $op = null): int|bool
	{
		$a = explode('.', self::normalize($a));
		$b = explode('.', self::normalize($b));

		$l = max(count($a), count($b));
		for ($i = 0; $i < $l; $i++) {
			$ac = $a[$i] ?? null;
			$bc = $b[$i] ?? null;

			if ($op === '~=' || $op === "~") {
				if ($ac === null || $bc === null) {
					return true;
				}
			} else {
				$ac ??= '0';
				$bc ??= '0';
			}

			if (version_compare($ac, $bc, '<')) {
				return $op ? $op === '<' : -1;
			}
			if (version_compare($ac, $bc, '>')) {
				return $op ? $op === '>' : 1;
			}
		}

		return $op === '='  || $op === '==' || $op === '~=' || $op === '~';
	}

	public static function sort(array $versions, bool $newestFirst = true): array
	{
		if ($newestFirst) {
			usort($versions, static fn($a, $b) => self::compare($b, $a));
		} else {
			usort($versions, static fn($a, $b) => self::compare($a, $b));
		}
		return $versions;
	}

	/// From a list of version strings, creates a mapping of version strings to
	/// arrays of compatible higher-level version tags.
	/// E.g. [1.0.0, 1.0.1] will return [1.0.0 => ['1.0.0'], 1.0.1 => ['1.0.1', '1.0', '1']]
	public static function getVersionTags(array $versions, array $levels = [2, 1]): array
	{
		$versions = self::sort($versions);

		$tags = array_map(fn($v) => [$v], $versions);

		// Transform version strings like version_compare does
		$versions = array_map(fn($v) => str_replace(['_', '-', '+'], '.', $v), $versions);
		$versions = array_map(fn($v) => explode('.', $v), $versions);

		foreach ($levels as $level) {
			$lvs = array_map(fn($v) => array_slice($v, 0, $level), $versions);
			$lv = null;
			foreach ($lvs as $i => $x) {
				if ($x !== $lv) {
					$tags[$i][] = implode('.', $x);
					$lv = $x;
				}
			}
		}
		$tags[0][] = '';

		// Create mapping of version strings to arrays of tags
		$result = [];
		foreach ($tags as $t) {
			$result[$t[0]] = $t;
		}
		return $result;
	}

	public static function getLatestPatchVersions(array $versions): array
	{
		$versions = self::sort($versions);
		$result = [];
		$lastVersionMajMin = null;
		foreach ($versions as $version) {
			$components = self::components($version);
			if (count($components) < 2) {
				throw new \InvalidArgumentException("Invalid version format: $version");
			}
			$majMin = implode('.', array_slice($components, 0, 2));
			if ($majMin !== $lastVersionMajMin) {
				$result[] = $version;
				$lastVersionMajMin = $majMin;
			}
		}
		return array_values($result);
	}
}
