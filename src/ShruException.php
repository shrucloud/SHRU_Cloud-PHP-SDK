<?php

namespace ShruCloud;

/**
 * Thrown for every failure this client can produce: a transport-level
 * problem (network error, invalid JSON), an authentication failure
 * (missing/expired/invalid signature, IP not allowlisted), or a normal
 * API-level rejection (insufficient balance, service not found, ...).
 *
 * getCode() carries the HTTP status the server returned (0 if the request
 * never reached the server at all - a connection failure). getMessage()
 * carries the server's own "message" field whenever one was returned, so
 * catching this one exception type and printing getMessage() is almost
 * always exactly the right thing to show a user.
 */
class ShruException extends \RuntimeException
{
    /** @var array|null The full decoded JSON response body, when one exists. */
    private $response;

    public function __construct(string $message, int $httpStatus = 0, ?array $response = null, ?\Throwable $previous = null)
    {
        parent::__construct($message, $httpStatus, $previous);
        $this->response = $response;
    }

    /**
     * True for a 401/403 - bad Key ID, expired/invalid signature, or the
     * caller's IP isn't on that key's allowlist. Distinguishing this from a
     * plain API rejection (eg. "Insufficient balance") lets a caller show a
     * "check your credentials/IP allowlist" message instead of a generic one.
     */
    public function isAuthError(): bool
    {
        return $this->getCode() === 401 || $this->getCode() === 403;
    }

    /** The full decoded JSON response body, if the server returned one. */
    public function getResponse(): ?array
    {
        return $this->response;
    }
}
