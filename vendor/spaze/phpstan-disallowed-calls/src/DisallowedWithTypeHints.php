<?php
declare(strict_types = 1);

namespace Spaze\PHPStan\Rules\Disallowed;

use Spaze\PHPStan\Rules\Disallowed\Allowed\UsagePosition;

interface DisallowedWithTypeHints extends Disallowed
{

	/**
	 * @param UsagePosition::* $position
	 */
	public function getAllowInPosition(int $position): bool;


	/**
	 * @param UsagePosition::* $position
	 */
	public function getAllowExceptInPosition(int $position): bool;

}
