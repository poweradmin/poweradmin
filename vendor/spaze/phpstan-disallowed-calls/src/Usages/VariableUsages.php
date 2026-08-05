<?php
declare(strict_types = 1);

namespace Spaze\PHPStan\Rules\Disallowed\Usages;

use PhpParser\Node;
use PhpParser\Node\Expr\Variable;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleError;
use PHPStan\ShouldNotHappenException;
use Spaze\PHPStan\Rules\Disallowed\DisallowedVariable;
use Spaze\PHPStan\Rules\Disallowed\RuleErrors\DisallowedVariableRuleErrors;

/**
 * Reports on a variable name usage.
 *
 * @package Spaze\PHPStan\Rules\Disallowed
 * @implements Rule<Variable>
 */
class VariableUsages implements Rule
{

	private DisallowedVariableRuleErrors $disallowedVariableRuleErrors;

	/** @var list<DisallowedVariable> */
	private array $disallowedVariables;


	/**
	 * @param DisallowedVariableRuleErrors $disallowedVariableRuleErrors
	 * @param list<DisallowedVariable> $disallowedVariables
	 */
	public function __construct(DisallowedVariableRuleErrors $disallowedVariableRuleErrors, array $disallowedVariables)
	{
		$this->disallowedVariableRuleErrors = $disallowedVariableRuleErrors;
		$this->disallowedVariables = $disallowedVariables;
	}


	public function getNodeType(): string
	{
		return Variable::class;
	}


	/**
	 * @param Node $node
	 * @param Scope $scope
	 * @return list<RuleError>
	 * @throws ShouldNotHappenException
	 */
	public function processNode(Node $node, Scope $scope): array
	{
		if (!($node instanceof Variable)) {
			throw new ShouldNotHappenException(sprintf('$node should be %s but is %s', Variable::class, get_class($node)));
		}

		$variableName = $node->name;
		if (!is_string($variableName)) {
			return [];
		}

		return $this->disallowedVariableRuleErrors->get('$' . $variableName, $node, $scope, $this->disallowedVariables);
	}

}
