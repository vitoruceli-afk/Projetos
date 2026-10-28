<?php

declare(strict_types=1);

namespace App\Models;

/*
|--------------------------------------------------------------------------
| ConfigSmtp
|--------------------------------------------------------------------------
|
| Configuração (linha única) do servidor de e-mail usado para enviar os
| rateios gerados. Suporta dois métodos:
|   - 'smtp'  : servidor SMTP com usuário/senha (AUTH LOGIN)
|   - 'oauth' : autenticação moderna XOAUTH2 (Microsoft/Google/custom)
|
*/
final class ConfigSmtp extends BaseModel
{
    protected static string $tabela = 'config_smtp';

    public static function obter(): array
    {
        $row = self::pdo()->query('SELECT * FROM config_smtp WHERE id = 1 LIMIT 1')->fetch();

        return $row ?: [
            'metodo'              => 'smtp',
            'host'                => '',
            'porta'               => 587,
            'seguranca'           => 'tls',
            'usuario'             => '',
            'senha'               => '',
            'remetente_nome'      => '',
            'remetente_email'     => '',
            'oauth_provedor'      => 'microsoft',
            'oauth_tenant'        => '',
            'oauth_client_id'     => '',
            'oauth_client_secret' => '',
            'oauth_refresh_token' => '',
            'oauth_token_url'     => '',
            'oauth_scope'         => '',
        ];
    }

    public static function configurado(): bool
    {
        $c = self::obter();
        if (($c['remetente_email'] ?? '') === '') {
            return false;
        }
        if (($c['metodo'] ?? 'smtp') === 'smtp') {
            return ($c['host'] ?? '') !== '';
        }
        return ($c['oauth_client_id'] ?? '') !== '' && ($c['oauth_refresh_token'] ?? '') !== '';
    }

    public static function salvar(array $d): void
    {
        $stmt = self::pdo()->prepare('
            INSERT INTO config_smtp
                (id, metodo, host, porta, seguranca, usuario, senha,
                 remetente_nome, remetente_email,
                 oauth_provedor, oauth_tenant, oauth_client_id, oauth_client_secret,
                 oauth_refresh_token, oauth_token_url, oauth_scope)
            VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                metodo = VALUES(metodo), host = VALUES(host), porta = VALUES(porta),
                seguranca = VALUES(seguranca), usuario = VALUES(usuario), senha = VALUES(senha),
                remetente_nome = VALUES(remetente_nome), remetente_email = VALUES(remetente_email),
                oauth_provedor = VALUES(oauth_provedor), oauth_tenant = VALUES(oauth_tenant),
                oauth_client_id = VALUES(oauth_client_id), oauth_client_secret = VALUES(oauth_client_secret),
                oauth_refresh_token = VALUES(oauth_refresh_token), oauth_token_url = VALUES(oauth_token_url),
                oauth_scope = VALUES(oauth_scope)
        ');

        $stmt->execute([
            in_array($d['metodo'] ?? 'smtp', ['smtp', 'oauth'], true) ? $d['metodo'] : 'smtp',
            $d['host'] ?? '',
            (int) ($d['porta'] ?? 587),
            in_array($d['seguranca'] ?? 'tls', ['none', 'tls', 'ssl'], true) ? $d['seguranca'] : 'tls',
            $d['usuario'] ?? '',
            $d['senha'] ?? '',
            $d['remetente_nome'] ?? '',
            $d['remetente_email'] ?? '',
            in_array($d['oauth_provedor'] ?? 'microsoft', ['microsoft', 'google', 'custom'], true) ? $d['oauth_provedor'] : 'microsoft',
            $d['oauth_tenant'] ?? '',
            $d['oauth_client_id'] ?? '',
            $d['oauth_client_secret'] ?? '',
            $d['oauth_refresh_token'] ?? '',
            $d['oauth_token_url'] ?? '',
            $d['oauth_scope'] ?? '',
        ]);
    }
}
