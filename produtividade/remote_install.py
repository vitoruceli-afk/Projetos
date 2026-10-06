#!/usr/bin/env python3
# remote_install.py
#
# Equivalente Linux do remote_install.ps1 (ver esse arquivo para a versao original, via WMI nativo
# do PowerShell/CIM) - faz a MESMA coisa (copia o MSI pro admin$/C$ da maquina de destino via SMB,
# roda o msiexec remotamente via WMI/DCOM Win32_Process.Create, espera terminar fazendo polling,
# le o log e o codigo de saida de volta) só que a partir de um servidor Linux, usando a biblioteca
# impacket em vez do WMI nativo do PowerShell (que so existe no Windows). Chamado por
# install_client.php::executarInstalacaoRemota() quando a aplicacao roda em Linux - ver o branch
# por SO ali (PHP_OS_FAMILY) - mantendo Windows intacto com o remote_install.ps1 de sempre.
#
# Mesmo contrato de entrada/saida do .ps1, de proposito (pra nao precisar mudar o lado PHP além do
# branch de qual script chamar):
#   Entrada (stdin, uma linha JSON):
#     {"computerName": "...", "username": "dominio\\usuario", "password": "...",
#      "msiPath": "/caminho/local/no/servidor/ActivityWatch-Produtividade.msi",
#      "serverIp": "10.1.100.123", "timeoutSegundos": 300}
#   Saida (stdout, uma linha JSON): {"ok": bool, "mensagem": "...", "log": "..."}
#
# Credenciais chegam só pelo stdin (nunca como argumento de linha de comando), mesmo cuidado do
# .ps1 original - evita que a senha apareca listada em "ps aux"/listagem de processos deste
# servidor enquanto o script roda.

import sys
import json
import time
import io
import os

from impacket.smbconnection import SMBConnection
from impacket.dcerpc.v5.dcomrt import DCOMConnection
from impacket.dcerpc.v5.dcom import wmi
from impacket.dcerpc.v5.dtypes import NULL

TAMANHO_MAX_LOG = 20000


def sair(resultado):
    sys.stdout.write(json.dumps(resultado, ensure_ascii=False))
    sys.stdout.flush()
    sys.exit(0)


def decodificar_log(dados_brutos):
    # msiexec /l*v grava o log em UTF-16LE com BOM (padrao do Windows Installer) - tenta isso
    # primeiro, cai pra utf-8/latin-1 se por algum motivo vier diferente, em vez de falhar.
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
    msi_path = entrada.get('msiPath', '')
    server_ip = entrada.get('serverIp', '')
    timeout_segundos = int(entrada.get('timeoutSegundos') or 300)
    if timeout_segundos <= 0:
        timeout_segundos = 300

    if '\\' in username_full:
        domain, user = username_full.split('\\', 1)
    else:
        domain, user = '', username_full

    if not os.path.isfile(msi_path):
        resultado['mensagem'] = f'Arquivo MSI nao encontrado neste servidor: {msi_path}'
        sair(resultado)
        return

    remote_msi_local = r'C:\Windows\Temp\ActivityWatch-Produtividade.msi'
    remote_log_local = r'C:\Windows\Temp\aw-install.log'
    remote_exitcode_local = r'C:\Windows\Temp\aw-install.exitcode'
    remote_bat_local = r'C:\Windows\Temp\aw-install-cmd.bat'

    smb = None
    dcom = None
    try:
        # ---- SMB: copia o MSI e o .bat pro admin$ (C$) da maquina de destino ----
        smb = SMBConnection(computer_name, computer_name)
        smb.login(user, password, domain)

        with open(msi_path, 'rb') as f:
            conteudo_msi = f.read()
        smb.putFile('C$', 'Windows\\Temp\\ActivityWatch-Produtividade.msi', io.BytesIO(conteudo_msi).read)

        for nome_remoto in ('Windows\\Temp\\aw-install.log', 'Windows\\Temp\\aw-install.exitcode'):
            try:
                smb.deleteFile('C$', nome_remoto)
            except Exception:
                pass

        # Mesmo motivo do .ps1 original: um .bat evita ter que escapar aspas aninhadas numa linha de
        # comando so (cmd.exe nao entende \" como aspas escapadas - convencao do parser argv do
        # runtime C/CreateProcess, nao do cmd.exe).
        bat_conteudo = (
            '@echo off\r\n'
            f'msiexec.exe /i "{remote_msi_local}" SERVERIP="{server_ip}" /quiet /norestart /l*v "{remote_log_local}"\r\n'
            f'echo %errorlevel% > "{remote_exitcode_local}"\r\n'
        )
        smb.putFile('C$', 'Windows\\Temp\\aw-install-cmd.bat', io.BytesIO(bat_conteudo.encode('ascii')).read)

        # ---- WMI/DCOM: Win32_Process.Create pra rodar o .bat remotamente ----
        dcom = DCOMConnection(computer_name, user, password, domain, '', '', oxidResolver=True)
        iInterface = dcom.CoCreateInstanceEx(wmi.CLSID_WbemLevel1Login, wmi.IID_IWbemLevel1Login)
        iWbemLevel1Login = wmi.IWbemLevel1Login(iInterface)
        iWbemServices = iWbemLevel1Login.NTLMLogin('//./root/cimv2', NULL, NULL)
        iWbemLevel1Login.RemRelease()

        win32Process, _ = iWbemServices.GetObject('Win32_Process')
        comando_remoto = f'cmd.exe /c {remote_bat_local}'
        # CurrentDirectory precisa ser uma string de verdade (nao NULL/None) e ProcessStartupInformation
        # precisa ser None (nao o NULL do impacket.dtypes) - as duas variantes "erradas" batem num bug
        # de serializacao desta versao do impacket (testado e confirmado numa maquina real antes de
        # integrar este script no fluxo de producao).
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
                raise Exception(f'Tempo limite de {timeout_segundos} segundos excedido esperando a instalacao terminar em {computer_name}.')

        time.sleep(2)  # da um instante para o SO liberar o handle do arquivo de log/exitcode

        codigo_saida = None
        try:
            buf = io.BytesIO()
            smb.getFile('C$', 'Windows\\Temp\\aw-install.exitcode', buf.write)
            codigo_saida = buf.getvalue().decode(errors='replace').strip()
        except Exception:
            pass

        try:
            buf_log = io.BytesIO()
            smb.getFile('C$', 'Windows\\Temp\\aw-install.log', buf_log.write)
            log_texto = decodificar_log(buf_log.getvalue())
            if len(log_texto) > TAMANHO_MAX_LOG:
                log_texto = log_texto[-TAMANHO_MAX_LOG:]
            resultado['log'] = log_texto
        except Exception:
            pass

        if codigo_saida in ('0', '3010'):
            resultado['ok'] = True
            resultado['mensagem'] = ('Instalacao concluida (reinicio pendente na maquina de destino).'
                                      if codigo_saida == '3010' else 'Instalacao concluida.')
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
