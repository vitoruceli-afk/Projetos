<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\ConfigSmtp;
use RuntimeException;

/*
|--------------------------------------------------------------------------
| Mailer
|--------------------------------------------------------------------------
|
| Cliente SMTP simples (sem dependências externas) para envio dos rateios.
| Suporta:
|   - método 'smtp'  : AUTH LOGIN com usuário/senha (com TLS/SSL/none)
|   - método 'oauth' : AUTH XOAUTH2 (Microsoft 365 / Google / custom),
|                       obtendo um access token via refresh_token grant.
|
| Lança RuntimeException com mensagem amigável em caso de falha.
|
*/
final class Mailer
{
    private const CRLF = "\r\n";

    /** @var resource|null */
    private $socket = null;

    /**
     * Envia um e-mail HTML com anexos opcionais.
     *
     * @param array<int,array{nome:string,email:string}> $destinatarios
     * @param array<int,array{nome:string,conteudo:string,tipo:string}> $anexos
     */
    public static function enviar(
        array $destinatarios,
        string $assunto,
        string $corpoHtml,
        array $anexos = []
    ): void {
        $cfg = ConfigSmtp::obter();

        if (($cfg['remetente_email'] ?? '') === '') {
            throw new RuntimeException('Remetente não configurado. Verifique a configuração SMTP.');
        }

        $destinatarios = array_values(array_filter(
            $destinatarios,
            static fn($d) => filter_var($d['email'] ?? '', FILTER_VALIDATE_EMAIL)
        ));

        if ($destinatarios === []) {
            throw new RuntimeException('Nenhum destinatário válido na lista de contatos.');
        }

        (new self())->disparar($cfg, $destinatarios, $assunto, $corpoHtml, $anexos);
    }

    private function disparar(
        array $cfg,
        array $destinatarios,
        string $assunto,
        string $corpoHtml,
        array $anexos
    ): void {
        $seguranca = $cfg['seguranca'] ?? 'tls';
        $host      = $cfg['host'] ?? '';
        $porta     = (int) ($cfg['porta'] ?? 587);

        if ($host === '') {
            throw new RuntimeException('Servidor SMTP (host) não configurado.');
        }

        $transporte = $seguranca === 'ssl' ? "ssl://{$host}" : $host;

        $this->socket = @fsockopen($transporte, $porta, $errno, $errstr, 20);
        if (!$this->socket) {
            throw new RuntimeException("Não foi possível conectar a {$host}:{$porta} ({$errstr}).");
        }
        stream_set_timeout($this->socket, 20);

        $this->ler(220);

        $ehloHost = $this->ehloHost($cfg['remetente_email']);
        $this->comando("EHLO {$ehloHost}", 250);

        if ($seguranca === 'tls') {
            $this->comando('STARTTLS', 220);
            if (!stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('Falha ao iniciar TLS com o servidor SMTP.');
            }
            $this->comando("EHLO {$ehloHost}", 250);
        }

        // Autenticação
        if (($cfg['metodo'] ?? 'smtp') === 'oauth') {
            $this->autenticarOAuth($cfg);
        } else {
            $this->autenticarLogin($cfg);
        }

        // Envelope
        $this->comando('MAIL FROM:<' . $cfg['remetente_email'] . '>', 250);
        foreach ($destinatarios as $d) {
            $this->comando('RCPT TO:<' . $d['email'] . '>', [250, 251]);
        }

        // Conteúdo
        $this->comando('DATA', 354);
        $mensagem = $this->montarMensagem($cfg, $destinatarios, $assunto, $corpoHtml, $anexos);
        $this->escrever($mensagem . self::CRLF . '.' );
        $this->ler(250);

        $this->comando('QUIT', [221, 250]);
        fclose($this->socket);
        $this->socket = null;
    }

    /*
    |----------------------------------------------------------------------
    | AUTENTICAÇÃO
    |----------------------------------------------------------------------
    */
    private function autenticarLogin(array $cfg): void
    {
        if (($cfg['usuario'] ?? '') === '') {
            return; // servidor sem autenticação
        }
        $this->comando('AUTH LOGIN', 334);
        $this->comando(base64_encode($cfg['usuario']), 334);
        $this->comando(base64_encode($cfg['senha'] ?? ''), 235);
    }

