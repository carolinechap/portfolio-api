<?php

declare(strict_types=1);

namespace App\Chat\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class HCaptchaVerifier
{
  public function __construct(
    private readonly HttpClientInterface $httpClient,
    #[Autowire(value: '%env(string:HCAPTCHA_VERIFY_URL)%')]
    private readonly string $verifyUrl,
    #[Autowire(value: '%env(string:HCAPTCHA_SECRET_KEY)%')]
    private readonly string $secretKey,
  ) {
  }

  /**
   * Verifies an hCaptcha token against the hCaptcha siteverify endpoint.
   *
   * Returns false for null/empty tokens, HTTP errors, or JSON decoding failures
   * — the caller treats any failure as an invalid token.
   */
  public function verify(?string $token): bool
  {
    if ($token === null || $token === '') {
      return false;
    }

    try {
      $response = $this->httpClient->request('POST', $this->verifyUrl, [
        'body' => [
          'secret'   => $this->secretKey,
          'response' => $token,
        ],
      ]);
      $data = $response->toArray(false);
    } catch (HttpClientExceptionInterface | \JsonException) {
      return false;
    }

    return ($data['success'] ?? false) === true;
  }
}
