<?php
/* This file is part of Jeedom and is licensed under the AGPL.
 *
 * Plugin ACRE SPC : intégration de la centrale ACRE/Siemens SPC dans Jeedom.
 */

require_once __DIR__ . '/../../../../core/php/core.inc.php';

class acreexp extends eqLogic {
    private const CACHE_LAST_REFRESH = 'acreexp:last_refresh';
    private const WATCHDOG_SERVICE = 'acre-exp-watchdog.service';
    private const WATCHDOG_UNIT = '/etc/systemd/system/acre-exp-watchdog.service';
    private const WATCHDOG_BIN = '/usr/local/bin/acre_exp_watchdog.py';
    private const STATUS_BIN = '/usr/local/bin/acre_exp_status.py';

    /**
     * Retourne des informations sur le démon du plugin.
     *
     * @return array
     */
    public static function deamon_info() {
        $info = [
            'state' => self::isWatchdogActive() ? 'ok' : 'nok',
            'launchable' => 'ok',
            'launchable_message' => '',
        ];

        if (!self::isWatchdogInstalled()) {
            $info['launchable'] = 'nok';
            $info['launchable_message'] = __('Le service watchdog n\'est pas installé', __FILE__);
        }

        if (count(eqLogic::byType('acreexp', true)) === 0) {
            $info['launchable'] = 'nok';
            $info['launchable_message'] = __('Aucun équipement configuré', __FILE__);
        }

        return $info;
    }

    /**
     * Démarre le démon PHP qui interroge périodiquement les centrales configurées.
     *
     * @param bool $_debug
     * @throws Exception
     */
    public static function deamon_start($_debug = false) {
        $info = self::deamon_info();
        if ($info['launchable'] === 'nok') {
            throw new Exception(__('Le démon ne peut pas être lancé : ', __FILE__) . $info['launchable_message']);
        }

        if (!self::isWatchdogInstalled()) {
            throw new Exception(__('Le service watchdog n\'est pas installé', __FILE__));
        }

        if ($_debug) {
            log::add('acreexp', 'debug', __('Redémarrage du service watchdog en mode debug', __FILE__));
        }

        self::runSystemctl('enable --now ' . escapeshellarg(self::WATCHDOG_SERVICE), $output, $code);
        if ($code !== 0) {
            log::add('acreexp', 'error', sprintf(__('Impossible de démarrer le service watchdog : %s', __FILE__), trim($output)));
            throw new Exception(__('Impossible de démarrer le service watchdog', __FILE__));
        }

        log::add('acreexp', 'info', __('Service watchdog démarré', __FILE__));
    }

    /**
     * Arrête le démon du plugin.
     */
    public static function deamon_stop() {
        self::runSystemctl('stop ' . escapeshellarg(self::WATCHDOG_SERVICE), $output, $code);
        if ($code !== 0) {
            log::add('acreexp', 'warning', sprintf(__('Arrêt du service watchdog : %s', __FILE__), trim($output)));
        } else {
            log::add('acreexp', 'info', __('Service watchdog arrêté', __FILE__));
        }
    }

    /**
     * Informations sur les dépendances (installation du watchdog système).
     *
     * @return array
     */
    public static function dependancy_info() {
        return [
            'log' => 'acreexp_dep',
            'progress_file' => self::getDependencyProgressFile(),
            'state' => self::isWatchdogInstalled() ? 'ok' : 'nok',
        ];
    }

    /**
     * Installation (ou réinstallation) des dépendances.
     *
     * @throws Exception
     */
    public static function dependancy_install() {
        $script = self::getResourcesDirectory() . '/install.sh';
        if (!file_exists($script)) {
            throw new Exception(__('Script install.sh introuvable', __FILE__));
        }

        $log = log::getPathToLog('acreexp_dep');
        $progress = self::getDependencyProgressFile();
        @file_put_contents($progress, '0');

        $cmd = self::getSudoCmd() . 'ASSUME_YES=true /bin/bash ' . escapeshellarg($script) . ' --install';
        $cmd .= ' >> ' . escapeshellarg($log) . ' 2>&1';

        $output = [];
        $code = 0;
        exec($cmd, $output, $code);

        @file_put_contents($progress, '100');

        if ($code !== 0) {
            throw new Exception(__('Échec de l\'installation des dépendances (voir log acreexp_dep)', __FILE__));
        }
    }

