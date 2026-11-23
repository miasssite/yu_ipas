<?php

declare(strict_types=1);

namespace Drupal\yu_ipas\Rules;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\yu_ipas\GeoIp\GeoLookupService;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Evaluates a request IP against exceptions, rules and the configured mode.
 */
final class RuleEngine {

  public const MODE_BLACKLIST = 'blacklist';
  public const MODE_WHITELIST = 'whitelist';

  /**
   * Bot UA patterns used for statistics only.
   */
  private const BOT_PATTERN = '/(bot|crawler|spider|slurp|curl|wget|httpclient|ahrefs|semrush|mj12|petalbot|dotbot|gigablast|baidu|sogou|yandex|google|bing|duckduck)/i';

  /**
   * Reverse-DNS suffixes used to verify trusted bots.
   */
  private const TRUSTED_BOT_RDNS = [
    '.yandex.ru',
    '.yandex.net',
    '.yandex.com',
    '.googlebot.com',
    '.google.com',
    '.bing.com',
    '.crawl.apple.com',
  ];

  /**
   * Per-request cache of rules/exceptions and UA-exception rDNS verdicts.
   *
   * @var array<string, mixed>
   */
  private array $runtimeCache = [];

  public function __construct(
    private readonly GeoLookupService $geoLookup,
    private readonly RuleStorage $storage,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly CacheBackendInterface $cache,
  ) {}

  /**
   * Evaluates the incoming request and returns a decision.
   */
  public function decide(string $ip, string $userAgent): Decision {
    $config = $this->configFactory->get('yu_ipas.settings');
    $mode = (string) $config->get('mode');
    $geo = $this->geoLookup->lookup($ip);
    $isBot = (bool) preg_match(self::BOT_PATTERN, $userAgent);

    // 1. Exceptions always win.
    if ($exception = $this->matchException($ip, $userAgent, (bool) $config->get('bot_rdns_verify'))) {
      return new Decision(Decision::EXCEPTION, $ip, $geo, $exception, 0, $isBot);
    }

    $rules = $this->getEnabledRules();

    // 2. Manual IP / CIDR rules always match directly (blacklist: block,
    //    whitelist: allow).
    foreach ($rules as $rule) {
      if ($rule->type === 'ip' && strcasecmp($rule->value, $ip) === 0) {
        return $this->ruleDecision($mode, $ip, $geo, $isBot, $rule);
      }
      if ($rule->type === 'cidr' && IpUtils::checkIp($ip, $rule->value)) {
        return $this->ruleDecision($mode, $ip, $geo, $isBot, $rule);
      }
    }

    // 3. Geo rules depend on the mode.
    if ($mode === self::MODE_WHITELIST) {
      // Allow only listed regions/countries; block everything else.
      foreach ($rules as $rule) {
        if ($rule->type === 'region' && $geo !== NULL && $geo['region'] === $rule->value) {
          return new Decision(Decision::PASS, $ip, $geo, 'whitelist region ' . $rule->value, (int) $rule->rid, $isBot);
        }
        if ($rule->type === 'country' && $geo !== NULL && $geo['country'] === $rule->value) {
          return new Decision(Decision::PASS, $ip, $geo, 'whitelist country ' . $rule->value, (int) $rule->rid, $isBot);
        }
      }
      return new Decision(Decision::BLOCK, $ip, $geo, 'whitelist: geography not allowed', 0, $isBot);
    }

    // Blacklist mode: block on any matching geo rule, pass otherwise.
    foreach ($rules as $rule) {
      if ($rule->type === 'region' && $geo !== NULL && $geo['region'] === $rule->value) {
        return new Decision(Decision::BLOCK, $ip, $geo, 'blacklist region ' . $rule->value, $rule->rid, $isBot);
      }
      if ($rule->type === 'country' && $geo !== NULL && $geo['country'] === $rule->value) {
        return new Decision(Decision::BLOCK, $ip, $geo, 'blacklist country ' . $rule->value, $rule->rid, $isBot);
      }
    }

    return new Decision(Decision::PASS, $ip, $geo, 'no matching rule', 0, $isBot);
  }

