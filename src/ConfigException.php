<?php
declare(strict_types=1);

namespace B2Browser;

/** Raised when the configuration is missing or invalid. Messages are safe to display. */
final class ConfigException extends \RuntimeException
{
    /** @param list<string> $problems */
    public function __construct(string $message, private readonly array $problems = [])
    {
        parent::__construct($message);
    }

    /** @return list<string> */
    public function problems(): array
    {
        return $this->problems === [] ? [$this->getMessage()] : $this->problems;
    }
}
