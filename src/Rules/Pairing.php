<?php

declare(strict_types=1);

namespace Drupal\pendulum_arc\Rules;

/**
 * Response shapes from spec 19.
 */
final class Pairing {

  public const SERVICES = ['analytics', 'seo', 'a11y', 'ai', 'security', 'brand'];

  /**
   * True when the pasted value is an Arc API key.
   *
   * @param string $key
   *   Pasted key.
   */
  public static function is_api_key(string $key): bool {
    return 1 === preg_match('/^arc_(live|test)_[A-Za-z0-9]+_[A-Za-z0-9]+$/', $key);
  }

  /**
   * Production or preview from the key prefix.
   *
   * @param string $key
   *   Valid API key.
   */
  public static function env_for_key(string $key): string {
    return str_starts_with($key, 'arc_test_') ? 'preview' : 'production';
  }

  /**
   * Credential label from GET /v1/me.
   *
   * @param array<string, mixed> $json
   *   Response body.
   */
  public static function account_label(array $json): string {
    return SiteOrigin::field($json, ['name', 'label']);
  }

  /**
   * Project rows from a list response.
   *
   * @param array<string, mixed> $json
   *   Response body.
   *
   * @return array<int, array{id: string, name: string, slug: string}>
   */
  public static function projects(array $json): array {
    $rows = self::rows($json, ['projects', 'items', 'data']);
    $out  = [];
    foreach ($rows as $row) {
      if (!is_array($row)) {
        continue;
      }
      $id   = SiteOrigin::field($row, ['id']);
      $name = SiteOrigin::field($row, ['name']);
      if ('' === $id) {
        continue;
      }
      $out[] = [
        'id'   => $id,
        'name' => '' !== $name ? $name : $id,
        'slug' => self::path_segment(SiteOrigin::field($row, ['slug'])),
      ];
    }
    return $out;
  }

  /**
   * One project from GET /v1/projects/{id}.
   *
   * @param array<string, mixed> $json
   *   Response body.
   *
   * @return array{id: string, name: string, slug: string}
   */
  public static function project(array $json): array {
    $row = $json['project'] ?? $json;
    if (!is_array($row)) {
      $row = [];
    }
    $parsed = self::projects(['projects' => [$row]]);
    if ([] === $parsed) {
      return [
        'id'   => '',
        'name' => '',
        'slug' => '',
      ];
    }
    return $parsed[0];
  }

  /**
   * Portal page for one site. Falls back to the portal origin when the
   * slug or site id is not a single path segment.
   *
   * @param string $portal
   *   Portal origin.
   * @param string $slug
   *   Project slug.
   * @param string $site_id
   *   Site id.
   */
  public static function site_portal_url(string $portal, string $slug, string $site_id): string {
    $origin = rtrim($portal, '/');
    $slug   = self::path_segment($slug);
    $site   = self::path_segment($site_id);
    if ('' === $origin || '' === $slug || '' === $site) {
      return $origin;
    }
    return $origin . '/app/projects/' . rawurlencode($slug) . '/sites/' . rawurlencode($site);
  }

  /**
   * One URL path segment, or empty when the value could change the path.
   *
   * @param string $value
   *   Raw id or slug.
   */
  private static function path_segment(string $value): string {
    $value = trim($value);
    if ('' === $value || str_contains($value, '/') || str_contains($value, '\\') || str_contains($value, '..')) {
      return '';
    }
    return $value;
  }

  /**
   * Site rows from a list response.
   *
   * @param array<string, mixed> $json
   *   Response body.
   * @param string $project_id
   *   Project the list was requested for.
   *
   * @return array<int, array{id: string, projectId: string, name: string, baseUrl: string}>
   */
  public static function sites(array $json, string $project_id): array {
    $rows = self::rows($json, ['sites', 'items', 'data']);
    $out  = [];
    foreach ($rows as $row) {
      if (!is_array($row)) {
        continue;
      }
      $site = SiteOrigin::site_row($row);
      if (NULL === $site) {
        continue;
      }
      if ('' === $site['projectId']) {
        $site['projectId'] = $project_id;
      }
      $out[] = $site;
    }
    return $out;
  }