    /**
     * Validation avant sauvegarde de l'équipement.
     *
     * @throws Exception
     */
    public function preSave() {
        if (!$this->hasCompleteConfiguration()) {
            return;
        }
    }

    /**
     * Synchronise les commandes après la sauvegarde.
     */
    public function postSave() {
        if (!$this->hasCompleteConfiguration()) {
            log::add('acreexp', 'info', __('Synchronisation différée : configuration incomplète.', __FILE__));
            return;
        }
        try {
            $this->synchronize(true);
        } catch (Exception $e) {
            log::add('acreexp', 'error', sprintf(__('Synchronisation impossible : %s', __FILE__), $e->getMessage()));
        }
    }

    /**
     * Interroge la centrale et met à jour les commandes existantes.
     */
    public function refreshFromController() {
        $this->synchronize(false);
    }

    /**
     * Rafraîchissement périodique via les tâches cron Jeedom.
     */
    public static function cron() {
        self::maybeRefreshEquipments();
    }

    /**
     * Rafraîchissement périodique (fallback).
     */
    public static function cron5() {
        self::maybeRefreshEquipments();
    }

    /**
     * Synchronise l'équipement avec la centrale.
     *
     * @param bool $createMissing Crée les commandes manquantes si vrai.
     */
    public function synchronize($createMissing = false) {
        $snapshot = $this->fetchControllerSnapshot();
        $this->applySnapshot($snapshot, (bool)$createMissing);
    }

    /**
     * Effectue un appel au script Python pour récupérer l'état courant.
     *
     * @return array
     * @throws Exception
     */
    private function fetchControllerSnapshot() {
        $host = trim((string)$this->getConfiguration('host'));
        $user = trim((string)$this->getConfiguration('user'));
        $code = trim((string)$this->getConfiguration('code'));
        $port = trim((string)$this->getConfiguration('port'));
        $https = (bool)$this->getConfiguration('https', 0);

        $protocol = $https ? 'https' : 'http';

        if ($host === '' || $user === '' || $code === '') {
            throw new Exception(__('Configuration incomplète : renseignez l\'hôte, l\'identifiant et le code utilisateur.', __FILE__));
        }

        $baseUrl = $host;
        if (strpos($baseUrl, 'http://') !== 0 && strpos($baseUrl, 'https://') !== 0) {
            $baseUrl = $protocol . '://' . $baseUrl;
        }
        if ($port !== '') {
            if (preg_match('#^https?://#i', $baseUrl)) {
                $baseUrl = preg_replace('#^(https?://[^/:]+)(:\d+)?#i', '$1:' . $port, $baseUrl);
            } else {
                $baseUrl .= ':' . $port;
            }
        }

        $runtimeDir = self::getRuntimeDirectory();
        if (!file_exists($runtimeDir)) {
            mkdir($runtimeDir, 0770, true);
        }

        $config = [
            'spc' => [
                'host' => $baseUrl,
                'user' => $user,
                'pin' => $code,
                'session_cache_dir' => $runtimeDir . '/session_' . $this->getId(),
            ],
        ];

        $tmpFile = tempnam($runtimeDir, 'cfg_');
        if ($tmpFile === false) {
            throw new Exception(__('Impossible de créer un fichier de configuration temporaire', __FILE__));
        }

        file_put_contents($tmpFile, self::arrayToYaml($config));

        $script = self::locateStatusScript();
        if ($script === '') {
            throw new Exception(__('Script acre_exp_status.py introuvable', __FILE__));
        }

        $useInstalledBinary = ($script === self::STATUS_BIN);
        if ($useInstalledBinary) {
            $cmd = self::getSudoCmd() . escapeshellarg($script) . ' -c ' . escapeshellarg($tmpFile);
        } else {
            $python = self::findPythonBinary();
            $cmd = escapeshellcmd($python) . ' ' . escapeshellarg($script) . ' -c ' . escapeshellarg($tmpFile);
        }
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($cmd, $descriptorSpec, $pipes);
        if (!is_resource($process)) {
            unlink($tmpFile);
            throw new Exception(__('Impossible d\'exécuter le script Python', __FILE__));
        }

        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        unlink($tmpFile);

        if ($exitCode !== 0) {
            throw new Exception(sprintf(__('Le script Python a retourné une erreur (%s)', __FILE__), trim($errors)));
        }

        $data = json_decode($output, true);
        if (!is_array($data)) {
            throw new Exception(__('Réponse JSON invalide depuis la centrale', __FILE__));
        }
        if (isset($data['error'])) {
            throw new Exception(sprintf(__('La centrale a retourné une erreur : %s', __FILE__), $data['error']));
        }

        return $data;
    }

