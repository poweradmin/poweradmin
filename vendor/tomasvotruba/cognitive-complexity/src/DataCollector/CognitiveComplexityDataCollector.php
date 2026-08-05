<?php

declare(strict_types=1);

namespace TomasVotruba\CognitiveComplexity\DataCollector;

final class CognitiveComplexityDataCollector
{
    private int $operationComplexity = 0;

    private int $nestingComplexity = 0;

    public function increaseOperation(): void
    {
        ++$this->operationComplexity;
    }

    public function increaseNesting(int $steps): void
    {
        $this->nestingComplexity += $steps;
    }

    public function getCognitiveComplexity(): int
    {
        return $this->nestingComplexity + $this->operationComplexity;
    }

    public function reset(): void
    {
        $this->operationComplexity = 0;
        $this->nestingComplexity = 0;
    }
}
