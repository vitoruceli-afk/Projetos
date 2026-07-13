<?php

namespace Core;

use Config\GLPIConfig;

/**
 * Cliente da API REST do GLPI (apirest.php).
 *
 * Cada método público abre sua própria sessão (initSession), executa o
 * trabalho e encerra a sessão (killSession) — não há cache de token entre
 * chamadas, já que este cliente é usado só em ações pontuais (sincronizações
 * manuais e criação de um chamado por preenchimento).
 *
 * Resolução de entidade/perfil por nome é feita buscando a coleção completa
 * e comparando o campo "name" exatamente, em vez de depender de ids de
 * search-option (que variam por instalação do GLPI).
 */
class GLPIClient
{
    private $sessionToken = null;

    /**
     * Testa apenas a autenticação (App-Token + User-Token), sem depender de
     * nomes de entidade/perfil estarem corretos.
     */
    public function testConnection()
    {
        return $this->runWithSession(function () {
            return true;
        });
    }

    public function getEntityIdByName($name = null)
    {
        return $this->runWithSession(function () use ($name) {
            return $this->resolveEntityId($name);
        });
    }

    public function getProfileIdByName($name = null)
    {
        return $this->runWithSession(function () use ($name) {
            return $this->resolveProfileId($name);
        });
    }

    /**
     * Usuários com o perfil "Super-Admin" (configurável) na entidade
     * NTI-CampusI (configurável). Retorna array pronto para upsert local:
     * ['glpi_user_id','username','full_name','email']
     */
    public function getSuperAdminTechnicians()
    {
        return $this->runWithSession(function () {
            $profileId = $this->resolveProfileId();
            $entityId = $this->resolveEntityId();

            $links = $this->fetchAllPages('Profile_User');
            $technicians = [];
            $seen = [];

            foreach ($links as $link) {
                if ((int)($link['profiles_id'] ?? 0) !== $profileId) {
                    continue;
                }
                if ((int)($link['entities_id'] ?? -1) !== $entityId) {
                    continue;
                }

                $userId = (int)($link['users_id'] ?? 0);
                if ($userId <= 0 || isset($seen[$userId])) {
                    continue;
                }
                $seen[$userId] = true;

                $user = $this->fetchUserDetails($userId);
                if ($user) {
                    $technicians[] = $user;
                }
            }

            return $technicians;
        });
    }

    /**
     * Computadores inventariados a partir da entidade raiz (DEVICE_ENTITY_NAME,
     * configurável — "Faesa"), recursivamente por todas as sub-entidades.
     * Retorna array pronto para upsert local:
     * ['glpi_items_id','name','model','manufacturer','serial_number','location_name']
     */
    public function getInventoriedComputers()
    {
        return $this->runWithSession(function () {
            // changeActiveEntities (chamado dentro de resolveEntityId) já escopa
            // recursivamente a sessão para a entidade + sub-entidades, então a
            // listagem abaixo já vem filtrada pelo próprio GLPI — não refiltrar
            // por igualdade exata de entities_id aqui (isso descartaria
            // dispositivos legitimamente cadastrados em sub-entidades).
            $this->resolveEntityId(GLPIConfig::DEVICE_ENTITY_NAME);

            $computers = $this->fetchAllPages(GLPIConfig::COMPUTER_ITEMTYPE);
            $manufacturers = $this->fetchIdNameMap('Manufacturer');
            $models = $this->fetchIdNameMap('ComputerModel');
            $locations = $this->fetchIdNameMap('Location');

            $devices = [];
            foreach ($computers as $computer) {
                $devices[] = [
                    'glpi_items_id' => (int)$computer['id'],
                    'name' => $computer['name'] !== '' && $computer['name'] !== null
                        ? $computer['name']
                        : ('GLPI-' . $computer['id']),
                    'model' => $models[(int)($computer['computermodels_id'] ?? 0)] ?? null,
                    'manufacturer' => $manufacturers[(int)($computer['manufacturers_id'] ?? 0)] ?? null,
                    'serial_number' => $computer['serial'] ?? null,
                    'location_name' => $locations[(int)($computer['locations_id'] ?? 0)] ?? null,
                ];
            }

            return $devices;
        });
    }

