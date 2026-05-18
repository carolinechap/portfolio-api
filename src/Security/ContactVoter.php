<?php

namespace App\Security;

use App\Entity\Contact;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * @extends Voter<string, Contact>
 */
class ContactVoter extends Voter
{
  public const string CREATE = 'CONTACT_CREATE';
  private Security $security;

  public function __construct(Security $security)
  {
    $this->security = $security;
  }

  protected function supports(string $attribute, $subject): bool
  {
    return $attribute === self::CREATE && $subject instanceof Contact;
  }

  protected function voteOnAttribute(string $attribute, $subject, TokenInterface $token): bool
  {
    return $this->security->isGranted('ROLE_ADMIN');
  }
}
