<?php

declare(strict_types=1);

namespace Drupal\yu_ipas\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\yu_ipas\Rules\RuleStorage;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Confirmation form for deleting an exception.
 */
final class ExceptionDeleteForm extends ConfirmFormBase {

  private int $eid = 0;

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
    return 'yu_ipas_exception_delete';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion(): \Drupal\Core\StringTranslation\TranslatableMarkup {
    $e = $this->storage->getException($this->eid);
    $label = $e->label !== '' ? $e->label : $e->type . ': ' . $e->value;
    return $this->t('Delete the exception %label?', ['%label' => $label]);
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    return Url::fromRoute('yu_ipas.exceptions');
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
  public function buildForm(array $form, FormStateInterface $form_state, ?int $eid = NULL): array {
    $this->eid = $eid ?? 0;
    if (!$this->storage->getException($this->eid)) {
      throw new NotFoundHttpException();
    }
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->storage->deleteException($this->eid);
    $this->messenger()->addStatus($this->t('The exception has been deleted.'));
    $form_state->setRedirect('yu_ipas.exceptions');
  }

}
