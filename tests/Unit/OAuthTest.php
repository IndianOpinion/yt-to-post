<?php

declare(strict_types=1);

namespace IndianOpinion\YtToPost\Tests\Unit;

use IndianOpinion\YtToPost\Exception\AuthException;
use IndianOpinion\YtToPost\Exception\NetworkException;
use IndianOpinion\YtToPost\Jobs\JobState;
use IndianOpinion\YtToPost\Tests\Support\FakeHttp;
use IndianOpinion\YtToPost\Tests\TestCase;
use IndianOpinion\YtToPost\YouTube\AccessTokens;
use IndianOpinion\YtToPost\YouTube\Connection;
use IndianOpinion\YtToPost\YouTube\OAuthClient;

/** PRD 4.11: OAuth connection (T74 to T79). */
final class OAuthTest extends TestCase
{
    /** @return array<string,string> */
    private function authQuery(int $user = 1): array
    {
        $this->settings(['client_id' => 'cid.apps', 'client_secret' => 'secret-value']);
        $url = $this->services->oauth()->authorizationUrl($user);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        /** @var array<string,string> $q */
        return $q;
    }

    public function testT74AuthorizationUrl(): void
    {
        $q = $this->authQuery();
        $this->assertSame('cid.apps', $q['client_id']);
        $this->assertSame('code', $q['response_type']);
        $this->assertSame('offline', $q['access_type']);
        $this->assertSame('consent', $q['prompt']);
        $this->assertSame(OAuthClient::SCOPE, $q['scope']);
        $this->assertSame('S256', $q['code_challenge_method']);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $q['code_challenge']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $q['state']);
        $this->assertSame(OAuthClient::redirectUri(), $q['redirect_uri']);
        $stored = get_transient(OAuthClient::STATE_PREFIX . $q['state']);
        $this->assertSame(1, $stored['user']);
        $this->assertSame(rtrim(strtr(base64_encode(hash('sha256', $stored['verifier'], true)), '+/', '-_'), '='), $q['code_challenge']);
        $this->assertSame($this->clock->now() + 600, \WpStub::$transients[OAuthClient::STATE_PREFIX . $q['state']][1]);
    }

    public function testT75CallbackChecks(): void
    {
        $oauth = $this->services->oauth();
        $q = $this->authQuery();
        $cases = [
            'missing state' => [['code' => 'c'], 1, true, 'expired or did not come from you'],
            'wrong state' => [['state' => str_repeat('a', 32), 'code' => 'c'], 1, true, 'expired'],
            'other user' => [['state' => $q['state'], 'code' => 'c'], 2, true, 'expired'],
        ];
        foreach ($cases as $name => [$query, $user, $can, $msg]) {
            try {
                $oauth->handleCallback($query, $user, $can);
                $this->fail($name);
            } catch (AuthException $e) {
                $this->assertStringContainsString($msg, $e->getMessage(), $name);
            }
        }
        $this->assertFalse(get_transient(OAuthClient::STATE_PREFIX . $q['state']), 'state is single use');

        $q = $this->authQuery();
        try {
            $oauth->handleCallback(['state' => $q['state'], 'code' => 'c'], 1, false);
            $this->fail('no capability');
        } catch (AuthException $e) {
            $this->assertStringContainsString('not allowed', $e->getMessage());
        }
        $q = $this->authQuery();
        try {
            $oauth->handleCallback(['state' => $q['state'], 'error' => 'access_denied'], 1, true);
            $this->fail('error parameter');
        } catch (AuthException $e) {
            $this->assertStringContainsString('access_denied', $e->getMessage());
        }
        $this->assertSame([], $this->http->requests);
        $this->assertSame(Connection::DISCONNECTED, Connection::state());

        $q = $this->authQuery();
        $this->http->on('#oauth2\.googleapis\.com/token#', FakeHttp::json(['access_token' => 'a', 'expires_in' => 3600]));
        try {
            $oauth->handleCallback(['state' => $q['state'], 'code' => 'c'], 1, true);
            $this->fail('no refresh token');
        } catch (AuthException $e) {
            $this->assertStringContainsString('Remove the app\'s access', $e->getMessage());
        }
        $this->assertSame(Connection::DISCONNECTED, Connection::state(), 'stores nothing');
        $body = [];
        parse_str((string) $this->http->requests[0]['body'], $body);
        $this->assertSame('authorization_code', $body['grant_type']);
        $this->assertArrayHasKey('code_verifier', $body);

        $q = $this->authQuery();
        $this->http->on('#oauth2\.googleapis\.com/token#', FakeHttp::json(['access_token' => 'a2', 'refresh_token' => 'r2', 'expires_in' => 3600]));
        $this->http->on('#/youtube/v3/channels#', FakeHttp::json(['items' => [['id' => self::OWN, 'snippet' => ['title' => 'Own']]]]));
        $msg = $oauth->handleCallback(['state' => $q['state'], 'code' => 'c'], 1, true, $this->services->youtube());
        $this->assertStringContainsString('connected', $msg);
        $c = Connection::get();
        $this->assertSame(Connection::CONNECTED, $c['state']);
        $this->assertStringNotContainsString('r2', $c['token'], 'refresh token stored encrypted');
        $this->assertSame('r2', $this->vault->decrypt($c['token']));
        $this->assertSame('a2', get_transient(AccessTokens::TRANSIENT));
        $this->assertSame($this->clock->now() + 3540, \WpStub::$transients[AccessTokens::TRANSIENT][1], 'expires 60 seconds early');
    }

    public function testT76RefreshOnceOn401AndInvalidGrant(): void
    {
        $this->connect();
        $this->http->on('#oauth2\.googleapis\.com/token#', FakeHttp::json(['access_token' => 'fresh', 'expires_in' => 3600]));
        $this->http->on('#/youtube/v3/videos#', FakeHttp::apiError(401, 'authError'), FakeHttp::json(['items' => [$this->item()]]));
        $job = $this->runner()->pipeline()->advance($this->createJob());
        $this->assertSame(JobState::CAPTIONS, $job->state);
        $this->assertCount(1, $this->http->urls('oauth2'));
        $this->assertSame('Bearer fresh', $this->http->requests[2]['headers']['Authorization']);

        $this->setUp();
        $this->connect();
        $waiting = $this->createJob('bbbbbbbbbbb');
        delete_transient(AccessTokens::TRANSIENT);
        $this->http->on('#oauth2\.googleapis\.com/token#', FakeHttp::json(['error' => 'invalid_grant'], 400));
        $job = $this->createJob();
        $this->runAll();
        $this->assertSame(Connection::NEEDS_RECONNECT, Connection::state());
        $this->assertSame(JobState::NEEDS_AUTH, $this->job($job->id)->state);
        $this->assertSame(JobState::NEEDS_AUTH, $this->job($waiting->id)->state);
        $this->assertCount(1, $this->http->urls('oauth2'), 'never loops on refresh');
        $this->assertSame(1, did_action('yt_to_post_connection_changed'));
        $notices = (new \IndianOpinion\YtToPost\Admin\Notices($this->services))->collect();
        $this->assertStringContainsString('needs reconnecting', implode(' ', $notices));

        Connection::save(['state' => Connection::CONNECTED]);
        $this->assertSame(2, $this->jobs->resumeAuthWaiting());
        $this->assertSame(JobState::METADATA, $this->job($job->id)->state);
    }

    public function testT78DisconnectRevokesAndDeletesEvenOnFailure(): void
    {
        $this->connect();
        $this->http->on('#oauth2\.googleapis\.com/revoke#', new NetworkException('down'));
        $err = $this->services->oauth()->disconnect();
        $this->assertSame('down', $err);
        $this->assertSame(Connection::DISCONNECTED, Connection::state());
        $this->assertSame('', Connection::channelId());
        $this->assertFalse(get_transient(AccessTokens::TRANSIENT));
        $body = [];
        parse_str((string) $this->http->requests[0]['body'], $body);
        $this->assertSame('refresh-token-1', $body['token']);

        $this->connect();
        $this->http->on('#oauth2\.googleapis\.com/revoke#', new \IndianOpinion\YtToPost\Http\HttpResponse(200, ''));
        $this->assertNull($this->services->oauth()->disconnect());
    }

    public function testT79ChangedSaltsNeedReconnectWithoutFatal(): void
    {
        $this->connect();
        delete_transient(AccessTokens::TRANSIENT);
        update_option('_test_salt', 'a-different-salt');
        $job = $this->createJob();
        $this->runAll();
        $this->assertSame(Connection::NEEDS_RECONNECT, Connection::state());
        $this->assertSame(JobState::NEEDS_AUTH, $this->job($job->id)->state);
        $this->assertSame([], $this->http->urls('oauth2'));
    }
}
