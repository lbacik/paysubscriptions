<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\OAuthConsentRepository;
use DateTime;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Gedmo\Timestampable\Traits\Timestampable;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * A User's remembered OAuth2 consent for one registered client.
 *
 * One row per (User, client): granting again overwrites the row. The row
 * snapshots the registered client display identity and the granted scopes, so
 * a later authorization is asked again when the registered identity or the
 * permissions change materially (#86). Revoking the row (issue #92) or
 * deleting the account removes the remembered consent; already-issued
 * access tokens stay valid until their short expiry.
 */
#[ORM\Entity(repositoryClass: OAuthConsentRepository::class)]
#[ORM\Table(name: 'oauth_consent')]
#[ORM\UniqueConstraint(name: 'UNIQ_OAUTH_CONSENT_USER_CLIENT', fields: ['user', 'clientId'])]
class OAuthConsent
{
    use Timestampable;

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
     * Snapshot of the registered client display identity at grant time.
     */
    #[ORM\Column(length: 128)]
    private ?string $clientName = null;

    /**
     * @var list<string>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $scopes = [];

    #[Gedmo\Timestampable(on: 'create')]
    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: false, options: ['default' => 'CURRENT_TIMESTAMP'])]
    protected $createdAt;

    #[Gedmo\Timestampable(on: 'create')]
    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: false, options: ['default' => 'CURRENT_TIMESTAMP'])]
    protected $updatedAt;

    public function __construct()
    {
        $this->createdAt = new DateTime();
        $this->updatedAt = new DateTime();
    }

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

    public function getClientName(): ?string
    {
        return $this->clientName;
    }

    public function setClientName(string $clientName): static
    {
        $this->clientName = $clientName;

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

    /**
     * Whether this remembered consent still covers the current authorization
     * request: same registered display identity and same scope set. Any
     * material change re-arms the consent screen.
     *
     * @param list<string> $scopes
     */
    public function covers(string $clientName, array $scopes): bool
    {
        $remembered = $this->scopes;
        sort($remembered);
        $requested = array_values($scopes);
        sort($requested);

        return $this->clientName === $clientName && $remembered === $requested;
    }
}
