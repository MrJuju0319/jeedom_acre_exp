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

class acreexpApi {
    /** @var acreexp */
    private $eqLogic;

    public function __construct(acreexp $eqLogic) {
        $this->eqLogic = $eqLogic;
    }

    public function fetchTopology(): array {
        return $this->request('/api/topology');
    }

    public function fetchStatus(string $resourcePath): array {
        return $this->request($resourcePath);
    }

    public function sendCommand(string $resourcePath, array $payload = []): array {
        return $this->request($resourcePath, 'POST', $payload);
    }

    private function request(string $endpoint, string $method = 'GET', array $payload = []): array {
        $url = $this->buildBaseUrl() . $endpoint;
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

        $username = $this->eqLogic->getConfiguration('username');
        $password = $this->eqLogic->getConfiguration('password');
        if ($username !== '' || $password !== '') {
            curl_setopt($ch, CURLOPT_USERPWD, $username . ':' . $password);
        }

        $verifyCertificate = (bool) $this->eqLogic->getConfiguration('verify_certificate', 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $verifyCertificate);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $verifyCertificate ? 2 : 0);

        if (!empty($payload)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        }

        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) {
            throw new Exception(sprintf(__('Erreur de communication avec la centrale : %s'), $curlError));
        }

        if ($statusCode >= 400) {
            throw new Exception(
                sprintf(__('La centrale a retourné un statut HTTP %1$s : %2$s'), $statusCode, $response)
            );
        }

        $decoded = json_decode($response, true);
        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception(sprintf(__('Réponse JSON invalide : %s'), json_last_error_msg()));
        }

        return $decoded ?? [];
    }

    private function buildBaseUrl(): string {
        $protocol = $this->eqLogic->getConfiguration('protocol', 'http');
        $ipAddress = trim((string) $this->eqLogic->getConfiguration('ip_address'));
        $port = $this->eqLogic->getConfiguration('port');

        if ($ipAddress === '') {
            throw new Exception(__('Adresse IP de la centrale non configurée.'));
        }

        $baseUrl = $protocol . '://' . $ipAddress;
        if (!empty($port)) {
            $baseUrl .= ':' . $port;
        }

        return rtrim($baseUrl, '/');
    }
}
