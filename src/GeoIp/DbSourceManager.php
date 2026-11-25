<?php

declare(strict_types=1);

namespace Drupal\yu_ipas\GeoIp;

/**
 * Registry of GeoIP database download sources (official + mirrors).
 *
 * The module reads MaxMind-format .mmdb files only, so every source must
 * ultimately provide a GeoLite2-compatible City database. Sources without
 * registration are preferred for production deployments where a MaxMind
 * license key is not available.
 *
 * Not supported on purpose:
 *  - IP2Location LITE — requires registration and ships a custom .DAT/.BIN
 *    format that the MaxMind reader cannot parse.
 *  - Sypex Geo (SxGeo) — downloadable without registration, but uses its own
 *    proprietary binary format (SxGeo.dat), not mmdb.
 * Both would require a separate lookup engine; use the "custom" source with
 * any direct .mmdb(.gz) URL instead.
 */
final class DbSourceManager {

  public const MAXMIND = 'maxmind';
  public const P3TERX = 'p3terx';
  public const JSDELIVR = 'jsdelivr';
  public const DBIP = 'dbip';
  public const CUSTOM = 'custom';

  /**
   * Returns metadata for every supported download source.
   *
   * @return array<string, array{label: string, description: string, needs_key: bool, url: string}>
   *   Source definitions keyed by source id. The "url" key documents the
   *   template used to build the actual download URL.
   */
  public static function all(): array {
    return [
      self::MAXMIND => [
        'label' => 'MaxMind (official)',
        'description' => 'Official GeoLite2-City tar.gz from download.maxmind.com. Requires a free license key.',
        'needs_key' => TRUE,
        'url' => 'https://download.maxmind.com/geoip/databases/GeoLite2-City/download',
      ],
      self::P3TERX => [
        'label' => 'P3TERX mirror (GitHub)',
        'description' => 'Raw GeoLite2-City.mmdb from the P3TERX/GeoLite.mmdb GitHub repository. Updated every 2-3 days. No registration required.',
        'needs_key' => FALSE,
        'url' => 'https://raw.githubusercontent.com/P3TERX/GeoLite.mmdb/download/GeoLite2-City.mmdb',
      ],
      self::JSDELIVR => [
        'label' => 'jsDelivr CDN mirror',
        'description' => 'Gzipped GeoLite2-City.mmdb.gz served via the jsDelivr CDN (npm geolite2-city package). Stable URL for automation. No registration required.',
        'needs_key' => FALSE,
        'url' => 'https://cdn.jsdelivr.net/npm/geolite2-city/GeoLite2-City.mmdb.gz',
      ],
      self::DBIP => [
        'label' => 'DB-IP Lite',
        'description' => 'Monthly dbip-city-lite-YYYY-MM.mmdb.gz from db-ip.com. Free, no registration required.',
        'needs_key' => FALSE,
        'url' => 'https://download.db-ip.com/free/dbip-city-lite-{Y}-{M}.mmdb.gz',
      ],
      self::CUSTOM => [
        'label' => 'Custom URL',
        'description' => 'Any direct URL to a .mmdb or gzipped .mmdb.gz file (own mirror, self-hosted copy).',
        'needs_key' => FALSE,
        'url' => '',
      ],
    ];
  }

  /**
   * Returns options array for a form select element.
   *
   * @return array<string, string>
   *   Source id => label (with a short hint).
   */
  public static function options(): array {
    $options = [];
    foreach (self::all() as $id => $source) {
      $suffix = (string) t($source['needs_key'] ? ' (license key required)' : ' (no registration)');
      $options[$id] = (string) t($source['label']) . $suffix;
    }
    return $options;
  }

  /**
   * Builds the concrete download URL for a source.
   *
   * @param string $sourceId
   *   One of the source id constants.
   * @param string $customUrl
   *   User-provided URL, used only by the "custom" source.
   * @param string $licenseKey
   *   MaxMind license key, appended as a query parameter for the official source.
   * @param int|null $timestamp
   *   Reference timestamp for month-based sources (DB-IP). Defaults to now.
   *
   * @return array{url: string, needs_key: bool, label: string}
   *   Resolved download info.
   *
   * @throws \InvalidArgumentException
   *   Unknown source id or missing custom URL.
   */
  public static function buildUrl(string $sourceId, string $customUrl = '', string $licenseKey = '', ?int $timestamp = NULL): array {
    $sources = self::all();
    if (!isset($sources[$sourceId])) {
      throw new \InvalidArgumentException((string) t('Unknown geo database source "@source".', ['@source' => $sourceId]));
    }
    $source = $sources[$sourceId];
    $timestamp ??= \time();

    switch ($sourceId) {
      case self::MAXMIND:
        return [
          'url' => $source['url'] . '?license_key=' . rawurlencode($licenseKey),
          'needs_key' => TRUE,
          'label' => (string) t($source['label']),
        ];

      case self::P3TERX:
      case self::JSDELIVR:
        return [
          'url' => $source['url'],
          'needs_key' => FALSE,
          'label' => (string) t($source['label']),
        ];

      case self::DBIP:
        // DB-IP publishes one file per month; the current month may not exist
        // yet early in the month, so fall back to the previous month.
        $current = gmdate('Y-m', $timestamp);
        $previous = gmdate('Y-m', strtotime('-1 month', $timestamp));
        return [
          'url' => strtr($source['url'], [
            '{Y}-{M}' => $current,
            '{Y}-{M_PREV}' => $previous,
          ]),
          'needs_key' => FALSE,
          'label' => (string) t($source['label']),
          'fallback_url' => strtr($source['url'], ['{Y}-{M}' => $previous]),
        ];

      case self::CUSTOM:
        $url = trim($customUrl);
        if ($url === '') {
          throw new \InvalidArgumentException((string) t('The "custom" source requires a direct .mmdb or .mmdb.gz URL in the settings.'));
        }
        if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $url)) {
          throw new \InvalidArgumentException((string) t('"@url" is not a valid http(s) URL for the custom geo database source.', ['@url' => $url]));
        }
        return [
          'url' => $url,
          'needs_key' => FALSE,
          'label' => (string) t($source['label']),
        ];
    }

    throw new \InvalidArgumentException((string) t('Unknown geo database source "@source".', ['@source' => $sourceId]));
  }

}
