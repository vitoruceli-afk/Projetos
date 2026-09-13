<?php

declare(strict_types=1);

use App\Core\Auth;
use App\Core\Session;
use App\Core\Database;
use App\Core\GLPIClient;
use App\Models\Dispositivo;
use App\Models\TipoDispositivo;

$contexto     = 'inicial';
$tituloPagina = 'Sincronizar com GLPI';

require __DIR__ . '/../includes/bootstrap.php';
Auth::exigirAdmin();

$resultado = null;
$config_glpi = [
    'habilitado' => false,
    'url_base' => '',
    'verificar_ssl' => true,
    'status_conexao' => 'desconectado'
];

$pdo = Database::pdo();

// Tentar carregar configuração do banco
try {
    $stmt = $pdo->query('SELECT * FROM config_glpi LIMIT 1');
    $cfg = $stmt->fetch();
    if ($cfg) {
        $config_glpi = array_merge($config_glpi, $cfg);
    }
} catch (\Throwable $e) {
    $config_glpi['status_conexao'] = 'erro_db';
}

// Processa salvamento de configurações
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'salvar_config') {
    $url_base = trim($_POST['url_base'] ?? '');
    $app_token = trim($_POST['app_token'] ?? '');
    $user_token = trim($_POST['user_token'] ?? '');
    $habilitado = isset($_POST['habilitado']) ? 1 : 0;
    $verificar_ssl = isset($_POST['verificar_ssl']) ? 1 : 0;

    if ($url_base === '' || $app_token === '' || $user_token === '') {
        $resultado = ['erro' => 'Preencha todos os campos obrigatórios.'];
    } else {
        try {
            // Testar conexão
            $glpi = new GLPIClient($url_base, $app_token, $user_token, (bool) $verificar_ssl);
            $conectado = $glpi->testarConexao();

            if ($conectado) {
                // Salvar no banco
                $sql = 'INSERT INTO config_glpi (url_base, app_token, user_token, verificar_ssl, habilitado, status_conexao) '
                     . 'VALUES (?, ?, ?, ?, ?, "conectado") '
                     . 'ON DUPLICATE KEY UPDATE url_base = ?, app_token = ?, user_token = ?, verificar_ssl = ?, habilitado = ?, status_conexao = "conectado"';
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    $url_base, $app_token, $user_token, $verificar_ssl, $habilitado,
                    $url_base, $app_token, $user_token, $verificar_ssl, $habilitado
                ]);

                $config_glpi['url_base'] = $url_base;
                $config_glpi['habilitado'] = $habilitado;
                $config_glpi['verificar_ssl'] = $verificar_ssl;
                $config_glpi['status_conexao'] = 'conectado';
                $resultado = ['sucesso' => 'Configuração salva e conexão com GLPI estabelecida!'];
                $glpi->encerrarSessao();
            } else {
                $resultado = ['erro' => 'Erro ao conectar com GLPI: ' . $glpi->ultimoErro()];
            }
        } catch (\Throwable $e) {
            $resultado = ['erro' => 'Erro: ' . $e->getMessage()];
        }
    }
}

