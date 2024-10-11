<?php

namespace App\DataFixtures;

use App\Entity\User;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class UserFixtures extends Fixture
{
  protected $userPasswordHasher;

  protected $params;

  public function __construct(
    UserPasswordHasherInterface $userPasswordHasher,
    ParameterBagInterface $params
  )
  {
    $this->userPasswordHasher = $userPasswordHasher;
    $this->params = $params;
  }

  public function load(ObjectManager $manager): void
    {
      $email    = $this->params->get('admin_email');
      $password = $this->params->get('admin_password');
      // Create admin user.
      $user = new User();
      $user->setEmail($email);
      $user->setRoles(['ROLE_ADMIN']);
      $user->setPassword($this->userPasswordHasher->hashPassword($user, $password));
      $manager->persist($user);
      $manager->flush();
    }
}
