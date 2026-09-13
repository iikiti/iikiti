<?php

namespace iikiti\CMS\Authentication\Mail;

/**
 * Delivers multi-factor verification codes to users.
 *
 * Kept behind an interface so the delivery channel can be swapped and so the
 * workflow steps can be tested without a real mailer.
 */
interface MfaCodeMailerInterface
{
	public function sendCode(string $email, string $code): void;
}