  /**
   * Site object from a token or create response.
   *
   * @param array<string, mixed> $json
   *   Response body.
   *
   * @return array{id: string, projectId: string, name: string, baseUrl: string}|null
   */
  public static function site(array $json): ?array {
    if (isset($json['site']) && is_array($json['site'])) {
      $nested = SiteOrigin::site_row($json['site']);
      if (NULL !== $nested) {
        return $nested;
      }
    }
    if (isset($json['access_token']) || isset($json['accessToken'])) {
      return NULL;
    }
    $id = SiteOrigin::field($json, ['siteId', 'site_id']);
    if ('' === $id) {
      return NULL;
    }
    return SiteOrigin::site_row(
      [
        'id'        => $id,
        'projectId' => SiteOrigin::field($json, ['projectId', 'project_id']),
        'name'      => SiteOrigin::field($json, ['name']),
        'baseUrl'   => SiteOrigin::field($json, ['baseUrl', 'base_url']),
      ]
    );
  }

  /**
   * OAuth token fields.
   *
   * @param array<string, mixed> $json
   *   Token response.
   *
   * @return array{accessToken: string, refreshToken: string, expiresIn: int, site: array{id: string, projectId: string, name: string, baseUrl: string}|null}
   */
  public static function token(array $json): array {
    $expires = 0;
    if (isset($json['expires_in']) && is_numeric($json['expires_in'])) {
      $expires = (int) $json['expires_in'];
    }
    elseif (isset($json['expiresIn']) && is_numeric($json['expiresIn'])) {
      $expires = (int) $json['expiresIn'];
    }
    return [
      'accessToken'  => SiteOrigin::field($json, ['access_token', 'accessToken']),
      'refreshToken' => SiteOrigin::field($json, ['refresh_token', 'refreshToken']),
      'expiresIn'    => $expires,
      'site'         => self::site($json),
    ];
  }

  /**
   * One row per service, using the latest score when Arc sent one.
   *
   * @param array<string, mixed> $json
   *   Scores response.
   *
   * @return array<int, array{service: string, score: float|null, band: string|null, issueCount: int, manualCount: int, issueSeverity: string|null, keyphraseScore: float|null, keyphraseBand: string|null, setup: string|null}>
   */
  public static function scores(array $json): array {
    $indexed = [];
    $raw     = $json['scores'] ?? NULL;
    if (is_array($raw) && array_is_list($raw)) {
      foreach ($raw as $row) {
        if (!is_array($row)) {
          continue;
        }
        $service = SiteOrigin::field($row, ['service']);
        if ('' !== $service) {
          $indexed[$service] = $row;
        }
      }
    }
    elseif (is_array($raw)) {
      foreach ($raw as $service => $row) {
        if (is_array($row)) {
          $indexed[(string) $service] = $row;
        }
      }
    }

    $setup = self::analytics_setup($json['analyticsSetup'] ?? NULL);
    $out = [];
    foreach (self::SERVICES as $service) {
      $row   = $indexed[$service] ?? [];
      $out[] = [
        'service'        => $service,
        'score'          => self::score_value($row),
        'band'           => self::band_value($row),
        'issueCount'     => self::count_value($row, 'issueCount'),
        'manualCount'    => self::count_value($row, 'manualCount'),
        'issueSeverity'  => self::severity_value($row),
        'keyphraseScore' => self::decimal_score($row, 'keyphraseScore'),
        'keyphraseBand'  => self::label_value($row, ['keyphraseBand', 'keyphrase_band']),
        'visitors'       => self::visitors($service, $row),
        'setup'          => 'analytics' === $service ? $setup : NULL,
      ];
    }
    return $out;
  }

