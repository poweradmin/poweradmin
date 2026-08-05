<?php
declare(strict_types = 1);

namespace Spaze\PHPStan\Rules\Disallowed\RuleErrors;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\ShouldNotHappenException;
use Spaze\PHPStan\Rules\Disallowed\Allowed\Allowed;
use Spaze\PHPStan\Rules\Disallowed\DisallowedConstant;
use Spaze\PHPStan\Rules\Disallowed\Formatter\Formatter;

class DisallowedConstantRuleErrors
{

	private Allowed $allowed;

	private Formatter $formatter;

	private ErrorTips $errorTips;


	public function __construct(Allowed $allowed, Formatter $formatter, ErrorTips $errorTips)
	{
		$this->allowed = $allowed;
		$this->formatter = $formatter;
		$this->errorTips = $errorTips;
	}


	/**
	 * @param string $constant
	 * @param Node $node
	 * @param Scope $scope
	 * @param string|null $displayName
	 * @param list<DisallowedConstant> $disallowedConstants
	 * @param string $identifier
	 * @return list<IdentifierRuleError>
	 * @throws ShouldNotHappenException
	 */
	public function get(string $constant, Node $node, Scope $scope, ?string $displayName, array $disallowedConstants, string $identifier): array
	{
		foreach ($disallowedConstants as $disallowedConstant) {
			if ($disallowedConstant->getConstant() === $constant && !$this->allowed->isAllowed($node, $scope, null, $disallowedConstant)) {
				$errorBuilder = RuleErrorBuilder::message(sprintf(
					'Using %s%s is forbidden%s',
					$disallowedConstant->getConstant(),
					$displayName && $displayName !== $disallowedConstant->getConstant() ? ' (as ' . $displayName . ')' : '',
					$this->formatter->formatDisallowedMessage($disallowedConstant->getMessage())
				));
				$errorBuilder->identifier($disallowedConstant->getErrorIdentifier() ?? $identifier);
				$this->errorTips->add($disallowedConstant->getErrorTip(), $errorBuilder);
				return [
					$errorBuilder->build(),
				];
			}
		}
		return [];
	}

}
