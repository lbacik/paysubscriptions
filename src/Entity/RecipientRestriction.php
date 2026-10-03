<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\RecipientRestrictionState;
use App\Repository\RecipientRestrictionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * One current deliverability restriction per normalized email address,
 * written only from SES permanent-bounce and complaint feedback.
 *
 * The row is keyed by address, not by User: a User who changes to another
 * address starts unrestricted, and the old address keeps its restriction.
 * There is no per-message history. Rows are written through
 * RecipientRestrictionRepository::restrict(), which never loosens a state.
 */
#[ORM\Entity(repositoryClass: RecipientRestrictionRepository::class)]
#[ORM\Table(name: 'recipient_restriction')]
#[ORM\UniqueConstraint(name: 'UNIQ_RECIPIENT_RESTRICTION_EMAIL', fields: ['email'])]
class RecipientRestriction
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(type: Types::STRING, length: 180)]
    private string $email;

    #[ORM\Column(type: Types::STRING, length: 16, enumType: RecipientRestrictionState::class)]
    private RecipientRestrictionState $state;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    private function __construct()
    {
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getState(): RecipientRestrictionState
    {
        return $this->state;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
