# Security

## Reporting a problem

Please report security problems privately, using **Report a vulnerability** on this repository's Security tab (GitHub's private vulnerability reporting). Please don't open a public issue. We'll reply as soon as we can and credit you in the fix if you'd like.

Helpful details: what you found, where it is (file and line, or the request), and what someone could do with it. Please don't test against the live connector beyond your own normal use, and don't try to use up its shared limits.

## What the connector can and can't do

- It only **reads**: it searches a public library of public-domain and Creative Commons books. Nothing a user does through it changes the library or the server.
- It stores **no accounts, documents or conversations**. Sign-in happens at WorkOS. The connector only checks each token and keeps a pseudonymous id for daily limits.
- **Secrets are never in this repository.** The keys and passwords live only on the server and on the build machine.
- Its security doesn't depend on keeping the code secret:
  - sign-in tokens are checked cryptographically;
  - limits and spending caps are enforced on the server;
  - the server runs in a locked-down sandbox that can only reach the two outside services it needs.

## Reviews

The latest security review, with what was found and what was fixed, is in [docs/security-review.md](docs/security-review.md).
