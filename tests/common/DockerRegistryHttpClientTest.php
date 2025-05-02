<?php

namespace common;

use dpe\common\DockerRegistryHttpClient;
use PHPUnit\Framework\TestCase;

class DockerRegistryHttpClientTest extends TestCase
{
	public function testParseWwwAuthHeader()
	{
		$s = 'Bearer a="123",b="a,b,c",d="\\"hello, world\\""';
		$this->assertSame(
			[
				'Bearer',
				[
					'a' => '123',
					'b' => 'a,b,c',
					'd' => '"hello, world"',
				],
			],
			DockerRegistryHttpClient::parseWwwAuthHeader($s)
		);
	}
}
