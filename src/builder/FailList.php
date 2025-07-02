<?php

namespace dpe\builder;

class FailList
{
	public function __construct(
		private readonly array $entries
	)
	{
	}

	public function filterMatrix(JobMatrix $m): JobMatrix
	{
		foreach ($this->entries as $entry) {
			$m = $this->applyFilterEntry($entry, $m);
		}
		return $m;
	}

	private function applyFilterEntry(array $entry, JobMatrix $m): JobMatrix
	{
		$filters = [];
		foreach ($entry as $var => $values) {
			if ($var === 'ext' || !$values) {
				continue;
			}
			$values = explode(',', $values);
			$varFilters = [];
			foreach ($values as $value) {
				if ($var === 'ext_version' || $var === 'php') {
					if (preg_match('/^(<=?|>=?|=|~)/', $value, $op)) {
						$op = $op[0];
						$value = substr($value, strlen($op));
					} else {
						$op = '~';
					}
					$varFilters[] = fn($v) => VersionTools::compare($v, $value, $op);
				} else {
					$varFilters[] = fn($v) => $v === $value;
				}
			}
			$filters[] = fn($conf) => array_reduce(
				$varFilters,
				fn($carry, $filter) => $carry || $filter($conf[$var]),
				false
			);
		}

		foreach ($m->configs() as $config) {
			$matches = array_reduce($filters, fn($carry, $filter) => $carry && $filter($config), true);
			if ($matches) {
				$m = $m->exclude($config);
			}
		}

		return $m;
	}

	public static function fromFile(string $filename, ?string $extFilter = null): self
	{
		if (!file_exists($filename)) {
			throw new \RuntimeException("File not found: $filename");
		}

		$file = fopen($filename, 'rb');
		$entries = [];
		$header = fgetcsv($file, separator: "\t");
		while ($line = fgetcsv($file, separator: "\t")) {
			if (str_starts_with($line[0], '#')) {
				continue; // Skip comment lines
			}
			$entry = array_combine($header, array_pad($line, count($header), ''));
			if ($extFilter && $entry['ext'] !== $extFilter) {
				continue;
			}
			$entries[] = $entry;
		}
		return new self($entries);
	}
}
