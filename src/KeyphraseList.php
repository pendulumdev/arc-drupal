<?php

declare(strict_types=1);

namespace Drupal\pendulum_arc;

use Drupal\Core\Database\Connection;
use Drupal\Core\State\StateInterface;
use Drupal\pendulum_arc\Rules\KeyphraseCursor;
use Drupal\pendulum_arc\Rules\PageAlerts;

/**
 * Local keyphrase list. One row per path. The scan and save hooks write here.
 */
final class KeyphraseList {

  public const STATE_KEY = 'pendulum_arc.keyphrases';

  public function __construct(
    private readonly Connection $database,
    private readonly StateInterface $state,
  ) {}

  /**
   * @return array{enabled: bool, acked_hash: string, focus: string, focus_synced: string, cursor: array<string, mixed>, sources: array<int, string>, scan_done: bool, sync_id: string, notice: bool, diverged: bool}
   */
  public function state(): array {
    $stored = $this->state->get(self::STATE_KEY);
    if (!is_array($stored)) {
      $stored = [];
    }
    $cursor = $stored['cursor'] ?? [];
    if (!is_array($cursor)) {
      $cursor = [];
    }
    $sources = $stored['sources'] ?? [];
    if (!is_array($sources)) {
      $sources = [];
    }
    return [
      'enabled' => !empty($stored['enabled']),
      'acked_hash' => (string) ($stored['acked_hash'] ?? ''),
      'focus' => (string) ($stored['focus'] ?? ''),
      'focus_synced' => (string) ($stored['focus_synced'] ?? ''),
      'cursor' => $cursor,
      'sources' => array_values(array_map('strval', $sources)),
      'scan_done' => !empty($stored['scan_done']),
      'sync_id' => (string) ($stored['sync_id'] ?? ''),
      'notice' => !empty($stored['notice']),
      'diverged' => !empty($stored['diverged']),
    ];
  }

  /**
   * Turns the list on from an empty table, or clears it when turned off.
   *
   * @param array<int, string> $sources
   *   Entity types that store a focus keyphrase. Used only when turning on.
   */
  public function setEnabled(bool $enabled, array $sources = []): void {
    if (!$enabled) {
      $this->wipeRows();
      $this->write([
        'enabled' => FALSE,
        'acked_hash' => '',
        'focus' => '',
        'focus_synced' => '',
        'cursor' => [],
        'sources' => [],
        'scan_done' => FALSE,
        'sync_id' => '',
        'notice' => TRUE,
        'diverged' => FALSE,
      ]);
      return;
    }
    $this->wipeRows();
    $this->write([
      'enabled' => TRUE,
      'acked_hash' => '',
      'focus' => '',
      'focus_synced' => '',
      'cursor' => KeyphraseCursor::start(),
      'sources' => array_values($sources),
      'scan_done' => FALSE,
      'sync_id' => '',
      'notice' => FALSE,
      'diverged' => FALSE,
    ]);
  }

  /**
   * Drops the accepted hash and starts the scan again.
   *
   * @param array<int, string> $sources
   *   Entity types to scan.
   */
  public function resetScan(array $sources): void {
    $state = $this->state();
    if (!$state['enabled']) {
      return;
    }
    $this->wipeRows();
    $state['acked_hash'] = '';
    $state['focus'] = '';
    $state['focus_synced'] = '';
    $state['cursor'] = KeyphraseCursor::start();
    $state['sources'] = array_values($sources);
    $state['scan_done'] = FALSE;
    $state['sync_id'] = '';
    $state['diverged'] = FALSE;
    $this->write($state);
  }

  /**
   * @param array<string, mixed> $state
   *   Sync state.
   */
  public function write(array $state): void {
    $this->state->set(self::STATE_KEY, $state);
  }

  /**
   * Site focus. The homepage is not a page row.
   */
  public function setFocus(string $phrase): void {
    $state = $this->state();
    $state['focus'] = $phrase;
    $this->write($state);
  }

  /**
   * Inserts or updates one path. A changed path tombstones the previous row.
   */
  public function put(string $kind, string $objectId, string $langcode, string $path, string $phrase): void {
    if (!$this->tableReady()) {
      return;
    }
    $existing = $this->byObject($kind, $objectId, $langcode);
    $hash = hash('sha256', $path);
    if (is_array($existing) && (string) $existing['path_hash'] !== $hash) {
      $this->tombstoneHash((string) $existing['path_hash']);
    }
    $this->database->merge('pendulum_arc_keyphrases')
      ->keys(['path_hash' => $hash])
      ->insertFields([
        'path_hash' => $hash,
        'path' => $path,
        'phrase' => $phrase,
        'synced_phrase' => '',
        'deleted' => 0,
        'entity_type' => $kind,
        'entity_id' => $objectId,
        'langcode' => $langcode,
      ])
      ->updateFields([
        'path' => $path,
        'phrase' => $phrase,
        'deleted' => 0,
        'entity_type' => $kind,
        'entity_id' => $objectId,
        'langcode' => $langcode,
      ])
      ->execute();
  }

  /**
   * Marks a path deleted when Arc has accepted it. A row Arc has never seen is removed.
   */
  public function clearPath(string $kind, string $objectId, string $langcode, string $path): void {
    if (!$this->tableReady()) {
      return;
    }
    $existing = $this->byObject($kind, $objectId, $langcode);
    if (is_array($existing)) {
      $this->tombstoneHash((string) $existing['path_hash']);
    }
    if ($path !== '') {
      $this->tombstoneHash(hash('sha256', $path));
    }
  }

