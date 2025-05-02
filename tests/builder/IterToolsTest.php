<?php

namespace builder;

use dpe\builder\IterTools;
use PHPUnit\Framework\TestCase;

class IterToolsTest extends TestCase
{
	public function testProduct()
	{
		$result = IterTools::product([1, 2], ['a', 'b'], ['c', 'd']);

		$expected = [
			[1, 'a', 'c'],
			[1, 'a', 'd'],
			[1, 'b', 'c'],
			[1, 'b', 'd'],
			[2, 'a', 'c'],
			[2, 'a', 'd'],
			[2, 'b', 'c'],
			[2, 'b', 'd'],
		];

		foreach ($result as $key => $value) {
			$this->assertEquals($expected[$key], $value);
		}
	}

	public function testProductAssociative()
	{
		$result = IterTools::product(...['a' => [1, 2], 'b' => ['c', 'd']]);
		$result = [...$result];

		$expected = [
			['a' => 1, 'b' => 'c'],
			['a' => 1, 'b' => 'd'],
			['a' => 2, 'b' => 'c'],
			['a' => 2, 'b' => 'd'],
		];

		$this->assertEquals($expected, $result);
		foreach ($result as $key => $value) {
			$this->assertEquals($expected[$key], $value);
		}
	}
}
