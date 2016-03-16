<?php

/**
 * @file
 * Contains \Drupal\views_selective_filters\Plugin\views\filter\Selective.
 */

namespace Drupal\views_selective_filters\Plugin\views\filter;

use Drupal\Core\Form\FormStateInterface;
use Drupal\views\Plugin\views\filter\InOperator;
use Drupal\views\Views;

/**
 * Views filter handler for selective values.
 *
 * @ingroup views_filter_handlers
 *
 * @ViewsFilter("views_selective_filters_filter")
 */
class Selective extends InOperator {

  /**
   * The original filter value options, if it's an options list handler.
   *
   * @var array|false
   */
  protected $originalOptions;

  protected static $results;

  /**
   * {@inheritdoc}
   */
  protected function defineOptions() {
    $options = parent::defineOptions();

    // Storage for field used to display values.
    $options['selective_display_field']['default'] = '';
    // Storage for sort used to sort display values.
    $options['selective_display_sort']['default'] = 'ASC';
    // Storage for aggregated fields
    $options['selective_aggregated_fields']['default'] = '';
    // Limit aggregated items to prevent a huge number of options in select.
    $options['selective_items_limit']['default'] = 100;

    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function getValueOptions() {
    if (isset($this->valueOptions)) {
      return $this->valueOptions;
    }

    $this->valueOptions = [];

    // If $this->view->selective_oids means that the handler is being called
    // inside the cloned view used to obtain the selective values and thus this
    // is to prevent infinite recursive loop.
    if (empty($this->view->selective_oids) && !empty($this->view->inited)) {
      $this->valueOptions = $this->getOids();
      debug('called');
      // TODO: Omit null values in result: they are improperly handled.
      // When constructing the query.
      $this->valueOptions = array_diff_key($this->valueOptions, ['' => NULL]);
      // Set a flag in the view so we know it is using selective filters.
      $this->view->using_selective = TRUE;
    }
    else {
      if (!empty($this->view->selective_oids)) {
        $this->valueOptions = [];
      }
      else {
        // This is a special case, if $this->valueOptions is not an array
        // then parent::valueForm() will throw an exception, so,
        // in our custom override no form is generated when $this->valueOptions
        // is not an array. We only want this to happen in the administrative
        // interface.
        // unset($this->valueOptions);
      }
    }

    return $this->valueOptions;
  }

  /**
   * {@inheritdoc}
   */
  protected function valueForm(&$form, FormStateInterface $form_state) {
    $this->getValueOptions();
    // If you call parent::valueForm() and $this->valueOptions
    // is not an array, an exception is thrown.
    if (isset($this->valueOptions) && is_array($this->valueOptions)) {
      parent::valueForm($form, $form_state);
    }
    // Avoid the 'illegal values' Form API error.
    $form['value']['#validated'] = TRUE;
    // Add behaviour for ajax block refresh.
    // Don't do this if the view is being executed
    // to obtain selective values.
    // if (empty($this->view->selective_oids)) {
    //   $form['#attached']['js'][] = drupal_get_path('module', 'views_filters_selective') . '/js/attachBehaviours.js';
    // }
  }

  /**
   * Checks if two base fields are compatible.
   */
  protected function BaseFieldCompatible($base_field1, $base_field2) {
    return strpos($base_field2, $base_field1) === 0;
    return preg_match('/^' . $base_field1 . '/', $base_field2);
  }

  /**
   * {@inheritdoc}
   */
  public function buildOptionsForm(&$form, FormStateInterface $form_state) {
    $base_field = $this->definition['field_base'];

    parent::buildOptionsForm($form, $form_state);
    // Filter should always be exposed, show warning.
    array_unshift($form['expose_button'], array(
      'warning' => array(
        '#type' => 'markup',
        '#markup' => '<div class="messages warning">' . t('This filter is always exposed to users.') . '</div>',
      )));
    // Remove option to unexpose filter. Tried to disable, but did not work.
    $form['expose_button']['checkbox']['checkbox']['#type'] = 'hidden';
    unset($form['expose_button']['button']);
    unset($form['expose_button']['markup']);
    // Do not allow to check "all values".
    $form['value']['#attributes']['disabled'] = 'disabled';
    // Cannot group without values.
    unset($form['group_button']);

    // Preload handlers, sorts and filters.
    // This gest cached all along.
    $this->view->display_handler->getHandlers('field');
    $this->view->display_handler->getHandlers('sort');
    $this->view->display_handler->getHandlers('filter');

    // Add combo to pick display field for filter.
    $options = [];
    foreach ($this->view->display_handler->handlers['field'] as $key => $handler) {
      if ($this->BaseFieldCompatible($base_field, $handler->field)) {
        $options[$handler->options['id']] = $handler->definition['group'] . ': ' . $handler->definition['title'] . '(' . $handler->label() . ')';
      }
    }

    $form['selective_display_field'] = array(
      '#title' => t('Display field'),
      '#type' => 'select',
      '#description' => t('Field to be used for the selective options.'),
      '#options' => $options,
      '#default_value' => $this->options['selective_display_field'],
    );

    // Add combo to pick sort for display.
    $options = [];
    $options['NONE'] = t('No sorting');
    // Add option for custom sortings.
    if ($this->getOriginalOptions()) {
      $options['ORIG'] = t('As the original filter');
    }
    $options['KASC'] = t('Custom key ascending (ksort)');
    $options['KDESC'] = t('Custom key descending (ksort reverse)');
    $options['ASC'] = t('Custom ascending (asort)');
    $options['DESC'] = t('Custom descending (asort reverse)');
    // TODO: Allow the use of view's sorts!
    //foreach ($this->view->display_handler->handlers['sort'] as $key => $handler) {
    //  $options[$handler->options['id']] = $handler->definition['group'] . ': ' . $handler->definition['title'];
    //}
    $form['selective_display_sort'] = array(
      '#title' => t('Sort field'),
      '#type' => 'select',
      '#description' => t('Choose wich field to use for display'),
      '#options' => $options,
      '#default_value' => $this->options['selective_display_sort'],
    );
    $form['selective_items_limit'] = array(
      '#title' => t('Limit number of select items'),
      '#type' => 'textfield',
      '#description' => t("Don't allow a badly configured selective filter to return thousands of possible values. Enter a limit or remove any value for no limit. We recommend to set a limit no higher than 100."),
      '#default_value' => $this->options['selective_items_limit'],
      '#min' => 0,
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildExposeForm(&$form, FormStateInterface $form_state) {
    parent::buildExposeForm($form, $form_state);
    // Remove reduce resultset interface.
    unset($form['expose']['reduce']);
    // TODO: Populated somewhere through AJAX, I could not find it....
    // Provide default value for filter name.
    if (empty($form['expose']['identifier']['#default_value'])) {
      $form['expose']['identifier']['#default_value'] = $this->options['field'];
    }
    if (empty($form['expose']['label']['#default_value'])) {
      $form['expose']['label']['#default_value'] = $this->definition['title'];
    }
    if (empty($form['ui_name']['#default_value'])) {
      $form['ui_name']['#default_value'] = $this->definition['title'];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function query() {
    // If this view was constructed to obtain the selective values for this
    // handler, the handler should not add any constraints itself.
    if (isset($this->view->selective_handler_signature) && $this->getSignature() === $this->view->selective_handler_signature) {
      return;
    }
    // Decode the values to restore special chars.
    if (is_array($this->value)) {
      $this->value = array_map('urldecode', $this->value);
    }
    elseif (is_string($this->value)){
      $this->value = urldecode($this->value);
    }
    parent::query();
  }

  /**
   * Returns a signature for current filter handler.
   *
   * @return string
   *   The signature.
   */
  protected function getSignature() {
    return hash('sha256', serialize(array(
      'name' => $this->view->name,
      'args' => $this->view->args,
      'input' => $this->view->exposed_input,
      'base_field' => $this->definition['field_base'],
      'real_field' => $this->real_field,
      'field' => $this->field,
      'table' => $this->table,
      'ui_name' => $this->options['ui_name'],
    )));
  }

  /**
   * Returns a list of options for current view, only at runtime.
   */
  protected function getOids() {
    // Parameters that we will be using during the process.
    $base_field = $this->definition['field_base'];
    $ui_name = $this->options['ui_name'];
    $signature = $this->getSignature();

    // Prevent same filters from being recalculated.
    if (empty(static::$results[$signature])) {
      // We don't want a badly configured selective filter
      // to return thousands of possible values.
      $max_items = (int) $this->options['selective_items_limit'];

      // Clone the view (so it works while editting) and get all results.
      $view_copy = Views::executableFactory()->get($this->view->storage);
      if (!$view_copy) {
        return NULL;
      }
      // Store a flag so that we can know from other places
      // that this view is being used to obtain selective data.
      $view_copy->selective_oids = TRUE;
      // Store information about what filter is this view being used for.
      $view_copy->selective_handler_signature = $signature;
      // Transfer contextual information to cloned view.
      $view_copy->setExposedInput($this->view->getExposedInput());
      $view_copy->setArguments($this->view->args);

      // Mess up with the field used for distinct have thousands of elements.
      // Limit result set to 100: anything above is not user friendly at all.
      $view_copy->setItemsPerPage($max_items);

      // Remove paging, and page number from context.
      if (isset($_GET['items_per_page'])) {
        $items_per_page = $_GET['items_per_page'];
        unset($_GET['items_per_page']);
      }
      if (isset($_GET['page'])) {
        $exposed_page = $_GET['page'];
        unset($_GET['page']);
      }

      // Manipulate display + default: don't know if fields are overriden.
      $display = $view_copy->display[$this->view->current_display];
      $display_default = $view_copy->display['default'];

      // Remove any exposed form configuration. This showed up with BEF module!
      unset($display->display_options['exposed_form']);
      unset($display_default->display_options['exposed_form']);

      // Also disable attachments.
      $display->handler->definition['accept attachments'] = FALSE;
      $display_default->handler->definition['accept attachments'] = FALSE;

      // If we are using fields from default or current display.
      if (isset($display->display_options['fields'])) {
        $display_options_fields = &$display->display_options['fields'];
      }
      else {
        $display_options_fields = &$display_default->display_options['fields'];
      }

      // Original implementation based field matching on ui_name matches
      // so we need to preserve backwards compatibility.
      $field_to_keep = $this->options['selective_display_field'];

      // Remove all fields but the one used to display and aggregate.
      foreach ($display_options_fields as $key => $value) {
        if ($key !== $field_to_keep) {
          unset($display_options_fields[$key]);
        }
        else {
          // If there is a group column on the field, remove it so Field Collections will work.
          // https://www.drupal.org/node/2333065
          unset($display_options_fields[$key]['group_column']);
        }
      }

      // Check to see if the user remembered to add the field.
      if (empty($display_options_fields)) {
        drupal_set_message(t('Selective query filter must have corresponding field added to view with Administrative Name set to "@name" and Base Type "@type"',
          array(
            '@name' => $ui_name,
            '@type' => $base_field)),
            'error');
        return [];
      }

      // Get ID of field that will be used for rendering.
      $display_field = reset($display_options_fields);

      // Get field Id.
      $display_field_id = $display_field['id'];

      // Check that relationships are coherent between Field and Filter.
      $no_display_field_relationship = empty($display_field['relationship']) || $display_field['relationship'] === 'none';
      $no_filter_relationship = empty($this->options['relationship']) || $this->options['relationship'] === 'none';
      $equal
        = (($no_display_field_relationship === TRUE) && ($no_filter_relationship === TRUE)) ||
        ($display_field['relationship'] === $this->options['relationship']);

      if (!$equal) {
        drupal_set_message(t('Selective filter "@name": relationship of field and filter must match.',
          array(
            '@name' => $ui_name,
            '@type' => $base_field)),
            'error');
        return [];
      }

      // If main field is excluded from presentation, bring it back.
      // Set group type for handler to populate database relationships in query.
      $display_field['exclude'] = 0;
      $display_field['group_type'] = 'group';

      // Remove all sorting: sorts must be added to aggregate fields.
      unset($display->display_options['sorts']);
      unset($display_default->display_options['sorts']);

      // Turn this into an aggregate query.
      $display->display_options['group_by'] = 1;
      $display->handler->options['group_by'] = 1;

      $display_default->display_options['group_by'] = 1;
      $display_default->handler->options['group_by'] = 1;

      // Aggregate is incompatible with distinct and pure distinct.
      // At least it does not make sense as it is implemented now.
      unset($display_default->display_options['query']['options']['distinct']);
      unset($display_default->display_options['query']['options']['pure_distinct']);

      unset($display->display_options['query']['options']['distinct']);
      unset($display->display_options['query']['options']['pure_distinct']);

      // Make sure we are not using a pager to prevent unnecessary count(*) queries.
      $display->display_options['pager'] = unserialize('a:2:{s:4:"type";s:4:"none";s:7:"options";a:1:{s:6:"offset";s:1:"0";}}');
      $display_default->display_options['pager'] = unserialize('a:2:{s:4:"type";s:4:"none";s:7:"options";a:1:{s:6:"offset";s:1:"0";}}');

      // Some style plugins can affect the built query, make sure
      // we use a reliable field based style plugin.
      $display->display_options['style_plugin'] = 'default';
      $display->display_options['style_options'] = unserialize('a:4:{s:9:"row_class";s:0:"";s:17:"default_row_class";i:1;s:17:"row_class_special";i:1;s:11:"uses_fields";i:0;}');
      $display->display_options['row_plugin'] = 'fields';
      $display->display_options['row_options'] = unserialize('s:6:"fields";');

      // Run View.
      $view_copy->execute($this->view->current_display);

      // Restore context parameters for real View.
      if (isset($items_per_page)) {
        $_GET['items_per_page'] = $items_per_page;
      }
      if (isset($exposed_page)) {
        $_GET['page'] = $exposed_page;
      }

      // Get Handler after execution.
      $display_field_handler = $view_copy->field[$display_field_id];

      // We show human-readable values when case.
      if (method_exists($display_field_handler, 'getValueOptions')) {
        $display_field_handler->getValueOptions();
      }

      // Create array of objects for selector.
      $oids = [];
      $field_alias = isset($display_field_handler->aliases[$display_field_handler->real_field]) ? $display_field_handler->aliases[$display_field_handler->real_field] : $display_field_handler->table_alias . '_' . $display_field_handler->real_field;
      foreach ($view_copy->result as $index => $row) {
        // $key = $display_field_handler->get_value($row) should be more robust.
        // But values are sometimes nested arrays, and we need single values.
        // For the filters.
        $key = $display_field_handler->get_value($row);
        if (is_array($key)) {
          $key = $row->{$field_alias};
        }
        $value = strip_tags($view_copy->render_field($display_field_id, $index));
        $oids[$key] = empty($value) ? t('Empty (@key)', array('@key' => empty($key) ? json_encode($key) : $key)) : $value;
      }

      // Sort values.
      $sort_option = $this->options['selective_display_sort'];
      switch($sort_option) {
        case 'ASC':
          asort($oids);
          break;

        case 'DESC':
          arsort($oids);
          break;

        case 'KASC':
          ksort($oids);
          break;

        case 'KDESC':
          krsort($oids);
          break;

        case 'ORIG':
          $oids = static::filterOriginalOptions($this->getOriginalOptions(), array_keys($oids));
          break;

        case 'NONE':
          break;

        default:
          asort($oids);
      }

      // If limit exceeded this field is not good for being "selective".
      if (!empty($max_items) && count($oids) == $max_items) {
        drupal_set_message(t('Selective filter "@field" has limited the amount of total results. Please, review you query configuration.', array('@field' => $ui_name)), 'warning');
      }

      static::$results[$signature] = $oids;
      $view_copy->destroy();
    }
    return static::$results[$signature];
  }

  /**
   * Filters a list of original options according to selected set.
   *
   * @param array $options
   *   The options list of the original filter.
   * @param array $set
   *   The narrowed set of results provided by the cloned view.
   *
   * @return array
   *   The original filter options list narrowed to the cloned query results.
   */
  static protected function filterOriginalOptions($options, $set) {
    $filtered = array();

    foreach ($options as $key => $value) {
      // Handle grouped options.
      // @see hook_options_list().
      if (is_array($value)) {
        $nested = static::filterOriginalOptions($value, $set);
        if (!empty($nested)) {
          $filtered[$key] = $nested;
        }
        continue;
      }
      if (in_array($key, $set)) {
        $filtered[$key] = $value;
      }
    }

    return $filtered;
  }

  /**
   * Returns the original filter value options, if provides an options list.
   *
   * @return array|false
   *   The original filter option list, if available, or FALSE.
   */
  protected function getOriginalOptions() {
    if (!isset($this->originalOptions)) {
      // $this->originalOptions = FALSE;
      // $class = $this->definition['proxy'];
      // $original_filter = new $class([], '', []);
      // if (is_callable(array($original_filter, 'getValueOptions'))) {
      //   $original_filter->set_definition($this->definition);
      //   $options = $original_filter->getValueOptions();
      //   // We store only non-empty array.
      //   if (is_array($options) && !empty($options)) {
      //     $this->originalOptions = $options;
      //   }
      //   else {
      //     $this->originalOptions = array();
      //   }
      // }
    }

    return $this->originalOptions;
  }

}
