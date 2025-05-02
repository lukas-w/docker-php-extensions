<?php


namespace builder;

use dpe\builder\DockerImageRef;
use PHPUnit\Framework\TestCase;

class DockerImageRefTest extends TestCase
{
	public function testFromString()
	{
		$imageRef = 'registry.example.com:5000/namespace/repo:tag';
		$dockerImageRef = DockerImageRef::fromString($imageRef);

		$this->assertEquals('registry.example.com', $dockerImageRef->host);
		$this->assertEquals('5000', $dockerImageRef->port);
		$this->assertEquals('namespace', $dockerImageRef->namespace);
		$this->assertEquals('repo', $dockerImageRef->repository);
		$this->assertEquals('tag', $dockerImageRef->tag);
	}

	public function testFromStringWithoutTag()
	{
		$imageRef = 'registry.example.com:5000/namespace/repo';
		$dockerImageRef = DockerImageRef::fromString($imageRef);

		$this->assertEquals('registry.example.com', $dockerImageRef->host);
		$this->assertEquals('5000', $dockerImageRef->port);
		$this->assertEquals('namespace', $dockerImageRef->namespace);
		$this->assertEquals('repo', $dockerImageRef->repository);
		$this->assertNull($dockerImageRef->tag);
	}

	public function testFromStringLibraryImage()
	{
		$imageRef = 'php:8.0-fpm';
		$dockerImageRef = DockerImageRef::fromString($imageRef);

		$this->assertNull($dockerImageRef->host);
		$this->assertNull($dockerImageRef->port);
		$this->assertNull($dockerImageRef->namespace);
		$this->assertEquals('php', $dockerImageRef->repository);
		$this->assertEquals('8.0-fpm', $dockerImageRef->tag);
	}
}
