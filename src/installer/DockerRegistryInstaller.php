<?php

namespace dpe\installer;

use dpe\common\Config;
use dpe\common\DockerRegistryClient;
use dpe\common\Target;
use RuntimeException;
use Throwable;

class DockerRegistryInstaller
{
	public function __construct(
		readonly DockerRegistryClient $registry,
		readonly string               $imagePrefix,
		readonly Target               $target,
		readonly Config               $config,
	)
	{
	}

	public function installExtension(
		string $name,
		string $version,
	): void
	{
		$image = $this->config->imageNamespace . '/' . $this->config->imageName($this->target, $name . '-' . $version);
		$imageTag = $this->config->imageTag($this->target, $name . '-' . $version);
		$ref = "$image:$imageTag";

		$manifest = $this->registry->manifest($image, $imageTag);
		if ($manifest === null) {
			throw new RuntimeException("Image $ref not found");
		}
		$layers = $manifest['layers'];
		if (count($layers) !== 2) {
			throw new RuntimeException("Expected 2 layers in image $ref, found " . count($layers));
		}

		$layer = $layers[0];
		$layerDigest = $layer['digest'];
		if ($layerDigest === null) {
			throw new RuntimeException("Layer digest not found in image $ref");
		}

		try {
			$this->extractLayer($layerDigest);
		} catch (Throwable $e) {
			throw new RuntimeException("Unable to extract image $image layer $layerDigest", previous: $e);
		}
	}

	private function extractLayer(string $digest): void
	{
		$path = $this->registry->downloadBlob($this->imagePrefix, $digest);

		$phar = new \PharData($path);
		try {
			if (!$phar->extractTo('/')) {
				throw new RuntimeException("Unknown error in PharData::extractTo");
			}
		} finally {
			unlink($path);
		}
	}
}
