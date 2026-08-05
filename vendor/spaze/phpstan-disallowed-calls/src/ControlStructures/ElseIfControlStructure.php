<?php
declare(strict_types = 1);

namespace Spaze\PHPStan\Rules\Disallowed\ControlStructures;

use PhpParser\Node;
use PhpParser\Node\Stmt\ElseIf_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleError;
use PHPStan\ShouldNotHappenException;
use Spaze\PHPStan\Rules\Disallowed\DisallowedKeyword;
use Spaze\PHPStan\Rules\Disallowed\RuleErrors\DisallowedKeywordRuleErrors;
use Spaze\PHPStan\Rules\Disallowed\RuleErrors\ErrorIdentifiers;

/**
 * Reports on using the elseif control structure.
 * But not the "else if" as that's parsed as "else" followed by "if".
 *
 * @package Spaze\PHPStan\Rules\Disallowed
 * @implements Rule<ElseIf_>
 */
class ElseIfControlStructure implements Rule
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
		return ElseIf_::class;
	}


	/**
	 * @param ElseIf_ $node
	 * @param Scope $scope
	 * @return list<RuleError>
	 * @throws ShouldNotHappenException
	 */
	public function processNode(Node $node, Scope $scope): array
	{
		return $this->disallowedKeywordRuleErrors->get($node, $scope, 'elseif', $this->disallowedKeywords, ErrorIdentifiers::DISALLOWED_ELSE_IF);
	}

}
