<?php

declare(strict_types=1);

namespace Espo\Modules\TurboSMS\Core;

use RuntimeException;

final class ApiException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $uncertain = false, int $code = 0)
    {
        parent::__construct($message, $code);
    }
}
