<?php
/**
 * Plugin Name: Local Mailguard
 * Description: Verhindert, dass eine lokale Entwicklungsinstanz E-Mails an echte
 *              Empfänger sendet. Alle Mails werden auf eine Sink-Adresse
 *              umgeschrieben und über den lokalen Mailcatcher (Mailpit) ausgeliefert.
 * Version:     1.0.0
 * Author:      wpsync
 *
 * ---------------------------------------------------------------------------
 * WARUM ES DAS GIBT
 *
 * DDEV liefert Mails normalerweise per sendmail_path an Mailpit — sicher.
 * Das Loch entsteht, sobald ein Prod-Dump importiert wird, der ein SMTP-Plugin
 * (wp-mail-smtp, FluentSMTP, Post SMTP …) samt echter Zugangsdaten mitbringt:
 * Dann umgeht der Versand Mailpit und geht direkt zum Provider — an echte
 * Kundenadressen, mit .local-Links.
 *
 * DREI RIEGEL
 *   1. Filter `wp_mail`      — Empfänger, Cc und Bcc werden ersetzt.
 *   2. Filter `phpmailer_init` (Priorität PHP_INT_MAX, läuft also nach jedem
 *      SMTP-Plugin) — Empfänger erneut geleert, Transport zurück auf PHP mail()
 *      und damit auf sendmail_path → Mailpit. SMTP-Credentials neutralisiert.
 *   3. Filter `pre_http_request` — Aufrufe an bekannte Mail-API-Endpunkte
 *      (SendGrid, Brevo, Mailgun …) werden abgebrochen.
 *
 * FAIL-SAFE
 * Der Schutz ist immer aktiv und wird NICHT anhand der Umgebung erraten. Eine
 * fehlgeschlagene Umgebungserkennung würde den Schutz sonst lautlos abschalten.
 * Abschalten geht nur explizit über wp-config.php:
 *
 *     define( 'LOCAL_MAILGUARD_OFF', true );
 *
 * Landet diese Datei versehentlich auf einem Produktivsystem, blockiert sie
 * dort Mails. Das ist der ungefährlichere von beiden Fehlern.
 * ---------------------------------------------------------------------------
 */

defined( 'ABSPATH' ) || exit;

if ( defined( 'LOCAL_MAILGUARD_OFF' ) && LOCAL_MAILGUARD_OFF ) {
	return;
}

const LOCAL_MAILGUARD_SINK = 'blocked@mailguard.invalid';

/**
 * Sammelt Adressen aus einem wp_mail-Feld, das String (auch komma-separiert)
 * oder Array sein kann.
 *
 * @param mixed $value
 * @return string[]
 */
function local_mailguard_collect( $value ): array {
	$out = [];

	foreach ( (array) $value as $item ) {
		foreach ( explode( ',', (string) $item ) as $part ) {
			$part = trim( $part );
			if ( $part !== '' ) {
				$out[] = $part;
			}
		}
	}

	return $out;
}

/**
 * Riegel 1: Empfänger umschreiben, Cc/Bcc aus den Headern entfernen.
 *
 * Der Original-Empfänger wandert in den Betreff und in einen eigenen Header,
 * damit in Mailpit sofort sichtbar ist, wohin die Mail echt gegangen wäre.
 */
