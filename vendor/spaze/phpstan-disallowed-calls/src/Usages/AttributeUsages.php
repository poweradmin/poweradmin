<?php
declare(strict_types = 1);

namespace Spaze\PHPStan\Rules\Disallowed\Usages;

use PhpParser\Node;
use PhpParser\Node\Attribute;
use PhpParser\Node\AttributeGroup;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt\ClassConst;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\EnumCase;
use PhpParser\Node\Stmt\Property;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use Spaze\PHPStan\Rules\Disallowed\DisallowedAttribute;
use Spaze\PHPStan\Rules\Disallowed\DisallowedAttributeFactory;
use Spaze\PHPStan\Rules\Disallowed\RuleErrors\DisallowedAttributeRuleErrors;

/**
 * @implements Rule<Node>
 */
class AttributeUsages implements Rule
{

	private DisallowedAttributeRuleErrors $disallowedAttributeRuleErrors;

	/** @var list<DisallowedAttribute> */
	private array $disallowedAttributes;

	/** @var list<Attribute> */
	private array $attributes;


	/**
	 * @param DisallowedAttributeRuleErrors $disallowedAttributeRuleErrors
	 * @param DisallowedAttributeFactory $disallowedAttributeFactory
	 * @param array $disallowedAttributes
	 * @phpstan-param DisallowedAttributesConfig $disallowedAttributes
	 */
	public function __construct(
		DisallowedAttributeRuleErrors $disallowedAttributeRuleErrors,
		DisallowedAttributeFactory $disallowedAttributeFactory,
		array $disallowedAttributes
	) {
		$this->disallowedAttributeRuleErrors = $disallowedAttributeRuleErrors;
		$this->disallowedAttributes = $disallowedAttributeFactory->createFromConfig($disallowedAttributes);
	}


	public function getNodeType(): string
	{
		return Node::class;
	}


	/**
	 * @param list<AttributeGroup> $attributeGroups
	 */
	private function addAttrs(array $attributeGroups): void
	{
		foreach ($attributeGroups as $attributeGroup) {
			foreach ($attributeGroup->attrs as $attr) {
				$this->attributes[] = $attr;
			}
		}
	}


	public function processNode(Node $node, Scope $scope): array
	{
		$this->attributes = [];
		if ($node instanceof ClassLike) {
			$this->addAttrs(array_values($node->attrGroups));
		} elseif ($node instanceof FunctionLike) {
			$this->addAttrs(array_values($node->getAttrGroups()));
		} elseif ($node instanceof Property) {
			$this->addAttrs(array_values($node->attrGroups));
		} elseif ($node instanceof ClassConst) {
			$this->addAttrs(array_values($node->attrGroups));
		} elseif ($node instanceof Param) {
			$this->addAttrs(array_values($node->attrGroups));
		} elseif ($node instanceof EnumCase) {
			$this->addAttrs(array_values($node->attrGroups));
		} else {
			return [];
		}

		$errors = [];
		foreach ($this->attributes as $attribute) {
			$ruleErrors = $this->disallowedAttributeRuleErrors->get($node, $attribute, $scope, $this->disallowedAttributes);
			if ($ruleErrors) {
				$errors = array_merge($errors, $ruleErrors);
			}
		}
		return $errors;
	}

}
