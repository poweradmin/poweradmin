<?php
declare(strict_types = 1);

namespace Spaze\PHPStan\Rules\Disallowed\ControlStructures;

use PhpParser\Node;
use PhpParser\Node\Expr\Include_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleError;
use PHPStan\ShouldNotHappenException;
use Spaze\PHPStan\Rules\Disallowed\DisallowedKeyword;
use Spaze\PHPStan\Rules\Disallowed\RuleErrors\DisallowedKeywordRuleErrors;
use Spaze\PHPStan\Rules\Disallowed\RuleErrors\ErrorIdentifiers;

/**
 * Reports on using the foreach loop.
 *
 * @package Spaze\PHPStan\Rules\Disallowed
 * @implements Rule<Include_>
 */
class RequireIncludeControlStructure implements Rule
{

	private DisallowedKeywordRuleErrors $disallowedKeywordRuleErrors;

	/** @var list<DisallowedKeyword> */
	private array $disallowedKeywords;


	/**
	 * @param DisallowedKeywordRuleErrors $disallowedKeywordRuleErrors
	 * @param list<DisallowedKeyword> $disallowedKeywords
	 */
	public function __construct(DisallowedKeywordRuleErrors $disallowedKeywordRuleErrors, array $disallowedKeywords)
	{
		$this->disallowedKeywordRuleErrors = $disallowedKeywordRuleErrors;
		$this->disallowedKeywords = $disallowedKeywords;
	}


	public function getNodeType(): string
	{
		return Include_::class;
	}


	/**
	 * @param Include_ $node
	 * @param Scope $scope
	 * @return list<RuleError>
	 * @throws ShouldNotHappenException
	 */
	public function processNode(Node $node, Scope $scope): array
	{
		$type = $identifier = null;
		switch ($node->type) {
			case Include_::TYPE_INCLUDE:
				$type = 'include';
				$identifier = ErrorIdentifiers::DISALLOWED_INCLUDE;
				break;
			case Include_::TYPE_REQUIRE:
				$type = 'require';
				$identifier = ErrorIdentifiers::DISALLOWED_REQUIRE;
				break;
			case Include_::TYPE_INCLUDE_ONCE:
				$type = 'include_once';
				$identifier = ErrorIdentifiers::DISALLOWED_INCLUDE_ONCE;
				break;
			case Include_::TYPE_REQUIRE_ONCE:
				$type = 'require_once';
				$identifier = ErrorIdentifiers::DISALLOWED_REQUIRE_ONCE;
				break;
			default:
				return [];
		}
		return $this->disallowedKeywordRuleErrors->get($node, $scope, $type, $this->disallowedKeywords, $identifier);
	}

}