add_filter( 'wp_mail', static function ( array $args ): array {
	$recipients = local_mailguard_collect( $args['to'] ?? [] );

	// Cc/Bcc stecken in den Headern und würden sonst durchgehen.
	$headers = $args['headers'] ?? '';
	$kept    = [];

	if ( is_array( $headers ) ) {
		$lines = $headers;
	} else {
		$lines = preg_split( "/\r\n|\r|\n/", (string) $headers ) ?: [];
	}

	foreach ( $lines as $line ) {
		if ( preg_match( '/^\s*(cc|bcc)\s*:/i', (string) $line, $m ) ) {
			$recipients = array_merge(
				$recipients,
				local_mailguard_collect( substr( (string) $line, strpos( (string) $line, ':' ) + 1 ) )
			);
			continue;
		}
		if ( trim( (string) $line ) !== '' ) {
			$kept[] = $line;
		}
	}

	$recipients = array_values( array_unique( $recipients ) );

	$label = 'unbekannt';
	if ( $recipients ) {
		$label = $recipients[0];
		if ( count( $recipients ) > 1 ) {
			$label .= ' +' . ( count( $recipients ) - 1 );
		}
	}

	$kept[] = 'X-Mailguard: blocked';
	$kept[] = 'X-Mailguard-Original-To: ' . implode( ', ', $recipients );

	$args['to']      = LOCAL_MAILGUARD_SINK;
	$args['headers'] = $kept;
	$args['subject'] = '[DEV → ' . $label . '] ' . ( $args['subject'] ?? '' );

	if ( $recipients ) {
		error_log( sprintf(
			'[local-mailguard] Empfänger umgeschrieben: %s → %s | Betreff: %s',
			implode( ', ', $recipients ),
			LOCAL_MAILGUARD_SINK,
			$args['subject']
		) );
	}

	return $args;
}, PHP_INT_MAX );

/**
 * Riegel 2: läuft nach jedem SMTP-Plugin und setzt Transport plus Empfänger
 * endgültig zurück.
 */
add_action( 'phpmailer_init', static function ( $phpmailer ): void {
	if ( ! is_object( $phpmailer ) ) {
		return;
	}

	try {
		$phpmailer->clearAllRecipients();
		$phpmailer->addAddress( LOCAL_MAILGUARD_SINK );

		// Zurück auf PHP mail() — nutzt sendmail_path aus der php.ini und
		// damit den Mailcatcher, den Local pro Site einrichtet.
		$phpmailer->isMail();

		// SMTP-Zugangsdaten aus importierten Prod-Dumps unbrauchbar machen.
		$phpmailer->Host       = '';
		$phpmailer->SMTPAuth   = false;
		$phpmailer->Username   = '';
		$phpmailer->Password   = '';
		$phpmailer->SMTPSecure = '';
	} catch ( \Throwable $e ) {
		error_log( '[local-mailguard] phpmailer_init: ' . $e->getMessage() );
	}
}, PHP_INT_MAX );

/**
 * Riegel 3: Versand über HTTP-APIs abbrechen. Best effort — deckt die
 * verbreiteten Anbieter ab, kann aber keinen unbekannten Endpunkt kennen.
 */
add_filter( 'pre_http_request', static function ( $preempt, $args, $url ) {
	$host = strtolower( (string) parse_url( (string) $url, PHP_URL_HOST ) );

	if ( $host === '' ) {
		return $preempt;
	}

	$blocked = [
		'api.sendgrid.com',
		'api.brevo.com',
		'api.sendinblue.com',
		'api.mailgun.net',
		'api.eu.mailgun.net',
		'api.postmarkapp.com',
		'api.sparkpost.com',
		'api.mailjet.com',
		'api.resend.com',
		'api.smtp2go.com',
		'mandrillapp.com',
		'api.mailchimp.com',
		'email.us-east-1.amazonaws.com',
		'api.elasticemail.com',
		'api.mailersend.com',
	];

	foreach ( $blocked as $needle ) {
		if ( $host === $needle || str_ends_with( $host, '.' . $needle ) ) {
			error_log( '[local-mailguard] HTTP-Mailversand blockiert: ' . $url );

			return new WP_Error(
				'local_mailguard_blocked',
				'Local Mailguard: Mailversand über externe API ist auf dieser Entwicklungsinstanz blockiert.'
			);
		}
	}

	return $preempt;
}, PHP_INT_MAX, 3 );

/**
 * Sichtbarer Hinweis im Backend, damit der aktive Schutz nicht überrascht.
 */
add_action( 'admin_notices', static function (): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	printf(
		'<div class="notice notice-info"><p><strong>Local Mailguard aktiv.</strong> '
		. 'Alle E-Mails werden auf <code>%s</code> umgeleitet und erreichen keine echten '
		. 'Empfänger. Sichtbar im Mailcatcher dieser Local-Instanz.</p></div>',
		esc_html( LOCAL_MAILGUARD_SINK )
	);
} );
