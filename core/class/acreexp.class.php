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

require_once __DIR__ . '/acreexpApi.class.php';

class acreexp extends eqLogic {
    public static function cron() {
        self::processAutoRefresh();
    }

    public static function cron5() {
        self::processAutoRefresh();
    }

    public static function cron15() {
        self::processAutoRefresh();
    }

    public static function cron30() {
        self::processAutoRefresh();
    }

    public static function cronHourly() {
        self::processAutoRefresh(true);
    }

    private static function processAutoRefresh(bool $force = false): void {
        foreach (eqLogic::byType(__CLASS__, true) as $acreexp) {
            /** @var acreexp $acreexp */
            if ($acreexp->getIsEnable() != 1) {
                continue;
            }

            try {
                $refreshInterval = (int) $acreexp->getConfiguration('refresh_interval', 60);
                $lastRefresh = (int) $acreexp->getCache('last_refresh', 0);

                if ($force || (time() - $lastRefresh) >= $refreshInterval) {
                    $acreexp->refreshStates();
                }
            } catch (Exception $e) {
                log::add(
                    'acreexp',
                    'error',
                    sprintf(
                        __('Erreur lors du rafraîchissement automatique de %1$s : %2$s'),
                        $acreexp->getName(),
                        $e->getMessage()
                    )
                );
            }
        }
    }

    public function preSave(): void {
        $protocol = $this->getConfiguration('protocol', 'http');
        if (!in_array($protocol, ['http', 'https'], true)) {
            throw new Exception(__('Le protocole doit être http ou https.'));
        }

        $refreshInterval = (int) $this->getConfiguration('refresh_interval', 60);
        if ($refreshInterval < 15) {
            throw new Exception(__('Le taux de rafraîchissement ne peut pas être inférieur à 15 secondes.'));
        }
    }

    public function postSave(): void {
        if ($this->getConfiguration('auto_sync_on_save', 1)) {
            try {
                $this->synchronizeCommands();
            } catch (Exception $e) {
                log::add(
                    'acreexp',
                    'error',
                    sprintf(__('Synchronisation impossible : %s'), $e->getMessage())
                );
            }
        }
    }

    public function getApi(): acreexpApi {
        return new acreexpApi($this);
    }

    public function synchronizeCommands(): void {
        log::add(
            'acreexp',
            'debug',
            sprintf(__('Synchronisation des commandes pour %s'), $this->getHumanName())
        );
        $topology = $this->getApi()->fetchTopology();
        if (!is_array($topology)) {
            throw new Exception(__('La topologie retournée est invalide.'));
        }

        $this->updateCommandsFromTopology($topology);
        $this->refreshStates();
    }

    public function refreshStates(): void {
        log::add(
            'acreexp',
            'debug',
            sprintf(__('Rafraîchissement de l\'état pour %s'), $this->getHumanName())
        );

        foreach ($this->getCmd(null, null) as $cmd) {
            /** @var acreexpCmd $cmd */
            if ($cmd->getType() !== 'info') {
                continue;
            }

            $resourcePath = $cmd->getConfiguration('resource_path');
            if ($resourcePath === null || $resourcePath === '') {
                continue;
            }

            try {
                $payload = $this->getApi()->fetchStatus($resourcePath);
                $value = $payload['value'] ?? ($payload['status'] ?? null);
                if ($value !== null) {
                    $cmd->event($value);
                }
            } catch (Exception $e) {
                log::add(
                    'acreexp',
                    'error',
                    sprintf(
                        __('Impossible de rafraîchir la commande %1$s : %2$s'),
                        $cmd->getName(),
                        $e->getMessage()
                    )
                );
            }
        }

        $this->setCache('last_refresh', time());
    }

    private function updateCommandsFromTopology(array $topology): void {
        $processed = [];

        foreach ($topology as $resourceType => $resources) {
            if (!is_array($resources)) {
                continue;
            }

            foreach ($resources as $resource) {
                if (!is_array($resource)) {
                    continue;
                }

                $resourceSignature = $this->createCommandsForResource((string) $resourceType, $resource);
                $processed[] = $resourceSignature;
            }
        }

        // Remove commands that are no longer present
        foreach ($this->getCmd(null, null) as $cmd) {
            $resourceSignature = $cmd->getConfiguration('resource_signature');
            if ($resourceSignature !== '' && !in_array($resourceSignature, $processed, true)) {
                $cmd->remove();
            }
        }
    }