    /**
     * Indique si l'équipement dispose d'une configuration complète.
     *
     * @return bool
     */
    private function hasCompleteConfiguration() {
        $host = trim((string)$this->getConfiguration('host'));
        $user = trim((string)$this->getConfiguration('user'));
        $code = trim((string)$this->getConfiguration('code'));

        return ($host !== '' && $user !== '' && $code !== '');
    }

    /**
     * Applique un snapshot récupéré depuis la centrale.
     *
     * @param array $snapshot
     * @param bool $createMissing Crée les commandes absentes si vrai.
     */
    private function applySnapshot(array $snapshot, $createMissing) {
        $zones = isset($snapshot['zones']) && is_array($snapshot['zones']) ? $snapshot['zones'] : [];
        $areas = isset($snapshot['areas']) && is_array($snapshot['areas']) ? $snapshot['areas'] : [];

        $validIds = [];

        foreach ($zones as $zone) {
            $zoneId = isset($zone['id']) ? (string)$zone['id'] : '';
            $zoneName = isset($zone['zone']) ? (string)$zone['zone'] : $zoneId;
            if ($zoneId === '') {
                $zoneId = strtolower(str_replace(' ', '_', $zoneName));
            }
            $validIds[] = 'zone::' . $zoneId . '::state';
            $validIds[] = 'zone::' . $zoneId . '::label';

            if ($createMissing) {
                $this->ensureInfoCmd('zone::' . $zoneId . '::state', sprintf(__('Zone %s - état', __FILE__), $zoneName), 'numeric');
                $this->ensureInfoCmd('zone::' . $zoneId . '::label', sprintf(__('Zone %s - texte', __FILE__), $zoneName), 'string');
            }

            $this->updateCmdValue('zone::' . $zoneId . '::state', isset($zone['etat']) ? $zone['etat'] : null);
            $this->updateCmdValue('zone::' . $zoneId . '::label', isset($zone['etat_txt']) ? $zone['etat_txt'] : '');
        }

        foreach ($areas as $area) {
            $areaId = isset($area['sid']) ? (string)$area['sid'] : '';
            $areaName = isset($area['secteur']) ? (string)$area['secteur'] : $areaId;
            if ($areaId === '') {
                $areaId = strtolower(str_replace(' ', '_', $areaName));
            }
            $validIds[] = 'area::' . $areaId . '::state';
            $validIds[] = 'area::' . $areaId . '::label';

            if ($createMissing) {
                $this->ensureInfoCmd('area::' . $areaId . '::state', sprintf(__('Secteur %s - état', __FILE__), $areaName), 'numeric');
                $this->ensureInfoCmd('area::' . $areaId . '::label', sprintf(__('Secteur %s - texte', __FILE__), $areaName), 'string');
            }

            $this->updateCmdValue('area::' . $areaId . '::state', isset($area['etat']) ? $area['etat'] : null);
            $this->updateCmdValue('area::' . $areaId . '::label', isset($area['etat_txt']) ? $area['etat_txt'] : '');
        }

        if ($createMissing) {
            $this->removeObsoleteCommands($validIds);
        }
    }

