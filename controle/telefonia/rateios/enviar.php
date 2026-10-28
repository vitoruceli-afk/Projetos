<?php

declare(strict_types=1);

use App\Core\Auth;
use App\Core\Session;
use App\Core\Mailer;
use App\Core\RateioEmail;
use App\Models\RateioHistorico;
use App\Models\Contato;
use App\Models\ConfigSmtp;

require __DIR__ . '/../../includes/bootstrap.php';
Auth::exigirLogin();

$id  = (int) ($_GET['id'] ?? 0);
$reg = RateioHistorico::buscarPorId($id);

if ($reg === null || $reg['tipo'] !== 'telefonia') {
    Session::flash('danger', 'Rateio não encontrado.');
    header('Location: ' . url('telefonia/rateios/listar.php'));
    exit;
}

if (!ConfigSmtp::configurado()) {
    Session::flash('danger', 'Configuração SMTP incompleta. Ajuste em Configuração SMTP (área inicial).');
    header('Location: ' . url('telefonia/rateios/listar.php'));
    exit;
}

$destinatarios = Contato::daLista('telefonia');

if ($destinatarios === []) {
    Session::flash('danger', 'Nenhum contato na lista da gerência telefonia. Cadastre em Contatos.');
    header('Location: ' . url('telefonia/rateios/listar.php'));
    exit;
}

try {
    Mailer::enviar(
        $destinatarios,
        RateioEmail::assunto($reg),
        RateioEmail::corpoHtml($reg),
        [[
            'nome'     => RateioEmail::nomeArquivo($reg),
            'conteudo' => RateioEmail::csv($reg),
            'tipo'     => 'text/csv',
        ]]
    );
    Session::flash('success', 'Rateio enviado para ' . count($destinatarios) . ' contato(s).');
} catch (\Throwable $e) {
    Session::flash('danger', 'Falha no envio: ' . $e->getMessage());
}

header('Location: ' . url('telefonia/rateios/listar.php'));
exit;
