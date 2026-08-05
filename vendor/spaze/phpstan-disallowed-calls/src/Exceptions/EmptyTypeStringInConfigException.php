<?php
declare(strict_types = 1);

namespace Spaze\PHPStan\Rules\Disallowed\Exceptions;

class EmptyTypeStringInConfigException extends InvalidConfigException
{

	public function __construct()
	{
		parent::__construct('typeString is empty');
	}

}
