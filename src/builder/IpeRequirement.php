<?php


namespace dpe\builder;

use dpe\common\Target;
use InvalidArgumentException;

class IpeRequirement
{
	public function __construct(
		public readonly bool    $negated,
		public readonly ?string $osId,
		public readonly ?string $osVersion,
		public readonly ?string $phpVersion,
	)
	{
		if (!$osId && !$phpVersion) {
			throw new InvalidArgumentException("At least one of osId or phpVersion must be set");
		}
		if ($this->osVersion && !$osId) {
			throw new InvalidArgumentException("osVersion requires osId");
		}
	}

	public function test(string $osId, string $osVersion, string $phpVersion): bool
	{
		$tests = [];
		if ($this->osId) {
			$tests[] = $this->osId === $osId;
		}
		if ($this->osVersion) {
			$tests[] = VersionTools::compare($osVersion, $this->osVersion) === 0;
		}
		if ($this->phpVersion) {
			if (! $phpVersion) {
				throw new InvalidArgumentException("no phpVersion provided");
			}
			$tests[] = VersionTools::compare($phpVersion, $this->phpVersion) === 0;
		}

		if ($this->negated) {
			return in_array(false, $tests);
		} else {
			return !in_array(false, $tests);
		}
	}

	public function testTarget(Target $target): bool
	{
		return $this->test(
			osId: $target->osId,
			osVersion: $target->osVersion,
			phpVersion: $target->phpVersion,
		);
	}

	public static function parse(string $req): self
	{
		$n = $req[0] === '!';
		if ($n) {
			$req = substr($req, 1);
		}

		if (str_contains($req, 'zts')) {
			throw new InvalidArgumentException("ZTS is not implemented");
		}

		$r = explode('-', $req);
		$osRef = array_pop($r);
		$phpVersion = array_pop($r);

		if (count($r)) {
			throw new InvalidArgumentException("Can't parse: $req");
		}

		[$osId, $osVersion] = Target::parseOsRef($osRef);

		return new self(
			negated: $n,
			osId: $osId,
			osVersion: $osVersion,
			phpVersion: $phpVersion,
		);
	}
}
