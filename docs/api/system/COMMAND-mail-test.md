# COMMAND mail:test

Sends one plain message with the installation's mail settings, so an operator sees at once whether the server can
send email. Used by the online installer and by `votectl mail test EMAIL`.

| | |
|---|---|
| Slice | 19c (step E) |
| Caller | The operator, on the server (`php artisan mail:test EMAIL`) |
| Network | None from the web: a command only |

## Request

`php artisan mail:test {to}`: `to` is an email address (case ignored, trimmed).

## Scenarios

| # | Scenario | Exit | Output |
|---|---|---|---|
| 1 | The address is valid and the mail server accepts the message | 0 | `Test message sent to <address> through <host>:<port>.` |
| 2 | The address is not valid | 1 | `This is not a valid email address.` Nothing is sent |
| 3 | The mail server refuses or cannot be reached | 1 | `The message could not be sent through <host>:<port>: <ExceptionClass>` and the server's reason, at most 300 characters |

## Notes

- The output names the host and the port only: never the user name, the password or the sender.
- The message has no link, no code and no personal data: a fixed subject (`VoteSystem: test message`) and two lines.
- It is sent at once (no queue), so a failure is seen where it happens.
