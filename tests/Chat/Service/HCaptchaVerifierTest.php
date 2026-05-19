<?php

declare(strict_types=1);

namespace App\Tests\Chat\Service;

use App\Chat\Service\HCaptchaVerifier;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class HCaptchaVerifierTest extends TestCase
{
  public function testReturnsTrueWhenApiReportsSuccess(): void
  {
    $client = new MockHttpClient([new MockResponse(json_encode(['success' => true], JSON_THROW_ON_ERROR))]);
    $verifier = new HCaptchaVerifier($client, 'https://h.test/siteverify', 'secret');

    self::assertTrue($verifier->verify('token'));
  }

  public function testReturnsFalseWhenApiReportsFailure(): void
  {
    $client = new MockHttpClient([new MockResponse(json_encode(['success' => false], JSON_THROW_ON_ERROR))]);
    $verifier = new HCaptchaVerifier($client, 'https://h.test/siteverify', 'secret');

    self::assertFalse($verifier->verify('token'));
  }

  public function testReturnsFalseWhenApiThrows(): void
  {
    $client = new MockHttpClient([new MockResponse('', ['http_code' => 500])]);
    $verifier = new HCaptchaVerifier($client, 'https://h.test/siteverify', 'secret');

    self::assertFalse($verifier->verify('token'));
  }

  public function testReturnsFalseOnEmptyToken(): void
  {
    $client = new MockHttpClient();
    $verifier = new HCaptchaVerifier($client, 'https://h.test/siteverify', 'secret');

    self::assertFalse($verifier->verify(''));
  }
}
