<?php

declare(strict_types=1);

namespace Drupal\yu_ipas\Rules;

/**
 * Immutable result of the rule engine evaluation.
 */
final class Decision {

  public const PASS = 'passed';
  public const BLOCK = 'blocked';
  public const EXCEPTION = 'exception';

  public function __construct(
    public readonly string $action,
    public readonly string $ip,
    public readonly ?array $geo = NULL,
    public readonly string $reason = '',
    public readonly int $ruleId = 0,
    public readonly bool $isBot = FALSE,
  ) {}

  /**
   * Returns TRUE when the request must be blocked.
   */
  public function isBlocked(): bool {
    return $this->action === self::BLOCK;
  }

}
