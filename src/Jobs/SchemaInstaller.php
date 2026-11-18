<?php

declare(strict_types=1);

namespace IndianOpinion\YtToPost\Jobs;

use IndianOpinion\YtToPost\Exception\ConfigurationException;

/**
 * Creates or upgrades the jobs table with dbDelta, then lists the columns
 * and fails loudly when one is missing, because dbDelta can skip a change
 * without saying so.
 */
final class SchemaInstaller
{
    public const VERSION = 1;
    public const VERSION_OPTION = 'yt_to_post_schema_version';
    public const TABLE = 'yt_to_post_jobs';

    public const COLUMNS = [
        'id', 'video_id', 'state', 'step', 'user_id', 'post_id', 'source_kind', 'transcript_language',
        'article_language', 'attempts', 'model_calls', 'quota_units', 'args', 'metadata', 'metadata_at',
        'chapters', 'source', 'transcript', 'notes', 'article', 'warnings', 'log', 'last_error',
        'held_until', 'created_at', 'updated_at', 'finished_at',
    ];

    public static function table(\wpdb $wpdb): string
    {
        return $wpdb->prefix . self::TABLE;
    }

    public static function sql(string $table, string $collate): string
    {
        return "CREATE TABLE $table (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  video_id varchar(11) NOT NULL DEFAULT '',
  state varchar(32) NOT NULL DEFAULT 'queued',
  step varchar(32) NOT NULL DEFAULT 'queued',
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  post_id bigint(20) unsigned NOT NULL DEFAULT 0,
  source_kind varchar(32) NOT NULL DEFAULT '',
  transcript_language varchar(35) NOT NULL DEFAULT '',
  article_language varchar(35) NOT NULL DEFAULT '',
  attempts int(11) NOT NULL DEFAULT 0,
  model_calls int(11) NOT NULL DEFAULT 0,
  quota_units int(11) NOT NULL DEFAULT 0,
  args longtext NULL,
  metadata longtext NULL,
  metadata_at bigint(20) NOT NULL DEFAULT 0,
  chapters longtext NULL,
  source longtext NULL,
  transcript longtext NULL,
  notes longtext NULL,
  article longtext NULL,
  warnings longtext NULL,
  log longtext NULL,
  last_error text NULL,
  held_until bigint(20) NOT NULL DEFAULT 0,
  created_at bigint(20) NOT NULL DEFAULT 0,
  updated_at bigint(20) NOT NULL DEFAULT 0,
  finished_at bigint(20) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY video_id (video_id),
  KEY state_held (state,held_until),
  KEY user_id (user_id),
  KEY finished_at (finished_at),
  KEY metadata_at (metadata_at)
) $collate;";
    }

    /**
     * @throws ConfigurationException when a column is missing after dbDelta
     */
    public static function install(\wpdb $wpdb): void
    {
        $table = self::table($wpdb);
        if (!function_exists('dbDelta') && defined('ABSPATH')) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }
        dbDelta(self::sql($table, $wpdb->get_charset_collate()));
        $missing = self::missingColumns($wpdb);
        if ($missing !== []) {
            throw new ConfigurationException(sprintf(
                'The jobs table %s is missing columns after install: %s',
                $table,
                implode(', ', $missing)
            ));
        }
        update_option(self::VERSION_OPTION, self::VERSION, false);
    }

    /** @return list<string> */
    public static function missingColumns(\wpdb $wpdb): array
    {
        $table = self::table($wpdb);
        $rows = $wpdb->get_col("SHOW COLUMNS FROM `$table`", 0);
        $have = array_map('strtolower', array_map('strval', is_array($rows) ? $rows : []));
        return array_values(array_diff(self::COLUMNS, $have));
    }

    public static function needsUpgrade(): bool
    {
        return (int) get_option(self::VERSION_OPTION, 0) < self::VERSION;
    }
}
