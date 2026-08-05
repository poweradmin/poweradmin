<?php
declare(strict_types = 1);

namespace Spaze\PHPStan\Rules\Disallowed\RuleErrors;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\RuleError;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\ShouldNotHappenException;
use Spaze\PHPStan\Rules\Disallowed\Allowed\Allowed;
use Spaze\PHPStan\Rules\Disallowed\DisallowedKeyword;
use Spaze\PHPStan\Rules\Disallowed\Formatter\Formatter;

class DisallowedKeywordRuleErrors
{

	private Allowed $allowed;

	private Formatter $formatter;

	private ErrorTips $errorTips;


	public function __construct(Allowed $allowed, Formatter $formatter, ErrorTips $errorTips)
	{
		$this->allowed = $allowed;
		$this->formatter = $formatter;
		$this->errorTips = $errorTips;
	}


	/**
	 * @param Node $node
	 * @param Scope $scope
	 * @param string $keyword
	 * @param list<DisallowedKeyword> $disallowedKeywords
	 * @param string $identifier
	 * @return list<RuleError>
	 * @throws ShouldNotHappenException
	 */
	public function get(Node $node, Scope $scope, string $keyword, array $disallowedKeywords, string $identifier): array
	{
		foreach ($disallowedKeywords as $disallowedKeyword) {
			if (
				$disallowedKeyword->getKeyword() === $keyword
				&& !$this->allowed->isAllowed($node, $scope, null, $disallowedKeyword)
			) {
				$errorBuilder = RuleErrorBuilder::message(sprintf(
					'Using the %s %s is forbidden%s',
					$keyword,
					$disallowedKeyword->getKeywordDescription(),
					$this->formatter->formatDisallowedMessage($disallowedKeyword->getMessage())
				));
				$errorBuilder->identifier($disallowedKeyword->getErrorIdentifier() ?? $identifier);
				$this->errorTips->add($disallowedKeyword->getErrorTip(), $errorBuilder);
				return [
					$errorBuilder->build(),
				];
			}
		}
		return [];
	}

}
