<?php
/*
 * Page de configuration globale du plugin ACRE SPC.
 */

require_once dirname(__FILE__) . '/../../../core/php/core.inc.php';
include_file('core', 'authentification', 'php');
if (!isConnect()) {
    include_file('desktop', '404', 'php');
    die();
}
?>
<form class="form-horizontal">
  <fieldset>
    <legend><i class="fas fa-cog"></i> {{Paramètres généraux}}</legend>
    <div class="form-group">
      <label class="col-sm-4 control-label">{{Intervalle de rafraîchissement (s)}}</label>
      <div class="col-sm-3">
        <input type="number" min="30" class="configKey form-control" data-l1key="poll_interval" placeholder="300" />
        <span class="help-block">{{Délai minimal entre deux interrogations automatiques d'une centrale.}}</span>
      </div>
    </div>
    <div class="form-group">
      <label class="col-sm-4 control-label">{{Binaire Python}}</label>
      <div class="col-sm-6">
        <input type="text" class="configKey form-control" data-l1key="python_binary" placeholder="python3" />
        <span class="help-block">{{Laisser vide pour laisser le plugin détecter automatiquement Python 3.}}</span>
      </div>
    </div>
  </fieldset>
</form>
<?php include_file('core', 'plugin.template', 'js'); ?>
