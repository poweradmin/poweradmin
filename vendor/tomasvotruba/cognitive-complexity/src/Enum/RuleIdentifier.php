<?php

declare(strict_types=1);

namespace TomasVotruba\CognitiveComplexity\Enum;

final class RuleIdentifier
{
    /**
     * @var string
     */
    public const DEPENDENCY_TREE = 'complexity.dependencyTree';

    /**
     * @var string
     */
    public const FUNCTION_COMPLEXITY = 'complexity.functionLike';

    /**
     * @var string
     */
    public const CLASS_LIKE_COMPLEXITY = 'complexity.classLike';
}
