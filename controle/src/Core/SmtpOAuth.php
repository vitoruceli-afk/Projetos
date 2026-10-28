<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/*
|--------------------------------------------------------------------------
| SmtpOAuth
|--------------------------------------------------------------------------
|
| Obtém um access token (XOAUTH2) a partir de um refresh_token, para os
| provedores Microsoft 365, Google ou um endpoint personalizado.
|
| Pré-requisitos do servidor: extensão cURL e acesso de saída à internet.
|
*/
final class SmtpOAuth
{
    public static function accessToken(array $cfg): string
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('A extensão cURL do PHP é necessária para o método OAuth.');
        }

        $provedor = $cfg['oauth_provedor'] ?? 'microsoft';
        $url      = self::tokenUrl($cfg, $provedor);
        $escopo   = self::escopo($cfg, $provedor);

        $campos = [
            'client_id'     => $cfg['oauth_client_id'] ?? '',
            'client_secret' => $cfg['oauth_client_secret'] ?? '',
            'grant_type'    => 'refresh_token',
            'refresh_token' => $cfg['oauth_refresh_token'] ?? '',
        ];
        if ($escopo !== '') {
            $campos['scope'] = $escopo;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($campos),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_TIMEOUT        => 20,
        ]);

        $resposta = curl_exec($ch);
        $http     = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $erroCurl = curl_error($ch);
        curl_close($ch);

        if ($resposta === false) {
            throw new RuntimeException('Falha ao contatar o provedor OAuth: ' . $erroCurl);
        }

        $dados = json_decode((string) $resposta, true);

        if ($http !== 200 || !isset($dados['access_token'])) {
            $desc = $dados['error_description'] ?? $dados['error'] ?? $resposta;
            throw new RuntimeException('Provedor OAuth recusou o token: ' . trim((string) $desc));
        }

        return (string) $dados['access_token'];
    }

    private static function tokenUrl(array $cfg, string $provedor): string
    {
        return match ($provedor) {
            'microsoft' => 'https://login.microsoftonline.com/'
                . (($cfg['oauth_tenant'] ?? '') !== '' ? $cfg['oauth_tenant'] : 'common')
                . '/oauth2/v2.0/token',
            'google'    => 'https://oauth2.googleapis.com/token',
            default     => $cfg['oauth_token_url'] ?? '',
        };
    }

    private static function escopo(array $cfg, string $provedor): string
    {
        if (($cfg['oauth_scope'] ?? '') !== '') {
            return $cfg['oauth_scope'];
        }
        return match ($provedor) {
            'microsoft' => 'https://outlook.office365.com/SMTP.Send offline_access',
            'google'    => 'https://mail.google.com/',
            default     => '',
        };
    }
}
