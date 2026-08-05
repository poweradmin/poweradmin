<?php
declare(strict_types = 1);

namespace Spaze\PHPStan\Rules\Disallowed\RuleErrors;

use PhpParser\Node;
use PhpParser\Node\Attribute;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\RuleErrorBuilder;
use Spaze\PHPStan\Rules\Disallowed\Allowed\Allowed;
use Spaze\PHPStan\Rules\Disallowed\DisallowedAttribute;
use Spaze\PHPStan\Rules\Disallowed\Formatter\Formatter;
use Spaze\PHPStan\Rules\Disallowed\Identifier\Identifier;

class DisallowedAttributeRuleErrors
{

	private Allowed $allowed;

	private Identifier $identifier;

	private Formatter $formatter;

	private ErrorTips $errorTips;


	public function __construct(Allowed $allowed, Identifier $identifier, Formatter $formatter, ErrorTips $errorTips)
	{
		$this->allowed = $allowed;
		$this->identifier = $identifier;
		$this->formatter = $formatter;
		$this->errorTips = $errorTips;
	}


	/**
	 * @param Node $node
	 * @param Scope $scope
	 * @param list<DisallowedAttribute> $disallowedAttributes
	 * @return list<IdentifierRuleError>
	 */
	public function get(Node $node, Attribute $attribute, Scope $scope, array $disallowedAttributes): array
	{
		foreach ($disallowedAttributes as $disallowedAttribute) {
			$attributeName = $attribute->name->toString();
			if (!$this->identifier->matches($disallowedAttribute->getAttribute(), $attributeName, $disallowedAttribute->getExcludes())) {
				continue;
			}
			if ($this->allowed->isAllowed($node, $scope, $attribute->args, $disallowedAttribute)) {
				continue;
			}

			$errorBuilder = RuleErrorBuilder::message(sprintf(
				'Attribute %s is forbidden%s%s',
				$attributeName,
				$this->formatter->formatDisallowedMessage($disallowedAttribute->getMessage()),
				$disallowedAttribute->getAttribute() !== $attributeName ? " [{$attributeName} matches {$disallowedAttribute->getAttribute()}]" : ''
			));
			$errorBuilder->identifier($disallowedAttribute->getErrorIdentifier() ?? ErrorIdentifiers::DISALLOWED_ATTRIBUTE);
			$this->errorTips->add($disallowedAttribute->getErrorTip(), $errorBuilder);
			return [
				$errorBuilder->build(),
			];
		}

		return [];
	}

}
