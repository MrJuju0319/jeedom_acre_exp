<?php
/* This file is part of Jeedom and is licensed under the AGPL.
 *
 * Plugin ACRE SPC : intégration de la centrale ACRE/Siemens SPC dans Jeedom.
 */

require_once __DIR__ . '/../../../../core/php/core.inc.php';

class acreexp extends eqLogic {
    private const CACHE_LAST_REFRESH = 'acreexp:last_refresh';
    private const PID_FILE_NAME = 'acreexp.pid';
    private const STOP_FILE_NAME = 'acreexp.stop';
    private const STATUS_BIN = '/usr/local/bin/acre_exp_status.py';
    private static $pythonDebug = false;

    /**
     * Retourne des informations sur le démon du plugin.
     *
     * @return array
     */
    public static function deamon_info() {
        $daemonRunning = self::isRefreshLoopRunning();
        $dependenciesReady = self::areDependenciesReady();

        $info = [
            'state' => $daemonRunning ? 'ok' : 'nok',
            'launchable' => $dependenciesReady ? 'ok' : 'nok',
            'launchable_message' => $dependenciesReady ? '' : __('Les dépendances Python ne sont pas installées', __FILE__),
        ];

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

        self::$pythonDebug = (bool)$_debug;

        if (!self::startRefreshLoop($_debug)) {
            throw new Exception(__('Impossible de démarrer la boucle de rafraîchissement', __FILE__));
        }

        if ($_debug) {
            log::add('acreexp', 'debug', __('Démon démarré en mode debug', __FILE__));
        }

        log::add('acreexp', 'info', __('Boucle de rafraîchissement démarrée', __FILE__));
    }

