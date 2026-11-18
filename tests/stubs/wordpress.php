<?php

/**
 * A small in-memory stand-in for the WordPress functions the plugin uses,
 * so PHPUnit runs without WordPress. State lives in WpStub and is reset
 * before every test.
 */

declare(strict_types=1);

final class WpStub
{
    /** @var array<string,array<int,list<callable>>> */
    public static array $filters = [];
    /** @var array<string,int> */
    public static array $actions = [];
    /** @var array<string,mixed> */
    public static array $options = [];
    /** @var array<string,array{0:mixed,1:int}> */
    public static array $transients = [];
    /** @var array<int,WP_Post> */
    public static array $posts = [];
    /** @var array<int,array<string,mixed>> */
    public static array $meta = [];
    /** @var array<string,list<int>> */
    public static array $cron = [];
    public static int $userId = 1;
    /** @var array<int,list<string>> */
    public static array $caps = [];
    public static int $nextPost = 100;
    public static string $locale = 'en_GB';
    public static string $timezone = 'UTC';
    /** @var array<string,array<string,mixed>> */
    public static array $routes = [];
    public static int $now = 0;
    /** @var list<array{0:string,1:mixed,2:mixed}> */
    public static array $sideloads = [];
    /** @var array<int,int> */
    public static array $thumbnails = [];

    public static function reset(): void
    {
        self::$filters = [];
        self::$actions = [];
        self::$options = [];
        self::$transients = [];
        self::$posts = [];
        self::$meta = [];
        self::$cron = [];
        self::$userId = 1;
        self::$caps = [1 => ['edit_posts', 'manage_options'], 2 => ['edit_posts'], 3 => []];
        self::$nextPost = 100;
        self::$locale = 'en_GB';
        self::$timezone = 'UTC';
        self::$routes = [];
        self::$sideloads = [];
        self::$thumbnails = [];
    }

    public static function time(): int
    {
        return self::$now > 0 ? self::$now : time();
    }
}

class WP_Error
{
    public function __construct(public string $code = '', public string $message = '', public mixed $data = null)
    {
    }

    public function get_error_message(): string
    {
        return $this->message;
    }

    public function get_error_code(): string
    {
        return $this->code;
    }

    public function get_error_data(): mixed
    {
        return $this->data;
    }
}

class WP_Post
{
    public int $ID = 0;
    public string $post_title = '';
    public string $post_content = '';
    public string $post_excerpt = '';
    public string $post_status = 'draft';
    public string $post_type = 'post';
    public int $post_author = 0;
    public string $post_date = '';
    public string $post_name = '';
    /** @var array<string,mixed> */
    public array $args = [];
    /** @var array<string,mixed> */
    public array $ksesTags = [];
}

class WP_REST_Request implements ArrayAccess
{
    /** @var array<string,mixed> */
    private array $params;
    /** @var array<string,string> */
    private array $headers;

    /**
     * @param array<string,mixed> $params
     * @param array<string,string> $headers
     */
    public function __construct(array $params = [], array $headers = [])
    {
        $this->params = $params;
        $this->headers = array_change_key_case($headers, CASE_LOWER);
    }

    public function get_param(string $k): mixed
    {
        return $this->params[$k] ?? null;
    }

    /** @return array<string,mixed> */
    public function get_params(): array
    {
        return $this->params;
    }

    /** @return array<string,mixed> */
    public function get_json_params(): array
    {
        return $this->params;
    }

    public function get_header(string $k): ?string
    {
        return $this->headers[strtolower($k)] ?? null;
    }

    public function offsetExists(mixed $o): bool
    {
        return isset($this->params[$o]);
    }

    public function offsetGet(mixed $o): mixed
    {
        return $this->params[$o] ?? null;
    }

    public function offsetSet(mixed $o, mixed $v): void
    {
        $this->params[$o] = $v;
    }

    public function offsetUnset(mixed $o): void
    {
        unset($this->params[$o]);
    }
}

class WP_REST_Response
{
    public function __construct(public mixed $data = null, public int $status = 200)
    {
    }

    public function get_data(): mixed
    {
        return $this->data;
    }

    public function get_status(): int
    {
        return $this->status;
    }
}

// Hooks ----------------------------------------------------------------------

function add_filter(string $hook, callable $cb, int $priority = 10, int $args = 1): bool
{
    WpStub::$filters[$hook][$priority][] = $cb;
    return true;
}

function add_action(string $hook, callable $cb, int $priority = 10, int $args = 1): bool
{
    return add_filter($hook, $cb, $priority, $args);
}

