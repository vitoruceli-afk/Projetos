<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| CONFIGURAÇÃO GERAL DA APLICAÇÃO
|--------------------------------------------------------------------------
|
| Edite este arquivo para apontar a aplicação para o seu banco de dados
| e (opcionalmente) configurar a integração com LDAP / Active Directory.
|
*/

return [

    /*
    |----------------------------------------------------------------------
    | APLICAÇÃO
    |----------------------------------------------------------------------
    */
    'app' => [
        'nome'      => 'Sistema de Rateio',
        // URL base da aplicação (sem barra final). Ex.: "" para raiz do domínio
        // ou "/rateio" caso esteja em uma subpasta.
        'base_url'  => '/rateio',
        'timezone'  => 'America/Sao_Paulo',
    ],

    /*
    |----------------------------------------------------------------------
    | BANCO DE DADOS (MySQL / MariaDB)
    |----------------------------------------------------------------------
    */
    'db' => [
        'host'    => 'localhost',
        'porta'   => 3306,
        'banco'   => 'rateio',
        'usuario' => 'root',
        'senha'   => '',
        'charset' => 'utf8mb4',
    ],

    /*
    |----------------------------------------------------------------------
    | LDAP / ACTIVE DIRECTORY
    |----------------------------------------------------------------------
    |
    | habilitado .......: liga/desliga a autenticação via LDAP.
    | host .............: ldap://servidor ou ldaps://servidor
    | porta ............: 389 (LDAP) ou 636 (LDAPS)
    | base_dn ..........: base de busca dos usuários.
    | dominio ..........: sufixo UPN usado para montar o login (ex.: empresa.local).
    | grupo_admin ......: DN ou nome do grupo de segurança cujos membros
    |                     receberão o perfil "admin".
    | grupo_usuario ....: DN ou nome do grupo de segurança cujos membros
    |                     receberão o perfil "usuario" (somente leitura).
    | filtro_login .....: atributo usado para localizar o usuário (sAMAccountName).
    |
    | A integração também pode ser editada pela tela de Usuários (admin),
    | que grava estes valores na tabela "config_ldap".
    |
    */
    'ldap' => [
        'habilitado'   => false,
        'host'         => 'ldap://192.168.0.1',
        'porta'        => 389,
        'base_dn'      => 'DC=empresa,DC=local',
        'dominio'      => 'empresa.local',
        'grupo_admin'  => 'CN=Rateio-Admins,OU=Grupos,DC=empresa,DC=local',
        'grupo_usuario'=> 'CN=Rateio-Usuarios,OU=Grupos,DC=empresa,DC=local',
        'filtro_login' => 'sAMAccountName',
    ],
];