    /**
     * Crée une commande info si elle n'existe pas.
     *
     * @param string $logicalId
     * @param string $name
     * @param string $subType
     */
    private function ensureInfoCmd($logicalId, $name, $subType) {
        $cmd = $this->getCmd(null, $logicalId);
        if (!is_object($cmd)) {
            $cmd = new acreexpCmd();
            $cmd->setLogicalId($logicalId);
            $cmd->setName($name);
            $cmd->setType('info');
            $cmd->setSubType($subType);
            $cmd->setEqLogic_id($this->getId());
            $cmd->setIsHistorized(0);
            $cmd->save();
        }
    }

    /**
     * Met à jour la valeur d'une commande si elle existe.
     *
     * @param string $logicalId
     * @param mixed $value
     */
    private function updateCmdValue($logicalId, $value) {
        $cmd = $this->getCmd(null, $logicalId);
        if (is_object($cmd)) {
            $cmd->event($value);
        }
    }

    /**
     * Supprime les commandes obsolètes qui ne figurent plus dans le snapshot.
     *
     * @param array $validLogicalIds
     */
    private function removeObsoleteCommands(array $validLogicalIds) {
        $valid = array_unique($validLogicalIds);
        foreach ($this->getCmd() as $cmd) {
            $logicalId = (string)$cmd->getLogicalId();
            if (strpos($logicalId, 'zone::') !== 0 && strpos($logicalId, 'area::') !== 0) {
                continue;
            }
            if (!in_array($logicalId, $valid)) {
                $cmd->remove();
            }
        }
    }

    /**
     * Convertit un tableau PHP en YAML minimaliste.
     *
     * @param array $data
     * @param int $indent
     * @return string
     */
    private static function arrayToYaml(array $data, $indent = 0) {
        $yaml = '';
        foreach ($data as $key => $value) {
            $spaces = str_repeat(' ', $indent);
            if (is_array($value)) {
                $yaml .= $spaces . $key . ":\n";
                $yaml .= self::arrayToYaml($value, $indent + 2);
            } else {
                $yaml .= $spaces . $key . ': ' . self::yamlScalar($value) . "\n";
            }
        }
        return $yaml;
    }

