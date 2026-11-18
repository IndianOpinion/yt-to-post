<?php

declare(strict_types=1);

namespace IndianOpinion\YtToPost;

use IndianOpinion\YtToPost\Admin\AdminController;
use IndianOpinion\YtToPost\Admin\MetaBox;
use IndianOpinion\YtToPost\Admin\Notices;
use IndianOpinion\YtToPost\Admin\SettingsPage;
use IndianOpinion\YtToPost\Admin\ToolsPage;
use IndianOpinion\YtToPost\Cli\Command;
use IndianOpinion\YtToPost\Http\WpHttpClient;
use IndianOpinion\YtToPost\Integrations\GenQueue;
use IndianOpinion\YtToPost\Jobs\Housekeeping;
use IndianOpinion\YtToPost\Jobs\JobService;
use IndianOpinion\YtToPost\Jobs\Runner;
use IndianOpinion\YtToPost\Jobs\Scheduler;
use IndianOpinion\YtToPost\Jobs\SchemaInstaller;
use IndianOpinion\YtToPost\Jobs\WpdbJobRepository;
use IndianOpinion\YtToPost\Rest\RestController;
use IndianOpinion\YtToPost\Support\RealSleeper;
use IndianOpinion\YtToPost\Support\SystemClock;
use IndianOpinion\YtToPost\YouTube\Connection;

/**
 * Wires the plugin into WordPress. Nothing runs on front-end requests.
 */
final class Plugin
{
    public const VERSION = '0.1.0';
    public const ADMIN_POST_ACTIONS = [
        'yt_to_post_create', 'yt_to_post_job_action', 'yt_to_post_supply', 'yt_to_post_settings', 'yt_to_post_toggle',
        'yt_to_post_connect', 'yt_to_post_disconnect', 'yt_to_post_oauth', 'yt_to_post_use_thumbnail',
    ];

    private static ?Services $services = null;

    public static function services(): Services
    {
        if (self::$services === null) {
            global $wpdb;
            self::$services = new Services(new WpdbJobRepository($wpdb), new SystemClock(), new WpHttpClient(), new RealSleeper());
        }
        return self::$services;
    }

    /** For tests and for sites that wire their own collaborators. */
    public static function setServices(?Services $services): void
    {
        self::$services = $services;
    }

    public static function jobs(): JobService
    {
        return new JobService(self::services());
    }

    public static function runner(): Runner
    {
        return new Runner(self::services());
    }

    public static function boot(string $file): void
    {
        if (!defined('YT_TO_POST_VERSION')) {
            define('YT_TO_POST_VERSION', self::VERSION);
        }
        register_activation_hook($file, [self::class, 'activate']);
        register_deactivation_hook($file, [self::class, 'deactivate']);

        add_action(Scheduler::RUN_HOOK, [self::class, 'cronRun']);
        add_action(Scheduler::HOUSEKEEPING_HOOK, [self::class, 'housekeeping']);
        add_action('yt_to_post_connection_changed', static function (string $state): void {
            if ($state === Connection::CONNECTED) {
                self::jobs()->resumeAuthWaiting();
            }
        });
        GenQueue::register(static fn (int $jobId, float $seconds) => self::runner()->run(['job' => $jobId, 'time' => $seconds])->toArray());

        if (is_admin()) {
            add_action('admin_menu', [self::class, 'menus']);
            add_action('admin_init', [self::class, 'adminInit']);
            add_action('admin_notices', static fn () => (new Notices(self::services()))->render());
            add_action('add_meta_boxes', [MetaBox::class, 'register'], 10, 2);
            foreach (self::ADMIN_POST_ACTIONS as $action) {
                add_action('admin_post_' . $action, static fn () => self::dispatch($action));
            }
        }
        add_action('rest_api_init', static fn () => (new RestController(self::services(), self::jobs()))->register());
        if (defined('WP_CLI') && WP_CLI && class_exists(\WP_CLI::class)) {
            \WP_CLI::add_command('yt-to-post', new Command(self::services(), self::jobs(), self::runner()));
        }
        add_action('plugins_loaded', static fn () => do_action('yt_to_post_loaded'), 20);
        if (did_action('plugins_loaded') > 0) {
            do_action('yt_to_post_loaded');
        }
    }

    public static function activate(bool $networkWide = false): void
    {
        if (is_multisite() && $networkWide) {
            foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $siteId) {
                switch_to_blog((int) $siteId);
                self::activateSite();
                restore_current_blog();
            }
            return;
        }
        self::activateSite();
    }

    private static function activateSite(): void
    {
        global $wpdb;
        SchemaInstaller::install($wpdb);
        add_option(Settings::OPTION, Settings::DEFAULTS, '', false);
        add_option('yt_to_post_version', self::VERSION, '', false);
        Scheduler::ensureHousekeeping();
    }

    public static function deactivate(): void
    {
        Scheduler::clear();
    }

    public static function adminInit(): void
    {
        if (SchemaInstaller::needsUpgrade()) {
            global $wpdb;
            SchemaInstaller::install($wpdb);
        }
        Scheduler::ensureHousekeeping();
        if (function_exists('wp_add_privacy_policy_content')) {
            wp_add_privacy_policy_content('yt-to-post', wp_kses_post(
                '<p>' . __('When an editor turns a YouTube video into a draft, this site sends requests to YouTube (Google) for the video\'s details and captions, and sends the transcript, which can contain personal data about the people speaking, to the language model provider configured by the site administrator. Job data, including the transcript, is kept for the retention period set by the administrator.', 'yt-to-post') . '</p>'
            ));
        }
    }

    public static function menus(): void
    {
        add_management_page(__('Video to draft', 'yt-to-post'), __('Video to draft', 'yt-to-post'), Capabilities::create(), AdminController::PAGE, static fn () => (new ToolsPage(self::services()))->render());
        add_options_page(__('YouTube to draft', 'yt-to-post'), __('YouTube to draft', 'yt-to-post'), Capabilities::settings(), AdminController::SETTINGS_PAGE, static fn () => (new SettingsPage())->render());
    }

    public static function dispatch(string $action): void
    {
        $controller = new AdminController(self::services(), self::jobs());
        // Each handler verifies its own nonce and capability first.
        $result = $controller->handle($action, $_POST, $_FILES, $_GET); // phpcs:ignore WordPress.Security.NonceVerification
        if ($result->status === 302) {
            wp_redirect($result->redirect); // Google's consent page: an external host by design.
            exit;
        }
        wp_safe_redirect($result->redirect);
        exit;
    }

    public static function cronRun(): void
    {
        if (defined('YT_TO_POST_DISABLE_CRON_RUNNER') && constant('YT_TO_POST_DISABLE_CRON_RUNNER')) {
            return;
        }
        self::runner()->run(['time' => Settings::int('tick_seconds')]);
    }

    public static function housekeeping(): void
    {
        (new Housekeeping(self::services()))->run();
    }
}
