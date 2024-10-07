<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Post;
use App\Attributes\CustomMapping;
use App\DataTransformer\PhoneDataTransformer;
use App\Repository\ContactRepository;
use App\State\ContactProcessor;
use App\Validator\ReCaptchaConstraint;
use App\Validator\UserValidationGroupsGenerator;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumber;
use libphonenumber\PhoneNumberUtil;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\NotBlank;

#[ORM\Entity(repositoryClass: ContactRepository::class)]
#[ApiResource(
  description: 'Contact resource',
  operations: [
    new Post(
      processor: ContactProcessor::class,
    ),
  ],
  normalizationContext: ['groups' => ['contact:read']],
  denormalizationContext: ['groups' => ['contact:write']],
)]
class Contact
{
  #[ORM\Id]
  #[ORM\GeneratedValue]
  #[ORM\Column]
  private ?int $id = null;
  #[ORM\Column(type: Types::STRING, length: 50)]
  #[NotBlank(message: 'error.field.not_blank')]
  #[Groups(['contact:read', 'contact:write'])]
  private ?string $firstname = null;

  #[ORM\Column(type: Types::STRING, length: 50)]
  #[NotBlank(message: 'error.field.not_blank')]
  #[Groups(['contact:read', 'contact:write'])]
  private ?string $lastname = null;

  #[ORM\Column(type: 'phone_number', nullable: true)]
  #[
    AssertPhoneNumber(type: AssertPhoneNumber::ANY, message: 'error.field.format')
  ]
  #[ApiProperty(openapiContext: ['type' => 'string'])]
  #[Groups(['contact:read', 'contact:write'])]
  private ?PhoneNumber $phone = null;

  #[ORM\Column(type: Types::STRING, length: 100)]
  #[
    NotBlank(message: 'error.field.not_blank'),
    Email(message: 'error.field.format', mode: Email::VALIDATION_MODE_STRICT)
  ]
  #[Groups(['contact:read', 'contact:write'])]
  private ?string $email = null;

  #[ORM\Column(type: Types::TEXT)]
  #[NotBlank(message: 'error.field.not_blank')]
  #[Groups(['contact:read', 'contact:write'])]
  private ?string $message = null;

  #[Groups(['contact:write'])]
  #[
    ReCaptchaConstraint
  ]
  private string $token;

  /**
   * @inheritDoc
   */
  public function getId(): ?int
  {
    return $this->id;
  }

  /**
   * @return string
   */
  public function getFirstname(): ?string
  {
    return $this->firstname;
  }

  /**
   * @param string $firstname
   *
   * @return Contact
   */
  public function setFirstname(string $firstname): Contact
  {
    $this->firstname = $firstname;

    return $this;
  }

  /**
   * @return string
   */
  public function getLastname(): ?string
  {
    return $this->lastname;
  }

  /**
   * @param string $lastname
   *
   * @return Contact
   */
  public function setLastname(string $lastname): Contact
  {
    $this->lastname = $lastname;

    return $this;
  }

  /**
   * @return PhoneNumber|null
   */
  public function getPhone(): ?PhoneNumber
  {
    return $this->phone;
  }

  /**
   * @param PhoneNumber|string|null $phone
   *
   * @return Contact
   */
  public function setPhone(PhoneNumber|string|null $phone): Contact
  {
    if (is_string($phone)) {
      try {
        /** @var PhoneNumber $phone */
        $phone = PhoneNumberUtil::getInstance()->parse($phone);
      } catch (NumberParseException) {
        $phone = null;
      }
    }

    $this->phone = $phone;

    return $this;
  }

  /**
   * @inheritDoc
   */
  public function getEmail(): ?string
  {
    return $this->email;
  }

  /**
   * @param string $email
   *
   * @return Contact
   */
  public function setEmail(string $email): Contact
  {
    $this->email = $email;

    return $this;
  }

  /**
   * @inheritDoc
   */
  public function getMessage(): ?string
  {
    return $this->message;
  }

  /**
   * @param string $message
   *
   * @return Contact
   */
  public function setMessage(string $message): Contact
  {
    $this->message = $message;

    return $this;
  }

  /**
   * @inheritDoc
   */
  public function getToken(): string {
    return $this->token;
  }

  /**
   * @param string $token
   *
   * @return Contact
   */
  public function setToken(string $token): void {
    $this->token = $token;
  }
}
