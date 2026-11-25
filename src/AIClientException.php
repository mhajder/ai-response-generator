<?php

/*********************************************************************
 * AI Client Exception
 *
 * Represents HTTP, transport, or payload parsing errors from an AI API.
 *********************************************************************/

declare(strict_types=1);

class AIClientException extends RuntimeException
{
    private int $statusCode;
    private string $responseBody;

    public function __construct(
        string $message,
        int $statusCode = 0,
        string $responseBody = '',
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $statusCode, $previous);
        $this->statusCode = $statusCode;
        $this->responseBody = $responseBody;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getResponseBody(): string
    {
        return $this->responseBody;
    }
}