    private function createCommandsForResource(string $resourceType, array $resource): string {
        $resourceId = (string) ($resource['id'] ?? uniqid($resourceType . '_'));
        $resourceName = trim((string) ($resource['name'] ?? $resource['label'] ?? $resourceId));
        $resourceSignature = $resourceType . ':' . $resourceId;

        $statusPath = (string) ($resource['status_path'] ?? $resource['resource_path'] ?? null);
        $statusValue = $resource['status'] ?? null;

        $infoLogicalId = strtolower($resourceType) . '_' . $resourceId . '_status';
        $infoName = $this->formatCommandName($resourceType, $resourceId, $resourceName, __('État'));

        /** @var acreexpCmd $infoCmd */
        $infoCmd = $this->getCmd(null, $infoLogicalId);
        if (!is_object($infoCmd)) {
            $infoCmd = new acreexpCmd();
            $infoCmd->setName($infoName);
            $infoCmd->setEqLogic_id($this->getId());
            $infoCmd->setEqType_name($this->getEqType_name());
            $infoCmd->setLogicalId($infoLogicalId);
            $infoCmd->setType('info');
            $infoCmd->setSubType('string');
            $infoCmd->setIsHistorized(0);
        }
        $infoCmd->setConfiguration('resource_signature', $resourceSignature);
        $infoCmd->setConfiguration('resource_path', $statusPath);
        $infoCmd->save();

        if ($statusValue !== null) {
            $infoCmd->event($statusValue);
        }

        if (!empty($resource['commands']) && is_array($resource['commands'])) {
            foreach ($resource['commands'] as $action) {
                $this->createActionCommand($resourceType, $resourceId, $resourceName, $resourceSignature, $action);
            }
        }

        return $resourceSignature;
    }

    private function createActionCommand(string $resourceType, string $resourceId, string $resourceName, string $resourceSignature, array $action): void {
        $actionId = (string) ($action['id'] ?? ($action['name'] ?? uniqid('cmd_')));
        $logicalId = strtolower($resourceType) . '_' . $resourceId . '_' . strtolower(str_replace(' ', '_', $actionId));
        $commandName = $this->formatCommandName($resourceType, $resourceId, $resourceName, $action['label'] ?? $actionId);

        /** @var acreexpCmd $cmd */
        $cmd = $this->getCmd(null, $logicalId);
        if (!is_object($cmd)) {
            $cmd = new acreexpCmd();
            $cmd->setEqLogic_id($this->getId());
            $cmd->setEqType_name($this->getEqType_name());
            $cmd->setLogicalId($logicalId);
            $cmd->setType('action');
            $cmd->setSubType('other');
        }

        $cmd->setName($commandName);
        $cmd->setConfiguration('resource_signature', $resourceSignature);
        $cmd->setConfiguration('resource_path', (string) ($action['resource_path'] ?? $action['endpoint'] ?? ''));
        $cmd->setConfiguration('payload', $action['payload'] ?? []);
        $cmd->save();
    }

    private function formatCommandName(string $resourceType, string $resourceId, string $resourceName, string $suffix): string {
        $labelMap = ['zones' => 'Zone', 'zone' => 'Zone', 'sectors' => 'Secteur', 'sector' => 'Secteur', 'doors' => 'Porte', 'door' => 'Porte'];
        $baseLabel = $labelMap[strtolower($resourceType)] ?? ucfirst($resourceType);
        $prefix = strtolower($baseLabel) . $resourceId;
        $normalizedName = $this->normalizeSegment($resourceName);
        $normalizedSuffix = $this->normalizeSegment($suffix, true);

        if ($normalizedName === '') {
            $normalizedName = 'valeur';
        }
        if ($normalizedSuffix === '') {
            $normalizedSuffix = 'etat';
        }

        return sprintf('%s-%s-%s', $prefix, $normalizedName, $normalizedSuffix);
    }

    private function normalizeSegment(string $value, bool $lowercase = false): string {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if (function_exists('iconv')) {
            $transformed = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
            if ($transformed !== false) {
                $value = $transformed;
            }
        }

        $value = preg_replace('/[^A-Za-z0-9]+/', '-', $value);
        $value = trim($value, '-');
        if ($lowercase) {
            $value = strtolower($value);
        }

        return $value;
    }
}

class acreexpCmd extends cmd {
    public function execute($_options = array()) {
        /** @var acreexp $eqLogic */
        $eqLogic = $this->getEqLogic();

        if ($this->getType() === 'action') {
            $resourcePath = $this->getConfiguration('resource_path');
            if ($resourcePath === '') {
                throw new Exception(__('Aucun endpoint configuré pour cette action.'));
            }

            $payload = $this->getConfiguration('payload', []);
            if (is_string($payload) && $payload !== '') {
                $decoded = json_decode($payload, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $payload = $decoded;
                }
            }
            if (!is_array($payload)) {
                $payload = [];
            }
            if (!empty($_options['message'])) {
                $payload['message'] = $_options['message'];
            }

            $result = $eqLogic->getApi()->sendCommand($resourcePath, $payload);
            $statusValue = $result['value'] ?? ($result['status'] ?? null);
            if ($statusValue !== null) {
                $signature = $this->getConfiguration('resource_signature');
                if ($signature !== '') {
                    foreach ($eqLogic->getCmd('info') as $infoCmd) {
                        if ($infoCmd->getConfiguration('resource_signature') === $signature) {
                            $infoCmd->event($statusValue);
                        }
                    }
                }
            }
            return $result;
        }

        return $this->getConfiguration('value');
    }
}
