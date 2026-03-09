<?php

// include ("../../../inc/includes.php");
// Session::checkRight("config", UPDATE);
// Html::header("WATI Config", $_SERVER['PHP_SELF'], "config", "plugins");
// (new PluginWatipluginConfig())->showFormConfig();
// Html::footer();

// Buscamos el archivo includes.php de forma dinámica hacia atrás
$host_path = realpath(__DIR__ . '/../../inc/includes.php');



if (!file_exists($host_path)) {
  die("Error: No se encontró el núcleo de GLPI en: " . $host_path);
}
     include($host_path);
//     // Verificar permisos
Session::checkRight("config", UPDATE);
// Cargar encabezados de GLPI
Html::header("WATI Plugin", $_SERVER['PHP_SELF'], "config", "plugins");
// Instanciar la clase y mostrar el formulario
$config = new PluginWatipluginConfig();

$config->showFormConfig();

Html::footer();

