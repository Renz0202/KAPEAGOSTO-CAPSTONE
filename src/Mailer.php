<?php

namespace App;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class Mailer {
	private PHPMailer $mail;
	private string $lastError = '';

	public function __construct(array $cfg) {
		$this->mail = new PHPMailer(true);
		$this->mail->isSMTP();
		$this->mail->Host       = $cfg['host'];
		$this->mail->SMTPAuth   = true;
		$this->mail->Username   = $cfg['username'];
		$this->mail->Password   = $cfg['password'];
		$this->mail->Port       = (int)$cfg['port'];
		$this->mail->CharSet    = 'UTF-8';
		$this->mail->SMTPDebug  = 0;

		$enc = strtolower((string)($cfg['encryption'] ?? 'tls'));
		if ($enc === 'ssl') {
			$this->mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
		} else {
			$this->mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
		}

		$this->mail->setFrom($cfg['from_email'], $cfg['from_name'] ?? '');
		if (!empty($cfg['reply_to'])) {
			$this->mail->addReplyTo($cfg['reply_to']);
		}
	}

	public function send(string $to, string $subject, string $htmlBody, ?string $altBody = null): bool {
		$this->lastError = '';
		try {
			$this->mail->clearAllRecipients();
			$this->mail->addAddress($to);
			$this->mail->isHTML(true);
			$this->mail->Subject = $subject;
			$this->mail->Body    = $htmlBody;
			$this->mail->AltBody = $altBody ?? strip_tags($htmlBody);
			return $this->mail->send();
		} catch (Exception $e) {
			$this->lastError = $e->getMessage();
			error_log('Mailer error: ' . $e->getMessage());
			return false;
		}
	}

	public function getLastError(): string {
		return $this->lastError;
	}
}
