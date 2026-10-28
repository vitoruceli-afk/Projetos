<?php

declare(strict_types=1);

namespace App\Core;

/*
|--------------------------------------------------------------------------
| GLPIClient
|--------------------------------------------------------------------------
|
| Cliente para integração com a API REST (v1 / apirest.php) do GLPI 11.
| Referência oficial:
|   https://glpi-developer-documentation.readthedocs.io/en/master/devapi/index.html
|   https://help.glpi-project.org/documentation/modules/configuration/general/api
|
| Autenticação (conforme documentação):
|   1) initSession   -> headers "App-Token" + "Authorization: user_token {token}"
|   2) demais chamadas -> headers "App-Token" + "Session-Token: {session_token}"
|   3) killSession   -> encerra a sessão aberta
|
| Cada item retornado é normalizado (campos de dropdown como
| manufacturers_id/locations_id/*models_id) para as chaves amigáveis
| name/serial/model/manufacturer/location, mantendo os campos originais
| do GLPI intactos (usados para auditoria em dados_glpi).
|
*/
final class GLPIClient
{
    private string $url_base;
    private string $app_token;
    private string $user_token;
    private bool $verificar_ssl;
    private ?string $session_token = null;
    private bool $conectado = false;
    private string $ultima_erro = '';

    private const TAMANHO_PAGINA = 500;

    public function __construct(string $url_base, string $app_token, string $user_token, bool $verificar_ssl = true)
    {
        $base = rtrim(trim($url_base), '/');
        if ($base !== '' && !str_ends_with($base, '/apirest.php')) {
            $base .= '/apirest.php';
        }

        $this->url_base = $base;
        $this->app_token = $app_token;
        $this->user_token = $user_token;
        $this->verificar_ssl = $verificar_ssl;
    }

    /**
     * Testa a conexão com o servidor GLPI (initSession)
     */
    public function testarConexao(): bool
    {
        try {
            $response = $this->request('GET', '/initSession');
            if (isset($response['session_token'])) {
                $this->session_token = $response['session_token'];
                $this->conectado = true;
                return true;
            }
            $this->ultima_erro = $response['message'] ?? 'Erro desconhecido ao conectar';
            return false;
        } catch (\Throwable $e) {
            $this->ultima_erro = $e->getMessage();
            return false;
        }
    }

    /**
     * Faz uma requisição HTTP à API GLPI e retorna o corpo já decodificado.
     */
    private function request(string $method, string $endpoint, ?array $data = null): array
    {
        return $this->requestComCabecalhos($method, $endpoint, $data)['body'];
    }

    /**
     * Faz a requisição HTTP crua, retornando corpo decodificado e o header
     * Content-Range (usado para paginação nas listagens de itens).
     *
     * @return array{body: array, content_range: ?string}
     */
    private function requestComCabecalhos(string $method, string $endpoint, ?array $data = null): array
    {
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('cURL não está disponível. Instale a extensão php-curl.');
        }

        $url = $this->url_base . $endpoint;
        $ch = curl_init();

        $headers = [
            'Content-Type: application/json',
            'App-Token: ' . $this->app_token,
        ];

