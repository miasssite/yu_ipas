<?php

declare(strict_types=1);

namespace Drupal\yu_ipas\Rules;

use Drupal\Core\Database\Connection;

/**
 * CRUD storage for rules, exceptions and the decision log.
 */
final class RuleStorage {

  public const TABLE_RULES = 'yu_ipas_rules';
  public const TABLE_EXCEPTIONS = 'yu_ipas_exceptions';
  public const TABLE_LOG = 'yu_ipas_log';

  public function __construct(
    private readonly Connection $database,
  ) {}

  // ---------------------------------------------------------------------------
  // Rules.
  // ---------------------------------------------------------------------------

  /**
   * Loads all rules ordered by type and creation time.
   *
   * @return array<int, object>
   *   List of rule rows (stdClass).
   */
  public function getRules(): array {
    return $this->database->select(self::TABLE_RULES, 'r')
      ->fields('r')
      ->orderBy('r.type')
      ->orderBy('r.value')
      ->execute()
      ->fetchAll();
  }

  /**
   * Loads a single rule by ID.
   */
  public function getRule(int $rid): ?object {
    $row = $this->database->select(self::TABLE_RULES, 'r')
      ->fields('r')
      ->condition('rid', $rid)
      ->execute()
      ->fetchObject();
    return $row !== FALSE ? $row : NULL;
  }

  /**
   * Inserts or updates a rule.
   *
   * @return int
   *   The rule ID.
   */
  public function saveRule(string $type, string $value, string $label, bool $enabled, ?int $rid = NULL): int {
    $fields = [
      'type' => $type,
      'value' => $value,
      'label' => $label,
      'enabled' => (int) $enabled,
    ];
    if ($rid !== NULL) {
      $this->database->update(self::TABLE_RULES)
        ->fields($fields)
        ->condition('rid', $rid)
        ->execute();
      return $rid;
    }
    $fields['created'] = \time();
    return (int) $this->database->insert(self::TABLE_RULES)
      ->fields($fields)
      ->execute();
  }

  /**
   * Deletes a rule.
   */
  public function deleteRule(int $rid): void {
    $this->database->delete(self::TABLE_RULES)->condition('rid', $rid)->execute();
  }

  /**
   * Flips the enabled flag of a rule and returns the new state.
   */
  public function toggleRule(int $rid): ?bool {
    if ($rule = $this->getRule($rid)) {
      $new = !((bool) $rule->enabled);
      $this->database->update(self::TABLE_RULES)
        ->fields(['enabled' => (int) $new])
        ->condition('rid', $rid)
        ->execute();
      return $new;
    }
    return NULL;
  }

  /**
   * Creates rules in bulk, skipping ones that already exist.
   *
   * @param array<int, array{type: string, value: string, label: string}> $rules
   *   Rules to create.
   *
   * @return int
   *   Number of newly inserted rules.
   */
  public function bulkAddRules(array $rules): int {
    $inserted = 0;
    $now = \time();
    foreach ($rules as $rule) {
      $exists = $this->database->select(self::TABLE_RULES, 'r')
        ->condition('r.type', $rule['type'])
        ->condition('r.value', $rule['value'])
        ->countQuery()
        ->execute()
        ->fetchField();
      if ((int) $exists > 0) {
        continue;
      }
      $this->database->insert(self::TABLE_RULES)
        ->fields([
          'type' => $rule['type'],
          'value' => $rule['value'],
          'label' => $rule['label'],
          'enabled' => 1,
          'created' => $now,
        ])
        ->execute();
      $inserted++;
    }
    return $inserted;
  }

  // ---------------------------------------------------------------------------
  // Exceptions.
  // ---------------------------------------------------------------------------

  /**
   * Loads all exceptions ordered by type and creation time.
   *
   * @return array<int, object>
   *   List of exception rows (stdClass).
   */
  public function getExceptions(): array {
    return $this->database->select(self::TABLE_EXCEPTIONS, 'e')
      ->fields('e')
      ->orderBy('e.type')
      ->orderBy('e.value')
      ->execute()
      ->fetchAll();
  }

  /**
   * Loads a single exception by ID.
   */
  public function getException(int $eid): ?object {
    $row = $this->database->select(self::TABLE_EXCEPTIONS, 'e')
      ->fields('e')
      ->condition('eid', $eid)
      ->execute()
      ->fetchObject();
    return $row !== FALSE ? $row : NULL;
  }

  /**
   * Inserts or updates an exception.
   *
   * @return int
   *   The exception ID.
   */
  public function saveException(string $type, string $value, string $label, bool $verifyRdns, bool $enabled, ?int $eid = NULL): int {
    $fields = [
      'type' => $type,
      'value' => $value,
      'label' => $label,
      'verify_rdns' => (int) $verifyRdns,
      'enabled' => (int) $enabled,
    ];
    if ($eid !== NULL) {
      $this->database->update(self::TABLE_EXCEPTIONS)
        ->fields($fields)
        ->condition('eid', $eid)
        ->execute();
      return $eid;
    }
    $fields['created'] = \time();
    return (int) $this->database->insert(self::TABLE_EXCEPTIONS)
      ->fields($fields)
      ->execute();
  }

  /**
   * Deletes an exception.
   */
  public function deleteException(int $eid): void {
    $this->database->delete(self::TABLE_EXCEPTIONS)->condition('eid', $eid)->execute();
  }

  // ---------------------------------------------------------------------------
  // Log.
  // ---------------------------------------------------------------------------

  /**
   * Records a decision in the log.
   */
  public function log(string $ip, ?array $geo, string $userAgent, bool $isBot, string $action, int $ruleId = 0): void {
    $this->database->insert(self::TABLE_LOG)
      ->fields([
        'created' => \time(),
        'ip' => mb_substr($ip, 0, 45),
        'country' => mb_substr($geo['country'] ?? '', 0, 4),
        'region' => mb_substr($geo['region'] ?? '', 0, 16),
        'user_agent' => mb_substr($userAgent, 0, 255),
        'is_bot' => (int) $isBot,
        'action' => $action,
        'rule_id' => $ruleId,
      ])
      ->execute();
  }

  /**
   * Deletes log rows older than the given number of days.
   *
   * @return int
   *   Number of deleted rows.
   */
  public function purgeOldLogs(int $days): int {
    if ($days <= 0) {
      return 0;
    }
    return (int) $this->database->delete(self::TABLE_LOG)
      ->condition('created', \time() - ($days * 86400), '<')
      ->execute();
  }

}
