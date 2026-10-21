<?php

declare(strict_types=1);

namespace App\Api;

use ApiPlatform\Metadata\Exception\ProblemExceptionInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * An API v1 error with a stable, relative problem `type` (`/problems/<slug>`),
 * rendered by API Platform as `application/problem+json`. Used only where one
 * status covers several domain causes a client must tell apart; every other
 * error keeps API Platform's default `/errors/{status}` type.
 */
final class ProblemException extends HttpException implements ProblemExceptionInterface
{
    public function __construct(
        private readonly string $type,
        private readonly string $title,
        int $status,
        string $detail,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($status, $detail, $previous);
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function getStatus(): ?int
    {
        return $this->getStatusCode();
    }

    public function getDetail(): ?string
    {
        return $this->getMessage();
    }

    public function getInstance(): ?string
    {
        return null;
    }
}
