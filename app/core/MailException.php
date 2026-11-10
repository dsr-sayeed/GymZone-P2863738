<?php
/** The email could not be handed to the mail service (for example, Mailpit is not running). */
// Thrown by Mailer when an email cannot be sent; the caller decides whether that matters.
final class MailException extends RuntimeException
{
}
