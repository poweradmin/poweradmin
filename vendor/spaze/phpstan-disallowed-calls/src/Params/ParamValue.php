<?php
declare(strict_types = 1);

namespace Spaze\PHPStan\Rules\Disallowed\Params;

use PHPStan\Type\Type;

abstract class ParamValue implements Param
{

	private ?int $position;

	private ?string $name;

	private Type $type;


	abstract public function matches(Type $type): bool;


	/**
	 * @param int|null $position
	 * @param string|null $name
	 * @param Type $type
	 */
	final public function __construct(?int $position, ?string $name, Type $type)
	{
		$this->position = $position;
		$this->name = $name;
		$this->type = $type;
	}


	public function getPosition(): ?int
	{
		return $this->position;
	}


	public function getName(): ?string
	{
		return $this->name;
	}


	public function getType(): Type
	{
		return $this->type;
	}

}
