<?php
declare(strict_types = 1);

namespace Spaze\PHPStan\Rules\Disallowed\Calls;

use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\ShouldNotHappenException;
use Spaze\PHPStan\Rules\Disallowed\DisallowedCall;
use Spaze\PHPStan\Rules\Disallowed\DisallowedCallFactory;
use Spaze\PHPStan\Rules\Disallowed\RuleErrors\DisallowedCallableParameterRuleErrors;
use Spaze\PHPStan\Rules\Disallowed\RuleErrors\DisallowedFunctionRuleErrors;

/**
 * Reports on dynamically calling a disallowed function.
 *
 * @package Spaze\PHPStan\Rules\Disallowed
 * @implements Rule<FuncCall>
 */
class FunctionCalls implements Rule
{

	private DisallowedFunctionRuleErrors $disallowedFunctionRuleErrors;

	private DisallowedCallableParameterRuleErrors $disallowedCallableParameterRuleErrors;

	/** @var list<DisallowedCall> */
	private array $disallowedCalls;


	/**
	 * @param DisallowedFunctionRuleErrors $disallowedFunctionRuleErrors
	 * @param DisallowedCallableParameterRuleErrors $disallowedCallableParameterRuleErrors
	 * @param DisallowedCallFactory $disallowedCallFactory
	 * @param array $forbiddenCalls
	 * @phpstan-param ForbiddenCallsConfig $forbiddenCalls
	 * @noinspection PhpUndefinedClassInspection ForbiddenCallsConfig is a type alias defined in PHPStan config
	 * @throws ShouldNotHappenException
	 */
	public function __construct(
		DisallowedFunctionRuleErrors $disallowedFunctionRuleErrors,
		DisallowedCallableParameterRuleErrors $disallowedCallableParameterRuleErrors,
		DisallowedCallFactory $disallowedCallFactory,
		array $forbiddenCalls
	) {
		$this->disallowedFunctionRuleErrors = $disallowedFunctionRuleErrors;
		$this->disallowedCallableParameterRuleErrors = $disallowedCallableParameterRuleErrors;
		$this->disallowedCalls = $disallowedCallFactory->createFromConfig($forbiddenCalls);
	}


	public function getNodeType(): string
	{
		return FuncCall::class;
	}


	/**
	 * @param FuncCall $node
	 * @param Scope $scope
	 * @return list<IdentifierRuleError>
	 * @throws ShouldNotHappenException
	 */
	public function processNode(Node $node, Scope $scope): array
	{
		$errors = $this->disallowedFunctionRuleErrors->get($node, $scope, $this->disallowedCalls);
		$paramErrors = $this->disallowedCallableParameterRuleErrors->getForFunction($node, $scope);
		return $errors || $paramErrors ? array_merge($errors, $paramErrors) : [];
	}

}
