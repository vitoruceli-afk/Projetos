#!/usr/bin/env python3
# remote_uninstall.py
#
# Equivalente Linux do remote_uninstall.ps1 (ver esse arquivo para a versao original e para o
# porque de procurar o ProductCode pelo nome em vez de supor um GUID fixo - cada build deste MSI
# gera um ProductCode novo, Id="*" no Product.wxs). Mesma mecanica de remote_install.py (SMB
# admin$ + WMI/DCOM via impacket), chamado por install_client.php::executarInstalacaoRemota()
# quando a aplicacao roda em Linux.
#
#   Entrada (stdin, uma linha JSON):
#     {"computerName": "...", "username": "dominio\\usuario", "password": "...", "timeoutSegundos": 300}
#   Saida (stdout, uma linha JSON): {"ok": bool, "mensagem": "...", "log": "..."}

import sys
import json
import time
import io

from impacket.smbconnection import SMBConnection
from impacket.dcerpc.v5.dcomrt import DCOMConnection
from impacket.dcerpc.v5.dcom import wmi
from impacket.dcerpc.v5.dtypes import NULL

NOME_PRODUTO = 'ActivityWatch (Produtividade FAESA)'
HKEY_LOCAL_MACHINE = 2147483650
TAMANHO_MAX_LOG = 20000


def sair(resultado):
    sys.stdout.write(json.dumps(resultado, ensure_ascii=False))
    sys.stdout.flush()
    sys.exit(0)


def decodificar_log(dados_brutos):
    for codificacao in ('utf-16', 'utf-8', 'latin-1'):
        try:
            return dados_brutos.decode(codificacao)
        except (UnicodeDecodeError, UnicodeError):
            continue
    return dados_brutos.decode('latin-1', errors='replace')


def processo_ainda_existe(iWbemServices, pid):
    try:
        resultado = iWbemServices.ExecQuery(f'SELECT ProcessId FROM Win32_Process WHERE ProcessId={pid}')
        resultado.Next(5000, 1)
        return True
    except Exception:
        return False


def achar_product_code(iWbemServices, nome_produto):
    # Mesmas duas vistas do registro que o remote_uninstall.ps1 confere (32 e 64 bits) - o produto
    # pode aparecer em qualquer uma dependendo de como o MSI foi empacotado.
    stdRegProv, _ = iWbemServices.GetObject('StdRegProv')
    chaves_base = (
        'SOFTWARE\\Microsoft\\Windows\\CurrentVersion\\Uninstall',
        'SOFTWARE\\WOW6432Node\\Microsoft\\Windows\\CurrentVersion\\Uninstall',
    )
    for chave_base in chaves_base:
        enum = stdRegProv.EnumKey(HKEY_LOCAL_MACHINE, chave_base)
        if enum.ReturnValue != 0 or not enum.sNames:
            continue
        for sub in enum.sNames:
            val = stdRegProv.GetStringValue(HKEY_LOCAL_MACHINE, f'{chave_base}\\{sub}', 'DisplayName')
            if val.ReturnValue == 0 and val.sValue == nome_produto:
                return sub
    return None


