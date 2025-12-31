<?php
// Fichier : plugins/alertcreator/front/config.form.php
include GLPI_ROOT . '/inc/includes.php';

Session::checkRight('config', UPDATE);

$configName = 'plugin_alertcreator';

// ---------------------------------------------------------------------
// Gestion de la licence et modals
// ---------------------------------------------------------------------
$activation_error = '';
$show_license_modal = false;
$show_logs_modal = false;

if (isset($_POST['activate_license'])) {
   $result = PluginAlertcreatorLicense::activate($_POST['license_key'] ?? '');
   if (!$result['success']) {
      $activation_error = $result['message'] ?? 'Erreur lors de l’activation de la licence.';
      $show_license_modal = true;
   }
}

$license = PluginAlertcreatorLicense::getStatus();
$has_valid_license = $license['valid'];

if (isset($_POST['show_license_modal']) && !$has_valid_license) {
   $show_license_modal = true;
}

if (isset($_POST['show_logs_modal'])) {
   if (empty($has_valid_license)) {
      Session::addMessageAfterRedirect('La consultation des logs AlertCreator est réservée aux installations avec une licence valide.', false, ERROR);
      Html::back();
   }
   $show_logs_modal = true;
}

// Vider les alertes
if (isset($_POST['clear_alerts'])) {
   global $DB;
   try {
      $deleted = $DB->delete('glpi_plugin_alertcreator_alerts', ['id' => ['>', 0]]);
      Session::addMessageAfterRedirect(sprintf('Table des alertes vidée (%d enregistrements supprimés).', (int)$deleted), false, INFO);
   } catch (Throwable $e) {
      Session::addMessageAfterRedirect('Erreur lors du vidage de la table des alertes : ' . $e->getMessage(), false, ERROR);
   }
   Html::back();
}

// Licence affichage
$license_help = '';
if ($has_valid_license && !empty($license['expires_at'])) {
   $ts = strtotime($license['expires_at']);
   if ($ts !== false) {
      $license_help = "Licence valable jusqu’au " . date('d/m/Y', $ts) . ".";
   }
}
if ($has_valid_license && empty($license_help) && !empty($license['message'])) {
   $license_help = $license['message'];
}

$license_key_display = '';
if ($has_valid_license && !empty($license['license_key'])) {
   $raw = (string)$license['license_key'];
   $len = mb_strlen($raw, 'UTF-8');
   if ($len <= 4) {
      $license_key_display = str_repeat('•', $len);
   } else {
      $start = mb_substr($raw, 0, 4, 'UTF-8');
      $end = $len > 8 ? mb_substr($raw, -4, null, 'UTF-8') : '';
      $middle_len = max(4, $len - mb_strlen($start, 'UTF-8') - mb_strlen($end, 'UTF-8'));
      $license_key_display = $start . str_repeat('•', $middle_len) . $end;
   }
}

// ---------------------------------------------------------------------
// Chargement configuration
// ---------------------------------------------------------------------
global $CFG_GLPI;
$values = Config::getConfigurationValues($configName, [
   'smtp_host', 'smtp_port', 'smtp_user', 'smtp_password', 'smtp_from',
   'smtp_tls', 'smtp_starttls', 'from_email', 'subject_prefix', 'base_url', 'logo_url'
]);

$smtp_host        = $values['smtp_host'] ?? 'smtp.office365.com';
$smtp_port        = $values['smtp_port'] ?? '587';
$smtp_user        = $values['smtp_user'] ?? 'usersmtp@ton-domaine.tld';
$smtp_password    = $values['smtp_password'] ?? '';
$smtp_from        = $values['smtp_from'] ?? 'dsi.support@ton-domaine.tld';
$smtp_tls         = isset($values['smtp_tls']) ? (bool)$values['smtp_tls'] : true;
$smtp_starttls    = isset($values['smtp_starttls']) ? (bool)$values['smtp_starttls'] : true;
$from_email       = $values['from_email'] ?? $smtp_from;
$subject_prefix   = $values['subject_prefix'] ?? '[GLPI] Rappel';
$base_url         = $values['base_url'] ?? ($CFG_GLPI['url_base'] ?? '');
$current_logo_url = $values['logo_url'] ?? '';

