<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Contact;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;

class EmailService
{
  public function __construct(
    private readonly MailerInterface $mailer,
    #[Autowire(value: '%env(string:EMAIL_TO)%')]
    private readonly string $emailTo,
    #[Autowire(value: '%env(string:EMAIL_FROM)%')]
    private readonly string $emailFrom,
    #[Autowire(value: '%env(string:FRONT_URL)%')]
    private readonly string $siteUrl,
  ) {
  }

  /**
   * Renders and sends the contact notification email.
   *
   * @throws TransportExceptionInterface If the mail transport fails (swallowed by the caller).
   */
  public function sendMail(Contact $contact): void
  {
    $phone = $contact->getPhone();

    $data = [
      'firstname'   => $contact->getFirstname(),
      'lastname'    => $contact->getLastname(),
      'name'        => trim($contact->getFirstname() . ' ' . $contact->getLastname()),
      'phone'       => $phone
        ? PhoneNumberUtil::getInstance()->format($phone, PhoneNumberFormat::INTERNATIONAL)
        : null,
      'email'       => $contact->getEmail(),
      'company'     => $contact->getCompany(),
      'opportunity' => $contact->getOpportunity(),
      'message'     => $contact->getMessage(),
    ];

    $email = (new TemplatedEmail())
      ->from($this->emailFrom)
      ->to($this->emailTo)
      ->subject('Nouveau message depuis caroline-chapeau.com')
      ->htmlTemplate('email/contact.html.twig')
      ->context([
        'contact'     => $data,
        'submittedAt' => new \DateTimeImmutable(),
        'siteUrl'     => $this->siteUrl,
      ]);

    $this->mailer->send($email);
  }
}
