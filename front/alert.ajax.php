<?php

// Fichier : plugins/alertcreator/front/alert.ajax.php
// Planifie une alerte (enregistrement en base) ET crée une tâche privée dans le ticket

require dirname(__DIR__, 3) . '/inc/includes.php';

// Forcer le fuseau horaire pour rester cohérent avec le cron
date_default_timezone_set('Europe/Paris');

header('Content-Type: application/json; charset=UTF-8');

try {
   if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
      http_response_code(405);
      echo json_encode(['success' => false, 'message' => 'Méthode non autorisée']);
      exit;
   }

   $ticket_id     = isset($_POST['ticket_id']) ? (int)$_POST['ticket_id'] : 0;
   $target_email  = isset($_POST['target_email']) ? trim($_POST['target_email']) : '';
   $reminder_date = isset($_POST['reminder_date']) ? trim($_POST['reminder_date']) : '';
   $message       = isset($_POST['message']) ? trim($_POST['message']) : '';

   if ($ticket_id <= 0 || empty($target_email) || empty($reminder_date) || empty($message)) {
      http_response_code(400);
      echo json_encode(['success' => false, 'message' => 'Données manquantes ou invalides']);
      exit;
   }

   if (!filter_var($target_email, FILTER_VALIDATE_EMAIL)) {
      http_response_code(400);
      echo json_encode(['success' => false, 'message' => 'Adresse e-mail invalide']);
      exit;
   }

   // Le champ datetime-local renvoie typiquement : 2025-12-13T17:49
   if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $reminder_date)) {
      http_response_code(400);
      echo json_encode(['success' => false, 'message' => 'Format de date invalide']);
      exit;
   }

   // ----- Contrôle licence / quota de créations d’alerte -----
   // Si pas de licence valide : max 3 alertes créées au total
   $license = PluginAlertcreatorLicense::getStatus();

   if (empty($license['valid'])) {
      // Utilisation du helper GLPI pour compter
      $count = countElementsInTable('glpi_plugin_alertcreator_alerts');

      if ($count >= 3) {
         http_response_code(403);
         echo json_encode([
            'success' => false,
            'message' => "Quota gratuit atteint : maximum 3 alertes créées sans licence. Veuillez activer une licence pour continuer.",
         ]);
         exit;
      }
   }

   // On enregistre tel quel, sans conversion de fuseau
   $reminder_datetime = str_replace('T', ' ', $reminder_date) . ':00';
   $now               = date('Y-m-d H:i:s');

   global $DB;

   // ---------- 1) Insertion de l’alerte dans la table du plugin ----------

   $DB->insertOrDie('glpi_plugin_alertcreator_alerts', [
      'tickets_id'        => $ticket_id,
      'target_email'      => $target_email,
      'reminder_datetime' => $reminder_datetime,
      'message'           => $message,
      'sent'              => 0,
      'created_at'        => $now,
      'updated_at'        => $now,
   ]);

   // ---------- 2) Création d’une tâche privée dans le ticket ----------

   $taskContent =
      "Alerte créée pour ce ticket.\n" .
      "Destinataire : {$target_email}.\n" .
      "Rappel prévu : {$reminder_datetime}.\n\n" .
      "Message :\n{$message}\n";

   $task = new TicketTask();

   // On laisse GLPI gérer entité, utilisateur courant, etc.
   $input = [
      'tickets_id' => $ticket_id,
      'content'    => $taskContent,
      'is_private' => 1,   // tâche privée
      'state'      => 2,   // 2 = fait (simple trace d’info)
      'actiontime' => 0,
   ];

   // Associer la tâche à l’utilisateur connecté si disponible
   $user_id = Session::getLoginUserID();
   if ($user_id) {
      $input['users_id'] = $user_id;
   }

   $task->add($input);

   echo json_encode([
      'success' => true,
      'message' => 'Alerte planifiée pour ' . $reminder_datetime,
   ]);
} catch (Throwable $e) {
   http_response_code(500);
   echo json_encode([
      'success' => false,
      'message' => 'Exception PHP',
      'error'   => $e->getMessage(),
   ]);
}
