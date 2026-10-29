# Changelog

## [Unreleased]

### Fixed

- A token without the scope a call needs (HTTP 403, reason `insufficientPermissions`) is an
  `UnauthorizedException`, as its docblock says ("lacks a permission: the account must be connected
  again"), where it was a plain `ProviderException`.
- The post's link (`Post::$link`) goes into the video's description, on its own line before the
  hashtags: it was dropped.
