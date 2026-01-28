<?php

/**
 * Plugin AlertCreator - File: setup.php
 */

if (!defined('GLPI_ROOT')) { die("Sorry. You can't access this file directly"); }

function plugin_init_alertcreator() {
   global $PLUGIN_HOOKS;

   $PLUGIN_HOOKS['csrf_compliant']['alertcreator'] = true;
   $PLUGIN_HOOKS['config_page']['alertcreator'] = 'front/config.form.php';

   // ON FORCE LE CHARGEMENT (Pas de condition IF)
   $PLUGIN_HOOKS['add_javascript']['alertcreator'][] = 'js/alertcreator.js';

   // Chargement des classes
   $plugin_dir = Plugin::getPhpDir('alertcreator');
   if (file_exists($plugin_dir . "/inc/license.class.php")) {
       include_once($plugin_dir . "/inc/license.class.php");
   }
}


function plugin_version_alertcreator() {
   return [
      'name'           => 'AlertCreator',
      'version'        => '1.0.3',
      'author'         => 'COREFORGE, Jessy Chaila',
      'license'        => 'GPLv2+',
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
