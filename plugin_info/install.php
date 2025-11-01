<?php
/*
 * Hooks d'installation pour le plugin ACRE SPC.
 */

require_once dirname(__FILE__) . '/../../../core/php/core.inc.php';
require_once dirname(__FILE__) . '/../core/php/acreexp.inc.php';

function acreexp_install() {
    acreexp_apply_default_configuration();
}

function acreexp_update() {
    acreexp_apply_default_configuration();
}

function acreexp_remove() {
    // Aucun traitement spécifique nécessaire pour le moment.
}
