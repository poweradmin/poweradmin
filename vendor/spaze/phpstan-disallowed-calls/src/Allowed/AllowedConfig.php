<?php
declare(strict_types = 1);

namespace Spaze\PHPStan\Rules\Disallowed\Allowed;

use PHPStan\ShouldNotHappenException;
use Spaze\PHPStan\Rules\Disallowed\Allowed\UsagePosition;
use Spaze\PHPStan\Rules\Disallowed\Params\Param;

class AllowedConfig
{

	/** @var list<string> */
	private array $allowIn;

	/** @var list<string> */
	private array $allowExceptIn;

	/** @var list<string> */
	private array $allowInCalls;

	/** @var list<string> */
	private array $allowExceptInCalls;

	/** @var list<string> */
	private array $allowInInstanceOf;

	/** @var list<string> */
	private array $allowExceptInInstanceOf;

	/** @var list<string> */
	private array $allowInClassWithAttributes;

	/** @var list<string> */
	private array $allowExceptInClassWithAttributes;

	/** @var list<string> */
	private array $allowInCallsWithAttributes;

	/** @var list<string> */
	private array $allowExceptInCallsWithAttributes;

	/** @var list<string> */
	private array $allowInClassWithMethodAttributes;

	/** @var list<string> */
	private array $allowExceptInClassWithMethodAttributes;

	/** @var array<int|string, Param> */
	private array $allowParamsInAllowed;

	/** @var array<int|string, Param> */
	private array $allowParamsAnywhere;

	/** @var array<int|string, Param> */
	private array $allowExceptParamsInAllowed;

	/** @var array<int|string, Param> */
	private array $allowExceptParams;

	private bool $allowInParamTypes;

	private bool $allowExceptInParamTypes;

	private bool $allowInReturnType;

	private bool $allowExceptInReturnType;


	/**
	 * @param list<string> $allowIn
	 * @param list<string> $allowExceptIn
	 * @param list<string> $allowInCalls
	 * @param list<string> $allowExceptInCalls
	 * @param list<string> $allowInInstanceOf
	 * @param list<string> $allowExceptInInstanceOf
	 * @param list<string> $allowInClassWithAttributes
	 * @param list<string> $allowExceptInClassWithAttributes
	 * @param list<string> $allowInCallsWithAttributes
	 * @param list<string> $allowExceptInCallsWithAttributes
	 * @param list<string> $allowInClassWithMethodAttributes
	 * @param list<string> $allowExceptInClassWithMethodAttributes
	 * @param array<int|string, Param> $allowParamsInAllowed
	 * @param array<int|string, Param> $allowParamsAnywhere
	 * @param array<int|string, Param> $allowExceptParamsInAllowed
	 * @param array<int|string, Param> $allowExceptParams
	 */
	public function __construct(
		array $allowIn,
		array $allowExceptIn,
		array $allowInCalls,
		array $allowExceptInCalls,
		array $allowInInstanceOf,
		array $allowExceptInInstanceOf,
		array $allowInClassWithAttributes,
		array $allowExceptInClassWithAttributes,
		array $allowInCallsWithAttributes,
		array $allowExceptInCallsWithAttributes,
		array $allowInClassWithMethodAttributes,
		array $allowExceptInClassWithMethodAttributes,
		array $allowParamsInAllowed,
		array $allowParamsAnywhere,
		array $allowExceptParamsInAllowed,
		array $allowExceptParams,
		bool $allowInParamTypes = false,
		bool $allowExceptInParamTypes = false,
		bool $allowInReturnType = false,
		bool $allowExceptInReturnType = false
	) {
		$this->allowIn = $allowIn;
		$this->allowExceptIn = $allowExceptIn;
		$this->allowInCalls = $allowInCalls;
		$this->allowExceptInCalls = $allowExceptInCalls;
		$this->allowInInstanceOf = $allowInInstanceOf;
		$this->allowExceptInInstanceOf = $allowExceptInInstanceOf;
		$this->allowInClassWithAttributes = $allowInClassWithAttributes;
		$this->allowExceptInClassWithAttributes = $allowExceptInClassWithAttributes;
		$this->allowInCallsWithAttributes = $allowInCallsWithAttributes;
		$this->allowExceptInCallsWithAttributes = $allowExceptInCallsWithAttributes;
		$this->allowInClassWithMethodAttributes = $allowInClassWithMethodAttributes;
		$this->allowExceptInClassWithMethodAttributes = $allowExceptInClassWithMethodAttributes;
		$this->allowParamsInAllowed = $allowParamsInAllowed;
		$this->allowParamsAnywhere = $allowParamsAnywhere;
		$this->allowExceptParamsInAllowed = $allowExceptParamsInAllowed;
		$this->allowExceptParams = $allowExceptParams;
		$this->allowInParamTypes = $allowInParamTypes;
		$this->allowExceptInParamTypes = $allowExceptInParamTypes;
		$this->allowInReturnType = $allowInReturnType;
		$this->allowExceptInReturnType = $allowExceptInReturnType;
	}


