<?php
/*
 * Plugin ACRE SPC : intégration de la centrale ACRE/Siemens SPC dans Jeedom.
 */

require_once __DIR__ . '/../../../../core/php/core.inc.php';

class acreexp extends eqLogic {
    private const CACHE_PREFIX = 'acreexp:last_refresh:';
    private const CONFIG_FILE = 'config.yaml';
    private const REQUIREMENTS_SENTINEL = '.requirements';
    private const VENV_DIRECTORY = 'venv';
    private const STATUS_SCRIPT = '/python/acre_exp_status.py';

    /**
     * Informations sur l'état des dépendances du plugin.
     */
    public static function dependancy_info() {
        return [
            'log' => 'acreexp_dep',
            'progress_file' => self::getDependencyProgressFile(),
            'state' => self::isBaseEnvironmentReady() ? 'ok' : 'nok',
        ];
    }

    /**
     * Installation (ou réinstallation) des dépendances.
     */
    public static function dependancy_install() {
        $script = self::getResourcesDirectory() . '/install.sh';
        if (!file_exists($script)) {
            throw new Exception(__('Script install.sh introuvable', __FILE__));
        }

        $log = log::getPathToLog('acreexp_dep');
        $progress = self::getDependencyProgressFile();
        @file_put_contents($progress, '0');

        $cmd = self::getSudoCmd() . '/bin/bash ' . escapeshellarg($script) . ' --install';
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
     * Synchronise l'équipement après sauvegarde.
     */
    public function postSave() {
        if (!$this->hasCompleteConfiguration()) {
            log::add('acreexp', 'info', __('Synchronisation différée : configuration incomplète.', __FILE__));
            return;
        }

        try {
            $this->prepareEnvironment();
            $this->synchronize(true);
        } catch (Exception $e) {
            log::add('acreexp', 'error', sprintf(__('Synchronisation impossible : %s', __FILE__), $e->getMessage()));
        }
    }

    /**
     * Nettoie les ressources de l'équipement après suppression.
     */
    public function postRemove() {
        self::removeEquipmentDirectory($this->getId());
    }

    /**
     * Rafraîchit l'équipement à la demande.
     */
    public function refreshFromController() {
        $this->synchronize(false);
    }

    /**
     * Tâche cron de rafraîchissement.
     */
    public static function cron() {
        self::maybeRefreshEquipments();
    }

    /**
     * Tâche cron fallback (cron5).
     */
    public static function cron5() {
        self::maybeRefreshEquipments();
    }

    /**
     * Synchronise l'équipement avec la centrale.
     */
    public function synchronize($createMissing = false) {
        if (!$this->hasCompleteConfiguration()) {
            throw new Exception(__('Configuration incomplète : renseignez l\'hôte, l\'identifiant et le code utilisateur.', __FILE__));
        }

        $snapshot = $this->fetchControllerSnapshot();
        $this->applySnapshot($snapshot, (bool)$createMissing);
        cache::set(self::CACHE_PREFIX . $this->getId(), time(), 0);
    }

    /**
     * Retourne un instantané des zones/secteurs depuis la centrale.
     */
    private function fetchControllerSnapshot() {
        $python = $this->prepareEnvironment();
        $configFile = $this->writeConfigurationFile();
        $script = self::getStatusScriptPath();

        if ($script === '') {
            throw new Exception(__('Script acre_exp_status.py introuvable', __FILE__));
        }

        $cmd = escapeshellarg($python) . ' ' . escapeshellarg($script) . ' -c ' . escapeshellarg($configFile);
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($cmd, $descriptorSpec, $pipes);
        if (!is_resource($process)) {
            throw new Exception(__('Impossible d\'exécuter le script Python', __FILE__));
        }

        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            $errorMessage = trim($errors);
            if ($errorMessage === '') {
                $errorMessage = trim($output);
            }
            if ($errorMessage === '') {
                $errorMessage = __('aucune sortie fournie', __FILE__);
            }
            throw new Exception(sprintf(__('Le script Python a retourné une erreur : %s', __FILE__), $errorMessage));
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
     * Vérifie si la configuration est complète.
     */
    private function hasCompleteConfiguration() {
        $host = trim((string)$this->getConfiguration('host'));
        $user = trim((string)$this->getConfiguration('user'));
        $code = trim((string)$this->getConfiguration('code'));

        return ($host !== '' && $user !== '' && $code !== '');
    }

    /**
     * Applique un instantané renvoyé par la centrale.
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
     * Met à jour la valeur d'une commande.
     */
    private function updateCmdValue($logicalId, $value) {
        $cmd = $this->getCmd(null, $logicalId);
        if (is_object($cmd)) {
            $cmd->event($value);
        }
    }

    /**
     * Supprime les commandes obsolètes.
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
     * Prépare l'environnement Python de l'équipement et retourne le binaire.
     */
    private function prepareEnvironment($force = false) {
        $eqDirectory = self::getEquipmentDirectory($this->getId());
        if (!file_exists($eqDirectory)) {
            mkdir($eqDirectory, 0775, true);
        }

        $python = self::resolvePythonBinary();
        if ($python === '') {
            throw new Exception(__('Python 3 est requis pour exécuter le script de communication.', __FILE__));
        }

        $venvDir = $eqDirectory . '/' . self::VENV_DIRECTORY;
        $pythonExecutable = self::locateVenvPython($venvDir);
        $requirements = self::getResourcesDirectory() . '/requirements.txt';
        $requirementsHash = file_exists($requirements) ? sha1_file($requirements) : '';
        $hashFile = $eqDirectory . '/' . self::REQUIREMENTS_SENTINEL;

        if ($force || $pythonExecutable === '') {
            $this->createVirtualEnv($python, $venvDir);
            $force = true;
        }

        $needInstall = $force;
        if (!$needInstall && file_exists($requirements) && (!file_exists($hashFile) || trim((string)file_get_contents($hashFile)) !== $requirementsHash)) {
            $needInstall = true;
        }

        if ($needInstall && file_exists($requirements)) {
            $this->installPythonRequirements($venvDir, $requirements);
            file_put_contents($hashFile, $requirementsHash);
        }

        $pythonExecutable = self::locateVenvPython($venvDir);
        if ($pythonExecutable === '') {
            throw new Exception(__('Binaire Python introuvable dans l\'environnement virtuel', __FILE__));
        }

        return $pythonExecutable;
    }

    /**
     * Retourne le binaire Python d'un environnement virtuel.
     */
    private static function locateVenvPython($venvDir) {
        $candidates = [
            $venvDir . '/bin/python3',
            $venvDir . '/bin/python',
            $venvDir . '/Scripts/python.exe',
            $venvDir . '/Scripts/python3.exe',
        ];

        foreach ($candidates as $candidate) {
            if ($candidate === '') {
                continue;
            }
            if (file_exists($candidate) && (is_executable($candidate) || stripos(PHP_OS, 'WIN') === 0)) {
                return $candidate;
            }
        }

        return '';
    }

    /**
     * Crée un environnement virtuel Python.
     */
    private function createVirtualEnv($python, $venvDir) {
        if (file_exists($venvDir)) {
            self::deleteDirectory($venvDir);
        }
        $cmd = escapeshellarg($python) . ' -m venv ' . escapeshellarg($venvDir);
        $output = [];
        $code = 0;
        exec($cmd . ' 2>&1', $output, $code);
        if ($code !== 0) {
            throw new Exception(sprintf(__('Impossible de créer l\'environnement virtuel Python : %s', __FILE__), trim(implode('\n', $output))));
        }
    }

    /**
     * Installe les dépendances Python dans l\'environnement virtuel.
     */
    private function installPythonRequirements($venvDir, $requirements) {
        $pythonExecutable = self::locateVenvPython($venvDir);
        if ($pythonExecutable === '') {
            throw new Exception(__('Binaire Python introuvable dans l\'environnement virtuel', __FILE__));
        }

        $commands = [
            ['cmd' => escapeshellarg($pythonExecutable) . ' -m ensurepip --upgrade', 'optional' => true],
            ['cmd' => escapeshellarg($pythonExecutable) . ' -m pip install --upgrade pip', 'optional' => false],
            ['cmd' => escapeshellarg($pythonExecutable) . ' -m pip install -r ' . escapeshellarg($requirements), 'optional' => false],
        ];

        foreach ($commands as $entry) {
            $output = [];
            $code = 0;
            exec($entry['cmd'] . ' 2>&1', $output, $code);
            if ($code !== 0) {
                if (!empty($entry['optional'])) {
                    continue;
                }
                throw new Exception(sprintf(__('Échec lors de l\'installation des dépendances Python : %s', __FILE__), trim(implode('\n', $output))));
            }
        }

        exec(escapeshellarg($pythonExecutable) . ' -m pip --version 2>&1', $verifyOutput, $verifyCode);
        if ($verifyCode !== 0) {
            throw new Exception(sprintf(__('Vérification de pip impossible : %s', __FILE__), trim(implode('\n', (array)$verifyOutput))));
        }
    }

    /**
     * Écrit le fichier de configuration YAML attendu par le script Python.
     */
    private function writeConfigurationFile() {
        $eqDirectory = self::getEquipmentDirectory($this->getId());
        if (!file_exists($eqDirectory)) {
            mkdir($eqDirectory, 0775, true);
        }

        $host = trim((string)$this->getConfiguration('host'));
        $port = trim((string)$this->getConfiguration('port'));
        $https = (bool)$this->getConfiguration('https', 0);
        $user = trim((string)$this->getConfiguration('user'));
        $code = trim((string)$this->getConfiguration('code'));

        $baseUrl = $host;
        $protocol = $https ? 'https' : 'http';
        if (strpos($baseUrl, 'http://') !== 0 && strpos($baseUrl, 'https://') !== 0) {
            $baseUrl = $protocol . '://' . $baseUrl;
        }
        if ($port !== '') {
            if (preg_match('#^https?://#i', $baseUrl)) {
                $baseUrl = preg_replace('#^(https?://[^/:]+)(:\\d+)?#i', '$1:' . $port, $baseUrl);
            } else {
                $baseUrl .= ':' . $port;
            }
        }

        $sessionDir = $eqDirectory . '/session';
        if (!file_exists($sessionDir)) {
            mkdir($sessionDir, 0775, true);
        }

        $config = [
            'spc' => [
                'host' => $baseUrl,
                'user' => $user,
                'pin' => $code,
                'session_cache_dir' => $sessionDir,
            ],
        ];

        $configFile = $eqDirectory . '/' . self::CONFIG_FILE;
        file_put_contents($configFile, self::arrayToYaml($config));
        return $configFile;
    }

    /**
     * Convertit un tableau PHP en YAML minimaliste.
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
     * Formate une valeur scalaire pour YAML.
     */
    private static function yamlScalar($value) {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_numeric($value)) {
            return (string)$value;
        }
        $escaped = str_replace("'", "''", (string)$value);
        return "'" . $escaped . "'";
    }

    /**
     * Détermine si un rafraîchissement est nécessaire pour chaque équipement.
     */
    private static function maybeRefreshEquipments() {
        $interval = max(1, (int)config::byKey('poll_interval', 'acreexp', 300));
        $now = time();

        foreach (eqLogic::byType('acreexp', true) as $eqLogic) {
            if (!($eqLogic instanceof self)) {
                continue;
            }
            if ((int)$eqLogic->getIsEnable() !== 1) {
                continue;
            }
            if (!$eqLogic->hasCompleteConfiguration()) {
                continue;
            }

            $lastKey = self::CACHE_PREFIX . $eqLogic->getId();
            $lastRun = (int)cache::byKey($lastKey, 0);
            if ($lastRun > 0 && ($now - $lastRun) < $interval) {
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
     * Retourne le dossier de stockage du plugin.
     */
    private static function getStorageDirectory() {
        $dir = dirname(__DIR__, 2) . '/data';
        if (!file_exists($dir)) {
            mkdir($dir, 0775, true);
        }
        return $dir;
    }

    /**
     * Retourne le dossier associé à un équipement.
     */
    private static function getEquipmentDirectory($eqId) {
        $dir = self::getStorageDirectory() . '/equipment_' . (int)$eqId;
        if (!file_exists($dir)) {
            mkdir($dir, 0775, true);
        }
        return $dir;
    }

    /**
     * Supprime récursivement un dossier.
     */
    private static function deleteDirectory($directory) {
        if (!file_exists($directory)) {
            return;
        }
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $fileInfo) {
            if ($fileInfo->isDir()) {
                @rmdir($fileInfo->getRealPath());
            } else {
                @unlink($fileInfo->getRealPath());
            }
        }
        @rmdir($directory);
    }

    /**
     * Nettoie le dossier d'un équipement.
     */
    private static function removeEquipmentDirectory($eqId) {
        $dir = self::getStorageDirectory() . '/equipment_' . (int)$eqId;
        self::deleteDirectory($dir);
    }

    /**
     * Retourne la commande sudo adaptée à l'environnement Jeedom.
     */
    private static function getSudoCmd() {
        $cmd = trim((string)system::getCmdSudo());
        if ($cmd !== '' && substr($cmd, -1) !== ' ') {
            $cmd .= ' ';
        }
        return $cmd;
    }

    /**
     * Détermine le chemin du script Python embarqué.
     */
    private static function getStatusScriptPath() {
        $local = self::getResourcesDirectory() . self::STATUS_SCRIPT;
        if (file_exists($local)) {
            return $local;
        }
        return '';
    }

    /**
     * Retourne le dossier resources du plugin.
     */
    private static function getResourcesDirectory() {
        return dirname(__DIR__, 2) . '/resources';
    }

    /**
     * Retourne le fichier de progression des dépendances.
     */
    private static function getDependencyProgressFile() {
        return self::getStorageDirectory() . '/dependancy';
    }

    /**
     * Indique si l'environnement de base (Python) est disponible.
     */
    private static function isBaseEnvironmentReady() {
        $python = self::resolvePythonBinary();
        if ($python === '') {
            return false;
        }
        exec(escapeshellarg($python) . ' --version 2>&1', $output, $code);
        return $code === 0;
    }

    /**
     * Résout le binaire Python à utiliser.
     */
    private static function resolvePythonBinary() {
        $configured = trim((string)config::byKey('python_binary', 'acreexp', ''));
        $candidates = [];
        if ($configured !== '') {
            $candidates[] = $configured;
        }
        $candidates = array_merge($candidates, [
            '/usr/bin/python3',
            '/usr/local/bin/python3',
            'python3',
            'python',
        ]);

        foreach ($candidates as $candidate) {
            if ($candidate === '') {
                continue;
            }
            $path = self::which($candidate);
            if ($path !== '') {
                return $path;
            }
        }
        return '';
    }

    /**
     * Equivalent PHP de la commande which.
     */
    private static function which($binary) {
        if (strpos($binary, '/') === 0 && is_executable($binary)) {
            return $binary;
        }
        $cmd = 'command -v ' . escapeshellarg($binary) . ' 2>/dev/null';
        $path = trim((string)shell_exec($cmd));
        return $path !== '' ? $path : '';
    }
}

class acreexpCmd extends cmd {
    public function execute($_options = array()) {
        throw new Exception(__('Cette commande est uniquement informative', __FILE__));
    }
}
