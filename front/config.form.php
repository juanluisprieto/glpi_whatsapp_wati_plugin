<?php
// Subimos 3 niveles para llegar a la raíz de GLPI: front -> watiplugin -> plugins -> raíz
include ("../../../inc/includes.php");

$config = new PluginWatipluginConfig();

if (isset($_POST["update"])) {
   // Validar permisos
   Session::checkRight("config", UPDATE);

   // Actualizar en la base de datos
   if ($config->update($_POST)) {
      Html::back(); // Regresa a la página anterior con mensaje de éxito
   }
}

// Por si alguien entra directamente a la URL sin POST
Html::displayErrorAndDie("Acceso directo no permitido");