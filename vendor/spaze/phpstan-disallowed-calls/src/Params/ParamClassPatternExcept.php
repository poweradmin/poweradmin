<?php
declare(strict_types = 1);

namespace Spaze\PHPStan\Rules\Disallowed\Params;

use PHPStan\Type\Type;

class ParamClassPatternExcept extends ParamClassPattern
{

	public function matches(Type $type): bool
	{
		return !parent::matches($type);
	}

}
