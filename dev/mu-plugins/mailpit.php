<?php
/**
 * Dev only: route all WordPress mail to Mailpit (http://localhost:8025).
 */
add_action(
	'phpmailer_init',
	static function ( $mailer ) {
		$mailer->isSMTP();
		$mailer->Host     = 'mailpit';
		$mailer->Port     = 1025;
		$mailer->SMTPAuth = false;
	}
);

// PHPMailer rejects the default "wordpress@localhost" sender.
add_filter(
	'wp_mail_from',
	static function ( $from ) {
		return 'wordpress@localhost' === $from ? 'wordpress@example.org' : $from;
	}
);
