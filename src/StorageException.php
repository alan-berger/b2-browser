<?php
declare(strict_types=1);

namespace B2Browser;

/** Raised when a server-side state file cannot be read or written. Security checks fail closed on it. */
final class StorageException extends \RuntimeException
{
}
