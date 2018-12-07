<?php

namespace Drupal\ui_patterns_field_group\Plugin\field_group\FieldGroupFormatter;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Render\Element;
use Drupal\field_group\FieldGroupFormatterBase;
use Drupal\ui_patterns\Form\PatternDisplayFormTrait;
use Drupal\ui_patterns\UiPatternsSourceManager;
use Drupal\ui_patterns\UiPatternsManager;
use Drupal\ui_patterns_field_group\Utility\EntityFinder;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Plugin implementation of the 'paragraph' formatter.
 *
 * @FieldGroupFormatter(
 *   id = "pattern_formatter",
 *   label = @Translation("Pattern"),
 *   description = @Translation("Wrap fields as a pattern."),
 *   supported_contexts = {
 *     "view",
 *   }
 * )
 */
class PatternFormatter extends FieldGroupFormatterBase implements ContainerFactoryPluginInterface {

  use PatternDisplayFormTrait;

  /**
   * UI Patterns manager.
   *
   * @var \Drupal\ui_patterns\UiPatternsManager
   */
  protected $patternsManager;

  /**
   * UI Patterns manager.
   *
   * @var \Drupal\ui_patterns\UiPatternsSourceManager
   */
  protected $sourceManager;

  /**
   * Entity finder utility.
   *
   * @var \Drupal\ui_patterns_field_group\Utility\EntityFinder
   */
  protected $entityFinder;

  /**
   * Constructs a Drupal\Component\Plugin\PluginBase object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\ui_patterns\UiPatternsManager $patterns_manager
   *   UI Patterns manager.
   * @param \Drupal\ui_patterns\UiPatternsSourceManager $source_manager
   *   UI Patterns source manager.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, UiPatternsManager $patterns_manager, UiPatternsSourceManager $source_manager) {
    parent::__construct($plugin_id, $plugin_definition, $configuration['group'], $configuration['settings'], $configuration['label']);
    $this->configuration = $configuration;
    $this->patternsManager = $patterns_manager;
    $this->sourceManager = $source_manager;
    $this->entityFinder = new EntityFinder();
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('plugin.manager.ui_patterns'),
      $container->get('plugin.manager.ui_patterns_source')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function preRender(&$element, $rendering_object) {

    $fields = [];
    $mapping = $this->getSetting('pattern_mapping');
    foreach ($mapping as $field) {
      if ($field['plugin'] == 'fieldgroup') {
        // @TODO:
        // - Figure out if temporary modification in the other fieldgroup
        // patterns can be fetch. When loading from config storage, we may not
        // have the latest changes.
        // - Make this recursive. A fieldgroup can have a fieldgroup child that
        // can have a fieldgroup child and so on...
        $group_settings = $this->getSubFieldgroupPatternSettings($field);

        // Build pattern group children content.
        $child_fields = [];
        foreach ($group_settings["format_settings"]["pattern_mapping"] as $child) {
          $child_fields[$child['destination']][] = $element[$field['source']][$child['source']];
        }
        $this->determineConfigSettings($element[$field['source']], $group_settings['format_settings']['pattern'], $child_fields);
      }
      $fields[$field['destination']][] = $element[$field['source']];
    }
    $this->determineConfigSettings($element, $this->getSetting('pattern'), $fields);
  }

  /**
   * Helper to get the pattern subfieldgroup settings.
   *
   * @param $field array
   *   Array if fieldgroup pattern fields config. Its determines the type of
   *   each field within the pattern, its source and its destination.
   *
   * @return array
   *   Array of settings for the group.
   */
  protected function getSubFieldgroupPatternSettings($field) {
    $config_name_pieces = [];

    // Build the key name of the view display config that we will retrieve
    // the group config from.
    foreach (['entity_type', 'bundle', 'mode'] as $key) {
      $config_name_pieces[] = $this->configuration["group"]->{$key};
    }
    $config_name = implode('.', $config_name_pieces);

    // Fetch the child pattern configuration to know which field goes where.
    $storage = \Drupal::entityTypeManager()->getStorage('entity_view_display');
    $view_display = $storage->load($config_name);
    $group_settings = $view_display->getThirdPartySetting('field_group', $field['source']);

    return $group_settings;
  }

  /**
   * Helper to build the context expected to render the fieldgroup pattern.
   *
   * @param $element array
   *   Field data.
   * @param $pattern_id string
   *   Machine name of the pattern to load.
   * @param $fields array
   *   Array of renderable elements keyed by "regions" of the pattern where they
   *   will be rendered and where values are renderable arrays.
   */
  protected function determineConfigSettings(&$element, $pattern_id, $fields) {
    $context['#id'] = $pattern_id;
    $context['#fields'] = $fields;

    $context['#type'] = 'pattern';
    $context['#multiple_sources'] = TRUE;
    $element['#variant'] = $this->getSetting('pattern_variant');

    // Allow default context values to not override those exposed elsewhere.
    $element['#context']['type'] = 'field_group';
    $element['#context']['group_name'] = $this->configuration['group']->group_name;
    $element['#context']['entity_type'] = $this->configuration['group']->entity_type;
    $element['#context']['bundle'] = $this->configuration['group']->bundle;
    $element['#context']['view_mode'] = $this->configuration['group']->mode;

    // Pass current entity to pattern context, if any.
    $element['#context']['entity'] = $this->entityFinder->findEntityFromFields($fields);
  }

  /**
   * Get field group name.
   *
   * @return string
   *   Field group name.
   */
  protected function getFieldGroupName() {
    return $this->configuration['group']->group_name;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm() {
    $form = parent::settingsForm();
    unset($form['id']);
    unset($form['classes']);

    if (isset($this->configuration['group']->children) && !empty($this->configuration['group']->children)) {
      $context = [
        'entity_type' => $this->configuration['group']->entity_type,
        'entity_bundle' => $this->configuration['group']->bundle,
        'entity_view_mode' => $this->configuration['group']->mode,
        'limit' => $this->configuration['group']->children,
      ];

      $this->buildPatternDisplayForm($form, 'entity_display', $context, $this->configuration['settings']);
    }
    else {
      $form['message'] = [
        '#markup' => $this->t('<b>Attention:</b> you have to add fields to this field group and save the whole entity display before being able to to access the pattern display configuration.'),
      ];
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    $label = $this->t('None');
    if (!empty($this->getSetting('pattern'))) {
      $label = $this->patternsManager->getDefinition($this->getSetting('pattern'))->getLabel();
    }

    return [
      $this->t('Pattern: @pattern', ['@pattern' => $label]),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public static function defaultContextSettings($context) {
    return [
      'pattern' => '',
      'pattern_mapping' => [],
      'pattern_variant' => '',
    ] + parent::defaultContextSettings($context);
  }

}

