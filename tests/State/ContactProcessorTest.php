<?php

declare(strict_types=1);

namespace App\Tests\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Post;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Contact;
use App\Service\EmailService;
use App\State\ContactProcessor;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

final class ContactProcessorTest extends TestCase
{
    public function testSendsAndPersistsWhileUnderDailyCap(): void
    {
        $mailer = $this->spyMailer();
        $persist = $this->spyPersist();
        $processor = new ContactProcessor($persist, $mailer, $this->limiter(2));

        $processor->process(new Contact(), $this->operation());
        $processor->process(new Contact(), $this->operation());

        self::assertSame(2, $mailer->sent, 'both submissions emailed');
        self::assertSame(2, $persist->calls, 'both submissions persisted');
    }

    public function testRejectsOverDailyCapWithoutSendingOrPersisting(): void
    {
        $mailer = $this->spyMailer();
        $persist = $this->spyPersist();
        $processor = new ContactProcessor($persist, $mailer, $this->limiter(1));

        $processor->process(new Contact(), $this->operation());

        try {
            $processor->process(new Contact(), $this->operation());
            self::fail('expected TooManyRequestsHttpException once the daily cap is reached');
        } catch (TooManyRequestsHttpException) {
            // expected
        }

        self::assertSame(1, $mailer->sent, 'no mail sent past the cap');
        self::assertSame(1, $persist->calls, 'nothing persisted past the cap');
    }

    private function limiter(int $limit): RateLimiterFactory
    {
        return new RateLimiterFactory(
            ['id' => 'contact_global', 'policy' => 'fixed_window', 'limit' => $limit, 'interval' => '1 day'],
            new InMemoryStorage(),
        );
    }

    private function operation(): Operation
    {
        return new Post();
    }

    /** @return EmailService&object{sent:int} */
    private function spyMailer(): EmailService
    {
        return new class extends EmailService {
            public int $sent = 0;

            public function __construct()
            {
            }

            public function sendMail(Contact $contact): void
            {
                $this->sent++;
            }
        };
    }

    /** @return ProcessorInterface<Contact, Contact>&object{calls:int} */
    private function spyPersist(): ProcessorInterface
    {
        return new class implements ProcessorInterface {
            public int $calls = 0;

            public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
            {
                $this->calls++;

                return $data;
            }
        };
    }
}
