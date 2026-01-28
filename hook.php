<?php

/**
 * Plugin AlertCreator - File: hook.php
 */

/**
 * Plugin installation process
 * @return boolean
 */
function plugin_alertcreator_install() {
   global $DB;

   // 1. Table creation (if needed)
   $migration = new Migration(100);
   if (!$DB->tableExists('glpi_plugin_alertcreator_alerts')) {
      $query = "CREATE TABLE `glpi_plugin_alertcreator_alerts` (
                  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
                  `tickets_id` int(11) unsigned NOT NULL DEFAULT '0',
                  `target_email` varchar(255) NOT NULL,
                  `reminder_date` datetime NOT NULL,
                  `message` text,
                  `is_sent` tinyint(1) NOT NULL DEFAULT '0',
                  `date_creation` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
                  PRIMARY KEY (`id`),
                  KEY `tickets_id` (`tickets_id`),
                  KEY `is_sent` (`is_sent`),
                  KEY `reminder_date` (`reminder_date`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
      $migration->addPostQuery($query);
   }
   $migration->executeMigration();

   // 2. Register Rights
   if (class_exists('PluginAlertcreatorProfile')) {
       PluginAlertcreatorProfile::createAdminAccess($_SESSION['glpiactiveprofile']['id']);
   }

   // 3. AUTOMATIC JS ASSETS INSTALLATION (COPIED FROM PURCHASEMANAGER LOGIC)
   // This ensures the JS file is copied to the public folder where browsers can read it.
   $js_source    = __DIR__ . '/js/alertcreator.js';
   $js_dest_dir  = GLPI_ROOT . '/public/plugins/alertcreator/js';
   $js_dest_file = $js_dest_dir . '/alertcreator.js';

   if (!is_dir($js_dest_dir)) {
       // Create directory with proper permissions
       mkdir($js_dest_dir, 0755, true);
   }
   
   if (file_exists($js_source)) {
       // Copy the file to public assets
       copy($js_source, $js_dest_file);
   }

   return true;
}

/**
 * Plugin uninstallation process
 * @return boolean
 */
function plugin_alertcreator_uninstall() {
   global $DB;
   
   // Tables are usually kept on uninstall, but rights can be cleaned up
   // $DB->delete('glpi_profilerights', ['name' => 'plugin_alertcreator']);

   return true;
}
