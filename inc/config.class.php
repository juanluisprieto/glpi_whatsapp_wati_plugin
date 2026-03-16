<?php

class PluginWatipluginConfig extends CommonDBTM {
   static $table = 'glpi_plugin_watiplugin_configs';

   static function getTypeName($nb = 0) { 
      return 'Configuración WATI'; 
   }
   
   public static function getBaseUrl() {
      global $CFG_GLPI;
      
      // 1. Intentar obtener la URL oficial de la configuración de GLPI
      if (isset($CFG_GLPI['url_base']) && !empty($CFG_GLPI['url_base'])) {
         return rtrim($CFG_GLPI['url_base'], '/');
      }

      // 2. Fallback: Construirla manualmente si la global falla
      $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? "https" : "http";
      $host     = $_SERVER['HTTP_HOST'] ?? 'localhost';
      return $protocol . "://" . $host;
   }


   function showFormConfig($target = null) {
      $this->getFromDB(1);

      if ($target === null) {
         $target = Toolbox::getItemTypeFormURL('PluginWatipluginConfig');
      }

      echo "<form action='".$target."' method='post'>";
      echo "<table class='tab_cadre_fixe'>";
      echo "<tr><th colspan='2'>Configuración API WATI & Plantillas</th></tr>";
      
      // Campo URL
      echo "<tr class='tab_bg_1'><td>URL de WATI</td>";
      echo "<td><input type='text' name='wati_url' value='".Html::entities_deep($this->fields['wati_url'])."' size='60'>/api/v1/sendTemplateMessage?whatsappNumber=</td></tr>";
      
      // Campo Token
      echo "<tr class='tab_bg_1'><td>Bearer Token</td>";
      echo "<td><textarea name='wati_token' cols='60' rows='3'>".Html::entities_deep($this->fields['wati_token'])."</textarea></td></tr>";
      
      // Campo para el Nombre del Template
      echo "<td>" . __('Nombre del Template WATI', 'watiplugin') . "</td>";
      echo "<td>";
      echo Html::input('wati_template_name', ['value' => $this->fields['wati_template_name'], 'size' => 40]);

      echo "Sú plantilla debe tener una estructura similar a la siguiente y estar aprobada por Meta dentro de WATI para su utilización
      <br/>
      Hola {{name}},<br/>
      El equipo de Soporte de GlobalSoft le comparte la siguiente información {{ticket}}<br/>
      {{url}}<br/>
      Si tiene alguna pregunta, no dude en ponerse en contacto con nosotros.<br/>";
      echo "*Los campos {{name}}, {{ticket}} y {{url}} son requeridos";
      echo "</td></tr>";

      // Campo para la URL de GLPI
      echo "<tr class='tab_bg_1'>";
      echo "<td>" . __('URL de este GLPI', 'watiplugin') . "</td>";
      echo "<td>";
      echo Html::input('glpi_base_url', [
         'value' => $this->fields['glpi_base_url'] ?: self::getBaseUrl(), 
         'size' => 40
      ]);
      echo "<p><small>Ej: https://glpi.tuempresa.com</small></p>";
      echo "</td></tr>";

      
      echo "<tr class='tab_bg_1'>";
      echo "<td>" . __('Ruta absoluta de GLPI (GLPI_ROOT)', 'watiplugin') . "</td>";
      echo "<td>";
      echo "<input type='text' name='glpi_root_path' value='" . $this->fields["glpi_root_path"] . "' size='50'>";
      echo "<p><small>Ejemplo: /var/www/glpi2</small></p>";
      echo "</td></tr>";
      
      echo "<tr><td colspan='2' class='center'>";
      echo "<input type='hidden' name='id' value='1'>";
      echo "<input type='submit' name='update' value='Guardar' class='submit'>";
      echo "</td></tr>";
      
  
      echo "</table>";
      Html::closeForm();
   }

}