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
- PHPStan at level max with an empty baseline, types only: `Data` reads the answers (the same
  values as the `(string)` / `(int)` casts, an array giving "" / 0 rather than "Array" / 1), the
  factory reads its options with `Config::string()` (the truthy checks kept), a post's `privacy`
  option that is not a scalar is sent as "" rather than "Array".

### Fixed

- An upload answered without the video's id is no post: no id, an empty one or one that is not a
  string nor a number is a `ProviderException` ("YouTube answered the upload without the video's
  id."), where it was a publication with the id `""` (and a warning) or `"Array"`, its permalink to
  no video; a video read back by `status()` with an id that is not one keeps the id asked for.
- An answer of another shape than the API's is a `ProviderException` ("YouTube answered in another
  shape than its API's."), not a `TypeError`: a video read back, a channel or its snippet that is
  not an object, a feed's items that are not a list, a title, thumbnail URL, `publishAt`,
  `publishedAt`, duration, video id, next page token or refresh token that is a list; an uploads
  playlist that is a list is not sent as a nested query field. An access token that is a list is no
  token ("No token in Google's answer.", where `"Array"` was taken for one); an error's reason that
  is a list is no code (the error's own exception, where its constructor threw a `TypeError`).
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
