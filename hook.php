<?php

/**
 * Plugin AlertCreator - File: hook.php
 */

/**
 * Installation hook for the plugin.
 * Creates the necessary database tables and constraints.
 *
 * @return bool
 */
function plugin_alertcreator_install() {
   global $DB;

   if (!$DB->tableExists('glpi_plugin_alertcreator_alerts')) {
      $migration = new Migration(100);
      
      // SQL query to create the alerts table with foreign key constraint
      $query = "CREATE TABLE `glpi_plugin_alertcreator_alerts` (
         `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
         `tickets_id` INT UNSIGNED NOT NULL,
         `target_email` VARCHAR(255) NOT NULL,
         `reminder_datetime` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
         `message` TEXT NOT NULL,
         `sent` TINYINT(1) NOT NULL DEFAULT 0,
         `sent_at` TIMESTAMP NULL DEFAULT NULL,
         `last_error` TEXT NULL,
         `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
         `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
         INDEX `idx_sent_reminder` (`sent`, `reminder_datetime`),
         CONSTRAINT `fk_alert_ticket`
            FOREIGN KEY (`tickets_id`) REFERENCES `glpi_tickets` (`id`)
            ON DELETE CASCADE
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

      $migration->addPostQuery($query);
      $migration->executeMigration();
   }

   return true;
}

/**
 * Uninstallation hook for the plugin.
 * Removes the plugin's database tables.
 *
 * @return bool
 */
function plugin_alertcreator_uninstall() {
   global $DB;

   if ($DB->tableExists('glpi_plugin_alertcreator_alerts')) {
      $DB->dropTable('glpi_plugin_alertcreator_alerts');
   }

   return true;
}
