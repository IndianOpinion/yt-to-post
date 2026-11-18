# Contributing

Thank you for helping. Small, focused pull requests are easiest to review.

## Setup

PHP 8.1 or later and Composer.

```sh
composer install
composer check   # phpcs, phpstan, phpunit
```

Tests run without WordPress: `tests/stubs/wordpress.php` stands in for the WordPress functions the plugin uses, and YouTube, Google OAuth and the model are fakes. No test touches the network. Tests that define constants run in a separate process.

## Style

- PSR-12, checked by `vendor/bin/phpcs`.
- PHPStan level 6, `vendor/bin/phpstan analyse`.
- `declare(strict_types=1);` in every file. Code must run on PHP 8.1: no readonly classes, no DNF types.
- Typed exceptions, never `@`, no shell, no eval. Escape every output.
- Plain English, short sentences, British spelling. No em dashes.

## Rules that do not bend

- YouTube only through the documented Data API v3 with the site's own OAuth or API key, or a transcript the user supplies. No watch-page fetching, no unofficial transcript libraries.
- Drafts only. Nothing may publish or schedule a post.
- Every paid call checks the kill switch and the daily caps first.

## Pull requests

- One topic per pull request, with a test for new behaviour.
- Update `CHANGELOG.md` under Unreleased, and `docs/ARCHITECTURE.md` if you change a decision.
- A DCO-style sign-off is not required.
