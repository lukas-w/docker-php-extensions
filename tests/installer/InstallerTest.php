<?php

namespace installer;

use dpe\common\ExtRef;
use PHPUnit\Framework\TestCase;

class InstallerTest extends TestCase
{
	public function testExtensionParse(): void
	{
		$tests = [
			["xdebug-1.2.3", new ExtRef(
				name: "xdebug",
				version: "1.2.3",
				compatible: false,
				channel: null,
			)],
			["xdebug-stable", new ExtRef(
				name: "xdebug",
				version: null,
				compatible: false,
				channel: "stable",
			)],
			["xdebug-^12", new ExtRef(
				name: "xdebug",
				version: "12",
				compatible: true,
				channel: null,
			)],
			["xdebug-^1.2@stable", new ExtRef(
				name: "xdebug",
				version: "1.2",
				compatible: true,
				channel: "stable",
			)],
		];
		foreach ($tests as $test) {
			$version = $test[0];
			$expected = $test[1];
			$this->assertEquals($expected, ExtRef::parse($version));
		}
	}
}
