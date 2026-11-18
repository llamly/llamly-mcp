<?php

declare(strict_types=1);

namespace Llamly\Free\History;

/**
 * A refused or failed mutation. Throw sites pass the message HTML-escaped (it may reach a page if it ever escapes
 * uncaught); callers that return it to an MCP client or the admin as JSON use reason() for the plain text.
 */
final class MutationFailure extends \RuntimeException
{
	public string $errorCode;
	public int $httpStatus;

	public function __construct(string $errorCode, string $message, int $httpStatus = 409)
	{
		$this->errorCode = $errorCode;
		$this->httpStatus = $httpStatus;
		parent::__construct($message);
	}

	/** The message as plain text for JSON responses (undoes the escaping applied at the throw site). */
	public function reason(): string
	{
		return wp_specialchars_decode($this->getMessage(), ENT_QUOTES);
	}
}
