<?php

declare(strict_types=1);

namespace Drupal\yu_ipas\GeoIp;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use MaxMind\Db\Reader;

/**
 * GeoIP lookup service backed by a local MaxMind GeoLite2-City database.
 *
 * The module ships with no geo data: the database must be downloaded once via
 * the "yu-ipas:download-db" Drush command (see SettingsForm for the license
 * key configuration). Lookups are cached per IP in the default cache bin.
 */
final class GeoLookupService {

  /**
   * Cache TTL for geo lookups: 30 days.
   */
  private const CACHE_TTL = 2592000;

  /**
   * Per-request in-memory lookup cache.
   *
   * @var array<string, array|null>
   */
  private static array $staticCache = [];

  /**
   * Lazily initialised MaxMind reader, NULL when the database is unavailable.
   */
  private static ?Reader $reader = NULL;

  private static bool $readerInitialised = FALSE;

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly CacheBackendInterface $cache,
    private readonly FileSystemInterface $fileSystem,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * Looks up geo information for an IP address.
   *
   * @return array{country: string, region: string, city: string}|null
   *   Geo information with ISO country code, ISO region code (e.g. "RU-MOW")
   *   and city name, or NULL when the IP cannot be resolved.
   */
  public function lookup(string $ip): ?array {
    if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP)) {
      return NULL;
    }

    if (array_key_exists($ip, self::$staticCache)) {
      return self::$staticCache[$ip];
    }

    $cid = 'yu_ipas:geo:' . md5($ip);
    if ($cached = $this->cache->get($cid)) {
      $result = $cached->data;
      self::$staticCache[$ip] = $result;
      return $result;
    }

    $result = $this->doLookup($ip);
    self::$staticCache[$ip] = $result;
    if ($result !== NULL) {
      $this->cache->set($cid, $result, $this->time() + self::CACHE_TTL);
    }

    return $result;
  }

  /**
   * Returns TRUE when the GeoLite2 database file is readable.
   */
  public function hasDatabase(): bool {
    return $this->getReader() instanceof Reader;
  }

  /**
   * Returns the absolute filesystem path of the configured mmdb file.
   */
  public function databasePath(): ?string {
    $path = (string) ($this->configFactory->get('yu_ipas.settings')->get('mmdb_path') ?? '');
    if ($path === '') {
      return NULL;
    }
    $realpath = $this->fileSystem->realpath($path);
    return $realpath !== FALSE ? $realpath : NULL;
  }

  /**
   * Performs the actual lookup against the mmdb reader.
   */
  private function doLookup(string $ip): ?array {
    $reader = $this->getReader();
    if (!$reader instanceof Reader) {
      return NULL;
    }

    try {
      $record = $reader->get($ip);
    }
    catch (\Throwable $e) {
      $this->loggerFactory->get('yu_ipas')->warning('GeoIP lookup failed for @ip: @message', [
        '@ip' => $ip,
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }

    if (!is_array($record)) {
      return NULL;
    }

    $country = (string) ($record['country']['iso_code'] ?? '');
    $region = '';
    if ($country !== '' && isset($record['subdivisions'][0]['iso_code'])) {
      $sub = (string) $record['subdivisions'][0]['iso_code'];
      if ($sub !== '') {
        $region = $country . '-' . $sub;
      }
    }
    $city = '';
    if (isset($record['city']['names'])) {
      $names = $record['city']['names'];
      $city = (string) ($names['ru'] ?? $names['en'] ?? '');
    }

    return [
      'country' => $country,
      'region' => $region,
      'city' => $city,
    ];
  }

  /**
   * Initialises the MaxMind reader once.
   */
  private function getReader(): ?Reader {
    if (!self::$readerInitialised) {
      self::$readerInitialised = TRUE;
      if (class_exists(Reader::class)) {
        $path = $this->databasePath();
        if ($path !== NULL && is_file($path) && is_readable($path)) {
          try {
            self::$reader = new Reader($path);
          }
          catch (\Throwable $e) {
            $this->loggerFactory->get('yu_ipas')->error('Cannot open GeoLite2 database at @path: @message', [
              '@path' => $path,
              '@message' => $e->getMessage(),
            ]);
          }
        }
      }
    }
    return self::$reader;
  }

  /**
   * Injectable time wrapper for tests.
   */
  private function time(): int {
    return \time();
  }

}
