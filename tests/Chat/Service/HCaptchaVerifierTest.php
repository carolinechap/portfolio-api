<?php

declare(strict_types=1);

namespace App\Tests\Chat\Service;

use App\Chat\Service\HCaptchaVerifier;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class HCaptchaVerifierTest extends TestCase
{
  public function testReturnsTrueWhenApiReportsSuccess(): void
  {
    $client = new MockHttpClient([new MockResponse(json_encode(['success' => true], JSON_THROW_ON_ERROR))]);
    $verifier = new HCaptchaVerifier($client, 'https://h.test/siteverify', 'secret', new ArrayAdapter(), new NullLogger());

    self::assertTrue($verifier->verify('token'));
  }

  public function testReturnsFalseWhenApiReportsFailure(): void
  {
    $client = new MockHttpClient([new MockResponse(json_encode(['success' => false], JSON_THROW_ON_ERROR))]);
    $verifier = new HCaptchaVerifier($client, 'https://h.test/siteverify', 'secret', new ArrayAdapter(), new NullLogger());

    self::assertFalse($verifier->verify('token'));
  }

  public function testReturnsFalseWhenApiThrows(): void
  {
    $client = new MockHttpClient([new MockResponse('', ['http_code' => 500])]);
    $verifier = new HCaptchaVerifier($client, 'https://h.test/siteverify', 'secret', new ArrayAdapter(), new NullLogger());

    self::assertFalse($verifier->verify('token'));
  }

  public function testReturnsFalseOnEmptyToken(): void
  {
    $client = new MockHttpClient();
    $verifier = new HCaptchaVerifier($client, 'https://h.test/siteverify', 'secret', new ArrayAdapter(), new NullLogger());

    self::assertFalse($verifier->verify(''));
  }

  public function testRejectsReplayedToken(): void
  {
    // siteverify would accept the same token twice; the verifier must not.
    $client = new MockHttpClient([
      new MockResponse(json_encode(['success' => true], JSON_THROW_ON_ERROR)),
      new MockResponse(json_encode(['success' => true], JSON_THROW_ON_ERROR)),
    ]);
    $verifier = new HCaptchaVerifier($client, 'https://h.test/siteverify', 'secret', new ArrayAdapter(), new NullLogger());

    self::assertTrue($verifier->verify('token'), 'first use accepted');
    self::assertFalse($verifier->verify('token'), 'replay rejected');
  }

  public function testFailedTokenIsNotConsumed(): void
  {
    // A token that fails verification must not be burned: a later genuine
    // success with the same string still passes (e.g. transient API failure).
    $client = new MockHttpClient([
      new MockResponse(json_encode(['success' => false], JSON_THROW_ON_ERROR)),
      new MockResponse(json_encode(['success' => true], JSON_THROW_ON_ERROR)),
    ]);
    $verifier = new HCaptchaVerifier($client, 'https://h.test/siteverify', 'secret', new ArrayAdapter(), new NullLogger());

    self::assertFalse($verifier->verify('token'));
    self::assertTrue($verifier->verify('token'));
  }
}
