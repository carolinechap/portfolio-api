<?php

declare(strict_types=1);

namespace App\Validator;

use App\Chat\Service\HCaptchaVerifier;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

class HCaptchaConstraintValidator extends ConstraintValidator
{
  public function __construct(
    private readonly HCaptchaVerifier $verifier,
  ) {
  }

  public function validate(mixed $value, Constraint $constraint): void
  {
    if (!$constraint instanceof HCaptchaConstraint) {
      throw new UnexpectedTypeException($constraint, HCaptchaConstraint::class);
    }

    if ($value === null || $value === '') {
      return;
    }

    if (!is_string($value)) {
      throw new UnexpectedValueException($value, 'string');
    }

    if (!$this->verifier->verify($value)) {
      $this->context->buildViolation($constraint->message)->addViolation();
    }
  }
}
