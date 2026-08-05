<?php
declare(strict_types = 1);

namespace Spaze\PHPStan\Rules\Disallowed\Exceptions;

class EmptyClassPatternInConfigException extends InvalidConfigException
{

	public function __construct()
	{
		parent::__construct('classPattern is empty');
	}

}
