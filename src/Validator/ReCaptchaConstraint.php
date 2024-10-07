<?php

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

/**
 * Class RecaptchaContraint
 */
#[\Attribute]
class ReCaptchaConstraint extends Constraint
{
  /** @var string $message */
  public string $message = 'Invalid reCAPTCHA token.';

}
