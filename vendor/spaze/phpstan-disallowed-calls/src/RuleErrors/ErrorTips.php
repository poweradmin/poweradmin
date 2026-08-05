<?php
declare(strict_types = 1);

namespace Spaze\PHPStan\Rules\Disallowed\RuleErrors;

use PHPStan\Rules\RuleError;
use PHPStan\Rules\RuleErrorBuilder;

class ErrorTips
{

	/**
	 * @param list<string> $tips
	 * @param RuleErrorBuilder<RuleError> $errorBuilder
	 */
	public function add(array $tips, RuleErrorBuilder $errorBuilder): void
	{
		foreach ($tips as $tip) {
			if ($tip === '') {
				continue;
			}
			$errorBuilder->addTip($tip);
		}
	}

}