function remove_filter(string $hook, callable $cb, int $priority = 10): bool
{
    foreach (WpStub::$filters[$hook][$priority] ?? [] as $i => $known) {
        if ($known === $cb) {
            unset(WpStub::$filters[$hook][$priority][$i]);
            return true;
        }
    }
    return false;
}

function remove_all_filters(string $hook): bool
{
    unset(WpStub::$filters[$hook]);
    return true;
}

function apply_filters(string $hook, mixed $value, mixed ...$args): mixed
{
    if (!isset(WpStub::$filters[$hook])) {
        return $value;
    }
    ksort(WpStub::$filters[$hook]);
    foreach (WpStub::$filters[$hook] as $cbs) {
        foreach ($cbs as $cb) {
            $value = $cb($value, ...$args);
        }
    }
    return $value;
}

function do_action(string $hook, mixed ...$args): void
{
    WpStub::$actions[$hook] = (WpStub::$actions[$hook] ?? 0) + 1;
    if (!isset(WpStub::$filters[$hook])) {
        return;
    }
    ksort(WpStub::$filters[$hook]);
    foreach (WpStub::$filters[$hook] as $cbs) {
        foreach ($cbs as $cb) {
            $cb(...$args);
        }
    }
}

function did_action(string $hook): int
{
    return WpStub::$actions[$hook] ?? 0;
}

// Options and transients -------------------------------------------------------

function get_option(string $name, mixed $default = false): mixed
{
    return array_key_exists($name, WpStub::$options) ? WpStub::$options[$name] : $default;
}

function update_option(string $name, mixed $value, mixed $autoload = null): bool
{
    WpStub::$options[$name] = $value;
    return true;
}

function add_option(string $name, mixed $value = '', string $deprecated = '', mixed $autoload = 'yes'): bool
{
    if (array_key_exists($name, WpStub::$options)) {
        return false;
    }
    WpStub::$options[$name] = $value;
    return true;
}

function delete_option(string $name): bool
{
    $had = array_key_exists($name, WpStub::$options);
    unset(WpStub::$options[$name]);
    return $had;
}

function wp_cache_delete(string $key, string $group = ''): bool
{
    return true;
}

function get_transient(string $name): mixed
{
    if (!isset(WpStub::$transients[$name])) {
        return false;
    }
    [$v, $exp] = WpStub::$transients[$name];
    if ($exp > 0 && $exp < WpStub::time()) {
        unset(WpStub::$transients[$name]);
        return false;
    }
    return $v;
}

function set_transient(string $name, mixed $value, int $ttl = 0): bool
{
    WpStub::$transients[$name] = [$value, $ttl > 0 ? WpStub::time() + $ttl : 0];
    return true;
}

function delete_transient(string $name): bool
{
    unset(WpStub::$transients[$name]);
    return true;
}

// Users, nonces, capabilities ----------------------------------------------------

function get_current_user_id(): int
{
    return WpStub::$userId;
}

function is_user_logged_in(): bool
{
    return WpStub::$userId > 0;
}

function current_user_can(string $cap, mixed ...$args): bool
{
    $caps = WpStub::$caps[WpStub::$userId] ?? [];
    if ($cap === 'edit_post') {
        return in_array('edit_posts', $caps, true);
    }
    return in_array($cap, $caps, true);
}

function get_userdata(int $id): object|false
{
    return $id > 0 ? (object) ['ID' => $id, 'display_name' => 'User ' . $id] : false;
}

function wp_create_nonce(string $action = ''): string
{
    return 'nonce-' . $action . '-' . WpStub::$userId;
}

function wp_verify_nonce(string $nonce, string $action = ''): int|false
{
    return hash_equals(wp_create_nonce($action), $nonce) ? 1 : false;
}

function wp_nonce_field(string $action = '', string $name = '_wpnonce', bool $referer = true, bool $echo = true): string
{
    $html = '<input type="hidden" name="' . $name . '" value="' . wp_create_nonce($action) . '">';
    if ($echo) {
        echo $html;
    }
    return $html;
}

function wp_nonce_url(string $url, string $action = ''): string
{
    return add_query_arg(['_wpnonce' => wp_create_nonce($action)], $url);
}

// Escaping and sanitising ----------------------------------------------------------