// ---------------------------------------------------------------------
// Traitement POST sauvegarde
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_config'])) {
   $new_smtp_host        = trim($_POST['smtp_host'] ?? '');
   $new_smtp_port        = trim($_POST['smtp_port'] ?? '');
   $new_smtp_user        = trim($_POST['smtp_user'] ?? '');
   $new_smtp_from        = trim($_POST['smtp_from'] ?? '');
   $new_smtp_tls         = isset($_POST['smtp_tls']);
   $new_smtp_starttls    = isset($_POST['smtp_starttls']);
   $posted_smtp_password = trim($_POST['smtp_password'] ?? '');
   $new_smtp_password    = $posted_smtp_password === '' ? $smtp_password : $posted_smtp_password;
   $new_from_email       = trim($_POST['from_email'] ?? '');
   $new_subject_prefix   = trim($_POST['subject_prefix'] ?? '');
   $new_base_url         = trim($_POST['base_url'] ?? '');

   $new_logo_url = $current_logo_url;

   if (isset($_FILES['logo_file']) && $_FILES['logo_file']['error'] === UPLOAD_ERR_OK) {
      $tmp  = $_FILES['logo_file']['tmp_name'];
      $name = $_FILES['logo_file']['name'];
      $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));

      if (in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg'])) {
         $dest_dir = GLPI_ROOT . '/plugins/alertcreator/public/img';
         if (!is_dir($dest_dir)) {
            @mkdir($dest_dir, 0755, true);
         }

         $dest_name = uniqid('logo_', true) . '.' . $ext;
         $dest_path = $dest_dir . '/' . $dest_name;

         if (@move_uploaded_file($tmp, $dest_path)) {
            if (!empty($current_logo_url)) {
               $old_file = GLPI_ROOT . parse_url($current_logo_url, PHP_URL_PATH);
               if (file_exists($old_file)) {
                  @unlink($old_file);
               }
            }
            $new_logo_url = $CFG_GLPI['url_base'] . '/plugins/alertcreator/public/img/' . $dest_name;
            Session::addMessageAfterRedirect('Logo uploadé avec succès.', false, INFO);
         } else {
            Session::addMessageAfterRedirect('Échec du déplacement du fichier uploadé.', false, ERROR);
         }
      } else {
         Session::addMessageAfterRedirect('Format de fichier non autorisé.', false, ERROR);
      }
   }

   if (isset($_POST['remove_logo']) && !empty($current_logo_url)) {
      $file_path = GLPI_ROOT . parse_url($current_logo_url, PHP_URL_PATH);
      if (file_exists($file_path)) {
         @unlink($file_path);
      }
      $new_logo_url = '';
      Session::addMessageAfterRedirect('Logo supprimé avec succès.', false, INFO);
   }

   $errors = [];
   if ($new_smtp_host === '') $errors[] = 'Le serveur SMTP ne doit pas être vide.';
   if ($new_smtp_port === '' || !ctype_digit($new_smtp_port)) $errors[] = 'Le port SMTP doit être un entier.';
   if ($new_smtp_user === '') $errors[] = 'Le nom d’utilisateur SMTP ne doit pas être vide.';
   if ($new_smtp_from === '' || !filter_var($new_smtp_from, FILTER_VALIDATE_EMAIL)) $errors[] = 'L’adresse e-mail d’expéditeur SMTP est invalide.';
   if ($new_from_email === '' || !filter_var($new_from_email, FILTER_VALIDATE_EMAIL)) $errors[] = 'L’adresse e-mail d’expéditeur des alertes est invalide.';
   if (empty($new_smtp_password)) $errors[] = 'Le mot de passe SMTP est vide. Renseigne-le au moins une fois.';
   if ($new_base_url !== '' && !filter_var($new_base_url, FILTER_VALIDATE_URL)) $errors[] = 'L’URL de base GLPI est invalide.';

   if (empty($errors)) {
      Config::setConfigurationValues($configName, [
         'smtp_host'        => $new_smtp_host,
         'smtp_port'        => $new_smtp_port,
         'smtp_user'        => $new_smtp_user,
         'smtp_password'    => $new_smtp_password,
         'smtp_from'        => $new_smtp_from,
         'smtp_tls'         => $new_smtp_tls ? 1 : 0,
         'smtp_starttls'    => $new_smtp_starttls ? 1 : 0,
         'from_email'       => $new_from_email,
         'subject_prefix'   => $new_subject_prefix,
         'base_url'         => $new_base_url,
         'logo_url'         => $new_logo_url,
      ]);

      $msmtprc_path = '/var/www/.msmtprc';
      $lines = [
         'defaults',
         'auth on',
         'tls ' . ($new_smtp_tls ? 'on' : 'off'),
         'tls_starttls ' . ($new_smtp_starttls ? 'on' : 'off'),
         'tls_trust_file /etc/ssl/certs/ca-certificates.crt',
         'logfile /var/log/msmtp.log',
         '',
         'account main',
         'host ' . $new_smtp_host,
         'port ' . $new_smtp_port,
         'user ' . $new_smtp_user,
         'password ' . $new_smtp_password,
         'from ' . $new_smtp_from,
         '',
         'account default : main'
      ];
      $msmtprc_content = implode("\n", $lines) . "\n";

      if (false === file_put_contents($msmtprc_path, $msmtprc_content)) {
         Session::addMessageAfterRedirect("Configuration enregistrée, mais impossible d’écrire $msmtprc_path (droits ?).", false, ERROR);
      } else {
         @chmod($msmtprc_path, 0600);
         Session::addMessageAfterRedirect("Configuration AlertCreator et $msmtprc_path mis à jour.", false, INFO);
      }

      Html::back();
   } else {
      foreach ($errors as $e) {
         Session::addMessageAfterRedirect($e, false, ERROR);
      }
      Html::back();
   }
}

