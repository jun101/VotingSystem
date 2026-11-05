# Security Policy

## Reporting a vulnerability

Please do **not** open a public issue for a security problem.

Report it privately through GitHub: open the **Security** tab of this repository and choose
**Report a vulnerability** (<https://github.com/jun101/VotingSystem/security/advisories/new>).

Include what you found, how to reproduce it, and which version you tested. You will get an answer
as soon as the maintainer can read it, and the problem will be fixed before it is made public.

## Supported versions

Only the latest release receives security fixes.

## What matters most here

This system keeps ballots secret. Anything that lets someone link a voter to a vote, read a vote
count before an election is closed, read another institution's data, or reach the audit records is
treated as critical.
