<?php

namespace builder;

use dpe\builder\VersionTools;
use PHPUnit\Framework\TestCase;

class VersionToolsTest extends TestCase
{
	public function testGetVersionTags(): void
	{
		$versions = [
			'1.0.0',
			'1.0.1',
			'1.1.0',
			'1.2.0',
			'1.2.3',
			'2.0.0',
			'2.1.0',
			'2.1.1',
		];
		$tags = VersionTools::getVersionTags($versions);
		$expected = [
			'1.0.0' => ['1.0.0'],
			'1.0.1' => ['1.0.1', '1.0'],
			'1.1.0' => ['1.1.0', '1.1'],
			'1.2.0' => ['1.2.0'],
			'1.2.3' => ['1.2.3', '1.2', '1'],
			'2.0.0' => ['2.0.0', '2.0'],
			'2.1.0' => ['2.1.0'],
			'2.1.1' => ['2.1.1', '2.1', '2', ''],
		];
		$this->assertEquals($expected, $tags);
	}
}