    /**
     * Formate un scalaire pour YAML.
     *
     * @param mixed $value
     * @return string
     */
    private static function yamlScalar($value) {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_numeric($value)) {
            return (string)$value;
        }
        $string = (string)$value;
        return '\'' . str_replace('\'', '\'\'', $string) . '\'';
    }

    /**
     * Retourne le chemin du binaire Python.
     *
     * @return string
     */
    private static function findPythonBinary() {
        $configured = trim((string)config::byKey('python_binary', 'acreexp', ''));
        if ($configured !== '') {
            return $configured;
        }
        $candidates = [
            '/opt/spc-venv/bin/python3',
            '/usr/bin/python3',
            '/usr/local/bin/python3',
        ];
        foreach ($candidates as $candidate) {
            if (file_exists($candidate)) {
                return $candidate;
            }
        }
        return '/usr/bin/env python3';
    }

    /**
     * Retourne le dossier runtime utilisé par le plugin.
     *
     * @return string
     */
    private static function getRuntimeDirectory() {
        return jeedom::getTmpFolder('acreexp');
    }

    /**
     * Retourne le dossier resources du plugin.
     *
     * @return string
     */
    private static function getResourcesDirectory() {
        return dirname(__DIR__, 2) . '/plugins/acreexp/resources';
    }

    /**
     * Détermine si un rafraîchissement est dû et, le cas échéant, déclenche la synchronisation.
     */
    private static function maybeRefreshEquipments() {
        $interval = max(10, (int)config::byKey('poll_interval', 'acreexp', 60));
        $now = time();
        $lastRun = (int)cache::byKey(self::CACHE_LAST_REFRESH, 0);

        if ($lastRun > 0 && ($now - $lastRun) < $interval) {
            return;
        }

        cache::set(self::CACHE_LAST_REFRESH, $now, 0);
        self::refreshEnabledEquipments();
    }

    /**
     * Rafraîchit tous les équipements activés et correctement configurés.
     */
    private static function refreshEnabledEquipments() {
        foreach (eqLogic::byType('acreexp', true) as $eqLogic) {
            if (!($eqLogic instanceof self)) {
                continue;
            }
            if ((int)$eqLogic->getIsEnable() !== 1) {
                continue;
            }
            if (!self::equipmentHasCompleteConfiguration($eqLogic)) {
                continue;
            }

            try {
                $eqLogic->refreshFromController();
            } catch (Exception $e) {
                log::add('acreexp', 'error', sprintf(__('Erreur lors du rafraîchissement de %s : %s', __FILE__), $eqLogic->getHumanName(), $e->getMessage()));
            }
        }
    }

    /**
     * Vérifie qu'un équipement dispose d'une configuration complète.
     *
     * @param self $eqLogic
     * @return bool
     */
    private static function equipmentHasCompleteConfiguration(self $eqLogic) {
        $host = trim((string)$eqLogic->getConfiguration('host'));
        $user = trim((string)$eqLogic->getConfiguration('user'));
        $code = trim((string)$eqLogic->getConfiguration('code'));

        return ($host !== '' && $user !== '' && $code !== '');
    }

    /**
     * Détermine le chemin du script status Python utilisable.
     *
     * @return string
     */
    private static function locateStatusScript() {
        $candidates = [
            self::STATUS_BIN,
            self::getResourcesDirectory() . '/acre_exp_status.py',
        ];
        foreach ($candidates as $candidate) {
            if (is_string($candidate) && file_exists($candidate)) {
                return $candidate;
            }
        }
        return '';
    }

    /**
     * Indique si le watchdog système est installé.
     *
     * @return bool
     */
    private static function isWatchdogInstalled() {
        if (file_exists(self::WATCHDOG_BIN) || file_exists(self::WATCHDOG_UNIT)) {
            return true;
        }
        $output = [];
        $code = 0;
        exec(self::getSudoCmd() . 'systemctl list-unit-files ' . escapeshellarg(self::WATCHDOG_SERVICE), $output, $code);
        if ($code === 0) {
            foreach ($output as $line) {
                if (strpos($line, self::WATCHDOG_SERVICE) !== false) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Retourne vrai si le service systemd est actif.
     *
     * @return bool
     */
    private static function isWatchdogActive() {
        $cmd = self::getSudoCmd() . 'systemctl is-active ' . escapeshellarg(self::WATCHDOG_SERVICE) . ' 2>&1';
        $output = trim((string)shell_exec($cmd));
        return ($output === 'active');
    }

    /**
     * Retourne le fichier de progression des dépendances.
     *
     * @return string
     */
    private static function getDependencyProgressFile() {
        return self::getRuntimeDirectory() . '/dependancy';
    }

    /**
     * Exécute une commande systemctl et retourne son résultat.
     *
     * @param string $arguments
     * @param string|null $output
     * @param int|null $code
     * @return bool
     */
    private static function runSystemctl($arguments, &$output = null, &$code = null) {
        $cmd = self::getSudoCmd() . 'systemctl ' . $arguments . ' 2>&1';
        $buffer = [];
        $status = 0;
        exec($cmd, $buffer, $status);
        $output = implode("\n", $buffer);
        $code = $status;
        return $status === 0;
    }

    /**
     * Retourne la commande sudo adaptée à l'environnement Jeedom.
     *
     * @return string
     */
    private static function getSudoCmd() {
        $cmd = trim((string)system::getCmdSudo());
        if ($cmd !== '' && substr($cmd, -1) !== ' ') {
            $cmd .= ' ';
        }
        return $cmd;
    }
}

class acreexpCmd extends cmd {
    /**
     * Exécution d'une commande.
     * Les commandes info n'ont pas d'action.
     *
     * @param array $_options
     * @return mixed
     */
    public function execute($_options = array()) {
        throw new Exception(__('Cette commande est uniquement informative', __FILE__));
    }
}
