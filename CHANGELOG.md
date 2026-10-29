# Changelog

## [Unreleased]

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
