<?php declare(strict_types=1);

namespace AutoDoc\Exceptions;

use Exception;
use Throwable;

class AutoDocException extends Exception
{
    public function __construct(string $message, Throwable $previousException)
    {
        $message = $message . $previousException->getMessage();

        if ($previousException instanceof AutoDocException) {
            $previousException = $previousException->getPrevious();
        }

        if ($previousException) {
            $this->file = $previousException->getFile();
            $this->line = $previousException->getLine();
        }

        parent::__construct($message, 0, $previousException);
    }
}
