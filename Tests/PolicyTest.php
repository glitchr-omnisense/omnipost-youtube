<?php

namespace Omnipost\YouTube\Tests;

use Omnipost\Exception\ProviderException;
use Omnipost\Exception\RateLimitedException;
use Omnipost\Exception\UsageLimitReachedException;
use Omnipost\Model\Media;
use Omnipost\Model\Post;
use Omnipost\Model\Variant;
use Omnipost\Platform;
use Omnipost\Registry;
use Omnipost\YouTube\YouTubeProviderFactory;
use Omnisense\Contracts\Policy\Unit;
use Omnisense\Contracts\Policy\UsagePolicy;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * YouTube's usage policy (policy.json) through the registry - the Video
 * Uploads bucket, the 10,000 units of the rest - and an upload sent once
 * whatever the answer: its opening call answered 429, its bytes answered
 * 503, Google's quotaExceeded.
 */
final class PolicyTest extends TestCase
{
    /** @var list<string> method and path of every request sent */
    private array $sent = [];

    private string $video;

    protected function setUp(): void
    {
        $this->video = (string) tempnam(sys_get_temp_dir(), 'omnipost-yt');
        file_put_contents($this->video, str_repeat('v', 1000));
    }

    protected function tearDown(): void
    {
        @unlink($this->video);
    }

    /** @param array<string, MockResponse> $answers by "METHOD /path" */
    private function registry(array $answers = []): Registry
    {
        $http = new MockHttpClient(function (string $method, string $url) use ($answers): MockResponse {
            $key = $method.' '.parse_url($url, \PHP_URL_PATH);
            $this->sent[] = $key;

            return $answers[$key] ?? match ($key) {
                'POST /upload/youtube/v3/videos' => new MockResponse('', ['response_headers' => ['Location' => 'https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable&upload_id=XYZ']]),
                'PUT /upload/youtube/v3/videos' => self::json(200, ['id' => 'vid1', 'status' => ['uploadStatus' => 'uploaded', 'privacyStatus' => 'private']]),
                default => self::json(200, ['items' => []]),
            };
        });

        return new Registry([new YouTubeProviderFactory($http)], ['youtube' => ['factory' => 'youtube', 'options' => ['access_token' => 'ya29.token', 'api_key' => 'AIza-key']]]);
    }

    /**
     * @param array<string, mixed>  $data
     * @param array<string, string> $headers
     */
    private static function json(int $status, array $data, array $headers = []): MockResponse
    {
        return new MockResponse((string) json_encode($data), ['http_code' => $status, 'response_headers' => ['Content-Type' => 'application/json'] + $headers]);
    }

    private function short(): Post
    {
        return Post::reel(Media::video('https://cdn.example/strauss.mp4', $this->video, 42.0, 1080, 1920), 'Strauss, tonight.')
            ->withVariant(new Variant(Platform::YOUTUBE, title: 'Strauss, Sonata'))->for(Platform::YOUTUBE);
    }

    public function testThePolicyFileReadsAsDeclared(): void
    {
        $policy = UsagePolicy::fromFile(__DIR__.'/../policy.json');

        self::assertSame('youtube', $policy->provider);
        self::assertSame('2026-11-10', $policy->provenance->verifiedAt);
        [$uploads, $units] = $policy->limitsFor('publish');
        self::assertSame(100, $uploads->max);
        self::assertSame(10000, $units->max);
        self::assertSame(Unit::Units, $units->unit);
        self::assertSame(0, $units->cost('publish'), 'videos.insert is in its own bucket');
        self::assertSame(50, $units->cost('delete'));
        self::assertSame(3, $units->cost('feed'));
        self::assertSame(2592000, $policy->rights->caching->maxTtl);
        self::assertTrue($policy->rights->attribution->required);
    }

    public function testTheUnitsAreCountedByOperation(): void
    {
        $registry = $this->registry();
        $registry->publisher('youtube')->delete('vid1');

        self::assertSame(9950, $registry->guard('youtube')->remaining('status'));
        self::assertSame(199, $registry->guard('youtube')->remaining('delete'));
    }

    public function testTheHundredAndFirstUploadOfTheDayIsNeverSent(): void
    {
        $registry = $this->registry();
        $publisher = $registry->publisher('youtube');
        for ($i = 0; $i < 100; ++$i) {
            $publisher->publish($this->short());
        }
        self::assertCount(200, $this->sent);

        $this->expectException(UsageLimitReachedException::class);
        try {
            $publisher->publish($this->short());
        } finally {
            self::assertCount(200, $this->sent, 'nothing sent');
        }
    }

    public function testAnUploadOpenedWithA429IsSentOnceAndTheNextCallsWait(): void
    {
        $registry = $this->registry(['POST /upload/youtube/v3/videos' => self::json(429, ['error' => ['code' => 429, 'message' => 'Too many requests.', 'errors' => [['reason' => 'rateLimitExceeded']]]], ['Retry-After' => '30'])]);
        $publisher = $registry->publisher('youtube');
        try {
            $publisher->publish($this->short());
            self::fail('A 429 is an error.');
        } catch (RateLimitedException $e) {
            self::assertInstanceOf(ProviderException::class, $e);
            self::assertSame('rateLimitExceeded', $e->providerCode);
            self::assertSame(30, $e->retryAfter);
        }
        self::assertSame(['POST /upload/youtube/v3/videos'], $this->sent, 'the video inserted once, not replayed');

        $this->expectException(UsageLimitReachedException::class);
        try {
            $publisher->status('vid1');
        } finally {
            self::assertCount(1, $this->sent, 'held while the wait lasts');
        }
    }

    public function testBytesAnswered503AreNotSentAgainNorTheUploadStartedAgain(): void
    {
        $registry = $this->registry(['PUT /upload/youtube/v3/videos' => self::json(503, ['error' => ['code' => 503, 'message' => 'Backend Error', 'errors' => [['reason' => 'backendError']]]])]);
        try {
            $registry->publisher('youtube')->publish($this->short());
            self::fail('A 503 is an error.');
        } catch (ProviderException $e) {
            self::assertNotInstanceOf(RateLimitedException::class, $e);
            self::assertSame(503, $e->status);
        }
        self::assertSame(['POST /upload/youtube/v3/videos', 'PUT /upload/youtube/v3/videos'], $this->sent);
        self::assertSame(99, $registry->guard('youtube')->remaining('publish'), 'counted: YouTube may have the video');
    }

    public function testQuotaExceededIsARateLimitedProviderException(): void
    {
        $registry = $this->registry(['GET /youtube/v3/videos' => self::json(403, ['error' => ['code' => 403, 'message' => 'The request cannot be completed because you have exceeded your quota.', 'errors' => [['reason' => 'quotaExceeded']]]])]);

        try {
            $registry->publisher('youtube')->status('vid1');
            self::fail('Over quota.');
        } catch (RateLimitedException $e) {
            self::assertSame(403, $e->status);
            self::assertSame('quotaExceeded', $e->providerCode);
            self::assertNull($e->retryAfter, 'Google gives no wait: the quota resets at midnight Pacific time');
        }
    }
}
