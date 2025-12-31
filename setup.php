<?php

// Fichier : plugins/alertcreator/setup.php

function plugin_init_alertcreator() {
   global $PLUGIN_HOOKS;

   // Page de configuration pour ce plugin
   $PLUGIN_HOOKS['config_page']['alertcreator'] = 'front/config.form.php';

   // Charger le JS sur l'interface (tickets, etc.)
   // GLPI ajoutera <script src="plugins/alertcreator/js/alertcreator.js"> dans les pages.
   $PLUGIN_HOOKS['add_javascript']['alertcreator'][] = 'js/alertcreator.js';
}

function plugin_version_alertcreator() {
   return [
      'name'         => 'AlertCreator',
      'version'      => '1.0.1',
      'author'       => 'COREFORGE, Jessy Chaila',
      'license'      => 'GPLv2+',
      'homepage'     => '',
      'requirements' => [
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
