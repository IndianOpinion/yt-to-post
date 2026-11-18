<?php

declare(strict_types=1);

namespace IndianOpinion\YtToPost\Tests\Unit;

use IndianOpinion\YtToPost\Exception\ConfigurationException;
use IndianOpinion\YtToPost\Jobs\SchemaInstaller;
use IndianOpinion\YtToPost\Jobs\JobState;
use IndianOpinion\YtToPost\Tests\Support\FakeHttp;
use IndianOpinion\YtToPost\Tests\TestCase;
use IndianOpinion\YtToPost\Uninstaller;

/** T-UNINSTALL and the schema check (lesson 12). */
final class UninstallTest extends TestCase
{
    public function testUninstallRemovesEverythingButPosts(): void
    {
        global $wpdb;
        $wpdb = new \wpdb();
        $this->connect();
        $this->routeVideo($this->item());
        $this->routeCaptions([self::track('t1', 'en')]);
        $this->answerArticle();
        $this->createJob();
        $job = $this->runAll();
        $this->assertSame(JobState::DONE, $job->state);
        \IndianOpinion\YtToPost\Jobs\Scheduler::ensureHousekeeping();
        \IndianOpinion\YtToPost\Jobs\Scheduler::kick();
        $this->http->on('#oauth2\.googleapis\.com/revoke#', new \IndianOpinion\YtToPost\Http\HttpResponse(200, ''));
        update_option('unrelated_option', 'stays');

        Uninstaller::run($this->http, $this->clock, $this->vault);

        $this->assertCount(1, $this->http->urls('revoke'));
        $this->assertSame(5, $this->http->requests[count($this->http->requests) - 1]['timeout']);
        $left = array_filter(array_keys(\WpStub::$options), static fn (string $k): bool => str_starts_with($k, 'yt_to_post'));
        $this->assertSame([], array_values($left));
        $this->assertSame('stays', get_option('unrelated_option'));
        $this->assertFalse(get_transient('yt_to_post_access_token'));
        $this->assertSame([], array_filter(\WpStub::$cron));
        $this->assertNotEmpty(array_filter($wpdb->queries, static fn (string $q): bool => str_contains($q, 'DROP TABLE IF EXISTS `wp_yt_to_post_jobs`')));
        $this->assertNotEmpty(array_filter($wpdb->queries, static fn (string $q): bool => str_starts_with($q, 'DELETE FROM wp_options WHERE option_name LIKE') && str_contains($q, 'yt') && str_contains($q, 'timeout')));
        $this->assertArrayHasKey($job->postId, \WpStub::$posts, 'the draft stays');
        $this->assertSame([], array_filter(\WpStub::$meta[$job->postId] ?? [], static fn ($v, string $k): bool => str_starts_with($k, '_yt_to_post_'), ARRAY_FILTER_USE_BOTH));
    }

    public function testUninstallAttemptsRevokeEvenWhenItFails(): void
    {
        global $wpdb;
        $wpdb = new \wpdb();
        $this->connect();
        $this->http->on('#revoke#', FakeHttp::json([], 500));
        Uninstaller::run($this->http, $this->clock, $this->vault);
        $this->assertCount(1, $this->http->urls('revoke'));
        $this->assertFalse(get_option('yt_to_post_connection'));
    }

    public function testSchemaInstallChecksColumns(): void
    {
        global $wpdb;
        $wpdb = new \wpdb();
        SchemaInstaller::install($wpdb);
        $this->assertSame(SchemaInstaller::VERSION, get_option(SchemaInstaller::VERSION_OPTION));
        $this->assertSame([], SchemaInstaller::missingColumns($wpdb));
        $this->assertStringContainsString('SHOW COLUMNS FROM `wp_yt_to_post_jobs`', implode("\n", $wpdb->queries));

        delete_option(SchemaInstaller::VERSION_OPTION);
        update_option('_test_dbdelta_skip', 'quota_units');
        try {
            SchemaInstaller::install($wpdb);
            $this->fail('a silently skipped column must fail loudly');
        } catch (ConfigurationException $e) {
            $this->assertStringContainsString('quota_units', $e->getMessage());
        }
        $this->assertFalse(get_option(SchemaInstaller::VERSION_OPTION));
    }
}
