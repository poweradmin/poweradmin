<?php
declare(strict_types = 1);

namespace Spaze\PHPStan\Rules\Disallowed\Usages;

use PhpParser\Node;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\IntersectionType;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\NullableType;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\TraitUse;
use PhpParser\Node\Stmt\UseUse;
use PhpParser\Node\UnionType;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleError;
use Spaze\PHPStan\Rules\Disallowed\Allowed\UsagePosition;
use Spaze\PHPStan\Rules\Disallowed\DisallowedNamespace;
use Spaze\PHPStan\Rules\Disallowed\DisallowedNamespaceFactory;
use Spaze\PHPStan\Rules\Disallowed\RuleErrors\DisallowedNamespaceRuleErrors;
use Spaze\PHPStan\Rules\Disallowed\RuleErrors\ErrorIdentifiers;
use Spaze\PHPStan\Rules\Disallowed\Type\TypeHintContextVisitor;
use Spaze\PHPStan\Rules\Disallowed\UsageFactory\NamespaceUsageFactory;

/**
 * @implements Rule<Node>
 */
class NamespaceUsages implements Rule
{

	private DisallowedNamespaceRuleErrors $disallowedNamespaceRuleErrors;

	private NamespaceUsageFactory $namespaceUsageFactory;

	/** @var list<DisallowedNamespace> */
	private array $disallowedNamespace;


	/**
	 * @param DisallowedNamespaceRuleErrors $disallowedNamespaceRuleErrors
	 * @param DisallowedNamespaceFactory $disallowNamespaceFactory
	 * @param array<array{namespace?:string|list<string>, class?:string|list<string>, exclude?:string|list<string>, excludeWithAttribute?:string|list<string>, message?:string, allowIn?:list<string>, allowExceptIn?:list<string>, disallowIn?:list<string>, allowInInstanceOf?:list<string>, allowExceptInInstanceOf?:list<string>, disallowInInstanceOf?:list<string>, allowInClassWithAttributes?:list<string>, allowExceptInClassWithAttributes?:list<string>, disallowInClassWithAttributes?:list<string>, allowInFunctionsWithAttributes?:list<string>, allowInMethodsWithAttributes?:list<string>, allowExceptInFunctionsWithAttributes?:list<string>, allowExceptInMethodsWithAttributes?:list<string>, disallowInFunctionsWithAttributes?:list<string>, disallowInMethodsWithAttributes?:list<string>, allowInClassWithMethodAttributes?:list<string>, allowExceptInClassWithMethodAttributes?:list<string>, disallowInClassWithMethodAttributes?:list<string>, allowInParamTypes?:bool, allowExceptInParamTypes?:bool, disallowInParamTypes?:bool, allowInReturnType?:bool, allowExceptInReturnType?:bool, disallowInReturnType?:bool, allowInUse?:bool, errorIdentifier?:string, errorTip?:string|list<string>}> $forbiddenNamespaces
	 */
	public function __construct(
		DisallowedNamespaceRuleErrors $disallowedNamespaceRuleErrors,
		DisallowedNamespaceFactory $disallowNamespaceFactory,
		NamespaceUsageFactory $namespaceUsageFactory,
		array $forbiddenNamespaces
	) {
		$this->disallowedNamespaceRuleErrors = $disallowedNamespaceRuleErrors;
		$this->disallowedNamespace = $disallowNamespaceFactory->createFromConfig($forbiddenNamespaces);
		$this->namespaceUsageFactory = $namespaceUsageFactory;
	}


	public function getNodeType(): string
	{
		return Node::class;
	}


	/**
	 * @param Node $node
	 * @param Scope $scope
	 * @return list<RuleError>
	 */
	public function processNode(Node $node, Scope $scope): array
	{
		$position = null;

		if ($node instanceof FullyQualified) {
			$description = 'Class';
			$identifier = ErrorIdentifiers::DISALLOWED_CLASS;
			$namespaces = [$this->namespaceUsageFactory->create($node->toString())];
			$position = $this->getPosition($node);
		} elseif ($node instanceof NullableType && $node->type instanceof FullyQualified) {
			$description = 'Class';
			$identifier = ErrorIdentifiers::DISALLOWED_CLASS;
			$namespaces = [$this->namespaceUsageFactory->create($node->type->toString())];
			$position = $this->getPosition($node);
		} elseif ($node instanceof UnionType || $node instanceof IntersectionType) {
			$description = 'Class';
			$identifier = ErrorIdentifiers::DISALLOWED_CLASS;
			$namespaces = [];
			foreach ($node->types as $type) {
				if ($type instanceof FullyQualified) {
					$namespaces[] = $this->namespaceUsageFactory->create($type->toString());
				}
			}
			$position = $this->getPosition($node);
		} elseif ($node instanceof UseUse) {
			$namespaces = [$this->namespaceUsageFactory->create($node->name->toString(), true)];
		} elseif ($node instanceof StaticCall && $node->class instanceof Name) {
			$namespaces = [$this->namespaceUsageFactory->create($node->class->toString())];
		} elseif ($node instanceof ClassConstFetch && $node->class instanceof Name) {
			$namespaces = [];
			$classReflection = $scope->resolveTypeByName($node->class)->getClassReflection();
			if ($classReflection && $classReflection->isEnum()) {
				$description = 'Enum';
				$identifier = ErrorIdentifiers::DISALLOWED_ENUM;
				$namespaces = [$this->namespaceUsageFactory->create($node->class->toString())];
			}
		} elseif ($node instanceof Class_ && ($node->extends !== null || count($node->implements) > 0)) {
			$namespaces = [];
			if ($node->extends !== null) {
				$namespaces[] = $this->namespaceUsageFactory->create($node->extends->toString());
			}
			foreach ($node->implements as $implement) {
				$namespaces[] = $this->namespaceUsageFactory->create($implement->toString());
			}
		} elseif ($node instanceof New_ && $node->class instanceof Name) {
			$description = 'Class';
			$identifier = ErrorIdentifiers::DISALLOWED_CLASS;
			$namespaces = [$this->namespaceUsageFactory->create($node->class->toString())];
		} elseif ($node instanceof TraitUse) {
			$description = 'Trait';
			$identifier = ErrorIdentifiers::DISALLOWED_TRAIT;
			$namespaces = [];
			foreach ($node->traits as $trait) {
				$namespaces[] = $this->namespaceUsageFactory->create($trait->toString());
			}
		} else {
			return [];
		}

		$errors = [];
		foreach ($namespaces as $namespaceUsage) {
			$ruleErrors = $this->disallowedNamespaceRuleErrors->getDisallowedMessage(
				$node,
				$namespaceUsage,
				$description ?? 'Namespace',
				$scope,
				$this->disallowedNamespace,
				$identifier ?? $identifier = ErrorIdentifiers::DISALLOWED_NAMESPACE,
				$position,
			);
			if ($ruleErrors) {
				$errors = array_merge($errors, $ruleErrors);
			}
		}

		return $errors;
	}


	/**
	 * @return UsagePosition::*|null
	 */
	private function getPosition(Node $node): ?int
	{
		if ($node->getAttribute(TypeHintContextVisitor::ATTRIBUTE_IN_PARAM_TYPE)) {
			return UsagePosition::PARAM_TYPE;
		}
		if ($node->getAttribute(TypeHintContextVisitor::ATTRIBUTE_IN_RETURN_TYPE)) {
			return UsagePosition::RETURN_TYPE;
		}
		return null;
	}

}
