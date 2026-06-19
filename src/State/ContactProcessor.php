<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Contact;
use App\Service\EmailService;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * @implements ProcessorInterface<Contact, Contact>
 */
final class ContactProcessor implements ProcessorInterface
{
  /**
   * @param ProcessorInterface<Contact, Contact> $persistProcessor
   */
  public function __construct(
    #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
    private ProcessorInterface $persistProcessor,
    private EmailService $emailService,
    private RateLimiterFactory $contactGlobalLimiter,
  )
  {
  }

  public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Contact
  {
    // Reached only after denormalization + validation (captcha included), so
    // this daily ceiling counts genuine submissions, not abuse attempts. Once
    // exhausted, no mail is sent and nothing is persisted: 429.
    $limit = $this->contactGlobalLimiter->create('contact_global')->consume();
    if (!$limit->isAccepted()) {
      throw new TooManyRequestsHttpException(
        max(0, $limit->getRetryAfter()->getTimestamp() - time()),
        'Daily contact limit reached',
      );
    }

    try {
      $this->emailService->sendMail($data);
    } catch (\Exception) {
    }

    return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
  }
}