    /**
     * Cria um chamado do tipo Requisição (2), já Fechado (6), na entidade
     * configurada, atribuído ao técnico informado. Retorna o id do chamado
     * criado ou lança \Exception com o motivo da falha.
     */
    public function createClosedTicket($name, $content, $technicianGlpiUserId, $requesterGlpiUserId = null)
    {
        return $this->runWithSession(function () use ($name, $content, $technicianGlpiUserId, $requesterGlpiUserId) {
            $entityId = $this->resolveEntityId();

            $input = [
                'name' => $name,
                'content' => $content,
                'type' => 2, // Requisição
                'status' => 6, // Fechado
                'entities_id' => $entityId,
                'itilcategories_id' => 0,
                'urgency' => 3,
                'impact' => 3,
                '_users_id_assign' => (int)$technicianGlpiUserId,
            ];

            if (!empty($requesterGlpiUserId)) {
                $input['_users_id_requester'] = (int)$requesterGlpiUserId;
            }

            $resp = $this->request('POST', '/Ticket', ['body' => ['input' => $input]]);

            if ($resp['status'] < 200 || $resp['status'] >= 300 || !isset($resp['body']['id'])) {
                $message = is_array($resp['body']) || is_object($resp['body'])
                    ? json_encode($resp['body'])
                    : (string)$resp['body'];
                throw new \Exception("Falha ao criar chamado no GLPI (HTTP {$resp['status']}): $message");
            }

            return (int)$resp['body']['id'];
        });
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    private function runWithSession(callable $fn)
    {
        if (!GLPIConfig::isConfigured()) {
            throw new \Exception('Integração GLPI não configurada. Preencha config/GLPIConfig.php.');
        }
        if (!function_exists('curl_init')) {
            throw new \Exception('Extensão cURL não está disponível nesta instalação do PHP.');
        }

        $this->initSession();
        try {
            return $fn();
        } finally {
            $this->killSession();
        }
    }

    private function initSession()
    {
        $resp = $this->request('GET', '/initSession');

        if ($resp['status'] < 200 || $resp['status'] >= 300 || empty($resp['body']['session_token'])) {
            throw new \Exception('Falha ao iniciar sessão no GLPI (HTTP ' . $resp['status'] . ')');
        }

        $this->sessionToken = $resp['body']['session_token'];
    }

    private function killSession()
    {
        if ($this->sessionToken === null) {
            return;
        }

        try {
            $this->request('GET', '/killSession');
        } catch (\Exception $e) {
            error_log('GLPIClient: falha ao encerrar sessão GLPI: ' . $e->getMessage());
        }

        $this->sessionToken = null;
    }

    private function resolveEntityId($name = null)
    {
        $name = $name ?? GLPIConfig::ENTITY_NAME;

        // Por padrão a sessão só enxerga a entidade ativa atual (definida pelo
        // GLPI ao logar), então a busca por nome abaixo poderia nunca encontrar
        // entidades fora dela (ex: a raiz, se a conta de serviço não estiver
        // com ela ativa por padrão). Amplia primeiro para "todas as entidades
        // que a conta tem direito de ver".
        $this->changeActiveEntity('all');

        foreach ($this->fetchAllPages('Entity') as $entity) {
            if (($entity['name'] ?? null) === $name) {
                $entityId = (int)$entity['id'];
                // Agora restringe de fato a sessão à entidade alvo (+ sub-entidades),
                // já que a listagem de itens (Computer, Profile_User, etc.) e a
                // criação de chamados são restritas à(s) entidade(s) ativa(s) da
                // sessão, não só ao campo entities_id do registro.
                $this->changeActiveEntity($entityId);
                return $entityId;
            }
        }

        throw new \Exception("Entidade GLPI \"$name\" não encontrada.");
    }

    private function changeActiveEntity($entityId, $recursive = true)
    {
        $resp = $this->request('POST', '/changeActiveEntities', [
            'body' => [
                'entities_id' => $entityId,
                'is_recursive' => $recursive,
            ],
        ]);

        if ($resp['status'] < 200 || $resp['status'] >= 300) {
            throw new \Exception('Falha ao definir entidade ativa no GLPI (HTTP ' . $resp['status'] . ')');
        }
    }

    private function resolveProfileId($name = null)
    {
        $name = $name ?? GLPIConfig::TECHNICIAN_PROFILE_NAME;

        foreach ($this->fetchAllPages('Profile') as $profile) {
            if (($profile['name'] ?? null) === $name) {
                return (int)$profile['id'];
            }
        }

        throw new \Exception("Perfil GLPI \"$name\" não encontrado.");
    }

    private function fetchUserDetails($userId)
    {
        $resp = $this->request('GET', "/User/$userId");

        if ($resp['status'] < 200 || $resp['status'] >= 300 || !is_array($resp['body'])) {
            return null;
        }

        $user = $resp['body'];

        $fullName = trim(($user['realname'] ?? '') . ' ' . ($user['firstname'] ?? ''));
        if ($fullName === '') {
            $fullName = $user['name'] ?? ('glpi_user_' . $userId);
        }

        return [
            'glpi_user_id' => $userId,
            'username' => $user['name'] ?? ('glpi_user_' . $userId),
            'full_name' => $fullName,
            'email' => $this->fetchUserEmail($userId),
        ];
    }

    private function fetchUserEmail($userId)
    {
        $resp = $this->request('GET', "/User/$userId/UserEmail");

        if ($resp['status'] < 200 || $resp['status'] >= 300 || !is_array($resp['body'])) {
            return null;
        }

        foreach ($resp['body'] as $emailRow) {
            if (!empty($emailRow['is_default']) && !empty($emailRow['email'])) {
                return $emailRow['email'];
            }
        }

        return $resp['body'][0]['email'] ?? null;
    }

    private function fetchIdNameMap($itemtype)
    {
        $map = [];
        foreach ($this->fetchAllPages($itemtype) as $item) {
            if (isset($item['id'])) {
                $map[(int)$item['id']] = $item['name'] ?? null;
            }
        }
        return $map;
    }

    private function fetchAllPages($itemtype, $pageSize = 200)
    {
        $items = [];
        $start = 0;

        while (true) {
            $resp = $this->request('GET', "/$itemtype", [
                'query' => ['range' => $start . '-' . ($start + $pageSize - 1)],
            ]);

            if ($resp['status'] < 200 || $resp['status'] >= 300 || !is_array($resp['body'])) {
                break;
            }

            $batch = $resp['body'];
            if (empty($batch)) {
                break;
            }

            $items = array_merge($items, $batch);
            $start += count($batch);

            $total = null;
            if (!empty($resp['headers']['content-range'])) {
                $slashPos = strrpos($resp['headers']['content-range'], '/');
                if ($slashPos !== false) {
                    $total = (int)substr($resp['headers']['content-range'], $slashPos + 1);
                }
            }

            if ($total !== null && $start >= $total) {
                break;
            }
            if ($total === null && count($batch) < $pageSize) {
                break;
            }
        }

        return $items;
    }

    private function request($method, $endpoint, $options = [])
    {
        $url = rtrim(GLPIConfig::API_BASE_URL, '/') . $endpoint;

        if (!empty($options['query'])) {
            $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($options['query']);
        }

        $headers = [
            'App-Token: ' . GLPIConfig::APP_TOKEN,
            'Content-Type: application/json',
        ];

        if ($this->sessionToken !== null) {
            $headers[] = 'Session-Token: ' . $this->sessionToken;
        } elseif ($endpoint === '/initSession') {
            $headers[] = 'Authorization: user_token ' . GLPIConfig::USER_TOKEN;
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, GLPIConfig::HTTP_TIMEOUT_SECONDS);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, GLPIConfig::VERIFY_SSL);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, GLPIConfig::VERIFY_SSL ? 2 : 0);

        $responseHeaders = [];
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($curl, $headerLine) use (&$responseHeaders) {
            $parts = explode(':', $headerLine, 2);
            if (count($parts) === 2) {
                $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
            return strlen($headerLine);
        });

        if (isset($options['body'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($options['body']));
        }

        $body = curl_exec($ch);

        if ($body === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new \Exception("Erro de conexão com o GLPI: $error");
        }

        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $decoded = json_decode($body, true);

        return [
            'status' => $status,
            'body' => $decoded !== null ? $decoded : $body,
            'headers' => $responseHeaders,
        ];
    }
}
