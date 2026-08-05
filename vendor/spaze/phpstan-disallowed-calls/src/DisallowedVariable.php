<?php
declare(strict_types = 1);

namespace Spaze\PHPStan\Rules\Disallowed;

use Spaze\PHPStan\Rules\Disallowed\Allowed\AllowedConfig;

class DisallowedVariable implements Disallowed
{

	private string $variable;

	private ?string $message;

	private AllowedConfig $allowedConfig;

	private ?string $errorIdentifier;

	/** @var list<string> */
	private array $errorTip;


	/**
	 * @param string $variable
	 * @param string|null $message
	 * @param AllowedConfig $allowedConfig
	 * @param string|null $errorIdentifier
	 * @param string|list<string> $errorTip
	 */
	public function __construct(
		string $variable,
		?string $message,
		AllowedConfig $allowedConfig,
		?string $errorIdentifier,
		$errorTip
	) {
		$this->variable = $variable;
		$this->message = $message;
		$this->allowedConfig = $allowedConfig;
		$this->errorIdentifier = $errorIdentifier;
		$this->errorTip = (array)$errorTip;
	}


	public function getVariable(): string
	{
		return $this->variable;
	}


	public function getMessage(): ?string
	{
		return $this->message;
	}


	/** @inheritDoc */
	public function getAllowIn(): array
	{
		return $this->allowedConfig->getAllowIn();
	}


	/** @inheritDoc */
	public function getAllowExceptIn(): array
	{
		return $this->allowedConfig->getAllowExceptIn();
	}


	public function getAllowInCalls(): array
	{
		return $this->allowedConfig->getAllowInCalls();
	}


	public function getAllowInInstanceOf(): array
	{
		return $this->allowedConfig->getAllowInInstanceOf();
	}


	public function getAllowExceptInInstanceOf(): array
	{
		return $this->allowedConfig->getAllowExceptInInstanceOf();
	}


	public function getAllowExceptInCalls(): array
	{
		return $this->allowedConfig->getAllowExceptInCalls();
	}


	public function getAllowInClassWithAttributes(): array
	{
		return $this->allowedConfig->getAllowInClassWithAttributes();
	}


	public function getAllowExceptInClassWithAttributes(): array
	{
		return $this->allowedConfig->getAllowExceptInClassWithAttributes();
	}


	public function getAllowInCallsWithAttributes(): array
	{
		return $this->allowedConfig->getAllowInCallsWithAttributes();
	}


	public function getAllowExceptInCallsWithAttributes(): array
	{
		return $this->allowedConfig->getAllowExceptInCallsWithAttributes();
	}


	public function getAllowInClassWithMethodAttributes(): array
	{
		return $this->allowedConfig->getAllowInClassWithMethodAttributes();
	}


	public function getAllowExceptInClassWithMethodAttributes(): array
	{
		return $this->allowedConfig->getAllowExceptInClassWithMethodAttributes();
	}


	public function getErrorIdentifier(): ?string
	{
		return $this->errorIdentifier;
	}


	/**
	 * @return list<string>
	 */
	public function getErrorTip(): array
	{
		return $this->errorTip;
	}

}
