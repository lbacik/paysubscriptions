<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\User;
use App\OAuth2\OAuth2Config;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * A refresh-token family for one User, client, scope set, and API resource
 * (issue #91).
 *
 * The first refresh token issued for an authorization starts a family; every
 * successful refresh rotates within the same family (the prior token becomes
 * unusable) without extending the absolute deadline. Reuse of a superseded
 * token revokes the whole family, requiring fresh authorization. A family
 * expires after 30 days without use and no later than 90 days after initial
 * authorization. Deleting the User removes its families.
 */
#[ORM\Entity]
#[ORM\Table(name: 'oauth_refresh_family')]
class OAuthRefreshFamily
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Column(length: 191)]
    private ?string $clientId = null;

    /**
     * @var list<string>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $scopes = [];

    #[ORM\Column(length: 255)]
    private ?string $audience = OAuth2Config::API_AUDIENCE;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: false)]
    private ?DateTimeImmutable $issuedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: false)]
    private ?DateTimeImmutable $lastUsedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: false)]
    private ?DateTimeImmutable $absoluteExpiresAt = null;

    #[ORM\Column(nullable: false, options: ['default' => false])]
    private bool $revoked = false;

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getClientId(): ?string
    {
        return $this->clientId;
    }

    public function setClientId(string $clientId): static
    {
        $this->clientId = $clientId;

        return $this;
    }

    /**
     * @return list<string>
     */
    public function getScopes(): array
    {
        return $this->scopes;
    }

    /**
     * @param list<string> $scopes
     */
    public function setScopes(array $scopes): static
    {
        $this->scopes = array_values($scopes);

        return $this;
    }

    public function getAudience(): ?string
    {
        return $this->audience;
    }

    public function setAudience(string $audience): static
    {
        $this->audience = $audience;

        return $this;
    }

    public function getIssuedAt(): ?DateTimeImmutable
    {
        return $this->issuedAt;
    }

    public function setIssuedAt(DateTimeImmutable $issuedAt): static
    {
        $this->issuedAt = $issuedAt;

        return $this;
    }

    public function getLastUsedAt(): ?DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    public function setLastUsedAt(DateTimeImmutable $lastUsedAt): static
    {
        $this->lastUsedAt = $lastUsedAt;

        return $this;
    }

    public function getAbsoluteExpiresAt(): ?DateTimeImmutable
    {
        return $this->absoluteExpiresAt;
    }

    public function setAbsoluteExpiresAt(DateTimeImmutable $absoluteExpiresAt): static
    {
        $this->absoluteExpiresAt = $absoluteExpiresAt;

        return $this;
    }

    public function isRevoked(): bool
    {
        return $this->revoked;
    }

    public function setRevoked(bool $revoked): static
    {
        $this->revoked = $revoked;

        return $this;
    }
}
