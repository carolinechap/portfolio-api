<?php

declare(strict_types=1);

namespace App\Validator;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class HCaptchaConstraintValidator extends ConstraintValidator
{
  public function __construct(
    private readonly HttpClientInterface $httpClient,
    #[Autowire(value: '%env(string:HCAPTCHA_VERIFY_URL)%')]
    private readonly string $verifyUrl,
    #[Autowire(value: '%env(string:HCAPTCHA_SECRET_KEY)%')]
    private readonly string $secretKey,
  ) {
  }

  public function validate(mixed $value, Constraint $constraint): void
  {
    if (!$constraint instanceof HCaptchaConstraint) {
      throw new UnexpectedTypeException($constraint, HCaptchaConstraint::class);
    }

    if (null === $value || '' === $value) {
      return;
    }

    if (!is_string($value)) {
      throw new UnexpectedValueException($value, 'string');
    }

    try {
      $response = $this->httpClient->request('POST', $this->verifyUrl, [
        'body' => [
          'secret'   => $this->secretKey,
          'response' => $value,
        ],
      ]);
      $responseData = $response->toArray(false);
    } catch (HttpClientExceptionInterface | \JsonException) {
      $this->context->buildViolation($constraint->message)->addViolation();

      return;
    }

    if (($responseData['success'] ?? false) !== true) {
      $this->context->buildViolation($constraint->message)->addViolation();
    }
  }
}