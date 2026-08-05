<?php
declare(strict_types = 1);

namespace Spaze\PHPStan\Rules\Disallowed\Type;

use PhpParser\Node;
use PhpParser\Node\AttributeGroup;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\IntersectionType;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\NullableType;
use PhpParser\Node\UnionType;
use PhpParser\NodeVisitorAbstract;

class TypeHintContextVisitor extends NodeVisitorAbstract
{

	public const ATTRIBUTE_IN_PARAM_TYPE = self::class . '_inParamType';
	public const ATTRIBUTE_IN_RETURN_TYPE = self::class . '_inReturnType';
	public const ATTRIBUTE_ENCLOSING_FUNCTION_ATTR_NAMES = self::class . '_enclosingFunctionAttrNames';


	public function enterNode(Node $node)
	{
		if ($node instanceof FunctionLike) {
			$attrNames = $this->extractAttrNames($node->getAttrGroups());
			foreach ($node->getParams() as $param) {
				if ($param->type !== null) {
					$this->markTypeNode($param->type, self::ATTRIBUTE_IN_PARAM_TYPE, $attrNames);
				}
			}
			if ($node->getReturnType() !== null) {
				$this->markTypeNode($node->getReturnType(), self::ATTRIBUTE_IN_RETURN_TYPE, $attrNames);
			}
		}
		return null;
	}


	/**
	 * @param list<string> $attrNames
	 */
	private function markTypeNode(Node $typeNode, string $attribute, array $attrNames): void
	{
		$this->annotateNode($typeNode, $attribute, $attrNames);
		if ($typeNode instanceof NullableType) {
			if ($typeNode->type instanceof FullyQualified) {
				$this->annotateNode($typeNode->type, $attribute, $attrNames);
			}
		} elseif ($typeNode instanceof UnionType || $typeNode instanceof IntersectionType) {
			foreach ($typeNode->types as $type) {
				if ($type instanceof FullyQualified) {
					$this->annotateNode($type, $attribute, $attrNames);
				}
			}
		}
	}


	/**
	 * @param list<string> $attrNames
	 */
	private function annotateNode(Node $node, string $positionAttribute, array $attrNames): void
	{
		$node->setAttribute($positionAttribute, true);
		$node->setAttribute(self::ATTRIBUTE_ENCLOSING_FUNCTION_ATTR_NAMES, $attrNames);
	}


	/**
	 * @param array<AttributeGroup> $attrGroups
	 * @return list<string>
	 */
	private function extractAttrNames(array $attrGroups): array
	{
		$names = [];
		foreach ($attrGroups as $attrGroup) {
			foreach ($attrGroup->attrs as $attr) {
				$names[] = $attr->name->toString();
			}
		}
		return $names;
	}

}
