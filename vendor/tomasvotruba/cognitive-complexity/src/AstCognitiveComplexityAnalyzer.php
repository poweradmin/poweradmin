<?php

declare(strict_types=1);

namespace TomasVotruba\CognitiveComplexity;

use PhpParser\Node\Stmt\Class_;
use TomasVotruba\CognitiveComplexity\DataCollector\CognitiveComplexityDataCollector;
use TomasVotruba\CognitiveComplexity\NodeTraverser\ComplexityNodeTraverserFactory;
use TomasVotruba\CognitiveComplexity\NodeVisitor\NestingNodeVisitor;

/**
 * @see \TomasVotruba\CognitiveComplexity\Tests\AstCognitiveComplexityAnalyzer\AstCognitiveComplexityAnalyzerTest
 *
 * implements the concept described in https://www.sonarsource.com/resources/white-papers/cognitive-complexity/
 */
final class AstCognitiveComplexityAnalyzer
{
    /**
     * @readonly
     */
    private ComplexityNodeTraverserFactory $complexityNodeTraverserFactory;

    /**
     * @readonly
     */
    private CognitiveComplexityDataCollector $cognitiveComplexityDataCollector;

    /**
     * @readonly
     */
    private NestingNodeVisitor $nestingNodeVisitor;

    public function __construct(
        ComplexityNodeTraverserFactory $complexityNodeTraverserFactory,
        CognitiveComplexityDataCollector $cognitiveComplexityDataCollector,
        NestingNodeVisitor $nestingNodeVisitor
    ) {
        $this->complexityNodeTraverserFactory = $complexityNodeTraverserFactory;
        $this->cognitiveComplexityDataCollector = $cognitiveComplexityDataCollector;
        $this->nestingNodeVisitor = $nestingNodeVisitor;
    }

    public function analyzeClassLike(Class_ $class): int
    {
        $totalCognitiveComplexity = 0;
        foreach ($class->getMethods() as $classMethod) {
            $totalCognitiveComplexity += $this->analyzeFunctionLike($classMethod);
        }

        return $totalCognitiveComplexity;
    }

    /**
     * @api
     * @param \PhpParser\Node\Stmt\Function_|\PhpParser\Node\Stmt\ClassMethod $functionLike
     */
    public function analyzeFunctionLike($functionLike): int
    {
        $this->cognitiveComplexityDataCollector->reset();
        $this->nestingNodeVisitor->reset();

        $nodeTraverser = $this->complexityNodeTraverserFactory->create();
        $nodeTraverser->traverse([$functionLike]);

        return $this->cognitiveComplexityDataCollector->getCognitiveComplexity();
    }
}
