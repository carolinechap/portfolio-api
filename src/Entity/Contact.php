<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\Repository\ContactRepository;
use App\State\ContactProcessor;
use App\Validator\HCaptchaConstraint;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumber;
use libphonenumber\PhoneNumberUtil;
use Misd\PhoneNumberBundle\Validator\Constraints\PhoneNumber as AssertPhoneNumber;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Serializer\Exception\NotNormalizableValueException;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\NotBlank;

#[ORM\Entity(repositoryClass: ContactRepository::class)]
#[ApiResource(
  description: 'Contact resource',
  operations: [
    new Post(
      processor: ContactProcessor::class,
      security: 'is_granted(\'' . User::ROLE_ADMIN . '\')'
    ),
  ],
  normalizationContext: ['groups' => ['contact:read']],
  denormalizationContext: ['groups' => ['contact:write']],
)]
class Contact
{

  public const string PHONE_DEFAULT_REGION = 'FR';

  #[ORM\Id]
  #[ORM\GeneratedValue]
  #[ORM\Column]
  private ?int $id = null;
  #[ORM\Column(type: Types::STRING, length: 50)]
  #[NotBlank(message: 'error.field.not_blank')]
  #[Groups(['contact:read', 'contact:write'])]
  private string $firstname;

  #[ORM\Column(type: Types::STRING, length: 50)]
  #[NotBlank(message: 'error.field.not_blank')]
  #[Groups(['contact:read', 'contact:write'])]
  private string $lastname;

  #[ORM\Column(type: 'phone_number', nullable: true)]
  #[
    AssertPhoneNumber(type: AssertPhoneNumber::ANY, defaultRegion: self::PHONE_DEFAULT_REGION, message: 'error.field.format')
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
  private string $email;

  #[ORM\Column(type: Types::TEXT)]
  #[NotBlank(message: 'error.field.not_blank')]
  #[Groups(['contact:read', 'contact:write'])]
  private string $message;

  #[ORM\Column(type: Types::STRING, length: 100, nullable: true)]
  #[Groups(['contact:read', 'contact:write'])]
  private ?string $company = null;

  #[ORM\Column(type: Types::STRING, length: 100, nullable: true)]
  #[Groups(['contact:read', 'contact:write'])]
  private ?string $opportunity = null;

  #[Groups(['contact:write'])]
  #[
    HCaptchaConstraint
  ]
  private string $token;

  /**
   * @inheritDoc
   */
  public function getId(): ?int
  {
    return $this->id;
  }

  public function getFirstname(): string
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

  public function getLastname(): string
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
      $phone = trim($phone);
      if ('' === $phone) {
        $phone = null;
      } else {
        // Le préfixe international (« + ») est obligatoire : le front l'envoie
        // toujours (FR « +33 » par défaut). Sans préfixe, on rejette.
        if (!str_starts_with($phone, '+')) {
          throw NotNormalizableValueException::createForUnexpectedDataType(
            'error.field.format',
            $phone,
            [PhoneNumber::class],
            'phone',
            true,
            0,
          );
        }

        try {
          $phone = PhoneNumberUtil::getInstance()->parse($phone, self::PHONE_DEFAULT_REGION);
        } catch (NumberParseException $e) {
          throw NotNormalizableValueException::createForUnexpectedDataType(
            'error.field.format',
            $phone,
            [PhoneNumber::class],
            'phone',
            true,
            0,
            $e,
          );
        }
      }
    }

    $this->phone = $phone;

    return $this;
  }

  public function getEmail(): string
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

  public function getMessage(): string
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
   * @return string|null
   */
  public function getCompany(): ?string
  {
    return $this->company;
  }

  /**
   * @param string|null $company
   *
   * @return Contact
   */
  public function setCompany(?string $company): Contact
  {
    $this->company = $company;

    return $this;
  }

  /**
   * @return string|null
   */
  public function getOpportunity(): ?string
  {
    return $this->opportunity;
  }

  /**
   * @param string|null $opportunity
   *
   * @return Contact
   */
  public function setOpportunity(?string $opportunity): Contact
  {
    $this->opportunity = $opportunity;

    return $this;
  }

  public function getToken(): string {
    return $this->token;
  }

  public function setToken(string $token): Contact {
    $this->token = $token;

    return $this;
  }
}
