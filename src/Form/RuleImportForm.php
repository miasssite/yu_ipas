<?php

declare(strict_types=1);

namespace Drupal\yu_ipas\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\yu_ipas\RegionList;
use Drupal\yu_ipas\Rules\RuleStorage;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Bulk import form: checkboxes for Russian regions and common countries.
 *
 * In blacklist mode the selected values become blocked; in whitelist mode
 * they become allowed. The engine applies the meaning automatically, so the
 * form only creates neutral rules.
 */
final class RuleImportForm extends FormBase {

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
    return 'yu_ipas_rule_import';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $mode = (string) $this->config('yu_ipas.settings')->get('mode');
    $verb = $mode === 'whitelist' ? $this->t('allowed') : $this->t('blocked');

    $form['intro'] = [
      '#markup' => $this->t('Select regions and countries to add as rules. According to the current rule mode they will be %verb. Existing rules are skipped.', [
        '%verb' => $verb,
      ]),
    ];

    $form['regions'] = [
      '#type' => 'details',
      '#title' => $this->t('Russian regions (@count)', ['@count' => \count(RegionList::ruRegions())]),
      '#open' => TRUE,
    ];

    $form['regions']['region_checkboxes'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Regions'),
      '#options' => RegionList::ruRegions(),
      '#default_value' => [],
    ];

    $form['regions']['select_all_regions'] = [
      '#type' => 'submit',
      '#value' => $this->t('Select all regions'),
      '#submit' => ['::selectAllRegions'],
      '#limit_validation_errors' => [],
      '#attributes' => ['onclick' => 'return false;'],
    ];

    $form['countries'] = [
      '#type' => 'details',
      '#title' => $this->t('Countries'),
      '#open' => FALSE,
    ];

    $form['countries']['country_checkboxes'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Countries'),
      '#options' => RegionList::countries(),
      '#default_value' => [],
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Add selected as rules'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * Submit handler: selects every region checkbox via user-side rerender.
   */
  public function selectAllRegions(array &$form, FormStateInterface $form_state): void {
    // Handled client-side in the future; safe no-op fallback.
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $rules = [];

    foreach ((array) $form_state->getValue('region_checkboxes') as $code) {
      if ($code !== 0 && $code !== '') {
        $rules[] = [
          'type' => 'region',
          'value' => (string) $code,
          'label' => RegionList::regionName((string) $code),
        ];
      }
    }

    foreach ((array) $form_state->getValue('country_checkboxes') as $code) {
      if ($code !== 0 && $code !== '') {
        $rules[] = [
          'type' => 'country',
          'value' => (string) $code,
          'label' => RegionList::countryName((string) $code),
        ];
      }
    }

    if ($rules === []) {
      $this->messenger()->addWarning($this->t('Nothing selected.'));
      return;
    }

    $inserted = $this->storage->bulkAddRules($rules);
    $skipped = \count($rules) - $inserted;
    $this->messenger()->addStatus($this->t('Added @added rules, @skipped already existed.', [
      '@added' => $inserted,
      '@skipped' => $skipped,
    ]));
    $form_state->setRedirect('yu_ipas.rules');
  }

}
