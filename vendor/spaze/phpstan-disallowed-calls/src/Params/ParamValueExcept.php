<?php
declare(strict_types = 1);

namespace Spaze\PHPStan\Rules\Disallowed\Params;

use PHPStan\Type\Type;

class ParamValueExcept extends ParamValue
{

	public function matches(Type $type): bool
	{
		return !$this->getType()->isSuperTypeOf($type)->yes();
	}

}
