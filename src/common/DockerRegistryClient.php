<?php

namespace dpe\common;

use JsonException;
use RuntimeException;

class DockerRegistryClient
{
	protected HttpClient $client;

	public function __construct(
		private readonly string $baseUrl,
		?string                 $auth = null,
	)
	{
		$this->client = new DockerRegistryHttpClient($baseUrl, $auth);
	}

	/**
	 * @throws JsonException
	 */
	public function hasImage(string $namespace, string $image, string $tag = "latest"): bool
	{
		return in_array($tag, $this->tagsList($namespace . '/' . $image), true);
	}

	/**
	 * @throws JsonException
	 */
	public function tagsList(string $image): array
	{
		try {
			$r = $this->client->getJson($image . '/tags/list');
			return $r['tags'] ?? [];
		} catch (RuntimeException $e) {
			if ($e->getCode() === 404) {
				return [];
			}
			if (str_starts_with($this->baseUrl, "https://ghcr.io") && $e->getPrevious()?->getCode() === 403) {
				// ghcr.io returns authentication errors for images that don't exist
				return [];
			}
			throw $e;
		}
	}

	/**
	 * @throws JsonException
	 */
	public function manifest(string $image, string $tag): ?array
	{
		try {
			$this->client->headers['Accept'] = 'application/vnd.docker.distribution.manifest.v2+json';
			return $this->client->getJson(
				$image . '/manifests/' . $tag,
				headers: [
					'Accept' => 'application/vnd.docker.distribution.manifest.v2+json',
				]
			);
		} catch (RuntimeException $e) {
			if ($e->getCode() === 404) {
				return null;
			}
			throw $e;
		}
	}

	public function downloadBlob(string $image, string $digest): string
	{
		return $this->client->getToTmpFile($image . '/blobs/' . $digest);
	}
}
