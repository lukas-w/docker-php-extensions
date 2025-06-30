<?php

namespace common;

use dpe\common\ExtRef;
use PHPUnit\Framework\TestCase;

class ExtRefTest extends TestCase
{
	public function testParse()
	{
		$ver = ExtRef::parse('ext-1.2.3');
		$this->assertEquals('ext', $ver->name);
		$this->assertEquals('1.2.3', $ver->version);
		$this->assertFalse($ver->compatible);
		$this->assertNull($ver->channel);

		$ver = ExtRef::parse('ext-stable');
		$this->assertEquals('ext', $ver->name);
		$this->assertNull($ver->version);
		$this->assertNull($ver->compatible);
		$this->assertEquals('stable', $ver->channel);

		$ver = ExtRef::parse('ext');
		$this->assertEquals('ext', $ver->name);
		$this->assertNull($ver->version);
		$this->assertNull($ver->compatible);
		$this->assertNull($ver->channel);
	}
}
