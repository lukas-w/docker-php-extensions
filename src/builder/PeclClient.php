<?php

namespace dpe\builder;

use dpe\common\ExtRef;
use dpe\common\HttpClient;
use RuntimeException;
use SimpleXMLElement;

class PeclClient
{
	private readonly HttpClient $client;

	public function __construct(
		readonly string $baseUrl = 'https://pecl.php.net/',
	)
	{
		$this->client = new HttpClient($this->baseUrl);
	}

	public function getStableVersions(string $package): array
	{
		$releases = $this->releases($package);
		return array_map(
			fn($r) => $r['version'],
			array_filter($releases, fn($r) => $r['stability'] === 'stable')
		);
	}

	public function releases(string $package): array
	{
		$xml = $this->requestXml(
			"rest/r/$package/allreleases.xml",
			"http://pear.php.net/dtd/rest.allpackages"
		);

		$releases = [];
		foreach ($xml->children() as $e) {
			if ($e->getName() !== 'r') {
				continue;
			}
			$r = [];
			foreach ($e->children() as $f) {
				match ($f->getName()) {
					'v' => $r['version'] = (string)$f,
					's' => $r['stability'] = (string)$f,
				};
			}

			// Override stability for certain version suffixes
			$suffix_mappings = [
				'dev' => 'devel',
			];
			foreach (ExtRef::CHANNELS as $channel) {
				$suffix_mappings[$channel] = $channel;
			}
			foreach ($suffix_mappings as $suffix => $channel) {
				if (str_ends_with($r['version'], $suffix)) {
					$r['stability'] = $channel;
					break;
				}
			}
			$releases[] = $r;
		}
		return $releases;
	}

	public function phpDependencies(string $package, string $version): PeclPhpDep
	{
		try {
			$xml = $this->requestXml(
				"rest/r/$package/package.$version.xml",
				""
	//			"http://pear.php.net/dtd/package-2.0"
			);
		} catch (RuntimeException $e) {
			throw new RuntimeException(
				"Failed to fetch PHP dependencies for package $package version $version",
				previous: $e
			);
		}
		return PeclPhpDep::fromXml($xml);
	}

	private function requestXml(string $path, string $ns): SimpleXMLElement
	{
		$r = $this->client->get($path);
		$xml = simplexml_load_string(
			$r,
			namespace_or_prefix: $ns
		);
		if (!($xml instanceof SimpleXMLElement)) {
			throw new \RuntimeException("Unable to parse XML resposne from $path");
		}
		return $xml;
	}
}
