<?php
namespace App\Validator;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class ReCaptchaConstraintValidator extends ConstraintValidator
{
  /**
   * Http Client
   *
   * @var HttpClientInterface $httpClient
   */
  private HttpClientInterface $httpClient;

  /**
   * Recaptcha url verify
   *
   * @var string $recaptchaUrl
   */
  protected $recaptchaUrl;

  /**
   * Recaptcha secret key
   *
   * @var string $recaptchaSecretKey
   */
  protected $recaptchaSecretKey;

  public function __construct(
    HttpClientInterface $httpClient,
    #[Autowire(value: '%env(string:RECAPTCHA_URL)%')] string $recaptchaUrl,
    #[Autowire(value: '%env(string:RECAPTCHA_SECRET_KEY)%')] string $recaptchaSecretKey

  )
  {
    $this->httpClient         = $httpClient;
    $this->recaptchaUrl       = $recaptchaUrl;
    $this->recaptchaSecretKey = $recaptchaSecretKey;
  }

  public function validate($value, Constraint $constraint)
  {
    if (!$constraint instanceof ReCaptchaConstraint) {
      throw new UnexpectedTypeException($constraint, ReCaptchaConstraint::class);
    }

    if (null === $value || '' === $value) {
      return;
    }

    if (!is_string($value)) {
      throw new UnexpectedValueException($value, 'string');
    }

    $response = $this->httpClient->request('POST', $this->recaptchaUrl, [
      'body' => [
        'secret' => $this->recaptchaSecretKey,
        'response' => $value,
      ],
    ]);

    $responseData = $response->toArray();

    if (!$responseData['success'] || $responseData['score'] < 0.5) {
      $this->context->buildViolation($constraint->message)->addViolation();
    }
  }
}
