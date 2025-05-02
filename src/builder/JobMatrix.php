<?php

namespace dpe\builder;

class JobMatrix
{
	public function __construct(
		public readonly array $vars,
		public readonly array $exclude,
	)
	{
		if (array_key_exists('exclude', $this->vars)) {
			throw new \InvalidArgumentException("The 'exclude' key is reserved and cannot be used as job matrix variable.");
		}
	}

	/**
	 * @throws \JsonException
	 */
	public function toJson(): string
	{
		return json_encode([
			...$this->vars,
			'exclude' => $this->exclude,
		], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
	}

	public function configs(): iterable
	{
		foreach (IterTools::product(...$this->vars) as $c) {
			if ($this->excludes($c)) {
				continue;
			}
			yield $c;
		}
	}

	public function excludes(array $config): bool
	{
		foreach ($this->exclude as $exclude) {
			if (self::matches($exclude, $config)) {
				return true;
			}
		}
		return false;
	}

	private static function matches(array $partialConfig, array $config): bool
	{
		foreach ($partialConfig as $key => $value) {
			if (!isset($config[$key]) || $config[$key] !== $value) {
				return false; // Mismatch found
			}
		}
		return true; // All keys match
	}

	public function pin(string $key, mixed $value): self
	{
		if (!array_key_exists($key, $this->vars)) {
			throw new \InvalidArgumentException("Pin key '$key' not in job matrix.");
		}
		return (new JobMatrix(
			vars: [
				...$this->vars,
				$key => [$value],
			],
			exclude: array_filter($this->exclude, fn($c) => $c[$key] !== $value),
		))->cleanup();
	}

	public function exclude(array $conf): self
	{
		foreach ($conf as $key => $value) {
			if (!array_key_exists($key, $this->vars)) {
				throw new \InvalidArgumentException("Exclude configuration key '$key' not in job matrix.");
			}
		}
		return (new JobMatrix($this->vars, [...$this->exclude, $conf]))->cleanup();
	}

	private function cleanup(): self
	{
		return $this->knockout()->removeRedundantExcludes();
	}

	private function knockout(): self
	{
		// Remove values from matrix that are always excluded
		$knockout = [...$this->vars];
		foreach ($this->configs() as $config) {
			foreach ($config as $key => $value) {
				$values = &$knockout[$key];
				$i = array_search($value, $values, true);
				if ($i !== false) {
					unset($values[$i]);
				}
			}
			unset($values);
		}

		// Remaining values in $knockout are those that are never seen
		$vars = [];
		foreach ($this->vars as $key => $values) {
			$vars[$key] = array_values(array_diff($values, $knockout[$key]));
		}

		return new JobMatrix(
			vars: $vars,
			exclude: $this->exclude,
		);
	}

	private function removeRedundantExcludes(): self
	{
		// Remove excludes that are using values not in the matrix
		$excludes = array_filter($this->exclude, function ($exclude) {
			foreach ($exclude as $key => $value) {
				if (!in_array($value, $this->vars[$key], true)) {
					return false;
				}
			}
			return true;
		});
		return new JobMatrix(
			vars: $this->vars,
			exclude: $excludes,
		);
	}
}