// ---------------------------------------------------------------------
// Affichage
// ---------------------------------------------------------------------
Html::header('Configuration AlertCreator', $_SERVER['PHP_SELF'], 'config', 'plugins');

echo "<form method='post' action='config.form.php' enctype='multipart/form-data'>";
echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
echo Html::hidden('MAX_FILE_SIZE', ['value' => 10485760]);

echo "<div class='card mb-4'>";
echo " <div class='card-header d-flex justify-content-between align-items-center'>";
echo "  <div>";
echo "   <h3 class='card-title mb-0'>Configuration AlertCreator</h3>";
echo $has_valid_license
   ? "   <div class='text-muted small'>Plugin en mode complet : alertes illimitées.</div>"
   : "   <div class='text-muted small'>Plugin en mode gratuit : limité à 3 alertes planifiées sans licence.</div>";
echo "  </div>";
echo "  <div class='d-flex gap-2'>";
if ($has_valid_license) {
   echo Html::submit('Voir les logs', ['name' => 'show_logs_modal', 'class' => 'btn btn-outline-primary']);
} else {
   echo "<button type='button' class='btn btn-outline-primary disabled' aria-disabled='true' title='Disponible uniquement avec une licence valide'>Voir les logs</button>";
}
echo "  </div>";
echo " </div>";

echo " <div class='card-body'>";

// Bloc Licence
echo " <h5>Licence</h5>";
echo " <div class='row mb-4'>";
echo "  <label class='col-md-3 col-form-label'>État de la licence</label>";
echo "  <div class='col-md-9'>";
if ($has_valid_license) {
   echo "   <div class='mb-2'><input type='text' class='form-control bg-light' value=\"" . Html::cleanInputText($license_key_display) . "\" readonly style='cursor:not-allowed; max-width:60ch;'></div>";
   echo !empty($license_help) ? "<div class='form-text'>" . Html::entities_deep($license_help) . "</div>" : "<div class='form-text'>Licence active.</div>";
} else {
   echo "   <div class='alert alert-warning p-3'>Mode gratuit : licence non activée.<br>Limité à 3 alertes.<br>";
   echo Html::submit('Activer la licence', ['name' => 'show_license_modal', 'class' => 'btn btn-outline-danger btn-sm mt-2']);
   echo "   </div>";
}
echo "  </div>";
echo " </div>";

// 3 colonnes côte à côte (sans couleur sur les headers)
echo " <div class='row g-4'>";

