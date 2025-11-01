/* global jeedom, init, loadEqLogic */

if (typeof isset === 'undefined') {
  window.isset = function (obj) {
    return typeof obj !== 'undefined' && obj !== null
  }
}

if (typeof is_numeric === 'undefined') {
  window.is_numeric = function (value) {
    return !isNaN(parseFloat(value)) && isFinite(value)
  }
}

$('#table_cmd').sortable({
  axis: 'y',
  cursor: 'move',
  items: '.cmd',
  placeholder: 'ui-state-highlight',
  tolerance: 'intersect',
  forcePlaceholderSize: true
})

function addCmdToTable (_cmd) {
  if (!isset(_cmd)) {
    _cmd = {configuration: {}}
  }
  if (!isset(_cmd.configuration)) {
    _cmd.configuration = {}
  }
  const tr = $('<tr class="cmd" data-cmd_id="' + init(_cmd.id) + '">')
  tr.append('<td class="hidden-xs"><span class="cmdAttr" data-l1key="id"></span></td>')

  const nameCol = $('<td>')
  nameCol.append('<input class="cmdAttr form-control input-sm" data-l1key="name" />')
  tr.append(nameCol)

  const typeCol = $('<td>')
  typeCol.append('<span class="type" type="' + init(_cmd.type) + '">' + jeedom.cmd.availableType() + '</span>')
  typeCol.append('<span class="subType" subType="' + init(_cmd.subType) + '"></span>')
  tr.append(typeCol)

  const logicalId = $('<td>')
  logicalId.append('<input class="cmdAttr form-control input-sm" data-l1key="logicalId" readonly />')
  tr.append(logicalId)

  tr.append('<td><span class="cmdAttr" data-l1key="htmlstate"></span></td>')

  const actionCol = $('<td>')
  const btnGroup = $('<span class="pull-right">')
  if (is_numeric(_cmd.id)) {
    btnGroup.append('<a class="btn btn-default btn-xs cmdAction" data-action="configure"><i class="fas fa-cogs"></i></a> ')
    btnGroup.append('<a class="btn btn-default btn-xs cmdAction" data-action="test"><i class="fas fa-rss"></i></a> ')
  }
  btnGroup.append('<a class="btn btn-danger btn-xs cmdAction" data-action="remove"><i class="fas fa-trash"></i></a>')
  actionCol.append(btnGroup)
  tr.append(actionCol)

  $('#table_cmd tbody').append(tr)
  const newRow = $('#table_cmd tbody tr').last()
  newRow.setValues(_cmd, '.cmdAttr')
  jeedom.cmd.changeType(newRow, init(_cmd.subType))
}

$('#table_cmd tbody').on('click', '.cmdAction[data-action=test]', function () {
  const cmdId = $(this).closest('tr').data('cmd_id')
  if (cmdId) {
    jeedom.cmd.execute({ id: cmdId })
  }
})

$('#bt_acreexp_refresh').on('click', function () {
  const eqLogicId = $('.eqLogicAttr[data-l1key=id]').val()
  if (!eqLogicId) {
    $('#div_alert').showAlert({ message: '{{Veuillez enregistrer l\'équipement avant de lancer une synchronisation.}}', level: 'warning' })
    return
  }
  $('#div_alert').showAlert({ message: '{{Synchronisation en cours...}}', level: 'info' })
  $.ajax({
    type: 'POST',
    url: 'plugins/acreexp/core/ajax/acreexp.ajax.php',
    data: {
      action: 'synchronize',
      id: eqLogicId,
      createMissing: 1
    },
    dataType: 'json',
    global: false,
    error: function (request, status, error) {
      $('#div_alert').showAlert({ message: error, level: 'danger' })
    },
    success: function (data) {
      if (data.state !== 'ok') {
        $('#div_alert').showAlert({ message: data.result, level: 'danger' })
        return
      }
      $('#div_alert').showAlert({ message: '{{Synchronisation terminée.}}', level: 'success' })
      if (typeof loadEqLogic === 'function') {
        loadEqLogic(eqLogicId)
      }
    }
  })
})


