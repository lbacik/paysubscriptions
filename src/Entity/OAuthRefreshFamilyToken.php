<?php

declare(strict_types=1);

namespace App\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Links one bundle refresh-token identifier to its family (issue #91).
 *
 * The bundle's own `oauth2_refresh_token` row carries expiry and revocation
 * but no family pointer, so this table records which family each token
 * belongs to and whether the token has been superseded by rotation. Reuse of
 * a superseded token revokes the whole family.
 */
#[ORM\Entity]
#[ORM\Table(name: 'oauth_refresh_family_token')]
#[ORM\Index(name: 'IDX_REFRESH_FAMILY_TOKEN_FAMILY', fields: ['family'])]
class OAuthRefreshFamilyToken
{
    #[ORM\Id]
    #[ORM\Column(length: 80)]
    private ?string $tokenId = null;

    #[ORM\ManyToOne(targetEntity: OAuthRefreshFamily::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?OAuthRefreshFamily $family = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: false)]
    private ?DateTimeImmutable $createdAt = null;

    #[ORM\Column(nullable: false, options: ['default' => false])]
    private bool $superseded = false;

    public function __construct(string $tokenId, OAuthRefreshFamily $family)
    {
        $this->tokenId = $tokenId;
        $this->family = $family;
        $this->createdAt = new DateTimeImmutable();
    }

    public function getTokenId(): ?string
    {
        return $this->tokenId;
    }

    public function getFamily(): ?OAuthRefreshFamily
    {
        return $this->family;
    }

    public function getCreatedAt(): ?DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function isSuperseded(): bool
    {
        return $this->superseded;
    }

    public function setSuperseded(bool $superseded): static
    {
        $this->superseded = $superseded;

        return $this;
    }
}
