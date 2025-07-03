<?php

namespace dpe\common;

use RuntimeException;

class Config
{
	public function __construct(
		public readonly string  $imageDomain,
		public readonly string  $imageNamespace,
		public readonly string  $imageNameTemplate,
		public readonly string  $imageTagTemplate,
		public readonly string  $imageTagLatestTemplate,
		public readonly bool    $ignoreExistingImages,
		private readonly string $githubToken,
	)
	{
	}

	public function getRegistryClient(): DockerRegistryClient
	{
		return match ($this->imageDomain) {
			"docker.io" => new DockerRegistryClient(
				"https://registry-1.docker.io/v2/",
			),
			"ghcr.io" => new DockerRegistryClient(
				"https://ghcr.io/v2/",
				// ghcr.io requires user:password basic auth, but the actual
				// username doesn't appear to matter when using a personal access token
				$this->githubToken ? "user:$this->githubToken" : null,
			),
			default => throw new RuntimeException("Unsupported registry {$this->imageDomain}"),
		};
	}

	public function imageRef(Target $target, string $extName, string $extVersion): string
	{
		$tagTemplate = $extVersion ? $this->imageTagTemplate : $this->imageTagLatestTemplate;
		return sprintf("%s/%s/%s:%s",
			$this->imageDomain,
			$this->imageNamespace,
			$this->formatTemplateString($this->imageNameTemplate, $target, $extName, $extVersion),
			$this->formatTemplateString($tagTemplate, $target, $extName, $extVersion),
		);
	}

	public function imageTag(Target $target, string $extName, string $extVersion): string
	{
		return $this->formatTemplateString($this->imageTagTemplate, $target, $extName, $extVersion);
	}

	public function imageTagLatest(Target $target, string $extName): string
	{
		return $this->formatTemplateString($this->imageTagLatestTemplate, $target, $extName, '');
	}

	public function imageName(Target $target, string $extName, string $extVersion): string
	{
		return $this->formatTemplateString($this->imageNameTemplate, $target, $extName, $extVersion);
	}

	private function formatTemplateString(string $s, Target $target, string $extName, string $extVersion): string
	{
		$replace = [
			'%ext_name%' => $extName,
			'%ext_version%' => $extVersion,
			'%os%' => $target->getOsRef(),
			'%php_version%' => $target->phpVersion,
		];
		return str_replace(array_keys($replace), array_values($replace), $s);
	}

	public static function fromEnv(): Config
	{
		return new Config(
			imageDomain: self::env("IMAGE_DOMAIN"),
			imageNamespace: self::env("IMAGE_NAMESPACE"),
			imageNameTemplate: self::env("IMAGE_NAME_TEMPLATE", "php-ext-%ext_name%"),
			imageTagTemplate: self::env("IMAGE_TAG_TEMPLATE", "%ext_version%-%php_version%-%os%"),
			imageTagLatestTemplate: self::env("IMAGE_TAG_LATEST_TEMPLATE", "%php_version%-%os%"),
			ignoreExistingImages: filter_var(self::env("DPE_IGNORE_EXISTING_IMAGES", ""), FILTER_VALIDATE_BOOLEAN) ?? false,
			githubToken: self::env("GITHUB_TOKEN", ""),
		);
	}

	private static function env(string $env, ?string $default = null): string
	{
		$v = getenv($env);
		if ($v) {
			return $v;
		}
		if ($default !== null) {
			return $default;
		}
		throw new RuntimeException("Environment variable $env not set");
	}
}
