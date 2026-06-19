<?php

declare(strict_types=1);

namespace App\Chat\Service;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class HCaptchaVerifier
{
  /**
   * How long a successfully redeemed token is remembered as "consumed".
   */
  private const int CONSUMED_TTL = 600;

  public function __construct(
    private readonly HttpClientInterface $httpClient,
    #[Autowire(value: '%env(string:HCAPTCHA_VERIFY_URL)%')]
    private readonly string $verifyUrl,
    #[Autowire(value: '%env(string:HCAPTCHA_SECRET_KEY)%')]
    private readonly string $secretKey,
    #[Autowire(service: 'cache.app')]
    private readonly CacheItemPoolInterface $consumedTokens,
  ) {
  }

  /**
   * Verifies an hCaptcha token against the hCaptcha siteverify endpoint.
   *
   * Returns false for null/empty tokens, HTTP errors, JSON decoding failures,
   * or a token that has already been redeemed.
   *
   * Anti-replay: hCaptcha tokens are single-use, but siteverify will happily
   * accept the same token repeatedly within its lifetime.
   */
  public function verify(?string $token): bool
  {
    if ($token === null || $token === '') {
      return false;
    }

    $item = $this->consumedTokens->getItem('hcaptcha.' . hash('sha256', $token));
    if ($item->isHit()) {
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

    if (($data['success'] ?? false) !== true) {
      return false;
    }

    $this->consumedTokens->save($item->set(true)->expiresAfter(self::CONSUMED_TTL));

    return true;
  }
}
