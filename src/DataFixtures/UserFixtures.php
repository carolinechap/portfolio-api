<?php

namespace App\DataFixtures;

use App\Entity\User;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class UserFixtures extends Fixture
{
  public function __construct(
    private readonly UserPasswordHasherInterface $userPasswordHasher,
    #[Autowire(value: '%env(string:USER_ADMIN_EMAIL)%')]
    private readonly string $adminEmail,
    #[Autowire(value: '%env(string:USER_ADMIN_PASSWORD)%')]
    private readonly string $adminPassword,
  ) {
  }

  public function load(ObjectManager $manager): void
  {
    $user = new User();
    $user->setEmail($this->adminEmail);
    $user->setRoles(['ROLE_ADMIN']);
    $user->setPassword($this->userPasswordHasher->hashPassword($user, $this->adminPassword));
    $manager->persist($user);
    $manager->flush();
  }
}
