<?php

namespace Config;

/**
 * Configuração de acesso à API REST do GLPI.
 *
 * Preencha API_BASE_URL, APP_TOKEN e USER_TOKEN com os valores gerados na
 * instalação do GLPI (Configurar > Geral > API para o App-Token; e a aba
 * "API" do usuário de serviço em Administração > Usuários para o User-Token).
 *
 * ENTITY_NAME e TECHNICIAN_PROFILE_NAME precisam bater EXATAMENTE (maiúsculas/
 * hífen inclusive) com os nomes cadastrados no GLPI, pois a resolução de id
 * é feita por comparação exata de texto, não por id numérico fixo.
 */
class GLPIConfig
{
    const API_BASE_URL = 'https://sac.faesa.br/glpi/apirest.php'; // TODO: preencher localmente
    const APP_TOKEN = 'QZZaJIu18slDpvTTurOsQ4U3ZS3VRXctMtMLNcu6';  // TODO: preencher localmente
    const USER_TOKEN = 'qBT0WpDHDy9zFhBQHpVt1mKiUbCoqOVlbnYhXJAT'; // TODO: preencher localmente

    const ENTITY_NAME = 'NTI - Campus I';
    const DEVICE_ENTITY_NAME = 'Faesa'; // entidade raiz usada para trazer os dispositivos inventariados
    const TECHNICIAN_PROFILE_NAME = 'Super-Admin';
    const COMPUTER_ITEMTYPE = 'Computer';

    const HTTP_TIMEOUT_SECONDS = 15;
    const VERIFY_SSL = false; // TODO: voltar para true assim que a cadeia de certificado de sac.faesa.br for corrigida (falta o intermediário GlobeSSL DV CA)

    public static function isConfigured()
    {
        return self::API_BASE_URL !== ''
            && self::APP_TOKEN !== ''
            && self::USER_TOKEN !== ''
            && strpos(self::API_BASE_URL, 'SEU-GLPI') === false
            && self::APP_TOKEN !== 'REPLACE_ME_APP_TOKEN'
            && self::USER_TOKEN !== 'REPLACE_ME_USER_TOKEN';
    }
}
