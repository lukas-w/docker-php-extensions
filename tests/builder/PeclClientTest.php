<?php

namespace builder;

use dpe\builder\PeclClient;
use PHPUnit\Framework\TestCase;

class PeclClientTest extends TestCase
{
	public function testReleases()
	{
		$client = new PeclClient();
		$releases = $client->releases('xdebug');
		$this->assertIsArray($releases);
		$this->assertNotEmpty($releases);
	}
}