// Colonne 1 : SMTP
echo " <div class='col-lg-4'>";
echo "  <div class='card h-100'>";
echo "   <div class='card-header'><h5 class='mb-0'>Paramètres SMTP (msmtp)</h5></div>";
echo "   <div class='card-body'>";
echo "    <div class='mb-3'><label class='form-label'>Serveur SMTP</label><input type='text' class='form-control' name='smtp_host' value=\"" . Html::cleanInputText($smtp_host) . "\" required></div>";
echo "    <div class='mb-3'><label class='form-label'>Port</label><input type='text' class='form-control' name='smtp_port' value=\"" . Html::cleanInputText($smtp_port) . "\" required></div>";
echo "    <div class='mb-3 d-flex gap-4'>";
echo "     <div class='form-check'><input class='form-check-input' type='checkbox' name='smtp_tls' id='smtp_tls' " . ($smtp_tls ? 'checked' : '') . "><label class='form-check-label' for='smtp_tls'>TLS</label></div>";
echo "     <div class='form-check'><input class='form-check-input' type='checkbox' name='smtp_starttls' id='smtp_starttls' " . ($smtp_starttls ? 'checked' : '') . "><label class='form-check-label' for='smtp_starttls'>STARTTLS</label></div>";
echo "    </div>";
echo "    <div class='mb-3'><label class='form-label'>Utilisateur SMTP</label><input type='text' class='form-control' name='smtp_user' value=\"" . Html::cleanInputText($smtp_user) . "\" required></div>";
echo "    <div class='mb-3'><label class='form-label'>Mot de passe SMTP</label><input type='password' class='form-control' name='smtp_password'><small class='form-text text-muted'>Laisser vide pour conserver</small></div>";
echo "    <div class='mb-3'><label class='form-label'>Adresse from SMTP</label><input type='email' class='form-control' name='smtp_from' value=\"" . Html::cleanInputText($smtp_from) . "\" required></div>";
echo "   </div>";
echo "  </div>";
echo " </div>";

// Colonne 2 : Alertes
echo " <div class='col-lg-4'>";
echo "  <div class='card h-100'>";
echo "   <div class='card-header'><h5 class='mb-0'>Paramètres des alertes</h5></div>";
echo "   <div class='card-body'>";
echo "    <div class='mb-3'><label class='form-label'>Adresse e-mail expéditeur alertes</label><input type='email' class='form-control' name='from_email' value=\"" . Html::cleanInputText($from_email) . "\" required></div>";
echo "    <div class='mb-3'><label class='form-label'>Préfixe du sujet</label><input type='text' class='form-control' name='subject_prefix' value=\"" . Html::cleanInputText($subject_prefix) . "\"></div>";
echo "   </div>";
echo "  </div>";
echo " </div>";

// Colonne 3 : Affichage mails + logo
echo " <div class='col-lg-4'>";
echo "  <div class='card h-100'>";
echo "   <div class='card-header'><h5 class='mb-0'>Paramètres d’affichage des mails</h5></div>";
echo "   <div class='card-body'>";
echo "    <div class='mb-3'><label class='form-label'>URL de base GLPI</label><input type='url' class='form-control' name='base_url' value=\"" . Html::cleanInputText($base_url) . "\"></div>";

echo "    <div class='mb-3'>";
echo "     <label class='form-label'>Logo dans les mails</label>";
if (!empty($current_logo_url)) {
   echo "     <div class='mb-3 p-3 border rounded bg-light position-relative'>";
   echo "      <div class='position-absolute top-0 end-0 p-2'>";
   echo "       <div class='form-check'>";
   echo "        <input class='form-check-input' type='checkbox' id='remove_logo' name='remove_logo' value='1'>";
   echo "        <label class='form-check-label text-danger fw-bold' for='remove_logo' style='cursor:pointer;'>✕ Supprimer</label>";
   echo "       </div>";
   echo "      </div>";
   echo "      <img src=\"{$current_logo_url}\" alt=\"Logo actuel\" style=\"max-height:120px; max-width:100%; border:1px solid #ddd; border-radius:4px;\">";
   echo "      <small class='form-text d-block mt-2'>Cochez ✕ Supprimer et enregistrez pour le retirer.</small>";
   echo "     </div>";
}
echo "     <div class='input-group mb-2'><input type='file' class='form-control' name='logo_file' accept='image/png,image/jpeg,image/gif,image/webp,image/svg+xml'></div>";
echo "     <small class='form-text'>Formats : PNG, JPG, GIF, WEBP, SVG (max 10 Mo)</small>";
echo "    </div>";
echo "   </div>";
echo "  </div>";
echo " </div>";

