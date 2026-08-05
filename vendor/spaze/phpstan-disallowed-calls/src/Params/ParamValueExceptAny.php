<?php
declare(strict_types = 1);

namespace Spaze\PHPStan\Rules\Disallowed\Params;

use PHPStan\Type\Type;

final class ParamValueExceptAny extends ParamValue
{

	/**
	 * @throws void
	 */
	public function matches(Type $type): bool
	{
		return false;
	}

}
