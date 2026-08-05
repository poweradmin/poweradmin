<?php
declare(strict_types = 1);

namespace Spaze\PHPStan\Rules\Disallowed\Allowed;

use PHPStan\Analyser\Scope;
use Spaze\PHPStan\Rules\Disallowed\File\FilePath;

class AllowedPath
{

	private FilePath $filePath;


	public function __construct(FilePath $filePath)
	{
		$this->filePath = $filePath;
	}


	public function matches(Scope $scope, string $allowedPath): bool
	{
		$file = $scope->getTraitReflection() ? $scope->getTraitReflection()->getFileName() : $scope->getFile();
		return $file !== null && $this->filePath->fnMatch($allowedPath, $file);
	}

}
