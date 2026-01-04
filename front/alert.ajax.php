<?php

/**
 * Plugin AlertCreator - File: alert.ajax.php
 * Schedules an alert (database record) AND creates a private task in the ticket.
 */

require dirname(__DIR__, 3) . '/inc/includes.php';

// Force timezone consistency with the cron job
date_default_timezone_set('Europe/Paris');

header('Content-Type: application/json; charset=UTF-8');

try {
   if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
      http_response_code(405);
      echo json_encode(['success' => false, 'message' => __('Méthode non autorisée', 'alertcreator')]);
      exit;
   }

   $ticket_id     = isset($_POST['ticket_id']) ? (int)$_POST['ticket_id'] : 0;
   $target_email  = isset($_POST['target_email']) ? trim($_POST['target_email']) : '';
   $reminder_date = isset($_POST['reminder_date']) ? trim($_POST['reminder_date']) : '';
   $message       = isset($_POST['message']) ? trim($_POST['message']) : '';

   if ($ticket_id <= 0 || empty($target_email) || empty($reminder_date) || empty($message)) {
      http_response_code(400);
      echo json_encode(['success' => false, 'message' => __('Données manquantes ou invalides', 'alertcreator')]);
      exit;
   }

   if (!filter_var($target_email, FILTER_VALIDATE_EMAIL)) {
      http_response_code(400);
      echo json_encode(['success' => false, 'message' => __('Adresse e-mail invalide', 'alertcreator')]);
      exit;
   }

   // Validate datetime-local format: YYYY-MM-DDTHH:MM
   if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $reminder_date)) {
      http_response_code(400);
      echo json_encode(['success' => false, 'message' => __('Format de date invalide', 'alertcreator')]);
      exit;
   }

   // ----- License / Alert quota check -----
   // If no valid license: maximum 3 alerts allowed
   $license = PluginAlertcreatorLicense::getStatus();

   if (empty($license['valid'])) {
      $count = countElementsInTable('glpi_plugin_alertcreator_alerts');

      if ($count >= 3) {
         http_response_code(403);
         echo json_encode([
            'success' => false,
            'message' => __("Quota gratuit atteint : maximum 3 alertes créées sans licence. Veuillez activer une licence pour continuer.", 'alertcreator'),
         ]);
         exit;
      }
   }

   // Prepare date format for database insertion
   $reminder_datetime = str_replace('T', ' ', $reminder_date) . ':00';
   $now               = date('Y-m-d H:i:s');

   global $DB;

   // ---------- 1) Alert insertion into plugin table ----------

   $DB->insertOrDie('glpi_plugin_alertcreator_alerts', [
      'tickets_id'        => $ticket_id,
      'target_email'      => $target_email,
      'reminder_datetime' => $reminder_datetime,
      'message'           => $message,
      'sent'              => 0,
      'created_at'        => $now,
      'updated_at'        => $now,
   ]);

   // ---------- 2) Create a private task in the ticket ----------

   $taskContent =
      __('Alerte créée pour ce ticket.', 'alertcreator') . "\n" .
      __('Destinataire :', 'alertcreator') . " {$target_email}.\n" .
      __('Rappel prévu :', 'alertcreator') . " {$reminder_datetime}.\n\n" .
      __('Message :', 'alertcreator') . "\n{$message}\n";

   $task = new TicketTask();

   $input = [
      'tickets_id' => $ticket_id,
      'content'    => $taskContent,
      'is_private' => 1, // Private task
      'state'      => 2, // 2 = Done (information log)
      'actiontime' => 0,
   ];

   // Associate task with current logged-in user if available
   $user_id = Session::getLoginUserID();
   if ($user_id) {
      $input['users_id'] = $user_id;
   }

   $task->add($input);

   echo json_encode([
      'success' => true,
      'message' => sprintf(__('Alerte planifiée pour %s', 'alertcreator'), $reminder_datetime),
   ]);
} catch (Throwable $e) {
   http_response_code(500);
   echo json_encode([
      'success' => false,
      'message' => __('Exception PHP', 'alertcreator'),
      'error'   => $e->getMessage(),
   ]);
}
