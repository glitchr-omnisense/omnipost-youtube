# omnipost/youtube

YouTube for [omnisense/omnipost](https://github.com/glitchr-omnisense/omnipost): the channel and its
uploads read with an API key, videos and Shorts uploaded, followed and deleted through OAuth,
through the YouTube Data API v3. The provider calls:

| Operation | Endpoint |
|---|---|
| the channel | `GET /channels?part=snippet,statistics,contentDetails` (`id=` or `mine=true`) |
| the feed | `GET /playlistItems?part=snippet,contentDetails` (the uploads playlist), then `GET /videos?part=contentDetails,statistics` |
| upload | `POST <upload_uri>/videos?uploadType=resumable&part=snippet,status` (the metadata), then `PUT` of the bytes to the URL answered |
| status | `GET /videos?part=status,snippet&id=` |
| delete | `DELETE /videos?id=` |
| tokens | `POST https://oauth2.googleapis.com/token` (`authorization_code`, `refresh_token`) |
| consent | `https://accounts.google.com/o/oauth2/v2/auth` |

on `https://www.googleapis.com/youtube/v3` and `https://www.googleapis.com/upload/youtube/v3`
(`Api::BASE_URI`, `Api::UPLOAD_URI`, the `base_uri` and `upload_uri` options). There is no
sandbox: a test channel is a real channel.

## Installation

```sh
composer require omnisense/omnipost omnipost/youtube
```

Requires PHP 8.2, `omnisense/omnipost` (1.x) and `symfony/http-client` (6.4, 7 or 8). In a Symfony
application with `Omnipost\Bridge\Symfony\OmnipostBundle`, the factory is registered as soon as the
package is installed.

## Credentials

- A Google Cloud project with the **YouTube Data API v3** enabled.
- To read: an **API key** (APIs & Services, Credentials) and the channel's id (`UC...`, in YouTube
  Studio, Settings, Channel, Advanced, or the channel's URL).
- To upload, delete, or read as the channel: an **OAuth client** (type *Web application*, the site's
  redirect URI declared), the consent screen with the scopes `youtube.upload` and
  `youtube.readonly`, and the channel's account among its test users while the app is in
  *Testing*. A refresh token of an app in Testing dies after 7 days: publish the consent screen for
  one that lasts. Then `authorizationUrl()` and `exchange()`
  ([Tokens](https://github.com/glitchr-omnisense/omnipost/blob/1.x/docs/usage-tokens.md)); the refresh token answered is the `refresh_token` option.
- **Quota**: 10 000 units a day per project by default (the README's figures: a read costs 1 unit,
  an upload about 100).

## Options

| Option | Type | Default | Required | |
|---|---|---|---|---|
| `api_key` | string | none | to read with a key | sent as `X-Goog-Api-Key` |
| `channel_id` | string | none | no | the channel read; without it, the OAuth token's own (`mine=true`) |
| `client_id` | string | none | to upload, delete, connect | the Google OAuth client |
| `client_secret` | string | none | the same | |
| `refresh_token` | string | none | to upload and delete (or `access_token`) | access tokens are drawn from it |
| `access_token` | string | none | no | a token at hand, used until refused |
| `privacy` | string | `private` | no | what an upload is set to: `private`, `unlisted`, `public` |
| `category_id` | string | none | no | the video category (`10` is Music) |
| `base_uri` | string | `https://www.googleapis.com/youtube/v3` | no | |
| `upload_uri` | string | `https://www.googleapis.com/upload/youtube/v3` | no | |

No option is required to build the provider (`null` and `''` are as if not given). Post options
read (`Post::$options`, or YouTube's variant's): `privacy`, `category_id`.

## Capabilities

| Call | Supported |
|---|---|
| `publish()` | `REEL` (YouTube decides whether it is a Short) and `VIDEO`; one video; a title required (100 characters), the caption with its hashtags as the description (5000), 30 hashtags; formats `video/mp4`, `video/quicktime`, `video/webm` (or `mp4`, `mov`, `webm`); a `REEL` at most 180 s |
| scheduling | yes: `scheduledAt` in the future uploads private with `publishAt` |
| `status()` | yes |
| `delete()` | yes, with OAuth |
| `account()`, `feed()` | yes, with a key and a channel id, or with OAuth for the token's channel |
| `refresh()`, `token()` | yes |
| `authorizationUrl()`, `exchange()` | yes (offline access, consent asked again) |
| `IMAGE`, `CAROUSEL`, `STORY` | no: refused by the validator |

## Behaviour

**Upload.** `publish()` validates (`InvalidPostException`, nothing sent), requires the OAuth
credentials (`InvalidConfigException` before the file is read), takes the file from
`Media::$path` or downloads `Media::$url` to a temporary file (300-second idle timeout), opens a
resumable upload with the metadata (title, description `Post::text()`, tags without `#`, category,
privacy, `selfDeclaredMadeForKids: false`, `publishAt` when scheduled), then `PUT`s the bytes
with the media's mime type (else the download's, else `video/*`), with no overall time limit. The
whole field-by-field mapping: [Publishing](https://github.com/glitchr-omnisense/omnipost/blob/1.x/docs/usage-publishing.md#youtube-one-upload).

**States.** From `status.uploadStatus`: `processed` is `PUBLISHED` - or `PENDING` while private
with a `publishAt` to come; `failed`, `rejected`, `deleted` are `FAILED` with the reason;
anything else `PROCESSING`. The permalink (`https://www.youtube.com/watch?v=<id>`) is set from the
first answer. `PUBLISHED` means processed: a private video is `PUBLISHED` too. The videos of a
project that has not passed Google's audit are locked private whatever `privacy` says.

**Tokens.** The access token is drawn from the refresh token before the first call that needs one,
and again within 60 seconds of its end; Google's answer gives its end (`expires_in`) and may give a
new refresh token, kept. An `access_token` option has no known end. `token()` is null until one is
drawn.

**Reading.** The channel is read once per provider object. A video of 180 s at most is a `REEL` in
the feed, a longer one a `VIDEO`; `url` is the embed player, `caption` the title, the thumbnail
the largest given (`maxres`, `high`, `standard`, `medium`, `default`); `maxResults` between 1
and 50. Subscribers hidden by the channel are null. Details: [Reading](https://github.com/glitchr-omnisense/omnipost/blob/1.x/docs/usage-reading.md#youtube).

## Example

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Omnipost\Model\Media;
use Omnipost\Model\Post;
use Omnipost\Model\Variant;
use Omnipost\Platform;
use Omnipost\YouTube\YouTubeProviderFactory;
use Symfony\Component\HttpClient\HttpClient;

$youtube = (new YouTubeProviderFactory(HttpClient::create()))->create([
    'api_key' => getenv('YOUTUBE_API_KEY'),
    'channel_id' => getenv('YOUTUBE_CHANNEL_ID'),
    'client_id' => getenv('YOUTUBE_CLIENT_ID'),
    'client_secret' => getenv('YOUTUBE_CLIENT_SECRET'),
    'refresh_token' => getenv('YOUTUBE_REFRESH_TOKEN'),
]);

$post = Post::reel(Media::video('https://site.example/media/reel.mp4', __DIR__.'/reel.mp4', 42.0, 1080, 1920), 'Strauss, tonight.', null, ['violin'])
    ->withVariant(new Variant(Platform::YOUTUBE, title: 'Strauss, Sonata in E-flat - live', options: ['privacy' => 'unlisted']));

$publication = $youtube->publish($post->for(Platform::YOUTUBE));   // PROCESSING, the video's id
$publication = $youtube->status($publication->id);                // PUBLISHED once processed

foreach ($youtube->feed(null, 5)->items as $item) {
    echo $item->publishedAt->format('Y-m-d'), ' ', $item->kind->value, ' ', $item->caption, ' ', $item->permalink, "\n";
}
```

The same calls were run on 2026-10-26 on a `MockHttpClient` (the requests and answers are in
[Publishing](https://github.com/glitchr-omnisense/omnipost/blob/1.x/docs/usage-publishing.md#one-post-every-network)); YouTube itself was not called. In
Symfony:

```yaml
omnipost:
    providers:
        youtube:
            factory: youtube
            options:
                api_key: '%env(default::YOUTUBE_API_KEY)%'
                channel_id: '%env(default::YOUTUBE_CHANNEL_ID)%'
                client_id: '%env(default::YOUTUBE_CLIENT_ID)%'
                client_secret: '%env(default::YOUTUBE_CLIENT_SECRET)%'
                refresh_token: '%env(default::YOUTUBE_REFRESH_TOKEN)%'
                privacy: private
                category_id: '10'
```

## Errors

| Answer | Exception |
|---|---|
| HTTP 401; reason `invalid_grant`, `invalid_client`, `unauthorized_client`, `authError`, `insufficientPermissions` (a 403: a token without the scope the call needs) | `UnauthorizedException` (`providerCode` the reason) |
| any other error (`quotaExceeded`, 404...) | `ProviderException` with the status and the API's first reason |
| transport error | `ProviderException` (`YouTube could not be reached: ...`), chained |
| a video not found by `status()` | `ProviderException` (404, `videoNotFound`) |
| the channel not found | `ProviderException` (404, `channelNotFound`) |
| a credential missing for the call | `InvalidConfigException` naming it |
| a post the network would refuse | `InvalidPostException`, nothing sent |

## Verified, and not

The package's tests (`Tests/YouTubeProviderTest.php`, 12 tests) pass (2026-10-26, PHP 8.4) on
answers written in the shapes the Data API v3 documents - not recorded from YouTube. No upload or
read was run against YouTube for this package (neither the README nor the history states one).

## Responsibility

This package is an independent, unofficial client, not affiliated with, endorsed by or sponsored by
Google LLC (YouTube). Whoever runs it complies with the
[YouTube API Services Terms of Service](https://developers.google.com/youtube/terms/api-services-terms-of-service);
see the README's Responsibility section.