  /**
   * Path of a GA4 landing label. Absolute URLs keep only the path.
   *
   * @param string $label
   *   Page path or URL from the report.
   */
  public static function landing_path(string $label): string {
    $label = trim($label);
    if ('' === $label) {
      return '/';
    }
    if (1 === preg_match('#^https?://#i', $label)) {
      $parts = parse_url($label);
      $path  = is_array($parts) ? (string) ($parts['path'] ?? '/') : '/';
    }
    else {
      $path = $label;
    }
    $path = '/' . ltrim($path, '/');
    if (str_contains($path, '..') || str_contains($path, '\\')) {
      return '/';
    }
    return $path;
  }

  /**
   * Visitor Analytics summary. Charts are always the four GA4 graphs.
   *
   * @param array<string, mixed> $json
   *   Analytics response.
   *
   * @return array<string, mixed>
   */
  public static function analytics(array $json): array {
    $charts = [];
    $raw    = $json['charts'] ?? NULL;
    if (is_array($raw)) {
      foreach ($raw as $chart) {
        if (!is_array($chart)) {
          continue;
        }
        $id = SiteOrigin::field($chart, ['id']);
        if ('' === $id) {
          continue;
        }
        $charts[] = $chart;
      }
    }
    $pages = [];
    $raw   = $json['landingPages'] ?? NULL;
    if (is_array($raw)) {
      foreach ($raw as $row) {
        if (!is_array($row)) {
          continue;
        }
        $path    = self::landing_path(SiteOrigin::field($row, ['path', 'label']));
        $pages[] = [
          'path'           => $path,
          'sessions'       => self::count_value($row, 'sessions'),
          'engagementRate' => self::rate_value($row, 'engagementRate'),
        ];
      }
    }
    $metrics = [];
    $raw     = $json['metrics'] ?? NULL;
    if (is_array($raw)) {
      foreach ($raw as $metric) {
        if (is_array($metric)) {
          $metrics[] = $metric;
        }
      }
    }
    return [
      'runId'             => SiteOrigin::field($json, ['runId', 'run_id']),
      'generation'        => self::generation($json['generation'] ?? ''),
      'rangeLabel'        => SiteOrigin::field($json, ['rangeLabel', 'range_label']),
      'connectionMeta'    => SiteOrigin::field($json, ['connectionMeta', 'connection_meta']),
      'searchConsoleMeta' => SiteOrigin::field($json, ['searchConsoleMeta', 'search_console_meta']),
      'overline'          => SiteOrigin::field($json, ['overline']),
      'title'             => SiteOrigin::field($json, ['title']),
      'detail'            => SiteOrigin::field($json, ['detail']),
      'metrics'           => $metrics,
      'charts'            => $charts,
      'landingPages'      => $pages,
      'issues'            => self::issue_groups($json['issues'] ?? NULL),
    ];
  }

  /**
   * Report Issues menu groups. Unknown rows are dropped.
   *
   * @param mixed $raw
   *   Response issues.
   *
   * @return array<int, array<string, mixed>>
   */
  private static function issue_groups($raw): array {
    if (!is_array($raw)) {
      return [];
    }
    $groups = [];
    foreach ($raw as $group) {
      if (!is_array($group)) {
        continue;
      }
      $label = SiteOrigin::field($group, ['label']);
      if ('' === $label) {
        continue;
      }
      $items = [];
      $rows  = $group['items'] ?? NULL;
      if (is_array($rows)) {
        foreach ($rows as $row) {
          if (!is_array($row)) {
            continue;
          }
          $title = SiteOrigin::field($row, ['title']);
          if ('' === $title) {
            continue;
          }
          $items[] = [
            'kind'            => SiteOrigin::field($row, ['kind']),
            'key'             => SiteOrigin::field($row, ['key']),
            'title'           => $title,
            'summary'         => SiteOrigin::field($row, ['summary']),
            'fix'             => SiteOrigin::field($row, ['fix']),
            'severity'        => SiteOrigin::field($row, ['severity']),
            'occurrenceCount' => self::count_value($row, 'occurrenceCount'),
          ];
        }
      }
      $groups[] = [
        'sectionId' => SiteOrigin::field($group, ['sectionId', 'section_id']),
        'label'     => $label,
        'items'     => $items,
      ];
    }
    return $groups;
  }

