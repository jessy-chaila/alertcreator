<?php

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access this file directly");
}

/**
 * Gestion de la licence du plugin alertcreator
 *
 * - La licence est une chaîne : base64(JSON) . '.' . base64(SIGNATURE)
 * - JSON contient : plugin, domain, glpi_uuid, issued_at, expires_at
 * - Signature Ed25519 (libsodium) sur le JSON
 * - Vérification avec une clé publique intégrée dans cette classe
 *
 * Source de vérité :
 * - Fichier : GLPI_ROOT/plugins/alertcreator/keys/licence.txt
 * - Fallback : champ "license_key" stocké en BDD (config plugin_alertcreator)
 *
 * Les champs en BDD (license_expires_at, license_status, ...) ne servent
 * qu’à l’affichage / info, pas à décider de la validité.
 */
class PluginAlertcreatorLicense {

   /** Section de configuration GLPI */
   private const CONFIG_SECTION = 'plugin_alertcreator';

   /** Chemin relatif du fichier de licence dans le plugin */
   private const LICENSE_REL_PATH = '/plugins/alertcreator/keys/licence.txt';

   /**
    * Clé publique (base64) générée par scripts/keys/license_tool.php
    * Exemple : echo base64_encode($publicKey);
    * À REMPLACER par la vraie valeur.
    */
   private const PUBLIC_KEY_B64 = 'mvMLN+ceMthBqkLmMenow1xYqken8a0QnzPhPlqW/+I=';

   /** Nom attendu du plugin dans le payload JSON de la licence */
   private const EXPECTED_PLUGIN = 'alertcreator';

   // =====================================================================
   //  Gestion de la config GLPI (cache / affichage)
   // =====================================================================

   /**
    * Charge la configuration "licence" dans la table Config GLPI
    */
   public static function loadConfig(): array {
      return Config::getConfigurationValues(self::CONFIG_SECTION, [
         'license_key',
         'license_status',
         'license_expires_at',
         'license_last_check',
         'license_message',
      ]);
   }

   /**
    * Sauvegarde la configuration "licence" dans Config GLPI
    */
   private static function saveConfig(array $data): void {
      $allowed = [
         'license_key',
         'license_status',
         'license_expires_at',
         'license_last_check',
         'license_message',
      ];

      $toSave = [];
      foreach ($allowed as $k) {
         if (array_key_exists($k, $data)) {
            $toSave[$k] = $data[$k];
         }
      }

      if (!empty($toSave)) {
         Config::setConfigurationValues(self::CONFIG_SECTION, $toSave);
      }
   }

   // =====================================================================
   //  Récupération de la licence brute (fichier + fallback BDD)
   // =====================================================================

   /**
    * Retourne la licence brute :
    * 1) Fichier GLPI_ROOT/plugins/alertcreator/keys/licence.txt
    * 2) Sinon, clé stockée en BDD (champ license_key)
    */
private static function getRawLicense(): string {
    $licenseFile = rtrim(GLPI_ROOT, DIRECTORY_SEPARATOR) . self::LICENSE_REL_PATH;

    // Si le fichier existe, c'est lui la priorité absolue
    if (file_exists($licenseFile)) {
        if (is_readable($licenseFile)) {
            return trim((string)file_get_contents($licenseFile));
        }
        return ''; // Fichier présent mais illisible = erreur
    }

    // SI TU VEUX QUE LA SUPPRESSION DU FICHIER COUPE TOUT :
    // Ne fais pas de fallback ici, retourne vide.
    return ''; 
    
    /* // Ancienne logique de fallback à supprimer si tu veux obliger le fichier :
    $conf = self::loadConfig();
    return trim((string)($conf['license_key'] ?? ''));
    */
}

   // =====================================================================
   //  API publique
   // =====================================================================

   /**
    * Indique si la licence est actuellement valide.
    *
    * Ne se base PAS sur license_expires_at en BDD :
    * on relit et revérifie la licence (signature + domaine + expiration).
    */
   public static function isValid(): bool {
      $license = self::getRawLicense();

      if ($license === '') {
         return false;
      }

      try {
         [$valid] = self::verifyLicense($license);
         return $valid;
      } catch (\Throwable $e) {
         return false;
      }
   }

   /**
    * Retourne l'état détaillé de la licence pour affichage dans l'UI
    */
   public static function getStatus(): array {
      $conf    = self::loadConfig();
      $license = self::getRawLicense();

      $result = [
         'valid'       => false,
         'license_key' => $license,
         'status'      => 'none',
         'expires_at'  => '',
         'last_check'  => $conf['license_last_check'] ?? '',
         'message'     => $conf['license_message'] ?? '',
      ];

      if ($license === '') {
         $result['message'] = 'Aucune licence trouvée (fichier ou configuration).';
         return $result;
      }

      try {
         [$valid, $payload, $msg] = self::verifyLicense($license);

         $result['valid']   = $valid;
         $result['status']  = $valid ? 'valid' : 'invalid';
         $result['message'] = $msg;

         if (isset($payload['expires_at'])) {
            $result['expires_at'] = $payload['expires_at'];
         }

      } catch (\Throwable $e) {
         $result['valid']   = false;
         $result['status']  = 'invalid';
         $result['message'] = 'Erreur de vérification : ' . $e->getMessage();
      }

      return $result;
   }

