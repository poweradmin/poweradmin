<?php
declare(strict_types = 1);

namespace Spaze\PHPStan\Rules\Disallowed\Calls;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\FunctionCallableNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\ShouldNotHappenException;
use Spaze\PHPStan\Rules\Disallowed\DisallowedCall;
use Spaze\PHPStan\Rules\Disallowed\DisallowedCallFactory;
use Spaze\PHPStan\Rules\Disallowed\RuleErrors\DisallowedFunctionRuleErrors;

/**
 * Reports on first class callable syntax for a disallowed method.
 *
 * @package Spaze\PHPStan\Rules\Disallowed
 * @implements Rule<FunctionCallableNode>
 */
class FunctionFirstClassCallables implements Rule
{

	private DisallowedFunctionRuleErrors $disallowedFunctionRuleErrors;

	/** @var list<DisallowedCall> */
	private array $disallowedCalls;


	/**
	 * @param DisallowedFunctionRuleErrors $disallowedFunctionRuleErrors
	 * @param DisallowedCallFactory $disallowedCallFactory
	 * @param array $forbiddenCalls
	 * @phpstan-param ForbiddenCallsConfig $forbiddenCalls
	 * @noinspection PhpUndefinedClassInspection ForbiddenCallsConfig is a type alias defined in PHPStan config
	 * @throws ShouldNotHappenException
	 */
	public function __construct(
		DisallowedFunctionRuleErrors $disallowedFunctionRuleErrors,
		DisallowedCallFactory $disallowedCallFactory,
		array $forbiddenCalls
	) {
		$this->disallowedFunctionRuleErrors = $disallowedFunctionRuleErrors;
		$this->disallowedCalls = $disallowedCallFactory->createFromConfig($forbiddenCalls);
	}


	public function getNodeType(): string
	{
		return FunctionCallableNode::class;
	}


	/**
	 * @param FunctionCallableNode $node
	 * @param Scope $scope
	 * @return list<IdentifierRuleError>
	 * @throws ShouldNotHappenException
	 */
	public function processNode(Node $node, Scope $scope): array
	{
		$originalNode = $node->getOriginalNode();
		return $this->disallowedFunctionRuleErrors->get($originalNode, $scope, $this->disallowedCalls);
	}

}
