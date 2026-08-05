<?php
declare(strict_types = 1);

namespace Spaze\PHPStan\Rules\Disallowed\Usages;

use PhpParser\Node;
use PhpParser\Node\Expr\ConstFetch;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleError;
use PHPStan\ShouldNotHappenException;
use Spaze\PHPStan\Rules\Disallowed\DisallowedConstant;
use Spaze\PHPStan\Rules\Disallowed\DisallowedConstantFactory;
use Spaze\PHPStan\Rules\Disallowed\RuleErrors\DisallowedConstantRuleErrors;
use Spaze\PHPStan\Rules\Disallowed\RuleErrors\ErrorIdentifiers;

/**
 * Reports on constant usage.
 *
 * @package Spaze\PHPStan\Rules\Disallowed
 * @implements Rule<ConstFetch>
 */
class ConstantUsages implements Rule
{

	private DisallowedConstantRuleErrors $disallowedConstantRuleError;

	/** @var list<DisallowedConstant> */
	private array $disallowedConstants;


	/**
	 * @param DisallowedConstantRuleErrors $disallowedConstantRuleErrors
	 * @param DisallowedConstantFactory $disallowedConstantFactory
	 * @param array<array{constant?:string|list<string>, message?:string, allowIn?:list<string>, allowExceptIn?:list<string>, disallowIn?:list<string>, allowInFunctions?:list<string>, allowInMethods?:list<string>, allowExceptInFunctions?:list<string>, allowExceptInMethods?:list<string>, disallowInFunctions?:list<string>, disallowInMethods?:list<string>, allowInInstanceOf?:list<string>, allowExceptInInstanceOf?:list<string>, disallowInInstanceOf?:list<string>, allowInClassWithAttributes?:list<string>, allowExceptInClassWithAttributes?:list<string>, disallowInClassWithAttributes?:list<string>, allowInFunctionsWithAttributes?:list<string>, allowInMethodsWithAttributes?:list<string>, allowExceptInFunctionsWithAttributes?:list<string>, allowExceptInMethodsWithAttributes?:list<string>, disallowInFunctionsWithAttributes?:list<string>, disallowInMethodsWithAttributes?:list<string>, allowInClassWithMethodAttributes?:list<string>, allowExceptInClassWithMethodAttributes?:list<string>, disallowInClassWithMethodAttributes?:list<string>, errorIdentifier?:string, errorTip?:string|list<string>}> $disallowedConstants
	 * @throws ShouldNotHappenException
	 */
	public function __construct(DisallowedConstantRuleErrors $disallowedConstantRuleErrors, DisallowedConstantFactory $disallowedConstantFactory, array $disallowedConstants)
	{
		$this->disallowedConstantRuleError = $disallowedConstantRuleErrors;
		$this->disallowedConstants = $disallowedConstantFactory->createFromConfig($disallowedConstants);
	}


	public function getNodeType(): string
	{
		return ConstFetch::class;
	}


	/**
	 * @param ConstFetch $node
	 * @param Scope $scope
	 * @return list<RuleError>
	 * @throws ShouldNotHappenException
	 */
	public function processNode(Node $node, Scope $scope): array
	{
		return $this->disallowedConstantRuleError->get((string)$node->name, $node, $scope, null, $this->disallowedConstants, ErrorIdentifiers::DISALLOWED_CONSTANT);
	}

}
