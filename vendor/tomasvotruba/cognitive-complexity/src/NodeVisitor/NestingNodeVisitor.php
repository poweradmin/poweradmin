<?php

declare(strict_types=1);

namespace TomasVotruba\CognitiveComplexity\NodeVisitor;

use PhpParser\Node;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\Ternary;
use PhpParser\Node\Stmt\Catch_;
use PhpParser\Node\Stmt\Do_;
use PhpParser\Node\Stmt\For_;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\If_;
use PhpParser\Node\Stmt\While_;
use PhpParser\NodeVisitorAbstract;
use TomasVotruba\CognitiveComplexity\DataCollector\CognitiveComplexityDataCollector;
use TomasVotruba\CognitiveComplexity\NodeAnalyzer\ComplexityAffectingNodeFinder;

final class NestingNodeVisitor extends NodeVisitorAbstract
{
    /**
     * @var array<class-string<Node>>
     */
    private const NESTING_NODE_TYPES = [
        If_::class,
        For_::class,
        While_::class,
        Catch_::class,
        Closure::class,
        Foreach_::class,
        Do_::class,
        Ternary::class,
    ];

    /**
     * @readonly
     */
    private CognitiveComplexityDataCollector $cognitiveComplexityDataCollector;

    /**
     * @readonly
     */
    private ComplexityAffectingNodeFinder $complexityAffectingNodeFinder;

    private int $measuredNestingLevel = 1;

    private int $previousNestingLevel = 0;

    public function __construct(
        CognitiveComplexityDataCollector $cognitiveComplexityDataCollector,
        ComplexityAffectingNodeFinder $complexityAffectingNodeFinder
    ) {
        $this->cognitiveComplexityDataCollector = $cognitiveComplexityDataCollector;
        $this->complexityAffectingNodeFinder = $complexityAffectingNodeFinder;
    }

    public function reset(): void
    {
        $this->measuredNestingLevel = 1;
    }

    /**
     * @param Node|int $node On PHP 8.5 with php-parser v5, BackedEnumCase values may be passed as int
     */
    public function enterNode($node): ?Node
    {
        if (! $node instanceof Node) {
            return null;
        }

        if ($this->isNestingNode($node)) {
            ++$this->measuredNestingLevel;
        }

        if (! $this->complexityAffectingNodeFinder->isIncrementingNode($node)) {
            return null;
        }

        if ($this->complexityAffectingNodeFinder->isBreakingNode($node)) {
            $this->previousNestingLevel = $this->measuredNestingLevel;
            return null;
        }

        // B2. Nesting level
        if ($this->measuredNestingLevel > 1 && $this->previousNestingLevel < $this->measuredNestingLevel) {
            // only going deeper, not on the same level
            $nestingComplexity = $this->measuredNestingLevel - 2;
            $this->cognitiveComplexityDataCollector->increaseNesting($nestingComplexity);
        }

        $this->previousNestingLevel = $this->measuredNestingLevel;

        return null;
    }

    /**
     * @param Node|int $node On PHP 8.5 with php-parser v5, BackedEnumCase values may be passed as int
     */
    public function leaveNode($node): ?Node
    {
        if (! $node instanceof Node) {
            return null;
        }

        if ($this->isNestingNode($node)) {
            --$this->measuredNestingLevel;
        }

        return null;
    }

    private function isNestingNode(Node $node): bool
    {
        foreach (self::NESTING_NODE_TYPES as $nestingNodeType) {
            if ($node instanceof $nestingNodeType) {
                return true;
            }
        }

        return false;
    }
}
