<?php
include('../../../inc/includes.php');

Session::checkRight("config", UPDATE);

if (isset($_POST["update"])) {
    $config = new PluginWatipluginConfig();
    $config->update($_POST);
    Session::addMessageAfterRedirect(__('Configuration saved successfully', 'watiplugin'), true, INFO);
    Html::redirect(Plugin::getWebDir('watiplugin') . '/front/config.php');
}

$plugin_config_url = Plugin::getWebDir('watiplugin') . '/front/config.php';
Html::header("WATI Plugin", $plugin_config_url, "config", "plugins");
(new PluginWatipluginConfig())->showFormConfig($plugin_config_url);
Html::footer();
