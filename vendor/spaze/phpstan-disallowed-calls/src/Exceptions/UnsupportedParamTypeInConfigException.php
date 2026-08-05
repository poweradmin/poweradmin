<?php
declare(strict_types = 1);

namespace Spaze\PHPStan\Rules\Disallowed\Exceptions;

use Throwable;

class UnsupportedParamTypeInConfigException extends InvalidConfigException
{

	public function __construct(?int $position, ?string $name, string $type, int $code = 0, ?Throwable $previous = null)
	{
		$message = sprintf(
			'Parameter%s%s has an unsupported type %s specified in configuration',
			$position ? " #{$position}" : '',
			$name ? " \${$name}" : '',
			$type
		);
		parent::__construct($message, $code, $previous);
	}

}