  /**
   * Next page cursor, when the list is paged.
   *
   * @param array<string, mixed> $json
   *   Response body.
   */
  public static function next_cursor(array $json): string {
    return SiteOrigin::field($json, ['nextCursor', 'next_cursor']);
  }

  /**
   * List rows under the first present key.
   *
   * @param array<string, mixed> $json
   *   Response body.
   * @param array<int, string> $keys
   *   Candidate list keys.
   *
   * @return array<int, mixed>
   */
  private static function rows(array $json, array $keys): array {
    foreach ($keys as $key) {
      if (isset($json[$key]) && is_array($json[$key])) {
        return $json[$key];
      }
    }
    return [];
  }

  /**
   * Analytics gap from the scores payload, or null when it can run.
   */
  private static function analytics_setup(mixed $value): ?string {
    if ($value === 'connect_google' || $value === 'assign_site' || $value === 'not_enabled') {
      return $value;
    }
    return NULL;
  }

  /**
   * Visitor counts for the Analytics card, or null.
   *
   * @param string $service
   *   Service key.
   * @param array<string, mixed> $row
   *   Score row.
   *
   * @return array{newUsers: int, returningUsers: int, previousNewUsers: int|null, previousReturningUsers: int|null, series: array<int, int>}|null
   */
  private static function visitors(string $service, array $row): ?array {
    if ('analytics' !== $service) {
      return NULL;
    }
    $raw = $row['visitors'] ?? NULL;
    if (!is_array($raw) || !is_numeric($raw['newUsers'] ?? NULL) || !is_numeric($raw['returningUsers'] ?? NULL)) {
      return NULL;
    }
    $series = [];
    if (is_array($raw['series'] ?? NULL)) {
      foreach ($raw['series'] as $point) {
        if (is_numeric($point)) {
          $series[] = (int) $point;
        }
      }
    }
    return [
      'newUsers'               => max(0, (int) $raw['newUsers']),
      'returningUsers'         => max(0, (int) $raw['returningUsers']),
      'previousNewUsers'       => is_numeric($raw['previousNewUsers'] ?? NULL) ? (int) $raw['previousNewUsers'] : NULL,
      'previousReturningUsers' => is_numeric($raw['previousReturningUsers'] ?? NULL) ? (int) $raw['previousReturningUsers'] : NULL,
      'series'                 => $series,
    ];
  }

  /**
   * Whole-number score from 0 to 100, or null.
   *
   * @param array<string, mixed> $row
   *   Score row.
   */
  private static function score_value(array $row): ?int {
    $number = self::decimal_score($row, 'score');
    return NULL === $number ? NULL : (int) round($number);
  }

  /**
   * Engagement rate stored from 0 to 1.
   *
   * @param array<string, mixed> $row
   *   Landing row.
   */
  private static function rate_value(array $row, string $key): ?float {
    $raw = $row[$key] ?? NULL;
    if (!is_numeric($raw)) {
      return NULL;
    }
    $number = (float) $raw;
    if ($number < 0 || $number > 1) {
      return NULL;
    }
    return $number;
  }

  /**
   * Score from 0 to 100, kept to one decimal, or null.
   *
   * @param array<string, mixed> $row
   *   Score row.
   */
  private static function decimal_score(array $row, string $key): ?float {
    $raw = $row[$key] ?? NULL;
    if (!is_numeric($raw)) {
      return NULL;
    }
    $number = (float) $raw;
    if ($number < 0 || $number > 100) {
      return NULL;
    }
    return round($number, 1);
  }

