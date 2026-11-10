<?php
/** Thrown when an administrator change would cancel existing bookings; the change is rolled back until confirmed. */
final class ConfirmationRequired extends UserError
{
    // $affected = how many bookings the change would cancel; it is shown in the confirmation dialog.
    public function __construct(public readonly int $affected, string $message)
    {
        parent::__construct($message, 409);
    }
}