// Processa sincronização
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'sincronizar') {
    if (!$config_glpi['habilitado'] || !$config_glpi['url_base'] || !$config_glpi['app_token']) {
        $resultado = ['erro' => 'GLPI não está configurado ou desabilitado.'];
    } else {
        try {
            $glpi = new GLPIClient(
                $config_glpi['url_base'],
                $config_glpi['app_token'],
                $config_glpi['user_token'],
                (bool) ($config_glpi['verificar_ssl'] ?? true)
            );

            if (!$glpi->testarConexao()) {
                throw new \Exception('Falha ao conectar com GLPI: ' . $glpi->ultimoErro());
            }

            $tipos = TipoDispositivo::listar();
            $tipo_map = array_column($tipos, 'id', 'nome');

            $dispositivos_importados = 0;
            $erros_import = [];

            // Cada entrada mapeia: rótulo para mensagens de erro, tipo local
            // (tabela tipos_dispositivos), e o método do GLPIClient que traz os itens.
            $grupos_importacao = [
                ['label' => 'Computador', 'tipo' => 'Computador', 'obter' => 'obterComputadores'],
                ['label' => 'Monitor', 'tipo' => 'Monitor', 'obter' => 'obterMonitores'],
                ['label' => 'Impressora', 'tipo' => 'Impressora', 'obter' => 'obterImpressoras'],
                ['label' => 'Equipamento de rede', 'tipo' => 'Roteador/Switch', 'obter' => 'obterDispositivosRede'],
                ['label' => 'Periférico', 'tipo' => 'Periféricos', 'obter' => 'obterPerifericos'],
                ['label' => 'Celular', 'tipo' => 'Celular/Smartphone', 'obter' => 'obterCelulares'],
            ];

            foreach ($grupos_importacao as $grupo) {
                $tipo_local = $tipo_map[$grupo['tipo']] ?? null;
                if ($tipo_local === null) {
                    $erros_import[] = "Tipo local \"{$grupo['tipo']}\" não encontrado — itens do tipo {$grupo['label']} não foram importados.";
                    continue;
                }

                $itens = $glpi->{$grupo['obter']}();
                foreach ($itens as $item) {
                    try {
                        if (Dispositivo::porIdGlpi((int) $item['id']) === null) {
                            Dispositivo::criar(
                                $tipo_local,
                                $item['name'] !== '' ? $item['name'] : ($grupo['label'] . ' ' . $item['id']),
                                null,
                                $item['serial'] ?? '',
                                $item['model'] ?? '',
                                $item['manufacturer'] ?? '',
                                null,
                                'ativo',
                                $item['location'] ?? '',
                                '',
                                0,
                                '',
                                '',
                                'glpi',
                                (int) $item['id'],
                                $item
                            );
                            $dispositivos_importados++;
                        }
                    } catch (\Throwable $e) {
                        $erros_import[] = $grupo['label'] . ' ' . ($item['name'] ?: $item['id']) . ': ' . $e->getMessage();
                    }
                }
            }

            $glpi->encerrarSessao();

            $resultado = [
                'sucesso' => "$dispositivos_importados dispositivo(s) importado(s) com sucesso!",
                'avisos' => $erros_import
            ];
        } catch (\Throwable $e) {
            $resultado = ['erro' => 'Erro na sincronização: ' . $e->getMessage()];
        }
    }
}

require __DIR__ . '/../includes/header.php';
?>

<h2 class="mb-4">Sincronizar com GLPI</h2>