echo " </div>"; // row

echo " </div>"; // card-body

echo " <div class='card-footer text-end'>";
echo "  <button type='submit' name='save_config' class='btn btn-primary btn-lg'>Enregistrer</button>";
echo " </div>";
echo "</div>"; // card

echo "</form>";

// ---------------------------------------------------------------------
// Modal Logs – seulement les blocs <pre> en noir, reste clair
// ---------------------------------------------------------------------
if ($show_logs_modal) {
   // 10 dernières lignes seulement
   $msmtp_log_path = '/var/log/msmtp.log';
   $msmtp_log = 'Fichier non lisible ou vide.';
   if (is_readable($msmtp_log_path)) {
      $lines = file($msmtp_log_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
      if ($lines !== false) {
         $last10 = array_slice($lines, -10);
         $msmtp_log = implode("\n", $last10) ?: 'Aucun log récent.';
      }
   }

   $cron_log_path = '/var/log/glpi/alertcreator_cron_debug.log';
   $cron_log = 'Fichier non lisible ou vide.';
   if (is_readable($cron_log_path)) {
      $lines = file($cron_log_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
      if ($lines !== false) {
         $last10 = array_slice($lines, -10);
         $cron_log = implode("\n", $last10) ?: 'Aucun log récent.';
      }
   }

   $msmtp_log = Html::entities_deep($msmtp_log);
   $cron_log = Html::entities_deep($cron_log);

   echo "<div class='modal modal-blur fade show' id='logs-modal' tabindex='-1' style='display:block;' aria-modal='true'>";
   echo " <div class='modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable'>";
   echo "  <div class='modal-content'>";
   echo "   <div class='modal-header'>";
   echo "    <h5 class='modal-title'>Logs AlertCreator (10 dernières lignes)</h5>";
   echo "   </div>";
   echo "   <div class='modal-body'>";
   echo "    <h6>/var/log/msmtp.log</h6>";
   echo "    <pre id='msmtp-pre' style='max-height:300px; overflow:auto; background:#111827; color:#e5e7eb; padding:15px; border-radius:6px;'>" . $msmtp_log . "</pre>";
   echo "    <h6 class='mt-4'>Cron AlertCreator</h6>";
   echo "    <pre id='cron-pre' style='max-height:300px; overflow:auto; background:#111827; color:#e5e7eb; padding:15px; border-radius:6px;'>" . $cron_log . "</pre>";
   echo "    <div class='alert alert-warning mt-4'>Attention : « Vider les alertes » supprimera toutes les alertes en base.</div>";
   echo "   </div>";
   echo "   <div class='modal-footer'>";
   echo "    <button type='submit' name='clear_alerts' class='btn btn-outline-danger me-auto' onclick=\"return confirm('Confirmer la suppression de toutes les alertes ?');\">Vider les alertes</button>";
   echo "    <button type='button' class='btn btn-secondary' data-dismiss='modal'>Fermer</button>";
   echo "   </div>";
   echo "  </div>";
   echo " </div>";
   echo "</div>";
   echo "<div class='modal-backdrop fade show'></div>";
}

// JS : scroll auto vers le bas + fermeture propre + confirmation suppression logo
?>
<script>
document.addEventListener('DOMContentLoaded', function () {
   // Scroll auto vers le bas des logs
   const preElements = document.querySelectorAll('pre');
   preElements.forEach(pre => {
      pre.scrollTop = pre.scrollHeight;
   });

   // Confirmation suppression logo
   const removeCheckbox = document.getElementById('remove_logo');
   if (removeCheckbox) {
      const form = removeCheckbox.closest('form');
      form.addEventListener('submit', function(e) {
         if (removeCheckbox.checked && !confirm('Confirmer la suppression du logo actuel ?')) {
            e.preventDefault();
         }
      });
   }

   // Fermeture propre de la modal logs
   const closeButtons = document.querySelectorAll('[data-dismiss="modal"]');
   closeButtons.forEach(btn => {
      btn.addEventListener('click', function() {
         document.getElementById('logs-modal').remove();
         document.querySelector('.modal-backdrop').remove();
      });
   });
});
</script>
<?php

Html::footer();
