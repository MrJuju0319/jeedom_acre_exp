<?php
if (!isConnect('admin')) {
    throw new Exception(__('401 - Accès non autorisé'));
}

$eqLogics = eqLogic::byType('acreexp');
?>
<div class="row row-overflow">
    <div class="col-lg-2 col-md-3 col-sm-4">
        <div class="bs-sidebar">
            <ul id="ul_eqLogic" class="nav nav-list bs-sidenav">
                <a class="btn btn-default eqLogicAction" data-action="add"><i class="fas fa-plus-circle"></i> {{Ajouter}}</a>
                <li class="filter" style="margin-bottom: 5px;">
                    <input class="filter form-control input-sm" placeholder="{{Rechercher}}" />
                </li>
                <?php
                foreach ($eqLogics as $eqLogic) {
                    echo '<li class="cursor li_eqLogic" data-eqLogic_id="' . $eqLogic->getId() . '"><a>' . $eqLogic->getHumanName(true) . '</a></li>';
                }
                ?>
            </ul>
        </div>
    </div>
    <div class="col-lg-10 col-md-9 col-sm-8 eqLogicThumbnailDisplay" style="border-left: solid 1px #EEE; padding-left: 25px;">
        <legend><i class="fas fa-shield-alt"></i> {{Acreexp}}</legend>
        <div class="eqLogicThumbnailContainer">
            <?php
            foreach ($eqLogics as $eqLogic) {
                $opacity = $eqLogic->getIsEnable() ? '' : 'opacity:0.3;';
                echo '<div class="eqLogicDisplayCard cursor" data-eqLogic_id="' . $eqLogic->getId() . '" style="' . $opacity . '">';
                echo '<i class="fas fa-building"></i>';
                echo '<br />';
                echo '<span>' . $eqLogic->getHumanName(true, true) . '</span>';
                echo '</div>';
            }
            ?>
        </div>
    </div>

    <div class="col-lg-10 col-md-9 col-sm-8 eqLogic" style="display:none;">
        <div class="input-group pull-right" style="margin-top:5px;">
            <span class="input-group-btn">
                <a class="btn btn-default eqLogicAction" data-action="configure"><i class="fas fa-cogs"></i> {{Configuration avancée}}</a>
                <a class="btn btn-success eqLogicAction" data-action="save"><i class="fas fa-save"></i> {{Sauvegarder}}</a>
            </span>
        </div>
        <ul class="nav nav-tabs" role="tablist">
            <li class="active"><a href="#general" role="tab" data-toggle="tab"><i class="fas fa-tachometer-alt"></i> {{Général}}</a></li>
            <li><a href="#configuration" role="tab" data-toggle="tab"><i class="fas fa-sliders-h"></i> {{Configuration}}</a></li>
            <li><a href="#commands" role="tab" data-toggle="tab"><i class="fas fa-list"></i> {{Commandes}}</a></li>
        </ul>
        <div class="tab-content">
            <div class="tab-pane active" id="general">
                <br />
                <form class="form-horizontal">
                    <fieldset>
                        <input type="hidden" class="eqLogicAttr" data-l1key="id" />
                        <div class="form-group">
                            <label class="col-sm-3 control-label">{{Nom de l'équipement}}</label>
                            <div class="col-sm-3">
                                <input type="text" class="eqLogicAttr form-control" data-l1key="name" placeholder="{{Nom de l'équipement}}" />
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="col-sm-3 control-label">{{Objet parent}}</label>
                            <div class="col-sm-3">
                                <select class="eqLogicAttr form-control" data-l1key="object_id">
                                    <?php
                                    foreach (jeeObject::all() as $object) {
                                        echo '<option value="' . $object->getId() . '">' . $object->getName() . '</option>';
                                    }
                                    ?>
                                </select>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="col-sm-3 control-label">{{Activer}}</label>
                            <div class="col-sm-3">
                                <input type="checkbox" class="eqLogicAttr" data-l1key="isEnable" />
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="col-sm-3 control-label">{{Visible}}</label>
                            <div class="col-sm-3">
                                <input type="checkbox" class="eqLogicAttr" data-l1key="isVisible" />
                            </div>
                        </div>
                    </fieldset>
                </form>
            </div>
            <div class="tab-pane" id="configuration">
                <br />
                <form class="form-horizontal">
                    <fieldset>
                        <div class="form-group">
                            <label class="col-sm-3 control-label">{{Protocole}}</label>
                            <div class="col-sm-3">
                                <select class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="protocol">
                                    <option value="http">http</option>
                                    <option value="https">https</option>
                                </select>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="col-sm-3 control-label">{{Adresse IP}}</label>
                            <div class="col-sm-3">
                                <input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="ip_address" placeholder="192.168.1.100" />
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="col-sm-3 control-label">{{Port}}</label>
                            <div class="col-sm-3">
                                <input type="number" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="port" placeholder="{{Par défaut selon le protocole}}" />
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="col-sm-3 control-label">{{Nom d'utilisateur}}</label>
                            <div class="col-sm-3">
                                <input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="username" />
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="col-sm-3 control-label">{{Mot de passe}}</label>
                            <div class="col-sm-3">
                                <input type="password" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="password" />
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="col-sm-3 control-label">{{Vérifier le certificat TLS}}</label>
                            <div class="col-sm-3">
                                <input type="checkbox" class="eqLogicAttr" data-l1key="configuration" data-l2key="verify_certificate" />
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="col-sm-3 control-label">{{Intervalle de rafraîchissement (secondes)}}</label>
                            <div class="col-sm-3">
                                <input type="number" min="15" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="refresh_interval" />
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="col-sm-3 control-label">{{Synchronisation}}</label>
                            <div class="col-sm-3">
                                <a class="btn btn-primary" id="bt_syncCommands"><i class="fas fa-sync"></i> {{Synchroniser les commandes}}</a>
                                <a class="btn btn-default" id="bt_refreshStates"><i class="fas fa-redo"></i> {{Rafraîchir}}</a>
                            </div>
                        </div>
                    </fieldset>
                </form>
            </div>
            <div class="tab-pane" id="commands">
                <br />
                <table id="table_cmd" class="table table-bordered table-condensed">
                    <thead>
                        <tr>
                            <th style="width: 250px;">{{Nom}}</th>
                            <th style="width: 150px;">{{Type}}</th>
                            <th style="width: 200px;">{{Chemin}}</th>
                            <th style="width: 200px;">{{Payload}}</th>
                            <th>{{Actions}}</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php include_file('desktop', 'acreexp', 'js', 'acreexp'); ?>