  /**
   * @return array<int, array{path: string, phrase: string, synced_phrase: string, deleted: bool}>
   */
  public function rows(): array {
    if (!$this->tableReady()) {
      return [];
    }
    $found = $this->database->select('pendulum_arc_keyphrases', 'k')
      ->fields('k', ['path', 'phrase', 'synced_phrase', 'deleted'])
      ->execute();
    $out = [];
    foreach ($found as $row) {
      $out[] = [
        'path' => (string) $row->path,
        'phrase' => (string) $row->phrase,
        'synced_phrase' => (string) $row->synced_phrase,
        'deleted' => (bool) $row->deleted,
      ];
    }
    return $out;
  }

  /**
   * Pages that are still in the list. Tombstones are omitted.
   *
   * @return array<int, array{path: string, phrase: string}>
   */
  public function currentPages(): array {
    $pages = [];
    foreach ($this->rows() as $row) {
      if ($row['deleted'] || $row['phrase'] === '' || $row['path'] === '' || $row['path'] === '/') {
        continue;
      }
      $pages[] = [
        'path' => $row['path'],
        'phrase' => $row['phrase'],
      ];
    }
    return $pages;
  }

  /**
   * How many current page rows are stored.
   */
  public function pageCount(): int {
    if (!$this->tableReady()) {
      return 0;
    }
    $query = $this->database->select('pendulum_arc_keyphrases', 'k');
    $query->condition('deleted', 0);
    $query->condition('phrase', '', '<>');
    $query->condition('path', '/', '<>');
    $query->condition('path', '', '<>');
    return (int) $query->countQuery()->execute()->fetchField();
  }

  /**
   * The menu count and the banner. A dirty row is enough.
   */
  public function needsSync(bool $connected): bool {
    $state = $this->state();
    $ready = $state['enabled'] && $state['scan_done'] && $connected;
    $clean = $state['focus'] === $state['focus_synced'] && empty($state['diverged']);
    $dirty = $ready && $clean && $this->hasDirty();
    return PageAlerts::keyphrasePending(
      $state['enabled'],
      $state['scan_done'],
      $connected,
      $state['focus'],
      $state['focus_synced'],
      $dirty,
      !empty($state['diverged']),
    );
  }

  /**
   * A row Arc has not accepted, including a tombstone.
   */
  public function hasDirty(): bool {
    if (!$this->tableReady()) {
      return FALSE;
    }
    $query = $this->database->select('pendulum_arc_keyphrases', 'k');
    $or = $query->orConditionGroup()
      ->condition('deleted', 1)
      ->where('phrase <> synced_phrase');
    $query->condition($or);
    return (int) $query->countQuery()->execute()->fetchField() > 0;
  }

  /**
   * Copies the accepted phrases over the synced columns and drops tombstones.
   */
  public function ack(string $hash, string $focus): void {
    if ($this->tableReady()) {
      $this->database->delete('pendulum_arc_keyphrases')
        ->condition('deleted', 1)
        ->execute();
      $this->database->query('UPDATE {pendulum_arc_keyphrases} SET synced_phrase = phrase WHERE deleted = 0');
    }
    $state = $this->state();
    $state['acked_hash'] = $hash;
    $state['focus'] = $focus;
    $state['focus_synced'] = $focus;
    $state['sync_id'] = '';
    $state['diverged'] = FALSE;
    $this->write($state);
  }

  /**
   * Removes every row. Used when sync is turned off.
   */
  public function wipeRows(): void {
    if (!$this->tableReady()) {
      return;
    }
    $this->database->truncate('pendulum_arc_keyphrases')->execute();
  }

  /**
   * @return array<string, mixed>|null
   */
  private function byObject(string $kind, string $objectId, string $langcode): ?array {
    $row = $this->database->select('pendulum_arc_keyphrases', 'k')
      ->fields('k', ['path_hash', 'path', 'synced_phrase', 'deleted'])
      ->condition('entity_type', $kind)
      ->condition('entity_id', $objectId)
      ->condition('langcode', $langcode)
      ->orderBy('deleted', 'ASC')
      ->range(0, 1)
      ->execute()
      ->fetchAssoc();
    return is_array($row) ? $row : NULL;
  }

  /**
   * Tombstones a row Arc has accepted. A row that was never sent is deleted.
   */
  private function tombstoneHash(string $hash): void {
    $row = $this->database->select('pendulum_arc_keyphrases', 'k')
      ->fields('k', ['synced_phrase'])
      ->condition('path_hash', $hash)
      ->execute()
      ->fetchAssoc();
    if (!is_array($row)) {
      return;
    }
    if ((string) ($row['synced_phrase'] ?? '') === '') {
      $this->database->delete('pendulum_arc_keyphrases')
        ->condition('path_hash', $hash)
        ->execute();
      return;
    }
    $this->database->update('pendulum_arc_keyphrases')
      ->fields([
        'deleted' => 1,
        'phrase' => '',
      ])
      ->condition('path_hash', $hash)
      ->execute();
  }

  private function tableReady(): bool {
    return $this->database->schema()->tableExists('pendulum_arc_keyphrases');
  }

}
