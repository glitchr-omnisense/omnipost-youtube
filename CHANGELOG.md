# Changelog

## [Unreleased]

### Added

- `policy.json` (`extra.omnisense.policy`), read 2026-11-10: videos.insert's own bucket, 100 a day
  (`publish`), and 10,000 units a day for the rest by cost (`status` 1, `account` 1, `feed` 3,
  `delete` 50); caching 30 days at most, YouTube credited as the source, no reselling nor
  redistribution. `YouTubeProvider` declares it (`DeclaresUsagePolicyInterface`).
- `Api` takes the provider's wait (its last argument, from `Config::backoff()`): no call while one
  lasts; a 429 and Google's quota and rate reasons (`RATE_LIMIT_REASONS`) are a
  `RateLimitedException`, a `ProviderException` as before, a Retry-After holding the next calls.
  Nothing retries: an upload is opened once and its bytes sent once (`Tests/PolicyTest.php`: a 429
  on the opening call, a 503 on the bytes, the 101st upload never sent).
- PHPStan at level max (`phpstan.neon`), the 112 errors there were in `phpstan-baseline.neon`.

### Fixed

- A failure no longer carries the credentials: "could not be reached" quoted the HTTP client's message
  (the URL, a key in its query) and wrapped the client's own exception, and every trace kept the
  options (the `Authorization` bearer, the `X-Goog-Api-Key`) or a response holding them in its
  arguments. The message and the previous - now a copy of the client's exception, the same class
  and code - have the key, the client secret and the tokens masked, and no exception the API throws
  keeps its trace's arguments. Needs omnisense/omnipost's `Http\Redaction`.
- A token without the scope a call needs (HTTP 403, reason `insufficientPermissions`) is an
  `UnauthorizedException`, as its docblock says ("lacks a permission: the account must be connected
  again"), where it was a plain `ProviderException`.
- The post's link (`Post::$link`) goes into the video's description, on its own line before the
  hashtags: it was dropped.
