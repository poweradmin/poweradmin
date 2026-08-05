<?php
declare(strict_types = 1);

namespace Spaze\PHPStan\Rules\Disallowed\Params;

use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\Type;

class ParamValueCaseInsensitiveExcept extends ParamValue
{

	public function matches(Type $type): bool
	{
		$fn = function (ConstantStringType $string): string {
			return strtolower($string->getValue());
		};
		return array_intersect(array_map($fn, $type->getConstantStrings()), array_map($fn, $this->getType()->getConstantStrings())) === [];
	}

}