<?php if ($resultado): ?>
    <?php if (isset($resultado['sucesso'])): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="bi bi-check-circle"></i> <?= e($resultado['sucesso']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php if (!empty($resultado['avisos'])): ?>
            <div class="alert alert-warning">
                <strong>Avisos durante a importação:</strong>
                <ul class="mb-0">
                    <?php foreach ($resultado['avisos'] as $aviso): ?>
                        <li><?= e($aviso) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
    <?php endif; ?>
    <?php if (isset($resultado['erro'])): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="bi bi-exclamation-circle"></i> <?= e($resultado['erro']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
<?php endif; ?>

<div class="row">
    <!-- Coluna 1: Configuração -->
    <div class="col-lg-6 mb-4">
        <div class="card shadow-sm">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0">
                    <i class="bi bi-gear"></i> Configuração de Conexão
                </h5>
            </div>
            <div class="card-body">
                <div class="mb-3 p-3" style="background: var(--bg); border-radius: 8px;">
                    <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 10px;">
                        <div class="badge" style="background: <?= $config_glpi['status_conexao'] === 'conectado' ? 'var(--online)' : 'var(--critical)' ?>;">
                            <i class="bi <?= $config_glpi['status_conexao'] === 'conectado' ? 'bi-check-circle' : 'bi-x-circle' ?>"></i>
                            <?= ucfirst(str_replace('_', ' ', $config_glpi['status_conexao'])) ?>
                        </div>
                    </div>
                    <?php if ($config_glpi['url_base']): ?>
                        <small class="text-muted">URL: <code><?= e($config_glpi['url_base']) ?></code></small>
                    <?php endif; ?>
                </div>

                <form method="POST" class="mb-0">
                    <input type="hidden" name="action" value="salvar_config">

                    <div class="mb-3">
                        <label class="form-label">URL do GLPI <span class="text-danger">*</span></label>
                        <input type="url" name="url_base" class="form-control"
                               value="<?= e($config_glpi['url_base']) ?>"
                               placeholder="https://glpi.empresa.com" required>
                        <small class="text-muted">
                            Pode informar só o domínio (<code>https://glpi.empresa.com</code>) ou já com
                            <code>/apirest.php</code> no final — o sistema completa automaticamente.
                        </small>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">App Token <span class="text-danger">*</span></label>
                        <input type="password" name="app_token" class="form-control" 
                               value="<?= e($config_glpi['app_token']) ?>"
                               placeholder="Seu app token do GLPI" required>
                        <small class="text-muted">Gere em: Configuração > API</small>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">User Token <span class="text-danger">*</span></label>
                        <input type="password" name="user_token" class="form-control" 
                               value="<?= e($config_glpi['user_token']) ?>"
                               placeholder="Seu user token do GLPI" required>
                        <small class="text-muted">Disponível no seu perfil de usuário</small>
                    </div>

                    <div class="form-check mb-2">
                        <input type="checkbox" name="verificar_ssl" class="form-check-input" id="chk_verificar_ssl"
                               <?= ($config_glpi['verificar_ssl'] ?? true) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="chk_verificar_ssl">
                            Verificar certificado SSL
                        </label>
                        <div class="form-text">
                            Desmarque apenas se o GLPI usar certificado autoassinado em rede interna.
                        </div>
                    </div>

                    <div class="form-check mb-3">
                        <input type="checkbox" name="habilitado" class="form-check-input" id="chk_habilitado"
                               <?= $config_glpi['habilitado'] ? 'checked' : '' ?>>
                        <label class="form-check-label" for="chk_habilitado">
                            Habilitar sincronização automática
                        </label>
                    </div>

                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-check-lg"></i> Salvar e Testar Conexão
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- Coluna 2: Sincronização -->
    <div class="col-lg-6 mb-4">
        <div class="card shadow-sm">
            <div class="card-header bg-info text-white">
                <h5 class="mb-0">
                    <i class="bi bi-arrow-repeat"></i> Sincronizar Dispositivos
                </h5>
            </div>
            <div class="card-body">
                <?php if ($config_glpi['status_conexao'] === 'conectado'): ?>
                    <div class="alert alert-info mb-3">
                        <strong>Conexão ativa!</strong> Você pode sincronizar dispositivos do GLPI.
                    </div>

                    <form method="POST" class="mb-0">
                        <input type="hidden" name="action" value="sincronizar">
                        
                        <h6 class="mb-2">Tipos de dispositivos que serão importados:</h6>
                        <ul class="small mb-3">
                            <li><i class="bi bi-laptop"></i> Computadores</li>
                            <li><i class="bi bi-square"></i> Monitores</li>
                            <li><i class="bi bi-printer"></i> Impressoras</li>
                            <li><i class="bi bi-router"></i> Equipamentos de rede</li>
                            <li><i class="bi bi-headphones"></i> Periféricos</li>
                            <li><i class="bi bi-phone"></i> Celulares/Smartphones</li>
                        </ul>
                        <p class="small text-muted mb-3">
                            Tablets não possuem um tipo próprio na API do GLPI — normalmente aparecem
                            como Celular ou Computador, e podem ser reclassificados manualmente após a importação.
                        </p>

                        <button type="submit" class="btn btn-info w-100">
                            <i class="bi bi-arrow-repeat"></i> Sincronizar Agora
                        </button>
                    </form>
                <?php else: ?>
                    <div class="alert alert-warning">
                        <strong>Conexão não estabelecida.</strong><br>
                        Configure a URL e os tokens na coluna ao lado, após testar com sucesso, você poderá sincronizar dispositivos.
                    </div>
                    <button type="button" class="btn btn-warning w-100" disabled>
                        <i class="bi bi-exclamation-circle"></i> Aguardando Conexão
                    </button>
                <?php endif; ?>
            </div>
        </div>

        <!-- Card de Informações -->
        <div class="card border-info shadow-sm">
            <div class="card-header bg-light">
                <h6 class="mb-0">
                    <i class="bi bi-info-circle"></i> Como obter os tokens do GLPI
                </h6>
            </div>
            <div class="card-body small">
                <p><strong>App Token:</strong></p>
                <ol class="ps-3 mb-3">
                    <li>Acesse seu GLPI como administrador</li>
                    <li>Vá a: Configuração → API</li>
                    <li>Clique em "Adicionar" e crie um novo token de aplicação</li>
                    <li>Copie o token gerado aqui</li>
                </ol>

                <p><strong>User Token:</strong></p>
                <ol class="ps-3">
                    <li>Clique na sua foto de perfil (topo direita)</li>
                    <li>Selecione "Preferências"</li>
                    <li>Vá à aba "API"</li>
                    <li>Copie seu "User token"</li>
                </ol>

                <div class="alert alert-light border-left border-warning mt-3 mb-0">
                    <strong>⚠️ Segurança:</strong> Nunca compartilhe seus tokens. Guarde-os com segurança.
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Documentação de Referência -->
<div class="card border-secondary mt-4">
    <div class="card-header bg-light">
        <h6 class="mb-0">
            <i class="bi bi-book"></i> Documentação de Referência
        </h6>
    </div>
    <div class="card-body small">
        <p>Para mais informações sobre a API REST do GLPI v11, consulte:</p>
        <ul>
            <li><a href="https://glpi-developer-documentation.readthedocs.io/en/master/devapi/index.html" target="_blank">GLPI Developer Documentation</a></li>
            <li><a href="https://help.glpi-project.org/documentation/modules/configuration/general/api" target="_blank">GLPI API Configuration</a></li>
        </ul>
        <p class="mb-0 mt-3 text-muted">Versão GLPI suportada: 11.x</p>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
