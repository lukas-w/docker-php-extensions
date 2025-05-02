<?php

namespace dpe\common;

use DateInterval;
use DateTimeImmutable;

class DockerRegistryHttpClient extends HttpClient
{
	private array $tokens = [];

	public function __construct(
		string                   $baseUrl,
		private readonly ?string $tokenAuth,
	)
	{
		parent::__construct($baseUrl);
	}

	protected function exec(string $path, mixed $out = null, string $method = 'GET', array $headers = []): string|bool
	{
		$token = $this->getTokenFromCache($path);

		if ($token) {
			$this->headers["Authorization"] = "Bearer " . $token;
		}

		$wwwAuth = null;
		curl_setopt($this->ch, CURLOPT_HEADERFUNCTION, function ($_, string $header) use (&$wwwAuth): int {
			$wwwAuthH = 'www-authenticate:';
			if (str_starts_with(strtolower($header), $wwwAuthH)) {
				$wwwAuth = trim(substr($header, strlen($wwwAuthH)));
			}
			return strlen($header);
		});

		$response = parent::exec($path, $out, $method, $headers);
		$code = curl_getinfo($this->ch, CURLINFO_HTTP_CODE);

		if ($code === 401) {
			if ($token) {
				throw new \RuntimeException("401 Unauthorized with token for $path");
			}
			if (!$wwwAuth) {
				throw new \RuntimeException("401 but no WWW-Authenticate header returned");
			}
			$this->requestToken($path, $wwwAuth);
			// Retry
			return $this->exec($path, $out);
		}

		return $response;
	}

	/**
	 * @throws \DateInvalidOperationException
	 */
	private function getTokenFromCache(string $path, int $timeTolerance = 30): ?string
	{
		$token = $this->tokens[$path] ?? null;
		if (!$token) return null;
		$issued = $token['issued_at'] ?? null;
		$expiresIn = $token['expires_in'] ?? null;
		if (!$issued || !$expiresIn) return $token['token'] ?? null;

		$issued = new DateTimeImmutable($issued);
		$expiry = $issued->add(new DateInterval('PT' . $expiresIn . 'S'));
		$now = (new DateTimeImmutable())->sub(new DateInterval('PT' . $timeTolerance . 'S'));
		return $expiry < $now ? $token['token'] : null;
	}

	private function requestToken(string $path, string $wwwAuth): void
	{
		[$scheme, $params] = self::parseWwwAuthHeader($wwwAuth);
		if ($scheme !== 'Bearer') {
			throw new \RuntimeException("Unsupported authentication scheme: $scheme");
		}
		foreach (['realm', 'service', 'scope'] as $required) {
			if (!isset($params[$required])) {
				throw new \RuntimeException("Missing required parameter '$required' in WWW-Authenticate header");
			}
		}
		$client = new HttpClient($params['realm']);
		unset($params['realm']);

		if ($this->tokenAuth) {
			$client->setHeaders([
				"Authorization: Basic " . base64_encode($this->tokenAuth),
			]);
		}

		try {
			$r = $client->getJson('?' . http_build_query($params));
		} catch (\Throwable $e) {
			throw new \RuntimeException("Failed to request token for $path", previous: $e);
		}
		$this->tokens[$path] = $r;
	}

	public static function parseWwwAuthHeader(string $header): array
	{
		[$scheme, $params] = explode(' ', $header, 2);

		$regex = '/(?\'key\'[a-z]+)="(?\'value\'(?:[^"]|\\\\")*(?<!\\\))"/';
		preg_match_all($regex, $params, $matches, PREG_SET_ORDER);

		$params = [];
		foreach ($matches as $match) {
			$key = $match['key'];
			$value = $match['value'];
			// Unescape quotes
			$value = str_replace('\\"', '"', $value);
			$params[$key] = $value;
		}

		return [$scheme, $params];
	}
}
