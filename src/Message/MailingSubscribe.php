<?php

declare(strict_types=1);

namespace App\Message;

final class MailingSubscribe
{
    public function __construct(
        public string $email,
        public string $entityId,
    ) {
    }
}
