# Security policy

Report a vulnerability privately to hello@indianopinion.org. Please do not open a public issue.

Include the version, what an attacker can do, and how to reproduce it. We answer within five working days and aim to ship a fix within 30 days of confirming the problem. We credit reporters in the CHANGELOG unless you ask us not to.

Supported versions: the latest 0.x release.

Areas we care most about: the OAuth flow and token storage, capability and nonce checks on admin forms and REST routes, transcript parsing (TTML in particular), escaping of model output in the draft, and anything that could publish a post without an editor.
