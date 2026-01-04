<?php

/**
 * Plugin AlertCreator - File: setup.php
 */

function plugin_init_alertcreator() {
   global $PLUGIN_HOOKS;

   // Configuration page for this plugin
   $PLUGIN_HOOKS['config_page']['alertcreator'] = 'front/config.form.php';

   // Load JavaScript on the interface (tickets, etc.)
   // MODIFICATION: Check if user is on Central interface (Admin/Tech) before loading the JS
   if (isset($_SESSION['glpiactiveprofile']['interface']) && $_SESSION['glpiactiveprofile']['interface'] == 'central') {
       $PLUGIN_HOOKS['add_javascript']['alertcreator'][] = 'js/alertcreator.js';
   }
}

function plugin_version_alertcreator() {
   return [
      'name'           => __('AlertCreator', 'alertcreator'),
      'version'        => '1.0.1',
      'author'         => 'COREFORGE, Jessy Chaila',
      'license'        => 'GPLv2+',
      'homepage'       => '',
      'requirements'   => [
         'glpi' => [
            'min' => '11.0.0',
         ],
      ],
   ];
}

function plugin_alertcreator_check_prerequisites() {
   return true;
}

function plugin_alertcreator_check_config($verbose = false) {
   return true;
}
