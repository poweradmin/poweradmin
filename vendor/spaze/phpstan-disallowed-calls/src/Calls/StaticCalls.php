<?php
declare(strict_types = 1);

namespace Spaze\PHPStan\Rules\Disallowed\Calls;

use PhpParser\Node;
use PhpParser\Node\Expr\StaticCall;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleError;
use PHPStan\ShouldNotHappenException;
use Spaze\PHPStan\Rules\Disallowed\DisallowedCall;
use Spaze\PHPStan\Rules\Disallowed\DisallowedCallFactory;
use Spaze\PHPStan\Rules\Disallowed\RuleErrors\DisallowedCallableParameterRuleErrors;
use Spaze\PHPStan\Rules\Disallowed\RuleErrors\DisallowedMethodRuleErrors;

/**
 * Reports on statically calling a disallowed method or two.
 *
 * Dynamic calls have a different rule, <code>MethodCalls</code>
 *
 * @package Spaze\PHPStan\Rules\Disallowed
 * @implements Rule<StaticCall>
 */
class StaticCalls implements Rule
{

	private DisallowedMethodRuleErrors $disallowedMethodRuleErrors;

	private DisallowedCallableParameterRuleErrors $disallowedCallableParameterRuleErrors;

	/** @var list<DisallowedCall> */
	private array $disallowedCalls;


	/**
	 * @param DisallowedMethodRuleErrors $disallowedMethodRuleErrors
	 * @param DisallowedCallableParameterRuleErrors $disallowedCallableParameterRuleErrors
	 * @param DisallowedCallFactory $disallowedCallFactory
	 * @param array $forbiddenCalls
	 * @phpstan-param ForbiddenCallsConfig $forbiddenCalls
	 * @noinspection PhpUndefinedClassInspection ForbiddenCallsConfig is a type alias defined in PHPStan config
	 * @throws ShouldNotHappenException
	 */
	public function __construct(
		DisallowedMethodRuleErrors $disallowedMethodRuleErrors,
		DisallowedCallableParameterRuleErrors $disallowedCallableParameterRuleErrors,
		DisallowedCallFactory $disallowedCallFactory,
		array $forbiddenCalls
	) {
		$this->disallowedMethodRuleErrors = $disallowedMethodRuleErrors;
		$this->disallowedCalls = $disallowedCallFactory->createFromConfig($forbiddenCalls);
		$this->disallowedCallableParameterRuleErrors = $disallowedCallableParameterRuleErrors;
	}


	public function getNodeType(): string
	{
		return StaticCall::class;
	}


	/**
	 * @param StaticCall $node
	 * @param Scope $scope
	 * @return list<RuleError>
	 * @throws ShouldNotHappenException
	 */
	public function processNode(Node $node, Scope $scope): array
	{
		$errors = $this->disallowedMethodRuleErrors->get($node->class, $node, $scope, $this->disallowedCalls);
		$paramErrors = $this->disallowedCallableParameterRuleErrors->getForMethod($node->class, $node, $scope);
		return $errors || $paramErrors ? array_merge($errors, $paramErrors) : [];
	}

}
