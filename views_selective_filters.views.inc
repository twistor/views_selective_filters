<?php

/**
 * @file
 * Views hooks for views_selective_filters.module.
 */

/**
 * Implements hook_views_data_alter().
 */
function views_selective_filters_views_data_alter(array &$data) {
  $endswith = function ($haystack, $needle) {
    return $needle === '' || substr($haystack, -strlen($needle)) === $needle;
  };

  foreach ($data as $table_name => $table_info) {
    foreach ($table_info as $field_name => $field_info) {
      if (empty($field_info['filter']['id']) || $field_info['filter']['id'] === 'views_selective_filters_filter') {
        continue;
      }

      // Ignore translated, format and delta.
      if ($endswith($field_name, '_i18n') || $endswith($field_name, '_format') || $field_name === 'delta') {
        continue;
      }

      // Compute underlying field name ($real_field).
      if (isset($field_info['filter']['field_name'])) {
        $real_field = $field_info['filter']['field_name'];
      }
      elseif (isset($field_info['filter']['real field'])) {
        $real_field = $field_info['filter']['real field'];
      }
      elseif (!empty($field_info['field'])) {
        $real_field = $field_name;
      }
      else {
        continue;
      }

      $selective = $field_info;

      if (!empty($field_info['filter']['title'])) {
        $title = $field_info['filter']['title'];
      }
      elseif (!empty($field_info['title'])) {
        $title = $field_info['title'];
      }
      else {
        $title = $real_field;
      }

      $selective['title'] = t('@title (selective)', ['@title' => $title]);

      $selective['filter']['id'] = 'views_selective_filters_filter';
      $selective['filter']['proxy'] = $field_info['filter']['id'];
      $selective['filter']['field'] = $field_name;
      $selective['filter']['field_base'] = $real_field;

      unset(
        $selective['argument'],
        $selective['field'],
        $selective['relationship'],
        $selective['sort'],
        $selective['filter']['title']
      );

      $data[$table_name][$field_name . '_selective'] = $selective;
    }
  }
}
