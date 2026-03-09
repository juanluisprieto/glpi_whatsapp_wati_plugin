<?php
define('PLUGIN_WATIPLUGIN_VERSION', '2.1.1');
function plugin_init_watiplugin() {
   global $PLUGIN_HOOKS;
   $PLUGIN_HOOKS['csrf_compliant']['watiplugin'] = true;
   $PLUGIN_HOOKS['item_update']['watiplugin']['Ticket'] = 'plugin_watiplugin_notification_assign';
   $PLUGIN_HOOKS['item_add']['watiplugin']['ITILFollowup'] = 'plugin_watiplugin_notification_followup';
   if (Session::haveRight("config", UPDATE)) {
      $PLUGIN_HOOKS['config_page']['watiplugin'] = 'config.php';
   }
}
function plugin_version_watiplugin() {
   return [
      'name'           => 'WATI Pro Notifier',
      'version'        => PLUGIN_WATIPLUGIN_VERSION,
      'author'         => 'Globalsoft',
      'license'        => 'GPLv2+',
      'min_glpi_version' => '10.0'
   ];
}
/**
 * Crea las plantillas de notificación por defecto para WATI
 */
function plugin_watiplugin_install() {
   global $DB;
   // 1. Crear tabla de configuración con el estándar BigInt Unsigned
   if (!$DB->tableExists("glpi_plugin_watiplugin_configs")) {
      $query = "CREATE TABLE `glpi_plugin_watiplugin_configs` (
                  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
                  `wati_url` varchar(255) DEFAULT NULL,
                  `wati_token` TEXT DEFAULT NULL,
                  `glpi_root_path` varchar(255) DEFAULT NULL,
                  `glpi_base_url` VARCHAR(255) DEFAULT NULL,
                  `wati_template_name` VARCHAR(255) DEFAULT NULL,

                  PRIMARY KEY (`id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;";

      $DB->query($query);

      // Insertar configuración inicial por defecto
      $DB->insert('glpi_plugin_watiplugin_configs', [
         'id' => 1,
         'wati_url' => ''
      ]);
   }
   // 1. Instanciar el objeto de Tipos de Solicitud
   $requestType = new RequestType();
   // 2. Verificar si ya existe un origen llamado 'WATI'
   $exists = $DB->request([
      'FROM'  => 'glpi_requesttypes',
      'WHERE' => ['name' => 'WATI']
   ])->current();

   if (!$exists) {
      // 3. Si no existe, lo creamos
      $requestType->add([
         'name' => 'WATI',
         'comment' => 'Origen de tickets creados vía WATI WhatsApp Webhook'
      ]);
   }
   return true;
}
function plugin_watiplugin_uninstall() {
   global $DB;
   $DB->query("DROP TABLE IF EXISTS glpi_plugin_watiplugin_configs");
   // Opcional: eliminar el origen WATI
   $DB->delete('glpi_requesttypes', ['name' => 'WATI']);
   return true;
}
