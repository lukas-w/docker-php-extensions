<?php

namespace dpe\common;

use DateInterval;
use DateTimeImmutable;

class DockerRegistryHttpClient extends HttpClient
{
	private array $tokens = [];
	private HttpClient $tokenClient;

	public function __construct(
		string                   $baseUrl,
		private readonly ?string $tokenAuth,
	)
	{
		parent::__construct($baseUrl);
		$this->tokenClient = new HttpClient();
	}

	protected function exec(string $path, mixed $out = null, string $method = 'GET', array $headers = []): array
	{
		$token = $this->getTokenFromCache($path);

		if ($token) {
			$this->headers["Authorization"] = "Bearer " . $token;
		}

		[$code, $response, $responseHeaders] = parent::exec($path, $out, $method, $headers);

		if ($code === 401) {
			if ($token) {
				throw new \RuntimeException("401 Unauthorized with token for $path");
			}
			$wwwAuth = $responseHeaders['www-authenticate'] ?? null;
			if (!$wwwAuth) {
				throw new \RuntimeException("401 but no WWW-Authenticate header returned");
			}
			$this->requestToken($path, $wwwAuth);
			// Retry
			return $this->exec($path, $out, $method, $headers);
		}

		return [$code, $response, $responseHeaders];
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

		if ($this->tokenAuth) {
			$this->tokenClient->setHeaders([
				"Authorization: Basic " . base64_encode($this->tokenAuth),
			]);
		}

		$realm = $params['realm'];
		unset($params['realm']);
		try {
			$r = $this->tokenClient->getJson($realm . '?' . http_build_query($params));
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
