<?php
/* This file is part of Jeedom.
 *
 * Jeedom is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Jeedom is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Jeedom.  If not, see <http://www.gnu.org/licenses/>.
 */

require_once __DIR__ . '/../class/acreexp.class.php';

try {
    ajax::init();

    if (!isConnect('admin')) {
        throw new Exception(__('401 - Accès non autorisé'));
    }

    $action = init('action');

    switch ($action) {
        case 'synchronize':
            $eqLogicId = (int) init('id');
            /** @var acreexp $eqLogic */
            $eqLogic = acreexp::byId($eqLogicId);
            if (!is_object($eqLogic)) {
                throw new Exception(__('Équipement introuvable : %s', $eqLogicId));
            }

            $eqLogic->synchronizeCommands();
            ajax::success();
            break;

        case 'refreshStates':
            $eqLogicId = (int) init('id');
            /** @var acreexp $eqLogic */
            $eqLogic = acreexp::byId($eqLogicId);
            if (!is_object($eqLogic)) {
                throw new Exception(__('Équipement introuvable : %s', $eqLogicId));
            }

            $eqLogic->refreshStates();
            ajax::success();
            break;

        default:
            throw new Exception(__('Action %s non supportée', $action));
    }
} catch (Exception $e) {
    ajax::error(displayException($e), $e->getCode());
}
