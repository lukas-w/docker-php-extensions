<?php

namespace builder;

use dpe\builder\PeclPhpDep;
use PHPUnit\Framework\TestCase;
use SimpleXMLElement;

class PeclPhpDepTest extends TestCase
{
	public function testFromXml()
	{
		$xml = <<<XML
<package>
<dependencies>
	<required>
		<php>
			<min>7.2.0</min>
			<max>8.4.0</max>
			<exclude>8.4.0</exclude>
		</php>
		<pearinstaller>
			<min>1.4.0</min>
		</pearinstaller>
	</required>
</dependencies>
</package>
XML;

		$elm = new SimpleXMLElement($xml);
		$dep = PeclPhpDep::fromXml($elm);
		$this->assertEquals('7.2.0', $dep->min);
		$this->assertEquals('8.4.0', $dep->max);
		$this->assertEquals(['8.4.0'], $dep->exclude);
	}

	public function testFromXml2Excludes()
	{
		$xml = <<<XML
<package>
<dependencies>
	<required>
		<php>
			<min>7.2.0</min>
			<max>8.4.0</max>
			<exclude>8.4.0</exclude>
			<exclude>8.2.0</exclude>
		</php>
		<pearinstaller>
			<min>1.4.0</min>
		</pearinstaller>
	</required>
</dependencies>
</package>
XML;

		$elm = new SimpleXMLElement($xml);
		$dep = PeclPhpDep::fromXml($elm);
		$this->assertEquals('7.2.0', $dep->min);
		$this->assertEquals('8.4.0', $dep->max);
		$this->assertEquals(['8.4.0', '8.2.0'], $dep->exclude);
	}

	public function testSatisfiedBy()
	{
		$dep = new PeclPhpDep('7.2.0', '8.4.0', ['8.4.0']);
		$this->assertTrue($dep->satisfiedBy('7.2.0'));
		$this->assertTrue($dep->satisfiedBy('8.3.0'));
		$this->assertFalse($dep->satisfiedBy('8.4.0'));
		$this->assertFalse($dep->satisfiedBy('8.5.0'));
	}
}
