<?php

namespace dpe\builder;

use SimpleXMLElement;

class PeclPhpDep
{
	public function __construct(
		public readonly string $min,
		public readonly string $max,
		public readonly array  $exclude = [],
	)
	{
		foreach ($this->exclude as $excl) {
			if (!is_string($excl)) {
				throw new \InvalidArgumentException("exclude must be a string array");
			}
		}
	}

	public function satisfiedBy(string $phpVersion): bool
	{
		$min = $this->min ?? null;
		$max = $this->max ?? null;
		if ($min && VersionTools::compare($phpVersion, $min) < 0) {
			return false;
		}
		if ($max && VersionTools::compare($phpVersion, $max) > 0) {
			return false;
		}
		foreach ($this->exclude ?? [] as $excl) {
			if (VersionTools::compare($phpVersion, $excl) === 0) {
				return false;
			}
		}
		return true;
	}

	public static function fromXml(SimpleXMLElement $elm): self
	{
		$depElm = $elm->dependencies->required->php;
		if (!$depElm) {
			error_log("No PHP dependencies found in XML.");
			return new self('', '', exclude: []);
		}
		return new self(
			min: (string)$depElm->min,
			max: (string)$depElm->max,
			exclude: array_values(array_filter(
				array_map(fn($v) => (string)$v, iterator_to_array($depElm->exclude, preserve_keys: false)),
			)),
		);
	}
}
