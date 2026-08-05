<?php
declare(strict_types = 1);

namespace Spaze\PHPStan\Rules\Disallowed\Formatter;

use PHPStan\Reflection\MethodReflection;
use Spaze\PHPStan\Rules\Disallowed\Normalizer\Normalizer;

class Formatter
{

	private Normalizer $normalizer;


	public function __construct(Normalizer $normalizer)
	{
		$this->normalizer = $normalizer;
	}


	public function getFullyQualified(string $class, MethodReflection $method): string
	{
		return sprintf('%s::%s', $class, $method->getName());
	}


	/**
	 * @param non-empty-list<string> $identifiers
	 * @return string
	 */
	public function formatIdentifier(array $identifiers): string
	{
		if (count($identifiers) === 1) {
			return $this->normalizer->normalizeNamespace($identifiers[0]);
		} else {
			array_walk($identifiers, function (string &$identifier): void {
				$identifier = $this->normalizer->normalizeNamespace($identifier);
			});
			return '{' . implode(',', $identifiers) . '}';
		}
	}


	public function formatDisallowedMessage(?string $message): string
	{
		if (!$message) {
			return '.';
		}
		if ($message[-1] !== '?' && $message[-1] !== '!') {
			$message = rtrim($message, '.') . '.';
		}
		return ', ' . $message;
	}

}
