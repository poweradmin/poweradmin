<?php
declare(strict_types = 1);

namespace Spaze\PHPStan\Rules\Disallowed\File;

use PHPStan\File\FileHelper;

class FilePath
{

	private FileHelper $fileHelper;

	private ?string $rootDir;


	public function __construct(FileHelper $fileHelper, ?string $rootDir = null)
	{
		$this->fileHelper = $fileHelper;
		$this->rootDir = $rootDir !== null ? $this->fileHelper->normalizePath($fileHelper->absolutizePath($rootDir)) : null;
	}


	public function fnMatch(string $path, string $file): bool
	{
		return fnmatch($this->absolutizePath($path, $this->rootDir), $this->absolutizePath($file, null), FNM_NOESCAPE);
	}


	/**
	 * Make path absolute unless it starts with a wildcard, then return as is.
	 *
	 * @param string $path
	 * @param string|null $rootDir
	 * @return string
	 */
	private function absolutizePath(string $path, ?string $rootDir): string
	{
		if (strpos($path, '*') === 0) {
			return $path;
		}

		if ($rootDir !== null) {
			$path = rtrim($rootDir, '/') . '/' . ltrim($path, '/');
		}
		return $this->fileHelper->normalizePath($this->fileHelper->absolutizePath($path));
	}

}
