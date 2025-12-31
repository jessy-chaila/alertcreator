#!/usr/bin/php
<?php
// Script CLI : envoi des alertes planifiées du plugin AlertCreator
// À lancer via cron sous l'utilisateur www-data

// Racine GLPI
define('GLPI_ROOT', dirname(__DIR__, 3)); // /var/www/html/glpi

// Forcer le fuseau horaire du script PHP (pour les logs et l'affichage mail)
date_default_timezone_set('Europe/Paris');

// ------------------------------------------------------------
// 1) Paramètres de connexion SQL GLPI
// => À ADAPTER une seule fois selon /etc/glpi/config_db.php
// ------------------------------------------------------------
$host = 'localhost';            // hôte MySQL/MariaDB
$name = 'glpi';                 // nom de la base GLPI
$user = 'glpi';            // utilisateur DB GLPI
$pass = 'glpi'; // mot de passe de cet utilisateur

// ------------------------------------------------------------
// NE RIEN MODIFIER EN DESSOUS, sauf si tu sais ce que tu fais
// ------------------------------------------------------------

// ---------- Connexion PDO à la base GLPI ----------
$dsn = "mysql:host={$host};dbname={$name};charset=utf8mb4";
try {
   $pdo = new PDO($dsn, $user, $pass, [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
   ]);

   // --- CORRECTION HEURE (GLPI 11+) ---
   // On force la session MySQL en UTC pour lire les TIMESTAMP bruts stockés par GLPI
   $pdo->exec("SET time_zone = '+00:00'");

} catch (PDOException $e) {
   fwrite(STDERR, "Connexion DB échouée : " . $e->getMessage() . "\n");
   exit(1);
}

// ---------------------------------------------------------------------
// 2) Charger la config AlertCreator depuis glpi_configs (context=plugin_alertcreator)
// ---------------------------------------------------------------------
$pluginContext = 'plugin_alertcreator';
$from_email_default = '';
$subject_prefix_default = '[GLPI] Rappel';

$from_email = $from_email_default;
$subject_prefix = $subject_prefix_default;
$smtp_from_email = '';
$plugin_base_url = '';
$plugin_logo_url = '';

