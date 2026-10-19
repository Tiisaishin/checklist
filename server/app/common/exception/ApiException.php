<?php
namespace app\common\exception;

use RuntimeException;

class ApiException extends RuntimeException
{
    public function __construct(string $message, protected int $status = 400, protected mixed $data = null)
    {
        parent::__construct($message, $status);
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    public function getData(): mixed
    {
        return $this->data;
    }
}