	/**
	 * @return list<string>
	 */
	public function getAllowIn(): array
	{
		return $this->allowIn;
	}


	/**
	 * @return list<string>
	 */
	public function getAllowExceptIn(): array
	{
		return $this->allowExceptIn;
	}


	/**
	 * @return list<string>
	 */
	public function getAllowInCalls(): array
	{
		return $this->allowInCalls;
	}


	/**
	 * @return list<string>
	 */
	public function getAllowExceptInCalls(): array
	{
		return $this->allowExceptInCalls;
	}


	/**
	 * @return list<string>
	 */
	public function getAllowInInstanceOf(): array
	{
		return $this->allowInInstanceOf;
	}


	/**
	 * @return list<string>
	 */
	public function getAllowExceptInInstanceOf(): array
	{
		return $this->allowExceptInInstanceOf;
	}


	/**
	 * @return list<string>
	 */
	public function getAllowInClassWithAttributes(): array
	{
		return $this->allowInClassWithAttributes;
	}


	/**
	 * @return list<string>
	 */
	public function getAllowExceptInClassWithAttributes(): array
	{
		return $this->allowExceptInClassWithAttributes;
	}


	/**
	 * @return list<string>
	 */
	public function getAllowInCallsWithAttributes(): array
	{
		return $this->allowInCallsWithAttributes;
	}


	/**
	 * @return list<string>
	 */
	public function getAllowExceptInCallsWithAttributes(): array
	{
		return $this->allowExceptInCallsWithAttributes;
	}


	/**
	 * @return list<string>
	 */
	public function getAllowInClassWithMethodAttributes(): array
	{
		return $this->allowInClassWithMethodAttributes;
	}


	/**
	 * @return list<string>
	 */
	public function getAllowExceptInClassWithMethodAttributes(): array
	{
		return $this->allowExceptInClassWithMethodAttributes;
	}


	/**
	 * @return array<int|string, Param>
	 */
	public function getAllowParamsInAllowed(): array
	{
		return $this->allowParamsInAllowed;
	}


	/**
	 * @return array<int|string, Param>
	 */
	public function getAllowParamsAnywhere(): array
	{
		return $this->allowParamsAnywhere;
	}


	/**
	 * @return array<int|string, Param>
	 */
	public function getAllowExceptParamsInAllowed(): array
	{
		return $this->allowExceptParamsInAllowed;
	}


	/**
	 * @return array<int|string, Param>
	 */
	public function getAllowExceptParams(): array
	{
		return $this->allowExceptParams;
	}


	public function getAllowInPosition(int $position): bool
	{
		switch ($position) {
			case UsagePosition::PARAM_TYPE:
				return $this->allowInParamTypes;
			case UsagePosition::RETURN_TYPE:
				return $this->allowInReturnType;
		}
		throw new ShouldNotHappenException("Position {$position} is not supported");
	}


	public function getAllowExceptInPosition(int $position): bool
	{
		switch ($position) {
			case UsagePosition::PARAM_TYPE:
				return $this->allowExceptInParamTypes;
			case UsagePosition::RETURN_TYPE:
				return $this->allowExceptInReturnType;
		}
		throw new ShouldNotHappenException("Position {$position} is not supported");
	}

}
