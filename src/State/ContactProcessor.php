<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\ContactDto;
use App\Entity\Contact;
use App\Service\EmailService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;

final class ContactProcessor implements ProcessorInterface
{
  public function __construct(
    #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
    private ProcessorInterface $persistProcessor,
    private EmailService $emailService,
    private MailerInterface $mailer,
    private EntityManagerInterface $entityManager,
  )
  {
  }

  public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = [])
  {
    if ($data instanceof Contact === false) {
      return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
    }
    $this->emailService->sendMail($data);

    return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
  }
}
