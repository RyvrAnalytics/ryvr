<?php

namespace App\Analytics\ClickHouse;

use RuntimeException;

class ClickHouseException extends RuntimeException
{
    public function isQueryLimit(): bool
    {
        // TOO_MANY_ROWS, TIMEOUT_EXCEEDED and TOO_MANY_ROWS_OR_BYTES.
        return in_array($this->getCode(), [158, 159, 396], true);
    }
}
