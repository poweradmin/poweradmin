<?php
declare(strict_types = 1);

namespace Spaze\PHPStan\Rules\Disallowed\Params;

use PHPStan\Type\Constant\ConstantIntegerType;
use PHPStan\Type\Type;
use Spaze\PHPStan\Rules\Disallowed\Exceptions\UnsupportedParamTypeException;

abstract class ParamValueFlag extends ParamValue
{

	/**
	 * @throws UnsupportedParamTypeException
	 */
	protected function isFlagSet(Type $type): bool
	{
		if (!$type instanceof ConstantIntegerType) {
			throw new UnsupportedParamTypeException();
		}
		foreach ($this->getType()->getConstantScalarValues() as $value) {
			if (is_int($value) && ($value & $type->getValue()) !== 0) {
				return true;
			}
		}
		return false;
	}

}
