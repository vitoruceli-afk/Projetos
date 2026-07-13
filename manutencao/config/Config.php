<?php

namespace Config;

class Config
{
    const APP_NAME = 'Sistema de Manutenção de Máquinas';
    const APP_VERSION = '1.0.0';
    const BASE_URL = 'http://localhost/manutencao/public';
    const TIMEZONE = 'America/Sao_Paulo';
    const LANGUAGE = 'pt_BR';

    const SESSION_TIMEOUT = 3600;
    const REMEMBER_ME_DURATION = 604800;

    const UPLOAD_MAX_SIZE = 10485760;
    const UPLOAD_PATH = '/uploads/';
    const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'pdf', 'docx', 'xlsx', 'txt'];

    const SMTP_HOST = 'smtp.gmail.com';
    const SMTP_PORT = 587;
    const SMTP_USER = '';
    const SMTP_PASS = '';
    const SMTP_FROM = '';
    const SMTP_FROM_NAME = 'Sistema de Manutenção';

    const ITEMS_PER_PAGE = 15;

    const LOG_PATH = '/logs/';
    const LOG_ENABLED = true;

    public static function init()
   {
       date_default_timezone_set(self::TIMEZONE);
       if (session_status() === PHP_SESSION_NONE) {
           session_start();
       }
   }

    public static function getBaseUrl()
    {
        return self::BASE_URL;
    }

    public static function setTimezone($timezone)
    {
        date_default_timezone_set($timezone);
    }
}