    /**
     * Arrête le démon du plugin.
     */
    public static function deamon_stop() {
        self::stopRefreshLoop();
        self::$pythonDebug = false;
        log::add('acreexp', 'info', __('Boucle de rafraîchissement arrêtée', __FILE__));
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
            'state' => self::areDependenciesReady() ? 'ok' : 'nok',
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
        $progressDir = dirname($progress);
        if (!file_exists($progressDir)) {
            @mkdir($progressDir, 0770, true);
        }
        @file_put_contents($progress, '0');

        $cmd = self::getSudoCmd() . 'ASSUME_YES=true /bin/bash ' . escapeshellarg($script)
            . ' --install --progress-file ' . escapeshellarg($progress);
        $cmd .= ' >> ' . escapeshellarg($log) . ' 2>&1';

        $output = [];
        $code = 0;
        exec($cmd, $output, $code);

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

        if (self::isPythonDebugEnabled()) {
            $cmd .= ' --debug';
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
     * Vérifie la présence de l'environnement Python et des dépendances requises.
     *
     * @return bool
     */
    private static function areDependenciesReady() {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }

        $python = self::findPythonBinary();
        if (!is_string($python) || $python === '') {
            $cached = false;
            return $cached;
        }

        $script = "import importlib.util, sys\n";
        $script .= "modules = ['yaml', 'requests', 'bs4', 'paho.mqtt.client']\n";
        $script .= "missing = [m for m in modules if importlib.util.find_spec(m) is None]\n";
        $script .= "sys.exit(0 if not missing else 1)\n";

        $tmp = @tempnam(sys_get_temp_dir(), 'acreexp_dep_');
        if ($tmp === false) {
            $cached = false;
            return $cached;
        }

        if (@file_put_contents($tmp, $script) === false) {
            @unlink($tmp);
            $cached = false;
            return $cached;
        }
        $cmd = escapeshellarg($python) . ' ' . escapeshellarg($tmp);
        $output = [];
        $code = 0;
        exec($cmd, $output, $code);
        @unlink($tmp);

        $cached = ($code === 0);
        return $cached;
    }

    /**
     * Indique si le mode debug doit être propagé aux scripts Python.
     *
     * @return bool
     */
    private static function isPythonDebugEnabled() {
        if (self::$pythonDebug) {
            return true;
        }

        $level = strtolower((string)config::byKey('log::level::acreexp', 'core', ''));
        return ($level === 'debug');
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
     * Retourne le chemin du fichier PID utilisé par la boucle de rafraîchissement.
     *
     * @return string
     */
    private static function getRefreshPidFile() {
        return self::getRuntimeDirectory() . '/' . self::PID_FILE_NAME;
    }

    /**
     * Retourne le chemin du fichier stop utilisé par la boucle de rafraîchissement.
     *
     * @param self $eqLogic
     * @return bool
     */
    private static function getRefreshStopFile() {
        return self::getRuntimeDirectory() . '/' . self::STOP_FILE_NAME;
    }

    /**
     * Retourne le PID courant de la boucle de rafraîchissement.
     *
     * @return string
     */
    private static function getRefreshPid() {
        $pidFile = self::getRefreshPidFile();
        if (!file_exists($pidFile)) {
            return 0;
        }
        $pid = (int)trim((string)@file_get_contents($pidFile));
        return ($pid > 0) ? $pid : 0;
    }

    /**
     * Indique si la boucle de rafraîchissement est active.
     *
     * @return bool
     */
    private static function isRefreshLoopRunning() {
        $pid = self::getRefreshPid();
        if ($pid <= 0) {
            return false;
        }

        if (function_exists('posix_kill')) {
            return @posix_kill($pid, 0);
        }

        $result = [];
        $code = 0;
        exec('ps -p ' . (int)$pid . ' -o pid=', $result, $code);
        return ($code === 0 && !empty($result));
    }

    /**
     * Démarre la boucle de rafraîchissement interne.
     *
     * @param bool $debug
     * @return bool
     */
    private static function startRefreshLoop($debug = false) {
        if (self::isRefreshLoopRunning()) {
            self::stopRefreshLoop();
            sleep(1);
        }

        $runtimeDir = self::getRuntimeDirectory();
        if (!file_exists($runtimeDir)) {
            mkdir($runtimeDir, 0770, true);
        }

        $pidFile = self::getRefreshPidFile();
        $stopFile = self::getRefreshStopFile();
        if (file_exists($stopFile)) {
            @unlink($stopFile);
        }

        $phpBinary = PHP_BINARY ?: '/usr/bin/php';
        $script = __DIR__ . '/../php/acreexp.php';
        $cmd = escapeshellarg($phpBinary) . ' ' . escapeshellarg($script)
            . ' --pid ' . escapeshellarg($pidFile)
            . ' --stop ' . escapeshellarg($stopFile);

        if ($debug) {
            $cmd .= ' --debug';
        }

        $logFile = log::getPathToLog('acreexp_daemon');
        $cmd .= ' >> ' . escapeshellarg($logFile) . ' 2>&1 & echo $!';

        $shellCmd = '/bin/bash -c ' . escapeshellarg($cmd);
        $pid = trim((string)shell_exec($shellCmd));

        if (!is_numeric($pid) || (int)$pid <= 0) {
            log::add('acreexp', 'error', __('PID invalide au démarrage de la boucle de rafraîchissement', __FILE__));
            return false;
        }

        file_put_contents($pidFile, $pid);
        log::add('acreexp', 'info', sprintf(__('Boucle de rafraîchissement démarrée (PID %s)', __FILE__), $pid));
        return true;
    }

    /**
     * Arrête la boucle de rafraîchissement interne.
     */
    private static function stopRefreshLoop() {
        $pid = self::getRefreshPid();
        if ($pid <= 0) {
            return;
        }

        $pidFile = self::getRefreshPidFile();
        $stopFile = self::getRefreshStopFile();
        if (!file_exists($stopFile)) {
            @file_put_contents($stopFile, (string)time());
        }

        self::sendSignal($pid, 'SIGTERM');

        for ($i = 0; $i < 10; $i++) {
            if (!self::isRefreshLoopRunning()) {
                break;
            }
            sleep(1);
            self::sendSignal($pid, 'SIGTERM');
        }

        if (self::isRefreshLoopRunning()) {
            log::add('acreexp', 'warning', __('Arrêt forcé de la boucle de rafraîchissement', __FILE__));
            self::sendSignal($pid, 'SIGKILL');
            sleep(1);
        }

        if (file_exists($pidFile)) {
            @unlink($pidFile);
        }
        if (file_exists($stopFile)) {
            @unlink($stopFile);
        }

        cache::set(self::CACHE_LAST_REFRESH, 0, 0);
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
        if (self::isRefreshLoopRunning()) {
            return;
        }

        $interval = max(1, (int)config::byKey('poll_interval', 'acreexp', 60));
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
     * Envoie un signal à un processus si possible.
     *
     * @return bool
     */
    private static function sendSignal($pid, $signalName) {
        if ($pid <= 0) {
            return;
        }
        $signal = defined($signalName) ? constant($signalName) : null;
        if ($signal === null) {
            if ($signalName === 'SIGTERM') {
                $signal = 15;
            } elseif ($signalName === 'SIGKILL') {
                $signal = 9;
            }
        }
        if ($signal === null) {
            return;
        }
        if (function_exists('posix_kill')) {
            @posix_kill($pid, $signal);
        } else {
            exec('kill -' . (int)$signal . ' ' . (int)$pid . ' >/dev/null 2>&1');
        }
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