        if ($this->session_token !== null) {
            $headers[] = 'Session-Token: ' . $this->session_token;
        } else {
            $headers[] = 'Authorization: user_token ' . $this->user_token;
        }

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => $this->verificar_ssl,
            CURLOPT_SSL_VERIFYHOST => $this->verificar_ssl ? 2 : 0,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
        ]);

        if ($method !== 'GET' && $data !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }

        $resposta = curl_exec($ch);
        $erro = curl_error($ch);

        if ($resposta === false || $erro) {
            curl_close($ch);
            throw new \RuntimeException('Erro cURL: ' . $erro);
        }

        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $headersBrutos = substr($resposta, 0, $header_size);
        $corpo = substr($resposta, $header_size);

        $content_range = null;
        if (preg_match('/^Content-Range:\s*(.+)$/mi', $headersBrutos, $m)) {
            $content_range = trim($m[1]);
        }

        if ($http_code >= 400) {
            throw new \RuntimeException("HTTP $http_code: $corpo");
        }

        $decodificado = json_decode($corpo, true);
        if ($decodificado === null && $corpo !== '' && $corpo !== 'null') {
            throw new \RuntimeException('Resposta inválida (JSON): ' . $corpo);
        }

        return ['body' => $decodificado ?? [], 'content_range' => $content_range];
    }

    /**
     * Busca todos os itens de um itemtype do GLPI, paginando automaticamente
     * (a API limita a quantidade de itens por requisição via Content-Range).
     */
    private function buscarTodos(string $itemtype): array
    {
        if (!$this->conectado && !$this->testarConexao()) {
            throw new \RuntimeException('Não conectado ao GLPI: ' . $this->ultima_erro);
        }

        $itens = [];
        $inicio = 0;

        do {
            $fim = $inicio + self::TAMANHO_PAGINA - 1;
            $resultado = $this->requestComCabecalhos(
                'GET',
                "/$itemtype?range={$inicio}-{$fim}&expand_dropdowns=true"
            );

            $pagina = $resultado['body'];
            if (isset($pagina['error'])) {
                throw new \RuntimeException('GLPI Error: ' . $pagina['error']);
            }
            if (!is_array($pagina)) {
                break;
            }

            foreach ($pagina as $item) {
                if (is_array($item)) {
                    $itens[] = self::normalizarItem($item, $itemtype);
                }
            }

            $total = null;
            if ($resultado['content_range'] !== null && str_contains($resultado['content_range'], '/')) {
                $total = (int) substr($resultado['content_range'], strrpos($resultado['content_range'], '/') + 1);
            }

            $recebidos = count($pagina);
            $inicio += self::TAMANHO_PAGINA;
        } while ($total !== null && $inicio < $total && $recebidos > 0);

        return $itens;
    }

    /**
     * Normaliza os campos específicos de dropdown de cada itemtype do GLPI
     * (ex.: computermodels_id, manufacturers_id, locations_id) para as
     * chaves amigáveis usadas pela sincronização (model/manufacturer/location).
     * Os campos originais do GLPI são preservados no array retornado.
     */
    private static function normalizarItem(array $item, string $itemtype): array
    {
        $campoModelo = [
            'Computer' => 'computermodels_id',
            'Monitor' => 'monitormodels_id',
            'Printer' => 'printermodels_id',
            'NetworkEquipment' => 'networkequipmentmodels_id',
            'Peripheral' => 'peripheralmodels_id',
            'Phone' => 'phonemodels_id',
        ][$itemtype] ?? null;

        $item['name'] = $item['name'] ?? '';
        $item['manufacturer'] = $item['manufacturers_id'] ?? '';
        $item['location'] = $item['locations_id'] ?? '';
        $item['model'] = $campoModelo !== null ? ($item[$campoModelo] ?? '') : '';

        // Com expand_dropdowns=true, um item sem dropdown associado (id 0)
        // retorna a string "0" ou vazia — normalizamos para string vazia.
        foreach (['manufacturer', 'location', 'model'] as $chave) {
            if ($item[$chave] === '0' || $item[$chave] === 0) {
                $item[$chave] = '';
            }
        }

        return $item;
    }

    /**
     * Obtém computadores do GLPI
     */
    public function obterComputadores(): array
    {
        try {
            return $this->buscarTodos('Computer');
        } catch (\Throwable $e) {
            $this->ultima_erro = $e->getMessage();
            return [];
        }
    }

    /**
     * Obtém monitores do GLPI
     */
    public function obterMonitores(): array
    {
        try {
            return $this->buscarTodos('Monitor');
        } catch (\Throwable $e) {
            $this->ultima_erro = $e->getMessage();
            return [];
        }
    }

    /**
     * Obtém impressoras do GLPI
     */
    public function obterImpressoras(): array
    {
        try {
            return $this->buscarTodos('Printer');
        } catch (\Throwable $e) {
            $this->ultima_erro = $e->getMessage();
            return [];
        }
    }

    /**
     * Obtém dispositivos de rede (roteadores, switches)
     */
    public function obterDispositivosRede(): array
    {
        try {
            return $this->buscarTodos('NetworkEquipment');
        } catch (\Throwable $e) {
            $this->ultima_erro = $e->getMessage();
            return [];
        }
    }

    /**
     * Obtém periféricos (teclado, mouse, webcam, etc)
     */
    public function obterPerifericos(): array
    {
        try {
            return $this->buscarTodos('Peripheral');
        } catch (\Throwable $e) {
            $this->ultima_erro = $e->getMessage();
            return [];
        }
    }

    /**
     * Obtém celulares / smartphones cadastrados no GLPI (itemtype Phone)
     */
    public function obterCelulares(): array
    {
        try {
            return $this->buscarTodos('Phone');
        } catch (\Throwable $e) {
            $this->ultima_erro = $e->getMessage();
            return [];
        }
    }

    /**
     * Faz uma requisição GET crua a um endpoint arbitrário da API (ex.:
     * sub-itens de um computador, como "/Computer/123/Item_DeviceProcessor").
     */
    public function obterBruto(string $endpoint): array
    {
        if (!$this->conectado && !$this->testarConexao()) {
            throw new \RuntimeException('Não conectado ao GLPI: ' . $this->ultima_erro);
        }
        return $this->request('GET', $endpoint);
    }

    /**
     * Busca processador, memória, armazenamento e sistema operacional de um
     * computador. Faz 2 chamadas extras por computador (o GLPI não traz tudo
     * isso na listagem nem no getItem sem parâmetros):
     *   - GET /Computer/{id}?with_devices=true  -> processador/memória/discos
     *   - GET /Computer/{id}/Item_OperatingSystem -> sistema operacional
     * Falhas em qualquer uma delas não interrompem a sincronização — o
     * computador é importado normalmente, só sem aquele dado específico.
     *
     * @return array{processador:string, memoria:string, armazenamento:string, sistema_operacional:string}
     */
    public function obterEspecificacoesComputador(int $id): array
    {
        $specs = [
            'processador' => '',
            'memoria' => '',
            'armazenamento' => '',
            'sistema_operacional' => '',
        ];

        try {
            $detalhe = $this->request('GET', "/Computer/$id?with_devices=true&expand_dropdowns=true");
            $devices = $detalhe['_devices'] ?? [];

            if (!empty($devices['Item_DeviceProcessor'])) {
                $nomes = array_unique(array_filter(array_column($devices['Item_DeviceProcessor'], 'deviceprocessors_id')));
                $specs['processador'] = implode(' + ', $nomes);
            }

            if (!empty($devices['Item_DeviceMemory'])) {
                $totalMb = (float) array_sum(array_column($devices['Item_DeviceMemory'], 'size'));
                $qtd = count($devices['Item_DeviceMemory']);
                $tamanho = self::formatarTamanho($totalMb);
                $specs['memoria'] = $tamanho !== '' ? $tamanho . ($qtd > 1 ? " ({$qtd}x)" : '') : '';
            }

            if (!empty($devices['Item_DeviceHardDrive'])) {
                $tamanhos = array_filter(array_map(
                    static fn($d) => self::formatarTamanho((float) ($d['capacity'] ?? 0)),
                    $devices['Item_DeviceHardDrive']
                ));
                $specs['armazenamento'] = implode(' + ', $tamanhos);
            }
        } catch (\Throwable) {
            // segue sem specs de hardware — não interrompe a sincronização
        }

        try {
            $os = $this->request('GET', "/Computer/$id/Item_OperatingSystem?expand_dropdowns=true");
            if (is_array($os) && isset($os[0]['operatingsystems_id']) && $os[0]['operatingsystems_id'] !== '' && $os[0]['operatingsystems_id'] !== 0) {
                $nome = (string) $os[0]['operatingsystems_id'];
                $versao = (string) ($os[0]['operatingsystemversions_id'] ?? '');
                $specs['sistema_operacional'] = trim($nome . ($versao !== '' && $versao !== '0' ? " ($versao)" : ''));
            }
        } catch (\Throwable) {
            // segue sem SO
        }

        return $specs;
    }

    /**
     * Converte um tamanho em MB (como devolvido pelo GLPI para memória e
     * discos) para uma string amigável em GB.
     */
    private static function formatarTamanho(float $mb): string
    {
        if ($mb <= 0) {
            return '';
        }
        $gb = $mb / 1024;
        if ($gb >= 1024) {
            $tb = $gb / 1024;
            return (fmod($tb, 1.0) === 0.0 ? (string) (int) $tb : number_format($tb, 1, ',', '')) . 'TB';
        }
        return (fmod($gb, 1.0) === 0.0 ? (string) (int) $gb : number_format($gb, 1, ',', '')) . 'GB';
    }

    /**
     * Mapeia um itemtype do GLPI para o nome do nosso tipo local
     * (deve corresponder a um registro em tipos_dispositivos).
     */
    public static function mapearTipo(string $tipo_glpi): string
    {
        $mapeamento = [
            'Computer' => 'Computador',
            'Monitor' => 'Monitor',
            'Printer' => 'Impressora',
            'NetworkEquipment' => 'Roteador/Switch',
            'Peripheral' => 'Periféricos',
            'Phone' => 'Celular/Smartphone',
        ];

        return $mapeamento[$tipo_glpi] ?? 'Outros';
    }

    /**
     * Encerra a sessão com GLPI
     */
    public function encerrarSessao(): bool
    {
        if (!$this->session_token) {
            return true;
        }

        try {
            $this->request('GET', '/killSession');
            $this->session_token = null;
            $this->conectado = false;
            return true;
        } catch (\Throwable $e) {
            $this->ultima_erro = $e->getMessage();
            return false;
        }
    }

    /**
     * Obtém o último erro
     */
    public function ultimoErro(): string
    {
        return $this->ultima_erro;
    }

    /**
     * Verifica se está conectado
     */
    public function estaConectado(): bool
    {
        return $this->conectado;
    }
}
