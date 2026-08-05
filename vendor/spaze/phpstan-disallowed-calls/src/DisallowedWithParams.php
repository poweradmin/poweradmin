<?php
declare(strict_types = 1);

namespace Spaze\PHPStan\Rules\Disallowed;

use Spaze\PHPStan\Rules\Disallowed\Params\Param;

interface DisallowedWithParams extends Disallowed
{

	/**
	 * @return array<int|string, Param>
	 */
	public function getAllowParamsInAllowed(): array;


	/**
	 * @return array<int|string, Param>
	 */
	public function getAllowParamsAnywhere(): array;


	/**
	 * @return array<int|string, Param>
	 */
	public function getAllowExceptParamsInAllowed(): array;


	/**
	 * @return array<int|string, Param>
	 */
	public function getAllowExceptParams(): array;

}
