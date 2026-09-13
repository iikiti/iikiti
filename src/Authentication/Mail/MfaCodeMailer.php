<?php

namespace iikiti\CMS\Authentication\Mail;

use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

/**
 * Sends verification codes by e-mail.
 */
class MfaCodeMailer implements MfaCodeMailerInterface
{
	public function __construct(
		private readonly MailerInterface $mailer,
		private readonly string $senderEmail,
	) {
	}

	public function sendCode(string $email, string $code): void
	{
		$message = (new Email())->
			from($this->senderEmail)->
			to($email)->
			subject('Your verification code')->
			text(sprintf(
				'Your verification code is %s. It expires shortly, so enter it as soon as possible.',
				$code
			));

		$this->mailer->send($message);
	}
}