    private function autenticarOAuth(array $cfg): void
    {
        $token   = SmtpOAuth::accessToken($cfg);
        $usuario = $cfg['usuario'] !== '' ? $cfg['usuario'] : $cfg['remetente_email'];

        $xoauth = base64_encode(
            'user=' . $usuario . "\x01auth=Bearer " . $token . "\x01\x01"
        );

        $this->escrever('AUTH XOAUTH2 ' . $xoauth);
        $resposta = $this->lerLinhas();
        $codigo   = (int) substr($resposta, 0, 3);

        if ($codigo !== 235) {
            // Em erro, o servidor pode pedir uma linha vazia antes de reportar
            $this->escrever('');
            throw new RuntimeException('Falha na autenticação OAuth (XOAUTH2): ' . trim($resposta));
        }
    }

    /*
    |----------------------------------------------------------------------
    | MONTAGEM DA MENSAGEM (MIME)
    |----------------------------------------------------------------------
    */
    private function montarMensagem(
        array $cfg,
        array $destinatarios,
        string $assunto,
        string $corpoHtml,
        array $anexos
    ): string {
        $remetenteNome = $cfg['remetente_nome'] !== '' ? $cfg['remetente_nome'] : $cfg['remetente_email'];
        $para = implode(', ', array_map(
            static fn($d) => self::formatarContato($d['nome'] ?? '', $d['email']),
            $destinatarios
        ));

        $boundary = 'b_' . bin2hex(random_bytes(12));

        $h = [];
        $h[] = 'From: ' . self::formatarContato($remetenteNome, $cfg['remetente_email']);
        $h[] = 'To: ' . $para;
        $h[] = 'Subject: ' . self::codificarCabecalho($assunto);
        $h[] = 'Date: ' . date('r');
        $h[] = 'MIME-Version: 1.0';
        $h[] = 'Content-Type: multipart/mixed; boundary="' . $boundary . '"';

        $corpo = [];
        $corpo[] = '--' . $boundary;
        $corpo[] = 'Content-Type: text/html; charset=UTF-8';
        $corpo[] = 'Content-Transfer-Encoding: base64';
        $corpo[] = '';
        $corpo[] = chunk_split(base64_encode($corpoHtml));

        foreach ($anexos as $a) {
            $corpo[] = '--' . $boundary;
            $corpo[] = 'Content-Type: ' . ($a['tipo'] ?? 'application/octet-stream')
                     . '; name="' . $a['nome'] . '"';
            $corpo[] = 'Content-Transfer-Encoding: base64';
            $corpo[] = 'Content-Disposition: attachment; filename="' . $a['nome'] . '"';
            $corpo[] = '';
            $corpo[] = chunk_split(base64_encode($a['conteudo']));
        }

        $corpo[] = '--' . $boundary . '--';

        return implode(self::CRLF, $h) . self::CRLF . self::CRLF . implode(self::CRLF, $corpo);
    }

    private static function formatarContato(string $nome, string $email): string
    {
        $nome = trim($nome);
        if ($nome === '') {
            return $email;
        }
        return '=?UTF-8?B?' . base64_encode($nome) . '?= <' . $email . '>';
    }

    private static function codificarCabecalho(string $texto): string
    {
        return '=?UTF-8?B?' . base64_encode($texto) . '?=';
    }

    /*
    |----------------------------------------------------------------------
    | PRIMITIVAS SMTP
    |----------------------------------------------------------------------
    */
    private function ehloHost(string $remetente): string
    {
        $partes = explode('@', $remetente);
        return $partes[1] ?? 'localhost';
    }

    /**
     * @param int|array<int,int> $esperado
     */
    private function comando(string $cmd, int|array $esperado): string
    {
        $this->escrever($cmd);
        $resposta = $this->lerLinhas();
        $this->validar($resposta, (array) $esperado, $cmd);
        return $resposta;
    }

    private function escrever(string $linha): void
    {
        fwrite($this->socket, $linha . self::CRLF);
    }

    private function ler(int $esperado): string
    {
        $resposta = $this->lerLinhas();
        $this->validar($resposta, [$esperado], 'conexão');
        return $resposta;
    }

    private function lerLinhas(): string
    {
        $dados = '';
        while (($linha = fgets($this->socket, 515)) !== false) {
            $dados .= $linha;
            // Resposta multilinha: o 4º caractere é '-' enquanto houver continuação
            if (strlen($linha) < 4 || $linha[3] === ' ') {
                break;
            }
        }
        return $dados;
    }

    private function validar(string $resposta, array $esperado, string $contexto): void
    {
        $codigo = (int) substr($resposta, 0, 3);
        if (!in_array($codigo, $esperado, true)) {
            throw new RuntimeException(
                "Erro SMTP em \"{$contexto}\": " . trim($resposta)
            );
        }
    }
}
