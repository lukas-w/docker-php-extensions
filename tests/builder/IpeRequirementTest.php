<?php

namespace builder;

use dpe\builder\IpeRequirement;
use dpe\common\Target;
use PHPUnit\Framework\TestCase;

class IpeRequirementTest extends TestCase
{
	public function testTest()
	{
		$r = new IpeRequirement(
			negated: true,
			osId: 'alpine',
			osVersion: '3.20',
			phpVersion: '7.4',
		);
		$this->assertFalse($r->testTarget(new Target(
			'7.4',
			'alpine',
			'3.20',
			'x86_64',
		)));
		$this->assertTrue($r->testTarget(new Target(
			'7.4',
			'alpine',
			'3.19',
			'x86_64',
		)));
		$this->assertTrue($r->testTarget(new Target(
			'8.0',
			'alpine',
			'3.20',
			'x86_64',
		)));

		$r = new IpeRequirement(
			negated: true,
			osId: 'alpine',
			osVersion: null,
			phpVersion: '7.4',
		);
		$this->assertFalse($r->testTarget(new Target(
			'7.4',
			'alpine',
			'3.19',
			'x86_64',
		)));
		$this->assertTrue($r->testTarget(new Target(
			'8.0',
			'alpine',
			'3.19',
			'x86_64',
		)));
	}
}