// Lecture des clés pertinentes dans glpi_configs
$stmtConf = $pdo->prepare("
   SELECT name, value
   FROM glpi_configs
   WHERE context = :ctx
     AND name IN ('from_email','subject_prefix','smtp_from','base_url','logo_url')
");
$stmtConf->execute([':ctx' => $pluginContext]);

while ($row = $stmtConf->fetch()) {
   $name = $row['name'];
   $value = $row['value'] ?? '';

   switch ($name) {
      case 'from_email':
         if ($value !== '') {
            $from_email = $value;
         }
         break;
      case 'subject_prefix':
         if ($value !== '') {
            $subject_prefix = $value;
         }
         break;
      case 'smtp_from':
         if ($value !== '') {
            $smtp_from_email = $value;
         }
         break;
      case 'base_url':
         if ($value !== '') {
            $plugin_base_url = rtrim($value, '/');
         }
         break;
      case 'logo_url':
         if ($value !== '') {
            $plugin_logo_url = $value;
         }
         break;
   }
}

// Si pas de from_email défini, on tombe sur smtp_from
if (empty($from_email) && !empty($smtp_from_email)) {
   $from_email = $smtp_from_email;
}

// URL de base effective : plugin_base_url (sinon liens relatifs)
$base_url = $plugin_base_url; 
// Logo : URL complète issue de la config plugin (sinon pas de logo)
$logo_url = $plugin_logo_url;

// ---------------------------------------------------------------------
// 2bis) Écriture d'un log de debug dans un répertoire système de logs
// ---------------------------------------------------------------------
$log_dir = '/var/log/glpi'; // À adapter si besoin
$cron_log_path = $log_dir . '/alertcreator_cron_debug.log';

// Créer le répertoire de logs s'il n'existe pas
if (!is_dir($log_dir)) {
   @mkdir($log_dir, 0770, true);
}

$debug_line = sprintf(
   "[%s] from_email=%s | base_url=%s | logo_url=%s\n",
   date('Y-m-d H:i:s'),
   $from_email ?: '(vide)',
   $base_url ?: '(vide)',
   $logo_url ?: '(vide)'
);

// Tenter d'écrire le fichier (pas bloquant si ça échoue)
@file_put_contents($cron_log_path, $debug_line, FILE_APPEND);


// ---------------------------------------------------------------------
// 3) Récupérer les alertes à envoyer
// ---------------------------------------------------------------------

// --- CORRECTION HEURE (GLPI 11+) ---
// On utilise gmdate() pour avoir l'heure UTC actuelle et comparer avec la base UTC
$now = gmdate('Y-m-d H:i:s');

$sqlAlerts = "
   SELECT id, tickets_id, target_email, reminder_datetime, message
   FROM glpi_plugin_alertcreator_alerts
   WHERE sent = 0
     AND reminder_datetime <= :now
   ORDER BY reminder_datetime ASC
   LIMIT 100
";

$stmtAlerts = $pdo->prepare($sqlAlerts);
$stmtAlerts->execute([':now' => $now]);
$alerts = $stmtAlerts->fetchAll();

if (!$alerts) {
   // Rien à faire
   exit(0);
}

// Préparer les requêtes pour réutiliser les statements
$stmtTicket = $pdo->prepare("
   SELECT name, status
   FROM glpi_tickets
   WHERE id = :id
");

$stmtUpdate = $pdo->prepare("
   UPDATE glpi_plugin_alertcreator_alerts
   SET sent = :sent,
       sent_at = :sent_at,
       last_error = :last_error,
       updated_at = :updated_at
   WHERE id = :id
");

// Statuts GLPI simplifiés (optionnel, juste informatif)
$statusMap = [
   1 => 'Nouveau',
   2 => 'Attribué',
   3 => 'Planifié',
   4 => 'En attente',
   5 => 'Résolu',
   6 => 'Clos',
];

// ---------------------------------------------------------------------
// 4) Boucle d'envoi
// ---------------------------------------------------------------------
foreach ($alerts as $alert) {
   $alert_id = (int)$alert['id'];
   $ticket_id = (int)$alert['tickets_id'];
   $target_email = $alert['target_email'];
   $reminder_dt_utc = $alert['reminder_datetime']; // valeur UTC brute
   $message = $alert['message'];

   // Conversion UTC -> Europe/Paris pour affichage dans le mail
   try {
      $dt = new DateTime($reminder_dt_utc, new DateTimeZone('UTC'));
      $dt->setTimezone(new DateTimeZone('Europe/Paris'));
      $reminder_dt_local = $dt->format('Y-m-d H:i:s');
   } catch (Exception $e) {
      $reminder_dt_local = $reminder_dt_utc;
   }

   // Récupération des infos ticket
   $ticket_title = '';
   $ticket_status = '';
   
   $stmtTicket->execute([':id' => $ticket_id]);
   $trow = $stmtTicket->fetch();

   if ($trow) {
      $ticket_title = $trow['name'] ?? '';
      $status_id = (int)($trow['status'] ?? 0);
      $ticket_status = $statusMap[$status_id] ?? 'N/A';
   } else {
      $ticket_title = "Ticket #{$ticket_id}";
      $ticket_status = 'N/A';
   }

   // ---------- ENVOI HTML ----------
   $subject = sprintf(
      '%s - Ticket #%d',
      $subject_prefix,
      $ticket_id
   );

   // URL du ticket : absolue si base_url renseignée, sinon relative
   if (!empty($base_url)) {
      $ticket_url = rtrim($base_url, '/') . "/front/ticket.form.php?id=" . $ticket_id;
   } else {
      $ticket_url = "/front/ticket.form.php?id=" . $ticket_id;
   }

   // Préparer le HTML
   $safe_message = nl2br(htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
   $safe_reminder_date = htmlspecialchars($reminder_dt_local, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
   $safe_ticket_title = htmlspecialchars($ticket_title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
   $safe_ticket_url = htmlspecialchars($ticket_url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
   $safe_ticket_status = htmlspecialchars($ticket_status ?: 'N/A', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
   $safe_logo_url = htmlspecialchars($logo_url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
   $support_mail = htmlspecialchars($from_email ?: $smtp_from_email, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

   $body = <<<HTML
<!DOCTYPE html>
<html lang="fr">
<head>
   <meta charset="UTF-8">
   <title>{$subject}</title>
   <style>
      body {
         margin: 0;
         padding: 0;
         font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
         background-color: #f5f5f5;
      }
      .container {
         max-width: 600px;
         margin: 20px auto;
         background-color: #ffffff;
         border-radius: 8px;
         overflow: hidden;
         box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
      }
      .logo-container {
         text-align: center;
         padding: 20px 20px 10px;
         background-color: #ffffff;
      }
      .logo-container img {
         max-height: 80px;   /* Logo plus petit */
         width: auto;
         height: auto;
      }
      .title-header {
         background-color: #004477;
         color: #ffffff;
         padding: 16px 20px;
         text-align: center;
      }
      .title-header h1 {
         margin: 0;
         font-size: 20px;
      }
      .content {
         padding: 16px 20px 24px 20px;
         color: #333333;
         font-size: 14px;
         line-height: 1.5;
      }
      .ticket-info {
         background-color: #f0f4f8;
         border-radius: 8px;
         padding: 12px 14px;
         margin: 12px 0;
         font-size: 13px;
      }
      .ticket-info p {
         margin: 4px 0;
      }
      .centered {
         text-align: center;
         margin-top: 20px;
         margin-bottom: 10px;
      }
      .footer {
         text-align: center;
         padding: 16px;
         font-size: 12px;
         color: #777777;
         background-color: #f9f9f9;
      }
      .button {
         display: inline-block;
         padding: 12px 24px;
         margin: 10px 0;
         background-color: #004477;
         color: #ffffff !important;
         text-decoration: none;
         border-radius: 6px;
         font-weight: 600;
         font-size: 14px;
      }
      a {
         color: #004477;
      }
   </style>
</head>
<body>
   <div class="container">
HTML;

   // Logo centré en haut (plus petit)
   if (!empty($safe_logo_url)) {
      $body .= <<<HTML
      <div class="logo-container">
         <img src="{$safe_logo_url}" alt="Logo" />
      </div>
HTML;
   }

   // Titre principal sous le logo
   $body .= <<<HTML
      <div class="title-header">
         <h1>Nouvelle alerte sur votre ticket</h1>
      </div>
      <div class="content">
         <p>Bonjour,</p>
         <p>Une alerte a été créée sur votre ticket.</p>
         <div class="ticket-info">
            <p><strong>Ticket :</strong>
               <a href="{$safe_ticket_url}">{$safe_ticket_title}</a>
            </p>
            <p><strong>Statut :</strong> {$safe_ticket_status}</p>
            <p><strong>Date prévue pour l'action :</strong> {$safe_reminder_date}</p>
         </div>
         <h3 class="centered">Détail de l'alerte</h3>
         <div class="ticket-info">
            <p><strong>Message :</strong></p>
            <p>{$safe_message}</p>
         </div>
         <p class="centered">
            <a class="button" href="{$safe_ticket_url}">Voir le ticket</a>
         </p>
      </div>
      <div class="footer">
         <p><a href="mailto:{$support_mail}">{$support_mail}</a></p>
      </div>
   </div>
</body>
</html>
HTML;

   // Envoi HTML
   if (!empty($from_email)) {
      ini_set('sendmail_from', $from_email);
   }

   $headers = "MIME-Version: 1.0\r\n";
   $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
   if (!empty($from_email)) {
      $headers .= "From: {$from_email}\r\n";
      $headers .= "Reply-To: {$from_email}\r\n";
   }

   $ok = mail($target_email, $subject, $body, $headers);

   // ---------- Mise à jour de l'alerte ----------
   // On utilise gmdate pour enregistrer l'heure d'envoi en UTC dans la DB
   $now_update = gmdate('Y-m-d H:i:s');
   
   $sent_val = $ok ? 1 : 0;
   $sent_at_val = $ok ? $now_update : null;
   $last_error_val = $ok ? null : ('mail() failed at ' . $now_update);

   $stmtUpdate->execute([
      ':sent' => $sent_val,
      ':sent_at' => $sent_at_val,
      ':last_error' => $last_error_val,
      ':updated_at' => $now_update,
      ':id' => $alert_id,
   ]);
}
