<?php

namespace dpe\builder;

use dpe\common\FileUtils;
use dpe\common\Target;
use Generator;

class IpeData
{
	private array $supportedExtensions;
	private array $specialRequirements;

	public function __construct(
		$dir = __DIR__ . "/../../ipe",
	)
	{
		$this->supportedExtensions = self::readIpeDataFile($dir . "/supported-extensions");
		$this->specialRequirements = self::readIpeDataFile($dir . "/special-requirements");
	}

	public function getSupportedExtensions(?string $phpVersion = null): array
	{
		$exts = $this->supportedExtensions;
		if ($phpVersion) {
			$exts = array_filter(
				$this->supportedExtensions,
				fn($versions) => in_array($phpVersion, $versions, true)
			);
		}
		return array_keys($exts);
	}

	public function isExtensionSupported(string $ext): bool
	{
		return array_key_exists($ext, $this->supportedExtensions);
	}

	public function isPhpVersionSupported(string $ext, string $phpVersion): bool
	{
		return array_key_exists($ext, $this->supportedExtensions)
			&& in_array($phpVersion, $this->supportedExtensions[$ext], true);
	}

	public function isSupported(string $ext, Target $target): bool
	{
		return $this->isPhpVersionSupported($ext, $target->phpVersion)
			&& $this->fulfillsSpecialRequirements($ext, $target);
	}

	public function getSpecialRequirements(string $ext): Generator
	{
		foreach ($this->specialRequirements[$ext] ?? [] as $req) {
			yield IpeRequirement::parse($req);
		}
	}

	private function fulfillsSpecialRequirements(string $ext, Target $target): bool
	{
		foreach ($this->specialRequirements[$ext] ?? [] as $req) {
			if (! IpeRequirement::parse($req)->testTarget($target)) {
				return false;
			}
		}
		return true;
	}

	private function readIpeDataFile(string $path): array
	{
		$data = FileUtils::read($path);
		$lines = explode("\n", $data);
		$result = [];
		foreach ($lines as $line) {
			$line = trim($line);
			if (!$line || $line[0] === "#") {
				continue;
			}
			$line = explode(" ", $line);
			$name = trim(array_shift($line));
			$result[$name] = array_map('trim', $line);
		}
		return $result;
	}
}
