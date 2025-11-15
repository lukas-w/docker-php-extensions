<?php

namespace dpe\common;

use CurlHandle;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

class HttpClient
{
	protected readonly CurlHandle $ch;
	private array $cache = [];
	public array $headers = [];

	public function __construct(
		private readonly string $baseUrl = '',
	)
	{
		$ch = curl_init();
		if (!$ch) {
			throw new RuntimeException('Failed to initialize cURL');
		}
		$this->ch = $ch;
		curl_setopt_array($this->ch, [
			CURLOPT_FOLLOWLOCATION => true,
		]);
	}

	public function __destruct()
	{
		curl_close($this->ch);
	}

	public function setHeaders(array $headers): void
	{
		$this->headers = $headers;
	}

	/**
	 * @throws JsonException
	 */
	public function getJson(string $path, array $headers = []): array
	{
		return json_decode($this->get($path, headers: $headers), associative: true, flags: JSON_THROW_ON_ERROR);
	}

	public function getStream(string $path, mixed $out = null): void
	{
		$this->get($path, $out);
	}

	public function getToTmpFile(string $path): string
	{
		$tmpFile = tempnam(sys_get_temp_dir(), 'php-ext-installer');
		if ($tmpFile === false) {
			throw new RuntimeException('Failed to create temporary file');
		}
		$fp = fopen($tmpFile, 'w');
		if ($fp === false) {
			unlink($tmpFile);
			throw new RuntimeException('Failed to open temporary file for writing');
		}
		try {
			$this->getStream($path, out: $fp);
		} finally {
			fclose($fp);
		}
		return $tmpFile;
	}

	public function get(string $path, bool $checkCode = true, mixed $out = null, array $headers = []): ?string
	{
		[$code, $response, $responseHeaders] = $this->exec($path, $out, headers: $headers);

		if ($checkCode) {
			$this->checkResponseCode('GET', $path, $code);
		}

		return is_string($response) ? $response : null;
	}

	public function request(string $method, string $path, array $headers = []): string
	{
		return $this->exec($path, method: $method, headers: $headers)[1];
	}

	protected function checkResponseCode(string $method, string $path, ?int $code = null): void
	{
		$code ??= curl_getinfo($this->ch, CURLINFO_HTTP_CODE);
		if ($code >= 200 && $code < 300) {
			return;
		}
		if ($code !== 404) {
			error_log("$method $this->baseUrl$path failed with status $code");
		}
		throw new RuntimeException('HTTP error: ' . $code, code: $code);
	}

	protected function exec(string $path, mixed $out = null, string $method = 'GET', array $headers = []): array
	{
		$headers = [...$this->headers, ...$headers];
		if ($method === 'GET') {
			$cacheKey = "$path:" . md5(json_encode($headers, JSON_THROW_ON_ERROR));

			$cacheValue = &$this->cache[$cacheKey];
			if ($cacheValue ?? null) {
				return $cacheValue;
			}
		}

		curl_setopt($this->ch, CURLOPT_CUSTOMREQUEST, $method);
		curl_setopt($this->ch, CURLOPT_URL, $this->baseUrl . $path);
		curl_setopt($this->ch, CURLOPT_HTTPHEADER, array_map(
			static fn($k, $v) => "$k: $v",
			array_keys($headers),
			$headers,
		));

		if (is_resource($out)) {
			curl_setopt($this->ch, CURLOPT_FILE, $out);
			curl_setopt($this->ch, CURLOPT_RETURNTRANSFER, false);
		} else if (is_null($out)) {
			curl_setopt($this->ch, CURLOPT_RETURNTRANSFER, true);
		} else {
			throw new InvalidArgumentException('Invalid output type: ' . gettype($out));
		}

		$responseHeaders = [];
		curl_setopt($this->ch, CURLOPT_HEADERFUNCTION, static function ($_, string $data) use (&$responseHeaders): int {
			$header = trim($data);
			if (str_contains($header, ':')) {
				[$name, $value] = explode(':', $header, 2);
				$responseHeaders[strtolower($name)] = trim($value);
			}
			return strlen($data);
		});

		$response = curl_exec($this->ch);
		if ($response === false) {
			throw new RuntimeException('Curl error: ' . curl_error($this->ch));
		}

		$code = curl_getinfo($this->ch, CURLINFO_HTTP_CODE);
		if ($code >= 200 && $code < 500) {
			$cacheValue = [$code, $response, $responseHeaders];
		}
		return [$code, $response, $responseHeaders];
	}
}
