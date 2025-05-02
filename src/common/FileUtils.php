<?php

namespace dpe\common;
use RuntimeException;

class FileUtils
{
	public static function read(string $path): string
	{
		if (!file_exists($path)) {
			throw new RuntimeException("$path not found");
		}
		$file_content = file_get_contents($path);
		if ($file_content === false) {
			throw new RuntimeException("Unable to read $path");
		}
		return $file_content;
	}
}
