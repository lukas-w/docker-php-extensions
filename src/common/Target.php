<?php

namespace dpe\common;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

class Target
{
	public function __construct(
		public readonly string $phpVersion,
		public readonly string $osId,
		public readonly string $osVersion,
//		public readonly string $platform,
	)
	{
		if (!self::verifyPhpVersion($this->phpVersion)) {
			throw new InvalidArgumentException("Invalid PHP version: $phpVersion");
		}
		if (!in_array($osId, ['debian', 'alpine'])) {
			throw new InvalidArgumentException("Invalid OS ID: $osId");
		}
		if (!$osVersion) {
			throw new InvalidArgumentException("Invalid OS version: $osVersion");
		}
//		if (!$platform) {
//			throw new InvalidArgumentException("Invalid platform: $platform");
//		}
	}

	public function toArray(): array
	{
		return [
			'phpVersion' => $this->phpVersion,
			'osId' => $this->osId,
			'osVersion' => $this->osVersion,
//			'platform' => $this->platform,
		];
	}

	public function getOsRef(): string
	{
		return self::osRef($this->osId, $this->osVersion);
	}

	public static function osRef(string $osId, string $osVersion): string
	{
		if ($osId === 'debian') {
			// codenames for debian
			return $osVersion;
		} else if ($osId === 'alpine') {
			return $osId . $osVersion;
		} else {
			throw new InvalidArgumentException("Invalid OS ID: $osId");
		}
	}

	public static function parseOsRef(string $osRef): array
	{
		if (preg_match('/^(alpine)(\d+\.\d+)$/', $osRef, $matches)) {
			$osId = $matches[1];
			$osVersion = $matches[2];
		} else if (preg_match('/^[a-z]+$/', $osRef)) {
			$osId = 'debian';
			$osVersion = $osRef;
		} else {
			throw new InvalidArgumentException("Invalid OS ref: $osRef");
		}
		if (!$osVersion) $osVersion = null;
		return [$osId, $osVersion];
	}

	public function __toString(): string
	{
		return implode('-', [
			$this->phpVersion,
			$this->getOsRef(),
//			$this->platform,
		]);
	}

	public function toString(): string
	{
		return $this->__toString();
	}

	public static function fromString(string $str): Target
	{
		$parts = explode('-', $str);
		if (count($parts) !== 2) {
			throw new InvalidArgumentException("Invalid target string: $str");
		}
		[$osId, $osVersion] = self::parseOsRef($parts[1]);
		return new self($parts[0], $osId, $osVersion);
//		return new self($parts[0], $osId, $osVersion, $parts[2]);
	}

	public static function verifyPhpVersion(string $version): bool
	{
		return preg_match('/^\d\.\d+$/', $version);
	}

	public static function host(): Target
	{
		$osInfo = self::hostOsInfo();
		foreach (['ID', 'VERSION_ID'] as $key) {
			if (!isset($osInfo[$key])) {
				throw new RuntimeException("Unable to get OS info: $key not found");
			}
		}
		$id = $osInfo['ID'];
		return new self(
			self::hostPhpVersion(),
			$id,
			$id === 'debian' ? $osInfo['VERSION_CODENAME'] : $osInfo['VERSION_ID'],
			php_uname('m'),
		);
	}

	private static function hostPhpVersion(): string
	{
		return implode('.', array_slice(explode('.', phpversion()), 0, 2));
	}

	private static function hostOsInfo(): array
	{
		try {
			$file = '/etc/os-release';
			$os_release = FileUtils::read($file);
			$lines = explode("\n", $os_release);
			$result = [];
			foreach ($lines as $line) {
				$line = explode('=', $line, 2);
				if (count($line) !== 2) {
					continue;
				}
				$key = trim($line[0]);
				$value = trim($line[1], "\"\r\t");
				$result[$key] = $value;
			}
			return $result;
		} catch (Throwable $e) {
			throw new RuntimeException("Can't identify OS", previous: $e);
		}
	}
}
