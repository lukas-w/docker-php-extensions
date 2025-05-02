<?php

namespace dpe\builder;

class VersionTools
{
	/// From a list of version strings, creates a mapping of version strings to
	/// arrays of compatible higher-level version tags.
	/// E.g. [1.0.0, 1.0.1] will return [1.0.0 => ['1.0.0'], 1.0.1 => ['1.0.1', '1.0', '1']]
	public static function getVersionTags(array $versions, array $levels = [2, 1]): array
	{
		usort($versions, 'version_compare');
		$versions = array_reverse($versions);

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
}