function esc_html(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function esc_attr(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function esc_url(string $url): string
{
    if (preg_match('#^(https?:)?//#i', $url) !== 1 && !str_starts_with($url, '/')) {
        return '';
    }
    $url = (string) preg_replace('|[^a-z0-9-~+_.?#=!&;,/:%@$\|*\'()\[\]\x80-\xff]|i', '', $url);
    $url = (string) preg_replace('/&(?!#?\w+;)/', '&#038;', $url);
    return str_replace("'", '&#039;', $url);
}

function esc_textarea(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function __(string $s, string $domain = 'default'): string
{
    return $s;
}

function esc_html__(string $s, string $domain = 'default'): string
{
    return esc_html($s);
}

function wp_kses_post(string $s): string
{
    return $s;
}

function sanitize_text_field(string $s): string
{
    return trim((string) preg_replace('/[\r\n\t ]+/', ' ', strip_tags($s)));
}

function sanitize_textarea_field(string $s): string
{
    return trim(strip_tags($s));
}

function wp_unslash(mixed $v): mixed
{
    return is_string($v) ? stripslashes($v) : (is_array($v) ? array_map('wp_unslash', $v) : $v);
}

function wp_slash(mixed $v): mixed
{
    return is_string($v) ? addslashes($v) : (is_array($v) ? array_map('wp_slash', $v) : $v);
}

function absint(mixed $v): int
{
    return abs((int) $v);
}

function wp_json_encode(mixed $v, int $flags = 0): string|false
{
    return json_encode($v, $flags);
}

function selected(mixed $a, mixed $b = true, bool $echo = true): string
{
    return (string) $a === (string) $b ? ' selected="selected"' : '';
}

function checked(mixed $a, mixed $b = true, bool $echo = true): string
{
    return (string) $a === (string) $b ? ' checked="checked"' : '';
}

function wp_die(string $message = ''): never
{
    throw new RuntimeException('wp_die: ' . $message);
}

// Posts and meta -------------------------------------------------------------------

/**
 * @param array<string,mixed> $args
 */
function wp_insert_post(array $args, bool $wpError = false): int|WP_Error
{
    $args = wp_unslash($args);
    $p = new WP_Post();
    $p->ID = WpStub::$nextPost++;
    $p->post_title = (string) ($args['post_title'] ?? '');
    $p->post_content = (string) ($args['post_content'] ?? '');
    $p->post_excerpt = (string) ($args['post_excerpt'] ?? '');
    $p->post_status = (string) ($args['post_status'] ?? 'draft');
    $p->post_type = (string) ($args['post_type'] ?? 'post');
    $p->post_author = (int) ($args['post_author'] ?? 0);
    $p->post_date = (string) ($args['post_date'] ?? '');
    $p->post_name = (string) ($args['post_name'] ?? '');
    $p->args = $args;
    // What the post kses rules would allow at insert time.
    $p->ksesTags = (array) apply_filters('wp_kses_allowed_html', [], 'post');
    WpStub::$posts[$p->ID] = $p;
    return $p->ID;
}

function get_post(int $id): ?WP_Post
{
    return WpStub::$posts[$id] ?? null;
}

function wp_delete_post(int $id, bool $force = false): void
{
    unset(WpStub::$posts[$id], WpStub::$meta[$id]);
}

/**
 * @param array<string,mixed> $args
 * @return list<int>
 */
function get_posts(array $args): array
{
    $out = [];
    foreach (WpStub::$posts as $id => $p) {
        $m = WpStub::$meta[$id] ?? [];
        if (isset($args['meta_key'])) {
            if (($m[$args['meta_key']] ?? null) != ($args['meta_value'] ?? null)) {
                continue;
            }
        }
        if (isset($args['meta_query'][0])) {
            $q = $args['meta_query'][0];
            $v = $m[$q['key']] ?? null;
            if ($v === null || !((int) $v < (int) $q['value'])) {
                continue;
            }
        }
        $out[] = $id;
    }
    return $out;
}

function update_post_meta(int $id, string $key, mixed $value): bool
{
    WpStub::$meta[$id][$key] = is_string($value) ? wp_unslash($value) : $value;
    return true;
}

function get_post_meta(int $id, string $key = '', bool $single = false): mixed
{
    return WpStub::$meta[$id][$key] ?? ($single ? '' : []);
}

function delete_post_meta(int $id, string $key): bool
{
    unset(WpStub::$meta[$id][$key]);
    return true;
}

function delete_post_meta_by_key(string $key): bool
{
    foreach (WpStub::$meta as $id => $m) {
        unset(WpStub::$meta[$id][$key]);
    }
    return true;
}

function get_edit_post_link(int $id, string $context = 'display'): string
{
    return 'https://example.org/wp-admin/post.php?post=' . $id . '&action=edit';
}

function media_sideload_image(string $url, int $postId, ?string $desc = null, string $return = 'html'): int|WP_Error
{
    WpStub::$sideloads[] = [$url, $postId, $return];
    return 900 + $postId;
}

function set_post_thumbnail(int $postId, int $attachmentId): bool
{
    WpStub::$thumbnails[$postId] = $attachmentId;
    return true;
}

function is_wp_error(mixed $v): bool
{
    return $v instanceof WP_Error;
}

// Time, locale, URLs -------------------------------------------------------------------

function wp_timezone(): DateTimeZone
{
    return new DateTimeZone(WpStub::$timezone);
}

function wp_date(string $format, ?int $ts = null): string
{
    return (new DateTimeImmutable('@' . ($ts ?? WpStub::time())))->setTimezone(wp_timezone())->format($format);
}

function get_locale(): string
{
    return WpStub::$locale;
}

function admin_url(string $path = ''): string
{
    return 'https://example.org/wp-admin/' . ltrim($path, '/');
}

function home_url(string $path = ''): string
{
    return 'https://example.org' . $path;
}

/**
 * @param array<string,mixed> $args
 */
function add_query_arg(array $args, string $url): string
{
    return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($args);
}

function is_admin(): bool
{
    return true;
}

function is_multisite(): bool
{
    return false;
}

function wp_salt(string $scheme = 'auth'): string
{
    return (string) get_option('_test_salt', 'test-salt-one');
}

// Cron ---------------------------------------------------------------------------------

function wp_next_scheduled(string $hook): int|false
{
    $list = WpStub::$cron[$hook] ?? [];
    return $list === [] ? false : min($list);
}

/** @param array<mixed> $args */
function wp_schedule_single_event(int $ts, string $hook, array $args = []): bool
{
    WpStub::$cron[$hook][] = $ts;
    return true;
}

/** @param array<mixed> $args */
function wp_schedule_event(int $ts, string $recurrence, string $hook, array $args = []): bool
{
    WpStub::$cron[$hook][] = $ts;
    return true;
}

function wp_unschedule_event(int $ts, string $hook): bool
{
    WpStub::$cron[$hook] = array_values(array_filter(WpStub::$cron[$hook] ?? [], static fn (int $t): bool => $t !== $ts));
    return true;
}

function wp_clear_scheduled_hook(string $hook): int
{
    $n = count(WpStub::$cron[$hook] ?? []);
    unset(WpStub::$cron[$hook]);
    return $n;
}

// REST -----------------------------------------------------------------------------------

/** @param array<string,mixed> $args */
function register_rest_route(string $ns, string $route, array $args): bool
{
    WpStub::$routes[$ns . $route] = $args;
    return true;
}

// Database --------------------------------------------------------------------------------

class wpdb
{
    public string $prefix = 'wp_';
    public string $options = 'wp_options';
    public string $last_error = '';
    public int $insert_id = 0;
    /** @var list<string> */
    public array $queries = [];
    /** @var list<string> */
    public array $columns = [];

    public function get_charset_collate(): string
    {
        return 'DEFAULT CHARSET=utf8mb4';
    }

    /** @return list<string> */
    public function get_col(string $sql, int $x = 0): array
    {
        $this->queries[] = $sql;
        return $this->columns;
    }

    public function query(string $sql): int
    {
        $this->queries[] = $sql;
        return 1;
    }

    public function prepare(string $sql, mixed ...$args): string
    {
        foreach ($args as $a) {
            $sql = (string) preg_replace('/%[sd]/', is_int($a) ? (string) $a : "'" . addslashes((string) $a) . "'", $sql, 1);
        }
        return $sql;
    }

    public function esc_like(string $s): string
    {
        return addcslashes($s, '_%\\');
    }
}

/** @return array<string,string> */
function dbDelta(string $sql): array
{
    global $wpdb;
    if ($wpdb instanceof wpdb) {
        $wpdb->queries[] = $sql;
        if (preg_match_all('/^\s{2}([a-z_]+) [a-z]/m', $sql, $m) > 0) {
            $wpdb->columns = $m[1];
        }
        if (get_option('_test_dbdelta_skip') !== false) {
            $wpdb->columns = array_values(array_diff($wpdb->columns, [(string) get_option('_test_dbdelta_skip')]));
        }
    }
    return [];
}

/** @param array<string,mixed> $args */
function wp_dropdown_categories(array $args = []): string
{
    return '<select name="' . esc_attr((string) ($args['name'] ?? 'cat')) . '"><option value="0">Default</option></select>';
}
