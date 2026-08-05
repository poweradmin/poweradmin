<?php
declare(strict_types = 1);

namespace Spaze\PHPStan\Rules\Disallowed\RuleErrors;

use PhpParser\Node\Expr\CallLike;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\ShouldNotHappenException;
use Spaze\PHPStan\Rules\Disallowed\Allowed\Allowed;
use Spaze\PHPStan\Rules\Disallowed\DisallowedCall;
use Spaze\PHPStan\Rules\Disallowed\File\FilePath;
use Spaze\PHPStan\Rules\Disallowed\Formatter\Formatter;
use Spaze\PHPStan\Rules\Disallowed\Identifier\Identifier;

class DisallowedCallsRuleErrors
{

	private Allowed $allowed;

	private Identifier $identifier;

	private FilePath $filePath;

	private Formatter $formatter;

	private ErrorTips $errorTips;


	public function __construct(Allowed $allowed, Identifier $identifier, FilePath $filePath, Formatter $formatter, ErrorTips $errorTips)
	{
		$this->allowed = $allowed;
		$this->identifier = $identifier;
		$this->filePath = $filePath;
		$this->formatter = $formatter;
		$this->errorTips = $errorTips;
	}


	/**
	 * @param CallLike|null $node
	 * @param Scope $scope
	 * @param string $name
	 * @param string|null $displayName
	 * @param string|null $definedIn
	 * @param bool $isBuiltIn
	 * @param list<DisallowedCall> $disallowedCalls
	 * @param string $identifier
	 * @param string|null $message
	 * @return list<IdentifierRuleError>
	 * @throws ShouldNotHappenException
	 */
	public function get(?CallLike $node, Scope $scope, string $name, ?string $displayName, ?string $definedIn, bool $isBuiltIn, array $disallowedCalls, string $identifier, ?string $message = null): array
	{
		$args = isset($node) && !$node->isFirstClassCallable() ? $node->getArgs() : null;
		foreach ($disallowedCalls as $disallowedCall) {
			if (
				$this->identifier->matches($disallowedCall->getCall(), $name, $disallowedCall->getExcludes())
				&& $this->definedInMatches($disallowedCall, $definedIn, $isBuiltIn)
				&& !$this->allowed->isAllowed($node, $scope, $args, $disallowedCall)
			) {
				$errorBuilder = RuleErrorBuilder::message(sprintf(
					$message ?? 'Calling %s is forbidden%s%s',
					($displayName && $displayName !== $name) ? "{$name}() (as {$displayName}())" : "{$name}()",
					$this->formatter->formatDisallowedMessage($disallowedCall->getMessage()),
					$disallowedCall->getCall() !== $name ? " [{$name}() matches {$disallowedCall->getCall()}()]" : ''
				));
				$errorBuilder->identifier($disallowedCall->getErrorIdentifier() ?? $identifier);
				$this->errorTips->add($disallowedCall->getErrorTip(), $errorBuilder);
				return [
					$errorBuilder->build(),
				];
			}
		}
		return [];
	}


	private function definedInMatches(DisallowedCall $disallowedCall, ?string $definedIn, bool $isBuiltIn): bool
	{
		if (!$disallowedCall->getDefinedIn()) {
			return true;
		} elseif ($isBuiltIn) {
			return false;
		} elseif ($definedIn === null) {
			return true;
		}
		foreach ($disallowedCall->getDefinedIn() as $callDefinedIn) {
			if ($this->filePath->fnMatch($callDefinedIn, $definedIn)) {
				return true;
			}
		}
		return false;
	}

}
