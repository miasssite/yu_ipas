<?php

declare(strict_types=1);

namespace Drupal\yu_ipas\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\yu_ipas\Rules\RuleStorage;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Confirmation form for deleting a rule.
 */
final class RuleDeleteForm extends ConfirmFormBase {

  private int $rid = 0;

  public function __construct(
    private readonly RuleStorage $storage,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('yu_ipas.rule_storage'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'yu_ipas_rule_delete';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion(): \Drupal\Core\StringTranslation\TranslatableMarkup {
    return $this->t('Delete the rule %rule?', ['%rule' => $this->ruleLabel()]);
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    return Url::fromRoute('yu_ipas.rules');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText(): \Drupal\Core\StringTranslation\TranslatableMarkup {
    return $this->t('Delete');
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?int $rid = NULL): array {
    $this->rid = $rid ?? 0;
    if (!$this->storage->getRule($this->rid)) {
      throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
    }
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->storage->deleteRule($this->rid);
    $this->messenger()->addStatus($this->t('The rule has been deleted.'));
    $form_state->setRedirect('yu_ipas.rules');
  }

  /**
   * Human readable label of the rule being deleted.
   */
  private function ruleLabel(): string {
    $rule = $this->storage->getRule($this->rid ?? 0);
    if (!$rule) {
      return '';
    }
    return $rule->label !== '' ? $rule->label : $rule->type . ': ' . $rule->value;
  }

}
