<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Contact;
use App\Service\EmailService;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

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
  )
  {
  }

  public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Contact
  {
    try {
      $this->emailService->sendMail($data);
    } catch (\Exception) {
    }

    return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
  }
}
