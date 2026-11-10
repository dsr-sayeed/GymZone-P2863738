<?php
/** An error whose message is safe and helpful to show to the user (validation, booking rules). */
// Services throw UserError when a rule is broken. Http::api() turns it into a JSON error message.
class UserError extends RuntimeException
{
    // $status is the HTTP status code sent with the message: 422 (invalid input) unless another one is given.
    public function __construct(string $message, private int $status = 422)
    {
        parent::__construct($message);
    }

    public function status(): int
    {
        return $this->status;
    }
}
