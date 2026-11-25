<?php

declare(strict_types=1);

namespace Drupal\yu_ipas\GeoIp;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use GuzzleHttp\ClientInterface;
use MaxMind\Db\Reader;

/**
 * Downloads and installs the GeoIP City database from any supported source.
 *
 * Shared by the "drush yu-ipas:download-db" command and the "Download
 * database now" button on the settings page. Every source must end up
 * producing a MaxMind-format City .mmdb file; the downloaded artifact is
 * always validated with the MaxMind reader before it replaces the live
 * database file.
 */
final class GeoDbDownloader {

  use StringTranslationTrait;

  /**
   * Download timeout in seconds (the databases are 40-70 MB).
   */
  private const TIMEOUT = 300;

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ClientInterface $httpClient,
    private readonly FileSystemInterface $fileSystem,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
    private readonly CacheBackendInterface $cache,
    private readonly StreamWrapperManagerInterface $streamWrapperManager,
  ) {}

  /**
   * Ensures PHP stream wrappers (private:// etc.) are registered.
   *
   * Drush runs may execute this code before the kernel registers the stream
   * wrappers, in which case is_dir()/copy() on a private:// destination would
   * silently resolve to a bogus filesystem path.
   */
  private function ensureStreamWrappers(): void {
    if (!in_array('private', stream_get_wrappers(), TRUE)) {
      $this->streamWrapperManager->register();
    }
  }

  /**
   * Downloads, validates and installs the geo database.
   *
   * @param string|null $sourceId
   *   Source id override (one of DbSourceManager constants). When NULL the
   *   source configured in yu_ipas.settings:db_source is used.
   * @param string $licenseKey
   *   MaxMind license key override. When empty the key is taken from the
   *   settings or the MAXMIND_LICENSE_KEY environment variable.
   *
   * @return array{path: string, source: string, label: string, url: string}
   *   Information about the installed database.
   *
   * @throws \RuntimeException
   *   On configuration, download, extraction or validation failure. The
   *   message is safe to show to an administrator.
   */
  public function download(?string $sourceId = NULL, string $licenseKey = ''): array {
    $settings = $this->configFactory->get('yu_ipas.settings');

    $sourceId ??= (string) ($settings->get('db_source') ?: DbSourceManager::MAXMIND);
    $customUrl = (string) ($settings->get('custom_db_url') ?? '');

    if ($licenseKey === '') {
      $licenseKey = (string) ($settings->get('maxmind_key') ?? '');
    }
    if ($licenseKey === '') {
      $licenseKey = (string) (getenv('MAXMIND_LICENSE_KEY') ?: '');
    }

    try {
      $info = DbSourceManager::buildUrl($sourceId, $customUrl, $licenseKey);
    }
    catch (\InvalidArgumentException $e) {
      throw new \RuntimeException($e->getMessage());
    }

    if ($info['needs_key'] && $licenseKey === '') {
      throw new \RuntimeException((string) $this->t('No MaxMind license key. Pass --key, set yu_ipas.settings:maxmind_key, the MAXMIND_LICENSE_KEY environment variable — or switch to a mirror source that does not require registration.'));
    }

    // Resolve the destination directory from the configured stream wrapper path.
    $destPath = (string) ($settings->get('mmdb_path') ?? '');
    if ($destPath === '') {
      throw new \RuntimeException((string) $this->t('mmdb_path is empty in yu_ipas.settings.'));
    }
    $this->ensureStreamWrappers();
    $realpath = $this->fileSystem->realpath($destPath);
    $finalDir = $realpath !== FALSE ? dirname($realpath) : dirname($destPath);
    if (!is_dir($finalDir) && !@mkdir($finalDir, 0775, TRUE)) {
      throw new \RuntimeException((string) $this->t('Cannot create directory @dir.', ['@dir' => $finalDir]));
    }

    $tmpDir = sys_get_temp_dir() . '/yu_ipas_' . uniqid();
    if (!mkdir($tmpDir, 0775, TRUE)) {
      throw new \RuntimeException((string) $this->t('Cannot create temporary directory @dir.', ['@dir' => $tmpDir]));
    }

    try {
      // DB-IP: the current month file may not exist yet — retry with the
      // previous month before giving up.
      $candidates = [$info['url']];
      if (isset($info['fallback_url']) && $info['fallback_url'] !== $info['url']) {
        $candidates[] = $info['fallback_url'];
      }

      $raw = NULL;
      $usedUrl = '';
      $lastError = '';
      foreach ($candidates as $url) {
        try {
          $raw = $this->fetch($url, $tmpDir);
          $usedUrl = $url;
          break;
        }
        catch (\RuntimeException $e) {
          $lastError = $e->getMessage();
        }
      }
      if ($raw === NULL) {
        throw new \RuntimeException($lastError !== '' ? $lastError : (string) $this->t('Download failed.'));
      }

      $mmdb = $this->extractMmdb($raw, $tmpDir);
      $this->validate($mmdb, $usedUrl);

      $dest = $finalDir . '/GeoLite2-City.mmdb';
      if (!@copy($mmdb, $dest)) {
        throw new \RuntimeException((string) $this->t('Cannot copy @src to @dest.', ['@src' => $mmdb, '@dest' => $dest]));
      }

      // Reset cached geo lookups so the fresh database is used immediately.
      $this->cache->deleteAll();

      $this->loggerFactory->get('yu_ipas')->notice((string) $this->t('Geo database installed from @source: @url'), [
        '@source' => $sourceId,
        '@url' => $usedUrl,
      ]);

      return [
        'path' => $dest,
        'source' => $sourceId,
        'label' => $info['label'],
        'url' => $usedUrl,
      ];
    }
    finally {
      $this->cleanup($tmpDir);
    }
  }

  /**
   * Fetches a URL into the temporary directory.
   *
   * @return string
   *   Path of the downloaded file.
   */
  private function fetch(string $url, string $tmpDir): string {
    $sink = $tmpDir . '/download.bin';
    try {
      $this->httpClient->get($url, [
        'sink' => $sink,
        'timeout' => self::TIMEOUT,
        'connect_timeout' => 30,
        'headers' => ['User-Agent' => 'yu_ipas/1.0 (Drupal geo database downloader)'],
      ]);
    }
    catch (\Throwable $e) {
      throw new \RuntimeException((string) $this->t('Download failed from @url: @msg', ['@url' => $url, '@msg' => $e->getMessage()]));
    }

    if (!is_file($sink) || filesize($sink) === 0) {
      throw new \RuntimeException((string) $this->t('Download from @url returned an empty file.', ['@url' => $url]));
    }
    return $sink;
  }

  /**
   * Produces a plain .mmdb file from the downloaded artifact.
   *
   * Supports raw .mmdb, gzipped .mmdb.gz and the MaxMind tar.gz archive —
   * the format is detected by magic bytes, not by file extension.
   *
   * @return string
   *   Path of the extracted .mmdb file.
   */
  private function extractMmdb(string $file, string $tmpDir): string {
    $handle = fopen($file, 'rb');
    if ($handle === FALSE) {
      throw new \RuntimeException((string) $this->t('Cannot open downloaded file @file.', ['@file' => $file]));
    }
    $magic = (string) fread($handle, 2);
    fclose($handle);

    if ($magic !== "\x1f\x8b") {
      // Not gzip: accept only if it already looks like an mmdb file.
      if ($this->looksLikeMmdb($file)) {
        return $file;
      }
      throw new \RuntimeException((string) $this->t('Downloaded file is neither gzip-compressed nor a valid .mmdb file.'));
    }

    // Stream-decompress instead of gzdecode() so that a 150 MB database never
    // has to fit into memory at once.
    $in = @gzopen($file, 'rb');
    if ($in === FALSE) {
      throw new \RuntimeException((string) $this->t('Cannot gunzip the downloaded file.'));
    }

    $decodedFile = $tmpDir . '/decoded.bin';
    $out = fopen($decodedFile, 'wb');
    if ($out === FALSE) {
      throw new \RuntimeException((string) $this->t('Cannot write @file.', ['@file' => $decodedFile]));
    }
    while (!gzeof($in)) {
      $chunk = gzread($in, 262144);
      if ($chunk === FALSE || fwrite($out, $chunk) === FALSE) {
        fclose($out);
        gzclose($in);
        throw new \RuntimeException((string) $this->t('Cannot gunzip the downloaded file.'));
      }
    }
    fclose($out);
    gzclose($in);

    // After gunzip the payload may be a tar archive (MaxMind) or a plain mmdb.
    $handle = fopen($decodedFile, 'rb');
    fseek($handle, 257);
    $isTar = fread($handle, 5) === 'ustar';
    fclose($handle);

    if ($isTar) {
      $tarFile = $tmpDir . '/archive.tar';
      rename($decodedFile, $tarFile);
      try {
        $tar = new \PharData($tarFile);
        $tar->extractTo($tmpDir, NULL, TRUE);
      }
      catch (\Throwable $e) {
        throw new \RuntimeException((string) $this->t('Tar extraction failed: @msg', ['@msg' => $e->getMessage()]));
      }
      $mmdb = $this->findMmdb($tmpDir);
      if ($mmdb === NULL) {
        throw new \RuntimeException((string) $this->t('No .mmdb file found inside the downloaded archive.'));
      }
      return $mmdb;
    }

    rename($decodedFile, $tmpDir . '/decoded.mmdb');
    return $tmpDir . '/decoded.mmdb';
  }

  /**
   * Validates that the file is loadable by the MaxMind reader.
   */
  private function validate(string $file, string $url): void {
    if (!class_exists(Reader::class)) {
      // Cannot validate without the library; the lookup service will report
      // the missing library on its own.
      return;
    }
    try {
      $reader = new Reader($file);
      // Any lookup attempt forces the reader to parse the metadata section;
      // a truncated or bogus file (e.g. an HTML error page) throws here.
      $reader->get('8.8.8.8');
    }
    catch (\Throwable $e) {
      throw new \RuntimeException((string) $this->t('The file downloaded from @url is not a valid MaxMind mmdb database: @msg', ['@url' => $url, '@msg' => $e->getMessage()]));
    }
  }

  /**
   * Cheap structural check for a raw mmdb file (metadata marker present).
   */
  private function looksLikeMmdb(string $file): bool {
    $size = filesize($file);
    if ($size === FALSE || $size < 100) {
      return FALSE;
    }
    $tail = (string) file_get_contents($file, FALSE, NULL, max(0, $size - 4096), 4096);
    return str_contains($tail, "\xab\xcd\xefMaxMind.com");
  }

  /**
   * Recursively finds the first .mmdb file inside the directory.
   */
  private function findMmdb(string $dir): ?string {
    $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
    foreach ($iterator as $file) {
      if ($file->isFile() && str_ends_with($file->getFilename(), '.mmdb')) {
        return $file->getPathname();
      }
    }
    return NULL;
  }

  /**
   * Removes the temporary download directory.
   */
  private function cleanup(string $dir): void {
    if (!is_dir($dir)) {
      return;
    }
    $it = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
      \RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($it as $file) {
      $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
    }
    @rmdir($dir);
  }

}