def main():
    resultado = {'ok': False, 'mensagem': '', 'log': ''}
    try:
        entrada = json.loads(sys.stdin.read())
    except Exception as e:
        resultado['mensagem'] = f'Falha ao ler parametros de entrada (JSON invalido): {e}'
        sair(resultado)
        return

    computer_name = entrada.get('computerName', '')
    username_full = entrada.get('username', '')
    password = entrada.get('password', '')
    timeout_segundos = int(entrada.get('timeoutSegundos') or 300)
    if timeout_segundos <= 0:
        timeout_segundos = 300

    if '\\' in username_full:
        domain, user = username_full.split('\\', 1)
    else:
        domain, user = '', username_full

    remote_log_local = r'C:\Windows\Temp\aw-uninstall.log'
    remote_exitcode_local = r'C:\Windows\Temp\aw-uninstall.exitcode'
    remote_bat_local = r'C:\Windows\Temp\aw-uninstall-cmd.bat'

    smb = None
    dcom = None
    try:
        dcom = DCOMConnection(computer_name, user, password, domain, '', '', oxidResolver=True)
        iInterface = dcom.CoCreateInstanceEx(wmi.CLSID_WbemLevel1Login, wmi.IID_IWbemLevel1Login)
        iWbemLevel1Login = wmi.IWbemLevel1Login(iInterface)
        iWbemServices = iWbemLevel1Login.NTLMLogin('//./root/cimv2', NULL, NULL)
        iWbemLevel1Login.RemRelease()

        product_code = achar_product_code(iWbemServices, NOME_PRODUTO)
        if not product_code:
            raise Exception(f"'{NOME_PRODUTO}' nao foi encontrado no registro de Programas e Recursos desta maquina (ja pode ter sido removido).")

        # Autentica no admin$ so agora, ja que a busca do ProductCode nao precisa dele (mesma ordem
        # do .ps1 original - evita montar a sessao SMB a toa quando o produto nem esta instalado).
        smb = SMBConnection(computer_name, computer_name)
        smb.login(user, password, domain)

        for nome_remoto in ('Windows\\Temp\\aw-uninstall.log', 'Windows\\Temp\\aw-uninstall.exitcode'):
            try:
                smb.deleteFile('C$', nome_remoto)
            except Exception:
                pass

        bat_conteudo = (
            '@echo off\r\n'
            f'msiexec.exe /x {product_code} /quiet /norestart /l*v "{remote_log_local}"\r\n'
            f'echo %errorlevel% > "{remote_exitcode_local}"\r\n'
        )
        smb.putFile('C$', 'Windows\\Temp\\aw-uninstall-cmd.bat', io.BytesIO(bat_conteudo.encode('ascii')).read)

        win32Process, _ = iWbemServices.GetObject('Win32_Process')
        comando_remoto = f'cmd.exe /c {remote_bat_local}'
        criado = win32Process.Create(comando_remoto, 'C:\\Windows\\Temp', None)
        if criado.ReturnValue != 0:
            raise Exception(f'Nao foi possivel criar o processo remoto (Win32_Process.Create retornou codigo {criado.ReturnValue}).')

        remote_pid = criado.ProcessId
        decorrido = 0
        intervalo = 5
        while processo_ainda_existe(iWbemServices, remote_pid):
            time.sleep(intervalo)
            decorrido += intervalo
            if decorrido >= timeout_segundos:
                raise Exception(f'Tempo limite de {timeout_segundos} segundos excedido esperando a desinstalacao terminar em {computer_name}.')

        time.sleep(2)

        codigo_saida = None
        try:
            buf = io.BytesIO()
            smb.getFile('C$', 'Windows\\Temp\\aw-uninstall.exitcode', buf.write)
            codigo_saida = buf.getvalue().decode(errors='replace').strip()
        except Exception:
            pass

        try:
            buf_log = io.BytesIO()
            smb.getFile('C$', 'Windows\\Temp\\aw-uninstall.log', buf_log.write)
            log_texto = decodificar_log(buf_log.getvalue())
            if len(log_texto) > TAMANHO_MAX_LOG:
                log_texto = log_texto[-TAMANHO_MAX_LOG:]
            resultado['log'] = log_texto
        except Exception:
            pass

        if codigo_saida in ('0', '3010'):
            resultado['ok'] = True
            resultado['mensagem'] = ('Desinstalacao concluida (reinicio pendente na maquina de destino).'
                                      if codigo_saida == '3010' else 'Desinstalacao concluida.')
        else:
            resultado['mensagem'] = f'msiexec terminou com codigo de saida {codigo_saida} (ver log).'

    except Exception as e:
        resultado['mensagem'] = str(e)
    finally:
        if dcom:
            try:
                dcom.disconnect()
            except Exception:
                pass
        if smb:
            try:
                smb.close()
            except Exception:
                pass

    sair(resultado)


if __name__ == '__main__':
    main()
