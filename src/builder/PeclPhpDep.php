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
		$min = $deps['min'] ?? null;
		$max = $deps['max'] ?? null;
		if ($min && version_compare($phpVersion, $min, '<')) {
			return false;
		}
		if ($max && version_compare($phpVersion, $max, '>')) {
			return false;
		}
		foreach ($deps['exclude'] ?? [] as $excl) {
			if (version_compare($phpVersion, $excl, '==')) {
				return false;
			}
		}
		return true;
	}

	public static function fromXml(SimpleXMLElement $elm): self
	{
		$depElm = $elm->dependencies->required->php;
		return new self(
			min: (string)$depElm->min,
			max: (string)$depElm->max,
			exclude: array_values(array_filter(
				array_map(
					fn($e) => $e->getName() === 'exclude' ? (string)$e : null,
					iterator_to_array($depElm->getChildren(), preserve_keys: false),
				)
			)),
		);
	}
}
