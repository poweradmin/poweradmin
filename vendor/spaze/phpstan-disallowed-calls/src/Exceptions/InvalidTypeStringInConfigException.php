<?php
declare(strict_types = 1);

namespace Spaze\PHPStan\Rules\Disallowed\Exceptions;

use Throwable;

class InvalidTypeStringInConfigException extends InvalidConfigException
{

	public function __construct(string $typeString, string $reason, ?Throwable $previous = null)
	{
		parent::__construct(sprintf("Invalid typeString '%s': %s", $typeString, $reason), 0, $previous);
	}

}
