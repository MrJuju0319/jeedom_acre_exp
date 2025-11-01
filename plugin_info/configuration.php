<?php
/*
 * Page de configuration globale du plugin ACRE SPC.
 */

require_once dirname(__FILE__) . '/../../../core/php/core.inc.php';
include_file('core', 'authentification', 'php');
if (!isConnect('admin')) {
    include_file('desktop', '404', 'php');
    die();
}

$plugin = plugin::byId('acreexp');
?>

<div class="row">
  <div class="col-lg-6">
    <form class="form-horizontal">
      <fieldset>
        <legend><i class="fas fa-wrench"></i> {{Paramètres généraux}}</legend>
        <div class="form-group">
          <label class="col-sm-4 control-label">{{Intervalle de rafraîchissement (s)}}</label>
          <div class="col-sm-6">
            <input type="number" class="configKey form-control" data-l1key="poll_interval"
                   value="<?php echo htmlspecialchars(config::byKey('poll_interval', 'acreexp', 60), ENT_QUOTES); ?>" />
          </div>
        </div>
        <div class="form-group">
          <label class="col-sm-4 control-label">{{Binaire Python personnalisé}}</label>
          <div class="col-sm-6">
            <input type="text" class="configKey form-control" data-l1key="python_binary"
                   placeholder="/opt/spc-venv/bin/python3"
                   value="<?php echo htmlspecialchars(config::byKey('python_binary', 'acreexp', ''), ENT_QUOTES); ?>" />
          </div>
        </div>
      </fieldset>
    </form>
  </div>

  <div class="col-lg-6">
    <legend><i class="fas fa-cubes"></i> {{Dépendances}}</legend>
    <div class="form-group">
      <label class="col-sm-4 control-label">{{État}}</label>
      <div class="col-sm-8">
        <span id="acreexp_dep_state" class="label label-default">{{Inconnu}}</span>
        <button type="button" class="btn btn-success btn-sm" id="bt_acreexp_installDep">
          <i class="fas fa-sync"></i> {{(Ré)installer}}
        </button>
        <a class="btn btn-default btn-sm" id="bt_acreexp_depLog" target="_blank"
           href="index.php?v=d&p=log&log=acreexp_dep">
          <i class="fas fa-file-alt"></i> {{Voir le log}}
        </a>
      </div>
    </div>
    <div class="form-group">
      <label class="col-sm-4 control-label">{{Progression}}</label>
      <div class="col-sm-8">
        <div class="progress" style="margin-bottom:0;">
          <div id="acreexp_dep_progress" class="progress-bar" role="progressbar" style="width:0%;">0%</div>
        </div>
      </div>
    </div>
    <div class="form-group">
      <div class="col-sm-offset-4 col-sm-8">
        <span class="help-block" id="acreexp_dep_message"></span>
      </div>
    </div>
  </div>
</div>

<script>
  (function () {
    'use strict'

    const $state = $('#acreexp_dep_state')
    const $progress = $('#acreexp_dep_progress')
    const $message = $('#acreexp_dep_message')

    function setState (text, level) {
      $state.removeClass('label-default label-success label-warning label-danger').addClass('label-' + level).text(text)
    }

    function refreshDependencies () {
      $.ajax({
        type: 'POST',
        url: 'plugins/acreexp/core/ajax/acreexp.ajax.php',
        data: { action: 'dependancyInfo' },
        dataType: 'json',
        global: false,
        success: function (data) {
          if (!data || data.state !== 'ok') {
            return
          }
          const info = data.result || {}
          const progress = parseInt(info.progress || 0, 10)
          $progress.css('width', progress + '%').text(progress + '%')

          if (info.in_progress) {
            $progress.addClass('progress-bar-striped active')
          } else {
            $progress.removeClass('progress-bar-striped active')
          }

          if (info.state === 'ok') {
            setState('{{OK}}', 'success')
            $message.text('{{Les dépendances sont installées.}}')
          } else if (info.in_progress) {
            setState('{{Installation en cours}}', 'warning')
            $message.text('{{Installation des dépendances en cours...}}')
          } else {
            setState('{{Absent}}', 'danger')
            $message.text('{{Cliquez sur "(Ré)installer" pour préparer l\'environnement Python.}}')
          }
        }
      })
    }

    $('#bt_acreexp_installDep').on('click', function () {
      const launch = function () {
        $.ajax({
          type: 'POST',
          url: 'plugins/acreexp/core/ajax/acreexp.ajax.php',
          data: { action: 'dependancyInstall' },
          dataType: 'json',
          global: false,
          success: function (data) {
            if (!data || data.state !== 'ok') {
              const msg = data && data.result ? data.result : '{{Impossible de lancer l\'installation des dépendances. Consultez le log.}}'
              $.growl.error({ title: '{{Erreur}}', message: msg })
              return
            }
            $.growl.notice({ title: '{{Dépendances}}', message: '{{Installation démarrée.}}' })
            refreshDependencies()
          },
          error: function (request, status, error) {
            $.growl.error({ title: '{{Erreur}}', message: error })
          }
        })
      }

      if (typeof bootbox !== 'undefined') {
        bootbox.confirm('{{Lancer l\'installation des dépendances ?}}', function (result) {
          if (result) {
            launch()
          }
        })
      } else {
        if (confirm('{{Lancer l\'installation des dépendances ?}}')) {
          launch()
        }
      }
    })

    refreshDependencies()
    setInterval(refreshDependencies, 5000)
  })()
</script>
