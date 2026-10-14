<?php
declare(strict_types=1);

namespace B2Browser\B2;

/**
 * An error from the B2 API or the transport. getMessage() holds internal
 * detail for the log; never show it to the user outside debug mode.
 */
final class B2Exception extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $httpStatus = 0,
        public readonly string $b2Code = '',
    ) {
        parent::__construct($message);
    }

    public function isAuthExpired(): bool
    {
        return $this->httpStatus === 401 && in_array($this->b2Code, ['expired_auth_token', 'bad_auth_token'], true);
    }

    public function isNotFound(): bool
    {
        return $this->httpStatus === 404;
    }
}