  /**
   * Non-negative count. Missing or junk is zero.
   *
   * @param array<string, mixed> $row
   *   Score row.
   */
  private static function count_value(array $row, string $key): int {
    $raw = $row[$key] ?? 0;
    if (!is_numeric($raw)) {
      return 0;
    }
    $number = (int) $raw;
    return $number < 0 ? 0 : $number;
  }

  /**
   * Closed severity, or null.
   *
   * @param array<string, mixed> $row
   *   Score row.
   */
  private static function severity_value(array $row): ?string {
    $severity = SiteOrigin::field($row, ['issueSeverity', 'issue_severity']);
    if (in_array($severity, ['critical', 'high', 'medium', 'low'], TRUE)) {
      return $severity;
    }
    return NULL;
  }

  /**
   * Short band label, or null.
   *
   * @param array<string, mixed> $row
   *   Score row.
   */
  private static function band_value(array $row): ?string {
    return self::label_value($row, ['band', 'scoreBand', 'score_band']);
  }

  /**
   * Short label, or null.
   *
   * @param array<string, mixed> $row
   *   Score row.
   * @param array<int, string> $keys
   *   Candidate keys.
   */
  private static function label_value(array $row, array $keys): ?string {
    $band = SiteOrigin::field($row, $keys);
    if ('' === $band) {
      return NULL;
    }
    // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- This file is unit-tested without WordPress.
    $band = trim(strip_tags($band));
    if (strlen($band) > 80) {
      $band = substr($band, 0, 80);
    }
    return '' === $band ? NULL : $band;
  }

  /**
   * Crawl report preview. Header, first-section tables, and cards.
   * Inspector fields are dropped.
   *
   * @param array<string, mixed> $json
   *   Report response.
   *
   * @return array<string, mixed>
   */
  public static function report(array $json): array {
    $service = SiteOrigin::field($json, ['service']);
    if (!in_array($service, ['seo', 'a11y', 'ai', 'security', 'brand'], TRUE)) {
      $service = '';
    }
    $hero = $json['hero'] ?? NULL;
    return [
      'runId'      => SiteOrigin::field($json, ['runId', 'run_id']),
      'generation' => self::generation($json['generation'] ?? ''),
      'service'    => $service,
      'hero'       => self::report_hero(is_array($hero) ? $hero : []),
      'blocks'     => self::report_blocks($json['blocks'] ?? NULL),
      'issues'     => self::issue_groups($json['issues'] ?? NULL),
    ];
  }

  /**
   * @param array<string, mixed> $hero
   *   Hero object.
   * @return array<string, mixed>
   */
  private static function report_hero(array $hero): array {
    $peer    = $hero['peer'] ?? NULL;
    $metrics = [];
    if (is_array($hero['metrics'] ?? NULL)) {
      foreach (array_slice($hero['metrics'], 0, 12) as $metric) {
        if (!is_array($metric)) {
          continue;
        }
        $label = self::report_text($metric['label'] ?? '', 80);
        if ('' === $label) {
          continue;
        }
        $metrics[] = [
          'label' => $label,
          'value' => self::report_text($metric['value'] ?? '-', 40),
          'mood'  => self::report_tone($metric['mood'] ?? ''),
          'tone'  => self::report_tone($metric['tone'] ?? ''),
        ];
      }
    }
    $inventory = [];
    if (is_array($hero['inventory'] ?? NULL)) {
      foreach (array_slice($hero['inventory'], 0, 12) as $row) {
        if (!is_array($row)) {
          continue;
        }
        $label = self::report_text($row['label'] ?? '', 80);
        $value = self::report_text($row['value'] ?? '', 240);
        if ('' === $label || '' === $value) {
          continue;
        }
        $inventory[] = [
          'label' => $label,
          'value' => $value,
        ];
      }
    }
    return [
      'title'     => self::report_text($hero['title'] ?? '', 120),
      'score'     => self::report_text($hero['score'] ?? '-', 40),
      'band'      => self::report_text($hero['band'] ?? '', 80),
      'mood'      => self::report_tone($hero['mood'] ?? ''),
      'coverage'  => self::report_text($hero['coverage'] ?? '', 40),
      'peer'      => is_array($peer) ? [
        'title' => self::report_text($peer['title'] ?? '', 80),
        'score' => self::report_text($peer['score'] ?? '-', 40),
        'band'  => self::report_text($peer['band'] ?? '', 80),
        'mood'  => self::report_tone($peer['mood'] ?? ''),
      ] : NULL,
      'metrics'   => $metrics,
      'summary'   => self::report_text($hero['summary'] ?? '', 240),
      'inventory' => $inventory,
      'note'      => self::report_text($hero['note'] ?? '', 500),
    ];
  }

