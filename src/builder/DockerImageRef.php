<?php

namespace dpe\builder;

class DockerImageRef
{
	public function __construct(
		public readonly ?string $host,
		public readonly ?string $port,
		public readonly ?string $namespace,
		public readonly string  $repository,
		public readonly ?string $tag,
	)
	{
	}

	public static function fromString(string $ref): DockerImageRef
	{
		$parts = explode('/', $ref);
		$image = array_pop($parts);
		$tag = null;
		$namespace = null;
		$host = null;
		$port = null;
		if (str_contains($image, ':')) {
			[$image, $tag] = explode(':', $image);
		}
		if ($parts) {
			$namespace = array_pop($parts);
		}
		if ($parts) {
			$host = array_pop($parts);
			if (str_contains($host, ':')) {
				[$host, $port] = explode(':', $host);
			}
		}
		return new DockerImageRef(
			$host,
			$port,
			$namespace,
			$image,
			$tag,
		);
	}
}
