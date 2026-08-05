<?php

declare(strict_types=1);

namespace TomasVotruba\CognitiveComplexity\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use TomasVotruba\CognitiveComplexity\AstCognitiveComplexityAnalyzer;
use TomasVotruba\CognitiveComplexity\Configuration;
use TomasVotruba\CognitiveComplexity\Enum\RuleIdentifier;
use TomasVotruba\CognitiveComplexity\Exception\ShouldNotHappenException;

/**
 * Based on https://www.sonarsource.com/docs/CognitiveComplexity.pdf
 *
 * A Cognitive Complexity score has 3 rules:
 * - B1. Ignore structures that allow multiple statements to be readably shorthanded into one
 * - B2. Increment (add one) for each break in the linear flow of the code
 * - B3. Increment when flow-breaking structures are nested
 *
 * @see https://www.tomasvotruba.com/blog/2018/05/21/is-your-code-readable-by-humans-cognitive-complexity-tells-you/
 *
 * @see \TomasVotruba\CognitiveComplexity\Tests\Rules\FunctionLikeCognitiveComplexityRule\FunctionLikeCognitiveComplexityRuleTest
 */
final class FunctionLikeCognitiveComplexityRule implements Rule
{
    /**
     * @var string
     */
    public const ERROR_MESSAGE = 'Cognitive complexity for "%s" is %d, keep it under %d';

    /**
     * @readonly
     */
    private AstCognitiveComplexityAnalyzer $astCognitiveComplexityAnalyzer;

    /**
     * @readonly
     */
    private Configuration $configuration;

    public function __construct(
        AstCognitiveComplexityAnalyzer $astCognitiveComplexityAnalyzer,
        Configuration $configuration
    ) {
        $this->astCognitiveComplexityAnalyzer = $astCognitiveComplexityAnalyzer;
        $this->configuration = $configuration;
    }

    /**
     * @return class-string<Node>
     */
    public function getNodeType(): string
    {
        return FunctionLike::class;
    }

    /**
     * @param FunctionLike $node
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (! $node instanceof ClassMethod && ! $node instanceof Function_) {
            return [];
        }

        $functionLikeCognitiveComplexity = $this->astCognitiveComplexityAnalyzer->analyzeFunctionLike($node);
        if ($functionLikeCognitiveComplexity <= $this->configuration->getMaxFunctionCognitiveComplexity()) {
            return [];
        }

        $functionLikeName = $this->resolveFunctionName($node, $scope);

        $message = sprintf(
            self::ERROR_MESSAGE,
            $functionLikeName,
            $functionLikeCognitiveComplexity,
            $this->configuration->getMaxFunctionCognitiveComplexity()
        );

        return [RuleErrorBuilder::message($message)->identifier(RuleIdentifier::FUNCTION_COMPLEXITY)->build()];
    }

    private function resolveFunctionName(FunctionLike $functionLike, Scope $scope): string
    {
        if ($functionLike instanceof Function_) {
            return $functionLike->name . '()';
        }

        if ($functionLike instanceof ClassMethod) {
            $name = '';

            $classReflection = $scope->getClassReflection();
            if ($classReflection instanceof ClassReflection) {
                $name = $classReflection->getName() . '::';
            }

            return $name . $functionLike->name . '()';
        }

        if ($functionLike instanceof Closure) {
            return 'closure';
        }

        if ($functionLike instanceof ArrowFunction) {
            return 'arrow function';
        }

        throw new ShouldNotHappenException();
    }
}