  /**
   * @param mixed $raw
   *   Blocks.
   * @return array<int, array<string, mixed>>
   */
  private static function report_blocks($raw): array {
    if (!is_array($raw)) {
      return [];
    }
    $blocks = [];
    foreach (array_slice($raw, 0, 24) as $block) {
      if (!is_array($block)) {
        continue;
      }
      $kind = SiteOrigin::field($block, ['kind']);
      if ('table' === $kind) {
        $blocks[] = self::report_table($block);
      }
      elseif ('card' === $kind) {
        $blocks[] = self::report_card($block);
      }
    }
    return $blocks;
  }

  /**
   * @param array<string, mixed> $block
   *   Table block.
   * @return array<string, mixed>
   */
  private static function report_table(array $block): array {
    $columns = [];
    if (is_array($block['columns'] ?? NULL)) {
      foreach (array_slice($block['columns'], 0, 12) as $column) {
        if (!is_array($column)) {
          continue;
        }
        $label = self::report_text($column['label'] ?? '', 80);
        if ('' === $label) {
          continue;
        }
        $columns[] = [
          'id'    => self::report_text($column['id'] ?? '', 40),
          'label' => $label,
          'align' => 'end' === ($column['align'] ?? '') ? 'end' : 'start',
        ];
      }
    }
    $rows = [];
    if (is_array($block['rows'] ?? NULL)) {
      foreach (array_slice($block['rows'], 0, 200) as $row) {
        if (!is_array($row) || !is_array($row['cells'] ?? NULL)) {
          continue;
        }
        $cells = [];
        foreach (array_slice($row['cells'], 0, 12) as $cell) {
          if (is_array($cell)) {
            $cells[] = self::report_cell($cell);
          }
        }
        $rows[] = [
          'id'    => self::report_text($row['id'] ?? '', 180),
          'kind'  => self::report_kind($row['kind'] ?? ''),
          'cells' => $cells,
        ];
      }
    }
    return [
      'kind'    => 'table',
      'id'      => self::report_text($block['id'] ?? '', 40),
      'heading' => self::report_text($block['heading'] ?? '', 80),
      'empty'   => self::report_text($block['empty'] ?? '', 180),
      'span'    => 'half' === ($block['span'] ?? '') ? 'half' : 'full',
      'columns' => $columns,
      'rows'    => $rows,
    ];
  }

