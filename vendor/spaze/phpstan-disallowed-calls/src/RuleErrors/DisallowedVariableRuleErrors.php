<?php
declare(strict_types = 1);

namespace Spaze\PHPStan\Rules\Disallowed\RuleErrors;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\RuleError;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\ShouldNotHappenException;
use Spaze\PHPStan\Rules\Disallowed\Allowed\Allowed;
use Spaze\PHPStan\Rules\Disallowed\DisallowedVariable;
use Spaze\PHPStan\Rules\Disallowed\Formatter\Formatter;

class DisallowedVariableRuleErrors
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
	 * @param string $variable
	 * @param Node $node
	 * @param Scope $scope
	 * @param list<DisallowedVariable> $disallowedVariables
	 * @return list<RuleError>
	 * @throws ShouldNotHappenException
	 */
	public function get(string $variable, Node $node, Scope $scope, array $disallowedVariables): array
	{
		foreach ($disallowedVariables as $disallowedVariable) {
			if ($disallowedVariable->getVariable() === $variable && !$this->allowed->isAllowed($node, $scope, null, $disallowedVariable)) {
				$errorBuilder = RuleErrorBuilder::message(sprintf(
					'Using %s is forbidden%s',
					$disallowedVariable->getVariable(),
					$this->formatter->formatDisallowedMessage($disallowedVariable->getMessage())
				));
				$errorBuilder->identifier($disallowedVariable->getErrorIdentifier() ?? ErrorIdentifiers::DISALLOWED_VARIABLE);
				$this->errorTips->add($disallowedVariable->getErrorTip(), $errorBuilder);
				return [
					$errorBuilder->build(),
				];
			}
		}

		return [];
	}

}
