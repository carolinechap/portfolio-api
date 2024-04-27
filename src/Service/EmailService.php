<?php

namespace App\Service;
use App\Entity\Contact;
use App\Model\EmailModel;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use function getenv;

/**
 * Class EmailService.
 */
class EmailService
{

  /** @var MailerInterface $mailer */
  protected MailerInterface $mailer;

  public function __construct(MailerInterface  $mailer) {
    $this->mailer = $mailer;
  }

  /**
   * Sends an email using the provided Contact information.
   *
   * @param Contact $contact The contact details for the email.
   * @throws \Exception If an error occurs during email sending.
   * @return void
   */
  public function sendMail(Contact $contact) : void{

  // $toEmail = getenv('EMAIL_TO');
   $toEmail ='kaluolin.maozi@gmail.com';
   $message = nl2br($contact->getMessage());

   try{
      $emailObject = (new TemplatedEmail())
        ->from($contact->getEmail())
        ->to($toEmail)
        ->subject('Contact submission')
        ->htmlTemplate('email/contact.html.twig')
        ->context([
          'contact' => $contact
        ]);

      $this->mailer->send($emailObject);

    } catch (\Exception $e) {
      throw new \Exception($e->getMessage(), $e->getCode(), $e);

    }

  }

}