  /**
   * @param array<string, mixed> $block
   *   Card block.
   * @return array<string, mixed>
   */
  private static function report_card(array $block): array {
    $fields = [];
    if (is_array($block['fields'] ?? NULL)) {
      foreach (array_slice($block['fields'], 0, 40) as $field) {
        if (!is_array($field)) {
          continue;
        }
        $label = self::report_text($field['label'] ?? '', 80);
        if ('' === $label) {
          continue;
        }
        $fields[] = [
          'label'    => $label,
          'value'    => self::report_text($field['value'] ?? '', 500),
          'tone'     => self::report_tone($field['tone'] ?? ''),
          'href'     => self::http_url(SiteOrigin::field($field, ['href'])),
          'caption'  => self::report_optional($field['caption'] ?? '', 240),
          'pill'     => self::report_optional($field['pill'] ?? '', 40),
          'pillTone' => self::report_accent($field['pillTone'] ?? ''),
          'group'    => self::report_optional($field['group'] ?? '', 40),
          'wide'     => !empty($field['wide']),
        ];
      }
    }
    $images = [];
    if (is_array($block['images'] ?? NULL)) {
      foreach (array_slice($block['images'], 0, 24) as $image) {
        if (!is_array($image)) {
          continue;
        }
        $src = self::http_url(SiteOrigin::field($image, ['src']));
        if (NULL === $src) {
          continue;
        }
        $href     = self::http_url(SiteOrigin::field($image, ['href']));
        $images[] = [
          'src'     => $src,
          'href'    => $href ?? $src,
          'alt'     => self::report_text($image['alt'] ?? '', 180),
          'caption' => self::report_text($image['caption'] ?? '', 180),
        ];
      }
    }
    $links = [];
    if (is_array($block['links'] ?? NULL)) {
      foreach (array_slice($block['links'], 0, 40) as $link) {
        if (!is_array($link)) {
          continue;
        }
        $href  = self::http_url(SiteOrigin::field($link, ['href']));
        $label = self::report_text($link['label'] ?? '', 240);
        if (NULL === $href || '' === $label) {
          continue;
        }
        $links[] = [
          'href'  => $href,
          'label' => $label,
        ];
      }
    }
    return [
      'kind'      => 'card',
      'id'        => self::report_text($block['id'] ?? '', 40),
      'heading'   => self::report_text($block['heading'] ?? '', 80),
      'badge'     => self::report_text($block['badge'] ?? '', 40),
      'badgeTone' => self::report_tone($block['badgeTone'] ?? ''),
      'note'      => self::report_text($block['note'] ?? '', 500),
      'href'      => self::http_url(SiteOrigin::field($block, ['href'])),
      'hrefLabel' => self::report_text($block['hrefLabel'] ?? '', 40),
      'span'      => 'half' === ($block['span'] ?? '') ? 'half' : 'full',
      'fields'    => $fields,
      'images'    => $images,
      'text'      => self::report_text($block['text'] ?? '', 4000),
      'links'     => $links,
    ];
  }

  /**
   * @param array<string, mixed> $cell
   *   Cell.
   * @return array<string, mixed>
   */
  private static function report_cell(array $cell): array {
    $image = NULL;
    if (is_array($cell['image'] ?? NULL)) {
      $src = self::http_url(SiteOrigin::field($cell['image'], ['src']));
      if (NULL !== $src) {
        $image = [
          'src' => $src,
          'alt' => self::report_text($cell['image']['alt'] ?? '', 180),
        ];
      }
    }
    return [
      'text'     => self::report_text($cell['text'] ?? '', 500),
      'href'     => self::http_url(SiteOrigin::field($cell, ['href'])),
      'image'    => $image,
      'mood'     => self::report_tone($cell['mood'] ?? ''),
      'caption'  => self::report_optional($cell['caption'] ?? '', 240),
      'pill'     => self::report_optional($cell['pill'] ?? '', 40),
      'pillTone' => self::report_accent($cell['pillTone'] ?? ''),
    ];
  }

  /**
   * Plain text, or null when empty.
   *
   * @param mixed $value
   *   Raw value.
   */
  private static function report_optional($value, int $max): ?string {
    $text = self::report_text($value, $max);
    return '' === $text ? NULL : $text;
  }