  /**
   * Builds a decision for a matched manual ip/cidr rule according to mode.
   */
  private function ruleDecision(string $mode, string $ip, ?array $geo, bool $isBot, object $rule): Decision {
    if ($mode === self::MODE_WHITELIST) {
      return new Decision(Decision::PASS, $ip, $geo, 'whitelist rule #' . $rule->rid, (int) $rule->rid, $isBot);
    }
    return new Decision(Decision::BLOCK, $ip, $geo, 'blacklist rule #' . $rule->rid, (int) $rule->rid, $isBot);
  }

  /**
   * Finds the first matching enabled exception.
   */
  private function matchException(string $ip, string $userAgent, bool $rdnsVerifyEnabled): ?string {
    foreach ($this->getEnabledExceptions() as $e) {
      switch ($e->type) {
        case 'ip':
          if (strcasecmp($e->value, $ip) === 0) {
            return 'trusted IP';
          }
          break;

        case 'cidr':
          if (IpUtils::checkIp($ip, $e->value)) {
            return 'trusted CIDR';
          }
          break;

        case 'ua':
          $pattern = '/' . preg_quote($e->value, '/') . '/i';
          if (preg_match($pattern, $userAgent) === 1) {
            if ((bool) $e->verify_rdns && $rdnsVerifyEnabled) {
              if (!$this->verifyBotRdns($ip)) {
                // UA claims to be a bot but the reverse DNS does not confirm
                // it: treat the request as spoofing and do NOT exempt it.
                return NULL;
              }
              return 'trusted bot (verified by rDNS)';
            }
            return 'trusted User-Agent';
          }
          break;
      }
    }
    return NULL;
  }

  /**
   * Verifies the client IP belongs to a trusted bot network via rDNS.
   *
   * Uses forward-confirmed reverse DNS: host -> rDNS name -> A record must
   * resolve back to the same IP. Result is cached for 6 hours per IP.
   */
  private function verifyBotRdns(string $ip): bool {
    $cid = 'yu_ipas:rdns:' . md5($ip);
    if ($cached = $this->cache->get($cid)) {
      return (bool) $cached->data;
    }

    $verified = FALSE;
    $host = @gethostbyaddr($ip);
    if ($host !== FALSE && $host !== $ip) {
      foreach (self::TRUSTED_BOT_RDNS as $suffix) {
        if (str_ends_with(strtolower($host), $suffix)) {
          // Forward confirmation.
          $forward = @gethostbyname($host);
          $verified = $forward !== $host && strcasecmp($forward, $ip) === 0;
          break;
        }
      }
    }

    $this->cache->set($cid, $verified, \time() + 21600);
    return $verified;
  }

  /**
   * Detects whether a User-Agent looks like a bot.
   */
  public static function isBot(string $userAgent): bool {
    return (bool) preg_match(self::BOT_PATTERN, $userAgent);
  }

  /**
   * Loads enabled rules (cached per request).
   *
   * @return array<int, object>
   */
  private function getEnabledRules(): array {
    if (!isset($this->runtimeCache['rules'])) {
      $this->runtimeCache['rules'] = array_values(array_filter(
        $this->storage->getRules(),
        static fn(object $r): bool => (bool) $r->enabled,
      ));
    }
    return $this->runtimeCache['rules'];
  }

  /**
   * Loads enabled exceptions (cached per request).
   *
   * @return array<int, object>
   */
  private function getEnabledExceptions(): array {
    if (!isset($this->runtimeCache['exceptions'])) {
      $this->runtimeCache['exceptions'] = array_values(array_filter(
        $this->storage->getExceptions(),
        static fn(object $e): bool => (bool) $e->enabled,
      ));
    }
    return $this->runtimeCache['exceptions'];
  }

  /**
   * Resets the per-request runtime cache.
   */
  public function resetRuntimeCache(): void {
    $this->runtimeCache = [];
  }

}
