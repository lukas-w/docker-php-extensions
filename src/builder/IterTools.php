<?php

namespace dpe\builder;

use Generator;

class IterTools
{
	public static function product(iterable ...$iters): Generator
	{
		return self::product_($iters);
	}

	public static function product_(array $iters): Generator
	{
		//reset($iters);
		$key = key($iters);
		$iter = current($iters);
		switch (count($iters)) {
			case 0:
				break;
			case 1:
				foreach ($iter as $x) {
					yield [$key => $x];
				}
				break;
			default:
				next($iters);
				$rest = array_slice($iters, 1, preserve_keys: true);
				foreach ($iter as $x) {
					foreach (self::product_($rest) as $y) {
						yield [$key => $x, ...$y];
					}
				}
				break;
		}
	}
}