  /**
   * Pill accent, or null.
   *
   * @param mixed $value
   *   Raw accent.
   */
  private static function report_accent($value): ?string {
    $accent  = is_string($value) ? $value : '';
    $allowed = ['cyan', 'emerald', 'lime', 'amber', 'orange', 'coral', 'red', 'muted'];
    return in_array($accent, $allowed, TRUE) ? $accent : NULL;
  }

  /**
   * Plain text, tags removed, length capped.
   *
   * @param mixed $value
   *   Raw value.
   */
  private static function report_text($value, int $max): string {
    if (is_array($value) || NULL === $value) {
      return '';
    }
    // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- This file is unit-tested without WordPress.
    $text = trim(strip_tags((string) $value));
    if (strlen($text) > $max) {
      $text = substr($text, 0, $max);
    }
    return $text;
  }

  /**
   * In-flight run status, or null.
   *
   * @param mixed $value
   *   Raw generation.
   */
  private static function generation($value): ?string {
    $status = is_string($value) ? $value : '';
    return in_array($status, ['queued', 'running'], TRUE) ? $status : NULL;
  }

  /**
   * Closed colour token, or null.
   *
   * @param mixed $value
   *   Raw tone.
   */
  private static function report_tone($value): ?string {
    $tone    = is_string($value) ? $value : '';
    $allowed = [
      'great',
      'good',
      'ok',
      'poor',
      'bad',
      'critical',
      'serious',
      'moderate',
      'minor',
      'warn',
      'muted',
    ];
    return in_array($tone, $allowed, TRUE) ? $tone : NULL;
  }

  /**
   * Row filter kind, or null.
   *
   * @param mixed $value
   *   Raw kind.
   */
  private static function report_kind($value): ?string {
    $kind    = is_string($value) ? $value : '';
    $allowed = ['profile', 'content', 'allow', 'disallow', 'inherited', 'unknown'];
    return in_array($kind, $allowed, TRUE) ? $kind : NULL;
  }

  /**
   * Absolute http(s) URL with no credentials, or null.
   */
  private static function http_url(string $value): ?string {
    $value = trim($value);
    if ('' === $value || !preg_match('#^https?://#i', $value)) {
      return NULL;
    }
    if (strlen($value) > 2048) {
      return NULL;
    }
    // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Unit tests load this file without WordPress.
    $parts = parse_url($value);
    if (!is_array($parts) || empty($parts['host'])) {
      return NULL;
    }
    if (isset($parts['user']) || isset($parts['pass'])) {
      return NULL;
    }
    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    if ('http' !== $scheme && 'https' !== $scheme) {
      return NULL;
    }
    return $value;
  }

  /**
   * Newest in-flight status per service from a runs page.
   *
   * @param array<string, mixed> $json
   *   Response body.
   *
   * @return array{busy: object}
   */
  public static function in_flight_runs(array $json): array {
    $busy = [];
    $seen = [];
    foreach (self::rows($json, ['runs']) as $row) {
      if (!is_array($row)) {
        continue;
      }
      $service = SiteOrigin::field($row, ['service']);
      if (!in_array($service, self::run_services(), TRUE) || isset($seen[$service])) {
        continue;
      }
      $seen[$service] = TRUE;
      $status         = SiteOrigin::field($row, ['status']);
      if ('queued' === $status || 'running' === $status) {
        $busy[$service] = $status;
      }
    }
    return ['busy' => (object) $busy];
  }

  /**
   * Enqueue acknowledgement.
   *
   * @param array<string, mixed> $json
   *   Response body.
   *
   * @return array{runId: string, service: string}
   */
  public static function enqueued_run(array $json): array {
    return [
      'runId'   => SiteOrigin::field($json, ['runId', 'run_id']),
      'service' => SiteOrigin::field($json, ['service']),
    ];
  }

  /**
   * Services the Generate menu can queue.
   *
   * @return array<int, string>
   */
  public static function run_services(): array {
    return ['analytics', 'seo', 'a11y', 'ai', 'security', 'brand'];
  }

}
