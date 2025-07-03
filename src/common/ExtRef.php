<?php

namespace dpe\common;

use RuntimeException;

class ExtRef
{
	private const VERSION_REGEX = '(?<version>bundled|[\d\.]+)';
	public const CHANNELS = [
		'alpha',
		'beta',
		'stable',
		'snapshot',
		'devel'
	];

	public function __construct(
		public readonly string  $name,
		public readonly ?string $version = null,
		public readonly ?bool   $compatible = null,
		public readonly ?string $channel = null,
	)
	{
		if (empty($this->name)) {
			throw new \InvalidArgumentException("Extension name cannot be empty");
		}
		if ($this->version !== null && !preg_match('/^' . self::VERSION_REGEX . '$/', $this->version)) {
			throw new \InvalidArgumentException("Invalid version format: $this->version");
		}
		if ($this->channel !== null && !in_array($this->channel, self::CHANNELS, true)) {
			throw new \InvalidArgumentException("Invalid channel: $this->channel");
		}
	}

	public function isBundled(): bool
	{
		return $this->version === 'bundled';
	}

	public function __toString(): string
	{
		$version = $this->version;
		if ($version && $this->compatible) {
			$version = '^' . $version;
		}
		$channel = $this->channel ? '@' . $this->channel : '';
		return $this->name . '-' . $version . $channel;
	}

	/// Parse argument as supported by mlocati/docker-php-extension-installer,
	/// in the form of <name>[-[^]<version>[@channel]]
	public static function parse(string $ref): self
	{
		$parts = explode('-', $ref);
		$name = implode('-', array_slice($parts, 0, -1));

		$version_spec = count($parts) > 1 ? $parts[count($parts) - 1] : null;

		if (!$version_spec) {
			return new self($ref);
		}

		if ($version_spec === 'bundled') {
			return new self(
				name: $name,
				version: 'bundled',
			);
		}

		$channels = [
			'alpha',
			'beta',
			'stable',
			'snapshot',
			'devel'
		];
		$channel_pattern = "(?<channel>" . implode('|', $channels) . ")";
		$version_pattern = "(?<compatible>\^)?(?<version>[^\-@]+)";

		$variants = [
			"/^$version_pattern@$channel_pattern$/", // Version with channel, e.g. ^2.8@stable
			"/^$version_pattern$/", // Version only, e.g. ^2.8, 2.8.1
			"/^$channel_pattern$/", // Channel only, e.g. stable
		];

		foreach ($variants as $variant) {
			$matches = [];
			$r = preg_match($variant, $version_spec, $matches, PREG_UNMATCHED_AS_NULL);
			if ($r === false) {
				throw new RuntimeException("Error parsing version: $version_spec");
			}
			if ($r === 1) {
				return new self(
					name: $name,
					version: $matches['version'] ?? null,
					compatible: str_contains($variant, '<compatible>')
						? isset($matches['compatible'])
						: null,
					channel: $matches['channel'] ?? null
				);
			}
		}
		throw new RuntimeException("Invalid version spec: $version_spec");
	}

	private static function channelPattern()
	{
		return '(?<channel>' . implode('|', self::CHANNELS) . ')';
	}
}
