<?php

namespace dpe\installer;

use dpe\common\HttpClient;

class IpeInstaller
{
	const HOST = 'https://github.com';
	const REPO = 'mlocati/docker-php-extension-installer';
	private HttpClient $client;
	private string $ipePath;

	public function __construct(
		public readonly ?string $ipeVersion = null,
	)
	{
		$this->client = new HttpClient(self::HOST . '/' . self::REPO);
	}

	public function __destruct()
	{
		unlink($this->ipePath);
	}

	private function downloadIpe(): void
	{
		if ($this->ipePath) return;
		$path = match ($this->ipeVersion) {
			null => "releases/latest/download/install-php-extensions",
			default => "releases/download/$this->ipeVersion/install-php-extensions",
		};
		$this->ipePath = $this->client->getToTmpFile($path);
		chmod($this->ipePath, 0777);
	}

	/**
	 * @param string[] $extensions
	 */
	public function installExtensions(array $extensions): void
	{
		$this->downloadIpe();
		$p = proc_open(
			[
				$this->ipePath,
				...$extensions,
			],
			[
				1 => ['file', 'php://stderr'],
				2 => ['file', 'php://stderr'],
			],
			$pipes,
		);
		if (!$p) {
			throw new \RuntimeException("Unable to run $this->ipePath");
		}
		$status = proc_close($p);
		if ($status !== 0) {
			throw new \RuntimeException("Error installing extensions: install-php-extensions returned $status");
		}
	}
}
