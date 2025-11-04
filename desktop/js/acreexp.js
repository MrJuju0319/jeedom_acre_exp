'use strict';

function acreexpSyncCommands() {
  var eqLogicId = $('.eqLogicAttr[data-l1key=id]').val();
  if (!eqLogicId) {
    $('#div_alert').showAlert({ message: '{{Veuillez sauvegarder l\'équipement avant de synchroniser.}}', level: 'warning' });
    return;
  }

  $('#div_alert').showAlert({ message: '{{Synchronisation en cours...}}', level: 'info' });
  $.ajax({
    type: 'POST',
    url: 'plugins/acreexp/core/ajax/acreexp.ajax.php',
    data: { action: 'synchronize', id: eqLogicId },
    dataType: 'json',
    error: function (request, status, error) {
      $('#div_alert').showAlert({ message: error, level: 'danger' });
    },
    success: function (data) {
      if (data.state !== 'ok') {
        $('#div_alert').showAlert({ message: data.result, level: 'danger' });
        return;
      }
      $('#div_alert').showAlert({ message: '{{Synchronisation terminée}}', level: 'success' });
      jeedom.cmd.displayByEqLogic({ id: eqLogicId, error: function (error) {
        $('#div_alert').showAlert({ message: error.message, level: 'danger' });
      }, success: function (cmds) {
        $('#table_cmd tbody').empty();
        cmds.forEach(function (cmd) { addCmdToTable(cmd); });
      }});
    }
  });
}

function acreexpRefreshStates() {
  var eqLogicId = $('.eqLogicAttr[data-l1key=id]').val();
  if (!eqLogicId) {
    $('#div_alert').showAlert({ message: '{{Veuillez sauvegarder l\'équipement avant de rafraîchir.}}', level: 'warning' });
    return;
  }

  $('#div_alert').showAlert({ message: '{{Rafraîchissement en cours...}}', level: 'info' });
  $.ajax({
    type: 'POST',
    url: 'plugins/acreexp/core/ajax/acreexp.ajax.php',
    data: { action: 'refreshStates', id: eqLogicId },
    dataType: 'json',
    error: function (request, status, error) {
      $('#div_alert').showAlert({ message: error, level: 'danger' });
    },
    success: function (data) {
      if (data.state !== 'ok') {
        $('#div_alert').showAlert({ message: data.result, level: 'danger' });
        return;
      }
      $('#div_alert').showAlert({ message: '{{Rafraîchissement terminé}}', level: 'success' });
    }
  });
}

$('#bt_syncCommands').off('click').on('click', function () {
  acreexpSyncCommands();
});

$('#bt_refreshStates').off('click').on('click', function () {
  acreexpRefreshStates();
});

function addCmdToTable(_cmd) {
  if (!isset(_cmd)) {
    _cmd = {};
  }
  if (!isset(_cmd.configuration)) {
    _cmd.configuration = {};
  }
  var tr = $('<tr class="cmd" data-cmd_id="' + init(_cmd.id) + '"></tr>');
  tr.append('<td><input class="cmdAttr form-control input-sm" data-l1key="name" /> <input type="hidden" class="cmdAttr" data-l1key="id" /></td>');
  tr.append('<td><span class="type">' + init(_cmd.type) + ' / ' + init(_cmd.subType) + '</span><input type="hidden" class="cmdAttr" data-l1key="type" /><input type="hidden" class="cmdAttr" data-l1key="subType" /></td>');
  tr.append('<td><input class="cmdAttr form-control input-sm" data-l1key="configuration" data-l2key="resource_path" /></td>');
  tr.append('<td><textarea class="cmdAttr form-control input-sm" data-l1key="configuration" data-l2key="payload"></textarea></td>');

  var actionTd = $('<td></td>');
  if (init(_cmd.type) === 'action') {
    actionTd.append('<a class="btn btn-default btn-xs cmdAction" data-action="test"><i class="fas fa-rss"></i> {{Tester}}</a> ');
  }
  actionTd.append('<a class="btn btn-default btn-xs cmdAction" data-action="configure"><i class="fas fa-cogs"></i> {{Configurer}}</a> ');
  actionTd.append('<a class="btn btn-default btn-xs cmdAction" data-action="remove"><i class="fas fa-trash"></i> {{Supprimer}}</a>');
  tr.append(actionTd);

  tr.find('.cmdAttr[data-l1key=name]').val(init(_cmd.name));
  tr.find('.cmdAttr[data-l1key=id]').val(init(_cmd.id));
  tr.find('.cmdAttr[data-l1key=type]').val(init(_cmd.type));
  tr.find('.cmdAttr[data-l1key=subType]').val(init(_cmd.subType));
  if (isset(_cmd.configuration) && isset(_cmd.configuration.resource_path)) {
    tr.find('.cmdAttr[data-l1key=\'configuration\'][data-l2key=\'resource_path\']').val(_cmd.configuration.resource_path);
  }
  if (isset(_cmd.configuration) && isset(_cmd.configuration.payload)) {
    var payload = _cmd.configuration.payload;
    if (typeof payload === 'object') {
      payload = JSON.stringify(payload);
    }
    tr.find('.cmdAttr[data-l1key=\'configuration\'][data-l2key=\'payload\']').val(payload);
  }
  $('#table_cmd tbody').append(tr);
}

function isset(obj) {
  return obj !== undefined && obj !== null;
}

function init(value, defaultValue) {
  if (value === undefined || value === null || value === '') {
    return defaultValue === undefined ? '' : defaultValue;
  }
  return value;
}

