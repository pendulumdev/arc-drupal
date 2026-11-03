<?php

declare(strict_types=1);

namespace Drupal\pendulum_arc\Rules;

/**
 * A matching acked hash means only dirty rows are sent. Otherwise the whole list replaces the target.
 */
final class KeyphrasePush {

  public const BATCH = 500;

  /**
   * @param array<int, array{path: string, phrase: string, synced_phrase: string, deleted: bool}> $rows
   *   Page rows.
   *
   * @return array{mode: string, upserts: array<int, array{path: string, focusKeyphrase: string}>, deletes: array<int, string>, send_focus: bool, focus: string|null}
   */
  public static function plan(?string $ackedHash, string $arcHash, string $focus, string $focusSynced, array $rows): array {
    $replace = $ackedHash === NULL || $ackedHash === '' || $ackedHash !== $arcHash;
    $focusPhrase = KeyphrasePhrase::list($focus);
    $syncedFocus = KeyphrasePhrase::list($focusSynced);
    $upserts = [];
    $deletes = [];
    foreach ($rows as $row) {
      $path = (string) ($row['path'] ?? '');
      if ($path === '' || $path === '/') {
        continue;
      }
      $deleted = !empty($row['deleted']);
      $phrase = KeyphrasePhrase::list((string) ($row['phrase'] ?? ''));
      $synced = KeyphrasePhrase::list((string) ($row['synced_phrase'] ?? ''));
      if ($replace) {
        if (!$deleted && $phrase !== '') {
          $upserts[] = [
            'path' => $path,
            'focusKeyphrase' => $phrase,
          ];
        }
        continue;
      }
      if ($deleted || ($phrase === '' && $synced !== '')) {
        $deletes[] = $path;
        continue;
      }
      if ($phrase !== '' && $phrase !== $synced) {
        $upserts[] = [
          'path' => $path,
          'focusKeyphrase' => $phrase,
        ];
      }
    }
    return [
      'mode' => $replace ? 'replace' : 'patch',
      'upserts' => $upserts,
      'deletes' => $deletes,
      'send_focus' => $replace || $focusPhrase !== $syncedFocus,
      'focus' => $focusPhrase === '' ? NULL : $focusPhrase,
    ];
  }

  /**
   * @param array<int, array{path: string, focusKeyphrase: string}> $upserts
   *   Upserts.
   * @param array<int, string> $deletes
   *   Paths to delete.
   *
   * @return array<int, array{upserts: array<int, array{path: string, focusKeyphrase: string}>, deletes: array<int, string>}>
   */
  public static function batches(array $upserts, array $deletes): array {
    $ops = [];
    foreach ($deletes as $path) {
      $ops[] = [
        'kind' => 'delete',
        'path' => $path,
      ];
    }
    foreach ($upserts as $row) {
      $ops[] = [
        'kind' => 'upsert',
        'row' => $row,
      ];
    }
    if (!$ops) {
      return [];
    }
    $out = [];
    foreach (array_chunk($ops, self::BATCH) as $chunk) {
      $batch = [
        'upserts' => [],
        'deletes' => [],
      ];
      foreach ($chunk as $op) {
        if ($op['kind'] === 'delete') {
          $batch['deletes'][] = $op['path'];
        }
        else {
          $batch['upserts'][] = $op['row'];
        }
      }
      $out[] = $batch;
    }
    return $out;
  }

}
