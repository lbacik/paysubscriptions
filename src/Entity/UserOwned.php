<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * An entity that belongs to exactly one User.
 *
 * API Platform's Doctrine reads of any implementing entity are restricted to
 * the token User by App\Doctrine\UserOwnedExtension, so a foreign identifier
 * is indistinguishable from a missing one (404) and a collection never shows
 * another User's rows. An entity-backed API resource whose entity holds User
 * data must implement this, or it ships unscoped.
 */
interface UserOwned
{
    public function getOwner(): ?User;
}
