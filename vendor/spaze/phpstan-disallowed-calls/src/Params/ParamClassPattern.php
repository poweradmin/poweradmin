<?php
declare(strict_types = 1);

namespace Spaze\PHPStan\Rules\Disallowed\Params;

use PHPStan\Type\Type;

class ParamClassPattern implements Param
{

	private ?int $position;

	private ?string $name;

	private string $pattern;


	/**
	 * @param int|null $position
	 * @param string|null $name
	 * @param string $pattern
	 */
	public function __construct(?int $position, ?string $name, string $pattern)
	{
		$this->position = $position;
		$this->name = $name;
		$this->pattern = $pattern;
	}


	public function matches(Type $type): bool
	{
		foreach ($type->getObjectClassNames() as $className) {
			if (fnmatch($this->pattern, $className, FNM_NOESCAPE | FNM_CASEFOLD)) {
				return true;
			}
		}
		return false;
	}


	public function getPosition(): ?int
	{
		return $this->position;
	}


	public function getName(): ?string
	{
		return $this->name;
	}

}
