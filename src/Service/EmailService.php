<?php

namespace App\Service;
use App\Entity\Contact;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;

/**
 * Class EmailService.
 */
class EmailService
{

  /** @var MailerInterface $mailer */
  protected MailerInterface $mailer;

  /**
   * @var string
   */
  protected string $emailTo;

  /**
   * @var string
   */
  protected string $emailFrom;

  /**
   * @var string
   */
  protected string $siteUrl;


  public function __construct(
    MailerInterface  $mailer,
    #[Autowire(value: '%env(string:EMAIL_TO)%')] string $emailTo,
    #[Autowire(value: '%env(string:EMAIL_FROM)%')] string $emailFrom,
    #[Autowire(value: '%env(string:FRONT_URL)%')] string $siteUrl,
  ) {
    $this->mailer    = $mailer;
    $this->emailTo   = $emailTo;
    $this->emailFrom = $emailFrom;
    $this->siteUrl   = $siteUrl;
  }

  /**
   * Sends an email using the provided Contact information.
   *
   * @param Contact $contact The contact details for the email.
   * @throws \Exception If an error occurs during email sending.
   * @return void
   */
  public function sendMail(Contact $contact) : void
  {
    // Create an array of contact information.
    $phone = $contact->getPhone();

    $data = [
      'firstname' => $contact->getFirstname(),
      'lastname'  => $contact->getLastname(),
      'name'      => trim($contact->getFirstname() . ' ' . $contact->getLastname()),
      'phone'     => $phone
        ? PhoneNumberUtil::getInstance()->format($phone, PhoneNumberFormat::INTERNATIONAL)
        : null,
      'email'     => $contact->getEmail(),
      'company'     => $contact->getCompany(),
      'opportunity' => $contact->getOpportunity(),
      'message'   => $contact->getMessage(),
    ];

   try {
      $emailObject = (new TemplatedEmail())
        ->from($this->emailFrom)
        ->to($this->emailTo)
        ->subject('Nouveau message depuis caroline-chapeau.com')
        ->htmlTemplate('email/contact.html.twig')
        ->context([
          'contact'     => $data,
          'submittedAt' => new \DateTimeImmutable(),
          'siteUrl'     => $this->siteUrl,
        ]);

      $this->mailer->send($emailObject);

    } catch (\Exception $e) {
      throw new \Exception($e->getMessage(), $e->getCode(), $e);

    }

  }
}