   /**
    * Active une licence saisie manuellement dans le formulaire de config
    * (fallback si tu veux laisser la possibilité de coller la clé dans l’UI).
    *
    * Retour :
    * [
    *   'success'    => bool,
    *   'error'      => string|null,
    *   'status'     => 'valid'|'invalid'|...,
    *   'message'    => string,
    *   'expires_at' => 'YYYY-MM-DD'|null
    * ]
    */
   public static function activate(string $key): array {
      $key = trim($key);

      if ($key === '') {
         self::saveConfig([
            'license_key'        => '',
            'license_status'     => 'invalid',
            'license_expires_at' => '',
            'license_last_check' => date('Y-m-d H:i:s'),
            'license_message'    => 'Clé de licence vide.',
         ]);

         return [
            'success'    => false,
            'error'      => 'empty_key',
            'status'     => 'invalid',
            'message'    => 'Clé de licence vide.',
            'expires_at' => null,
         ];
      }

      // Vérification réelle de la licence signée
      try {
         [$valid, $payload, $msg] = self::verifyLicense($key);

         $expiresAt = $payload['expires_at'] ?? null;

         self::saveConfig([
            'license_key'        => $key,
            'license_status'     => $valid ? 'valid' : 'invalid',
            'license_expires_at' => $expiresAt ?? '',
            'license_last_check' => date('Y-m-d H:i:s'),
            'license_message'    => $msg,
         ]);

         return [
            'success'    => $valid,
            'error'      => $valid ? null : 'invalid_license',
            'status'     => $valid ? 'valid' : 'invalid',
            'message'    => $msg,
            'expires_at' => $expiresAt,
         ];

      } catch (\Throwable $e) {
         $msg = 'Erreur lors de la vérification de la licence : ' . $e->getMessage();

         self::saveConfig([
            'license_key'        => $key,
            'license_status'     => 'invalid',
            'license_expires_at' => '',
            'license_last_check' => date('Y-m-d H:i:s'),
            'license_message'    => $msg,
         ]);

         return [
            'success'    => false,
            'error'      => 'verification_error',
            'status'     => 'invalid',
            'message'    => $msg,
            'expires_at' => null,
         ];
      }
   }

   // =====================================================================
   //  Vérification technique de la licence (signature + logique)
   // =====================================================================

   /**
    * Retourne la clé publique (binaire) à partir de la constante base64
    */
   private static function getPublicKey(): string {
      $pk = base64_decode(self::PUBLIC_KEY_B64, true);
      if ($pk === false) {
         throw new \RuntimeException('Clé publique invalide (Base64).');
      }
      return $pk;
   }

   /**
    * Vérifie la licence :
    * - format base64(json).base64(signature)
    * - signature Ed25519 (sodium)
    * - plugin, domaine, expiration
    *
    * Retourne : [bool $valid, array $payload, string $message]
    */
   private static function verifyLicense(string $license): array {
      if (!extension_loaded('sodium')) {
         throw new \RuntimeException('Extension sodium requise pour vérifier la licence.');
      }

      if (strpos($license, '.') === false) {
         throw new \InvalidArgumentException('Format de licence invalide (séparateur manquant).');
      }

      [$jsonB64, $sigB64] = explode('.', $license, 2);

      $json      = base64_decode($jsonB64, true);
      $signature = base64_decode($sigB64, true);

      if ($json === false || $signature === false) {
         throw new \InvalidArgumentException('Licence corrompue (Base64 invalide).');
      }

      $publicKey = self::getPublicKey();

      if (!sodium_crypto_sign_verify_detached($signature, $json, $publicKey)) {
         throw new \RuntimeException('Signature de licence invalide.');
      }

      $payload = json_decode($json, true);
      if (!is_array($payload)) {
         throw new \RuntimeException('Payload JSON invalide dans la licence.');
      }

      $messageParts = [];

      // 1) Plugin
      $plugin = $payload['plugin'] ?? '';
      if ($plugin !== self::EXPECTED_PLUGIN) {
         throw new \RuntimeException('Cette licence ne correspond pas à ce plugin.');
      }

      // 2) Expiration (format Y-m-d généré par ton script)
      if (!isset($payload['expires_at'])) {
         throw new \RuntimeException('Champ expires_at manquant dans la licence.');
      }

      $expTs = strtotime($payload['expires_at'] . ' 23:59:59');
      if ($expTs === false) {
         throw new \RuntimeException('Date d\'expiration invalide dans la licence.');
      }
      if ($expTs < time()) {
         throw new \RuntimeException('Licence expirée.');
      }

      $messageParts[] = 'Valide jusqu\'au ' . date('d/m/Y', $expTs);

      // 3) Domaine
      if (!isset($payload['domain'])) {
         throw new \RuntimeException('Champ domain manquant dans la licence.');
      }

      $licenseDomain = strtolower($payload['domain']);
      $currentDomain = strtolower($_SERVER['HTTP_HOST'] ?? '');

      if ($currentDomain !== '') {
         if ($currentDomain !== $licenseDomain) {
            throw new \RuntimeException(sprintf(
               'Domaine non autorisé. Licence pour "%s", instance actuelle : "%s".',
               $licenseDomain,
               $currentDomain
            ));
         }
         $messageParts[] = 'Domaine autorisé : ' . $licenseDomain;
      } else {
         // Cas CLI / CRON : pas d’HTTP_HOST
         $messageParts[] = 'Domaine non vérifié (contexte CLI/CRON, HTTP_HOST vide).';
      }

      $message = implode(' | ', $messageParts);

      return [true, $payload, $message];
   }
}
