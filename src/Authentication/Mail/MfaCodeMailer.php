<?php

namespace iikiti\CMS\Authentication\Mail;

use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

/**
 * Sends verification codes by e-mail.
 */
#[Autoconfigure(lazy: true)]
class MfaCodeMailer implements MfaCodeMailerInterface
{
	public function __construct(
		private readonly MailerInterface $mailer,
		#[Autowire('%mfa.sender_email%')]
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
