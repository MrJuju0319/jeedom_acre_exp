<?php
if (!isConnect('admin')) {
    throw new Exception(__('401 - Accès non autorisé'));
}

$plugin = plugin::byId('acreexp');
sendVarToJS('eqType', $plugin->getId());

$eqLogics = eqLogic::byType($plugin->getId());
?>
<div class="row row-overflow">
    <div class="col-xs-12 eqLogicThumbnailDisplay">
        <legend><i class="fas fa-cog"></i> {{Gestion}}</legend>
        <div class="eqLogicThumbnailContainer">
            <div class="cursor eqLogicAction logoPrimary" data-action="add">
                <i class="fas fa-plus-circle"></i>
                <br>
                <span>{{Ajouter}}</span>
            </div>
            <div class="cursor eqLogicAction logoSecondary" data-action="gotoPluginConf">
                <i class="fas fa-wrench"></i>
                <br>
                <span>{{Configuration}}</span>
            </div>
        </div>
        <legend><i class="fas fa-shield-alt"></i> {{Mes centrales Acre}}</legend>
        <?php if (count($eqLogics) === 0) { ?>
            <div class="text-center" style="margin-top:20px;">
                <span class="label label-info" style="font-size:1.2em;">{{Aucun équipement Acreexp n'est configuré}}</span>
            </div>
        <?php } else { ?>
            <div class="input-group" style="margin:5px;">
                <input class="form-control roundedLeft" placeholder="{{Rechercher}}" id="in_searchEqlogic" />
                <div class="input-group-btn">
                    <a id="bt_resetSearch" class="btn" style="width:30px;"><i class="fas fa-times"></i></a>
                </div>
            </div>
            <div class="eqLogicThumbnailContainer">
                <?php foreach ($eqLogics as $eqLogic) {
                    $opacity = $eqLogic->getIsEnable() ? '' : 'disableCard';
                    ?>
                    <div class="eqLogicDisplayCard cursor <?php echo $opacity; ?>" data-logical-id="<?php echo $eqLogic->getLogicalId(); ?>" data-eqLogic_id="<?php echo $eqLogic->getId(); ?>">
                        <i class="fas fa-building"></i>
                        <br>
                        <span class="name"><?php echo $eqLogic->getHumanName(true, true); ?></span>
                    </div>
                <?php } ?>
            </div>
        <?php } ?>
    </div>

    <div class="col-xs-12 eqLogic" style="display:none;">
        <div class="input-group pull-right" style="display:inline-flex">
            <span class="input-group-btn">
                <a class="btn btn-default btn-sm eqLogicAction roundedLeft" data-action="configure"><i class="fas fa-cogs"></i> {{Configuration avancée}}</a>
                <a class="btn btn-success btn-sm eqLogicAction" data-action="save"><i class="fas fa-check-circle"></i> {{Sauvegarder}}</a>
                <a class="btn btn-danger btn-sm eqLogicAction roundedRight" data-action="remove"><i class="fas fa-minus-circle"></i> {{Supprimer}}</a>
            </span>
        </div>

        <ul class="nav nav-tabs" role="tablist">
            <li role="presentation">
                <a class="eqLogicAction cursor" data-action="returnToThumbnailDisplay"><i class="fas fa-arrow-circle-left"></i></a>
            </li>
            <li role="presentation" class="active"><a href="#eqlogictab" aria-controls="eqlogictab" role="tab" data-toggle="tab"><i class="fas fa-tachometer-alt"></i> {{Équipement}}</a></li>
            <li role="presentation"><a href="#commandtab" aria-controls="commandtab" role="tab" data-toggle="tab"><i class="fas fa-list"></i> {{Commandes}}</a></li>
        </ul>

        <div class="tab-content">
            <div role="tabpanel" class="tab-pane active" id="eqlogictab">
                <br />
                <form class="form-horizontal">
                    <fieldset>
                        <div class="col-lg-6">
                            <legend><i class="fas fa-info-circle"></i> {{Général}}</legend>
                            <div class="form-group">
                                <label class="col-sm-4 control-label">{{Nom de l'équipement}}</label>
                                <div class="col-sm-6">
                                    <input type="hidden" class="eqLogicAttr" data-l1key="id" />
                                    <input type="text" class="eqLogicAttr form-control" data-l1key="name" placeholder="{{Nom de l'équipement}}" />
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="col-sm-4 control-label">{{Objet parent}}</label>
                                <div class="col-sm-6">
                                    <select class="eqLogicAttr form-control" data-l1key="object_id">
                                        <?php foreach (jeeObject::all() as $object) {
                                            echo '<option value="' . $object->getId() . '">' . $object->getName() . '</option>';
                                        } ?>
                                    </select>
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="col-sm-4 control-label">{{Activer}}</label>
                                <div class="col-sm-6">
                                    <input type="checkbox" class="eqLogicAttr" data-l1key="isEnable" />
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="col-sm-4 control-label">{{Visible}}</label>
                                <div class="col-sm-6">
                                    <input type="checkbox" class="eqLogicAttr" data-l1key="isVisible" />
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-6">
                            <legend><i class="fas fa-network-wired"></i> {{Connexion}}</legend>
                            <div class="form-group">
                                <label class="col-sm-4 control-label">{{Protocole}}</label>
                                <div class="col-sm-6">
                                    <select class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="protocol">
                                        <option value="http">http</option>
                                        <option value="https">https</option>
                                    </select>
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="col-sm-4 control-label">{{Adresse IP}}</label>
                                <div class="col-sm-6">
                                    <input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="ip_address" placeholder="192.168.1.100" />
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="col-sm-4 control-label">{{Port}}</label>
                                <div class="col-sm-6">
                                    <input type="number" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="port" placeholder="{{Par défaut selon le protocole}}" />
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="col-sm-4 control-label">{{Nom d'utilisateur}}</label>
                                <div class="col-sm-6">
                                    <input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="username" />
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="col-sm-4 control-label">{{Mot de passe}}</label>
                                <div class="col-sm-6">
                                    <input type="password" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="password" />
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="col-sm-4 control-label">{{Vérifier le certificat TLS}}</label>
                                <div class="col-sm-6">
                                    <input type="checkbox" class="eqLogicAttr" data-l1key="configuration" data-l2key="verify_certificate" />
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="col-sm-4 control-label">{{Intervalle de rafraîchissement (secondes)}}</label>
                                <div class="col-sm-6">
                                    <input type="number" min="15" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="refresh_interval" />
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="col-sm-4 control-label">{{Actions}}</label>
                                <div class="col-sm-6">
                                    <a class="btn btn-primary" id="bt_syncCommands"><i class="fas fa-sync"></i> {{Synchroniser les commandes}}</a>
                                    <a class="btn btn-default" id="bt_refreshStates"><i class="fas fa-redo"></i> {{Rafraîchir}}</a>
                                </div>
                            </div>
                        </div>
                    </fieldset>
                </form>
            </div>

            <div role="tabpanel" class="tab-pane" id="commandtab">
                <br />
                <table id="table_cmd" class="table table-bordered table-condensed">
                    <thead>
                        <tr>
                            <th style="width: 80px;">{{ID}}</th>
                            <th style="width: 200px;">{{Nom}}</th>
                            <th style="width: 90px;">{{Type}}</th>
                            <th style="width: 120px;">{{Sous-type}}</th>
                            <th style="width: 220px;">{{Chemin}}</th>
                            <th style="width: 220px;">{{Payload}}</th>
                            <th style="width: 120px;">{{Options}}</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php include_file('core', 'plugin.template', 'js'); ?>
<?php include_file('desktop', 'acreexp', 'js', 'acreexp'); ?>
