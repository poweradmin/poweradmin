<?php
declare(strict_types = 1);

namespace Spaze\PHPStan\Rules\Disallowed\Calls;

use PhpParser\Node;
use PhpParser\Node\Expr\ShellExec;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\ShouldNotHappenException;
use Spaze\PHPStan\Rules\Disallowed\DisallowedCall;
use Spaze\PHPStan\Rules\Disallowed\DisallowedCallFactory;
use Spaze\PHPStan\Rules\Disallowed\RuleErrors\DisallowedCallsRuleErrors;
use Spaze\PHPStan\Rules\Disallowed\RuleErrors\ErrorIdentifiers;

/**
 * Reports on dynamically using the execution backtick operator (<code>`ls`</code>).
 *
 * This class is in the Calls namespace because according to the docs,
 * "Use of the backtick operator is identical to shell_exec()"
 * https://www.php.net/operators.execution
 *
 * @package Spaze\PHPStan\Rules\Disallowed
 * @implements Rule<ShellExec>
 */
class ShellExecCalls implements Rule
{

	private DisallowedCallsRuleErrors $disallowedCallsRuleErrors;

	/** @var list<DisallowedCall> */
	private array $disallowedCalls;


	/**
	 * @param DisallowedCallsRuleErrors $disallowedCallsRuleErrors
	 * @param DisallowedCallFactory $disallowedCallFactory
	 * @param array $forbiddenCalls
	 * @phpstan-param ForbiddenCallsConfig $forbiddenCalls
	 * @noinspection PhpUndefinedClassInspection ForbiddenCallsConfig is a type alias defined in PHPStan config
	 * @throws ShouldNotHappenException
	 */
	public function __construct(DisallowedCallsRuleErrors $disallowedCallsRuleErrors, DisallowedCallFactory $disallowedCallFactory, array $forbiddenCalls)
	{
		$this->disallowedCallsRuleErrors = $disallowedCallsRuleErrors;
		$this->disallowedCalls = $disallowedCallFactory->createFromConfig($forbiddenCalls);
	}


	public function getNodeType(): string
	{
		return ShellExec::class;
	}


	/**
	 * @throws ShouldNotHappenException
	 */
	public function processNode(Node $node, Scope $scope): array
	{
		return $this->disallowedCallsRuleErrors->get(
			null,
			$scope,
			'shell_exec',
			null,
			null,
			true,
			$this->disallowedCalls,
			ErrorIdentifiers::DISALLOWED_BACKTICK,
			'Using the backtick operator (`...`) is forbidden because shell_exec() is forbidden%2$s%3$s',
		);
	}

}
