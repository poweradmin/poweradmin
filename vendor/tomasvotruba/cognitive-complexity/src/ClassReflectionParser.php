<?php

declare(strict_types=1);

namespace TomasVotruba\CognitiveComplexity;

use PhpParser\Node\Stmt\Class_;
use PhpParser\NodeFinder;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use PHPStan\Reflection\ClassReflection;

final class ClassReflectionParser
{
    /**
     * @readonly
     */
    private Parser $phpParser;

    /**
     * @readonly
     */
    private NodeFinder $nodeFinder;

    public function __construct()
    {
        $parserFactory = new ParserFactory();
        $this->phpParser = $parserFactory->createForHostVersion();

        $this->nodeFinder = new NodeFinder();
    }

    public function parse(ClassReflection $classReflection): ?Class_
    {
        $fileName = $classReflection->getFileName();
        if (! is_string($fileName)) {
            return null;
        }

        /** @var string $fileContents */
        $fileContents = file_get_contents($fileName);

        $stmts = $this->phpParser->parse($fileContents);
        if ($stmts === null) {
            return null;
        }

        return $this->nodeFinder->findFirstInstanceOf($stmts, Class_::class);
    }
}
