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
	}

	public function satisfiedBy(string $phpVersion): bool
	{
		$min = $this->min ?? null;
		$max = $this->max ?? null;
		if ($min && version_compare($phpVersion, $min, '<')) {
			return false;
		}
		if ($max && version_compare($phpVersion, $max, '>')) {
			return false;
		}
		foreach ($this->exclude ?? [] as $excl) {
			if (version_compare($phpVersion, $excl, '==')) {
				return false;
			}
		}
		return true;
	}

	public static function fromXml(SimpleXMLElement $elm): self
	{
		$depElm = $elm->dependencies->required->php;
		if (!$depElm) {
			throw new \RuntimeException("No PHP dependencies found in XML.");
		}
		return new self(
			min: (string)$depElm->min,
			max: (string)$depElm->max,
			exclude: array_values(array_filter(
				iterator_to_array($depElm->exclude, preserve_keys: false),
			)),
		);
	}
}
