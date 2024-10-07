<?php

namespace App\Dto;

use App\Validator\ReCaptchaConstraint;
use libphonenumber\PhoneNumber;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints as Assert;

class ContactDto
{
  #[Groups(['contact:read', 'contact:write'])]
  #[Assert\NotBlank(message: 'error.field.not_blank')]
  private ?string $firstname = null;

  #[Groups(['contact:read', 'contact:write'])]
  #[Assert\NotBlank(message: 'error.field.not_blank')]
  private ?string $lastname = null;

  #[Groups(['contact:read', 'contact:write'])]
  #[Assert\Length(min: 10, minMessage: 'error.field.format')]
  private ?PhoneNumber $phone = null;

  #[Groups(['contact:read', 'contact:write'])]
  #[Assert\NotBlank(message: 'error.field.not_blank')]
  #[Assert\Email(message: 'error.field.format')]
  private ?string $email = null;

  #[Groups(['contact:read', 'contact:write'])]
  #[Assert\NotBlank(message: 'error.field.not_blank')]
  private ?string $message = null;

  #[Groups(['contact:write'])]
  #[ReCaptchaConstraint]
  private string $token;

  // Getters and setters for all properties
  public function getFirstname(): ?string
  {
    return $this->firstname;
  }

  public function setFirstname(?string $firstname): void
  {
    $this->firstname = $firstname;
  }

  public function getLastname(): ?string
  {
    return $this->lastname;
  }

  public function setLastname(?string $lastname): void
  {
    $this->lastname = $lastname;
  }

  public function getPhone(): ?PhoneNumber
  {
    return $this->phone;
  }

  public function setPhone(?PhoneNumber $phone): void
  {
    $this->phone = $phone;
  }

  public function getEmail(): ?string
  {
    return $this->email;
  }

  public function setEmail(?string $email): void
  {
    $this->email = $email;
  }

  public function getMessage(): ?string
  {
    return $this->message;
  }

  public function setMessage(?string $message): void
  {
    $this->message = $message;
  }

  public function getToken(): string
  {
    return $this->token;
  }

  public function setToken(string $token): void
  {
    $this->token = $token;
  }
}
