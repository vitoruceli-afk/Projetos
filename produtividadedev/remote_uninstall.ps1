# remote_uninstall.ps1
# Remove o pacote "ActivityWatch (Produtividade FAESA)" de uma maquina da rede via WMI/DCOM - mesma
# mecanica do remote_install.ps1 (admin$ + CIM sobre DCOM, sem depender de WinRM). A diferenca e que
# aqui primeiro precisamos DESCOBRIR o ProductCode instalado na maquina de destino (cada build deste
# MSI gera um ProductCode novo - Id="*" no Product.wxs -, entao nao da pra supor qual e; procuramos
# pelo nome do produto no registro de Programas e Recursos).
#
# Desinstalar o NOSSO pacote (nao o ActivityWatch "puro") e suficiente: a sequencia de desinstalacao
# dele ja dispara UninstallActivityWatch (roda o unins000.exe do Inno) e RemoverTarefaAgente (tira a
# Tarefa Agendada do agente proprio) - ver Product.wxs.
#
# Entrada esperada (stdin, uma linha JSON):
#   { "computerName": "...", "username": "...", "password": "...", "timeoutSegundos": 300 }
# Saida (stdout, uma linha JSON): { "ok": bool, "mensagem": "...", "log": "..." }

$ErrorActionPreference = 'Stop'
$NOME_PRODUTO = 'ActivityWatch (Produtividade FAESA)'
$resultado = @{ ok = $false; mensagem = ''; log = '' }

function Sair($obj) {
    $json = $obj | ConvertTo-Json -Compress -Depth 4
    $bytes = [System.Text.Encoding]::UTF8.GetBytes($json)
    $stdout = [Console]::OpenStandardOutput()
    $stdout.Write($bytes, 0, $bytes.Length)
    $stdout.Flush()
    exit 0
}

try {
    $raw = [Console]::In.ReadToEnd()
    $entrada = $raw | ConvertFrom-Json
} catch {
    $resultado.mensagem = "Falha ao ler parametros de entrada (JSON invalido): $($_.Exception.Message)"
    Sair $resultado
}

$computerName = $entrada.computerName
$username = $entrada.username
$password = $entrada.password
$timeoutSegundos = [int]$entrada.timeoutSegundos
if ($timeoutSegundos -le 0) { $timeoutSegundos = 300 }

$adminShare = "\\$computerName\C$"
$remoteLogUnc = "$adminShare\Windows\Temp\aw-uninstall.log"
$remoteExitCodeUnc = "$adminShare\Windows\Temp\aw-uninstall.exitcode"
$remoteLogLocal = "C:\Windows\Temp\aw-uninstall.log"
$remoteExitCodeLocal = "C:\Windows\Temp\aw-uninstall.exitcode"

$mapeado = $false
$session = $null

# Acha o ProductCode pelo nome, em ambas as vistas do registro (32/64 bits) - ver comentario no
# topo do arquivo sobre por que nao da pra supor um GUID fixo.
function AcharProductCode($session, $nomeProduto) {
    $chavesBase = @(
        'SOFTWARE\Microsoft\Windows\CurrentVersion\Uninstall',
        'SOFTWARE\WOW6432Node\Microsoft\Windows\CurrentVersion\Uninstall'
    )
    foreach ($chaveBase in $chavesBase) {
        $enum = Invoke-CimMethod -CimSession $session -ClassName StdRegProv -MethodName EnumKey -Arguments @{ hDefKey = [uint32]2147483650; sSubKeyName = $chaveBase }
        if ($enum.ReturnValue -ne 0 -or -not $enum.sNames) { continue }
        foreach ($sub in $enum.sNames) {
            $val = Invoke-CimMethod -CimSession $session -ClassName StdRegProv -MethodName GetStringValue -Arguments @{ hDefKey = [uint32]2147483650; sSubKeyName = "$chaveBase\$sub"; sValueName = 'DisplayName' }
            if ($val.ReturnValue -eq 0 -and $val.sValue -eq $nomeProduto) {
                return $sub
            }
        }
    }
    return $null
}

try {
    # Ver comentario equivalente em remote_install.ps1: evita ConvertTo-SecureString (modulo
    # Microsoft.PowerShell.Security, cujo autoload falha nesta maquina por causa do PowerShell 7
    # tambem instalado) montando a SecureString direto via .NET.
    $securePwd = New-Object System.Security.SecureString
    foreach ($c in $password.ToCharArray()) { $securePwd.AppendChar($c) }
    $securePwd.MakeReadOnly()
    $cred = New-Object System.Management.Automation.PSCredential($username, $securePwd)
    $cimOption = New-CimSessionOption -Protocol Dcom
    $session = New-CimSession -ComputerName $computerName -Credential $cred -SessionOption $cimOption

    $productCode = AcharProductCode -session $session -nomeProduto $NOME_PRODUTO
    if (-not $productCode) {
        throw "'$NOME_PRODUTO' nao foi encontrado no registro de Programas e Recursos desta maquina (ja pode ter sido removido)."
    }

    # Autentica no admin$ so agora, ja que a busca do ProductCode nao precisa dele (evita montar a
    # sessao SMB a toa quando o produto nem esta instalado).
    cmd.exe /c "net use `"$adminShare`" /user:$username $password 2>&1" | Out-Null
    if ($LASTEXITCODE -ne 0) {
        throw "Falha ao autenticar em $adminShare com o usuario '$username' (net use retornou codigo $LASTEXITCODE)."
    }
    $mapeado = $true

    Remove-Item -LiteralPath $remoteLogUnc -Force -ErrorAction SilentlyContinue
    Remove-Item -LiteralPath $remoteExitCodeUnc -Force -ErrorAction SilentlyContinue

    # Mesma tecnica do .bat do remote_install.ps1: cmd.exe nao entende \" como aspas escapadas,
    # entao uma linha de comando so com aspas aninhadas quebra silenciosamente.
    $remoteBatUnc = "$adminShare\Windows\Temp\aw-uninstall-cmd.bat"
    $remoteBatLocal = "C:\Windows\Temp\aw-uninstall-cmd.bat"
    $batContent = @"
@echo off
msiexec.exe /x $productCode /quiet /norestart /l*v "$remoteLogLocal"
echo %errorlevel% > "$remoteExitCodeLocal"
"@
    $localBatPath = Join-Path $env:TEMP "aw-uninstall-cmd-$([guid]::NewGuid().ToString('N')).bat"
    [System.IO.File]::WriteAllText($localBatPath, $batContent, [System.Text.Encoding]::ASCII)
    try {
        Copy-Item -LiteralPath $localBatPath -Destination $remoteBatUnc -Force
    } finally {
        Remove-Item -LiteralPath $localBatPath -Force -ErrorAction SilentlyContinue
    }

    $comandoRemoto = "cmd.exe /c $remoteBatLocal"
    $criado = Invoke-CimMethod -CimSession $session -ClassName Win32_Process -MethodName Create -Arguments @{ CommandLine = $comandoRemoto }
    if ($criado.ReturnValue -ne 0) {
        throw "Nao foi possivel criar o processo remoto (Win32_Process.Create retornou codigo $($criado.ReturnValue))."
    }

    $remotePid = $criado.ProcessId
    $decorrido = 0
    $intervalo = 5
    while ($true) {
        Start-Sleep -Seconds $intervalo
        $decorrido += $intervalo
        $rodando = Get-CimInstance -CimSession $session -ClassName Win32_Process -Filter "ProcessId=$remotePid" -ErrorAction SilentlyContinue
        if (-not $rodando) { break }
        if ($decorrido -ge $timeoutSegundos) {
            throw "Tempo limite de $timeoutSegundos segundos excedido esperando a desinstalacao terminar em $computerName."
        }
    }

    Start-Sleep -Seconds 2

    $codigoSaida = $null
    if (Test-Path -LiteralPath $remoteExitCodeUnc) {
        $codigoSaida = (Get-Content -LiteralPath $remoteExitCodeUnc -Raw -ErrorAction SilentlyContinue).Trim()
    }
    if (Test-Path -LiteralPath $remoteLogUnc) {
        $resultado.log = (Get-Content -LiteralPath $remoteLogUnc -Raw -ErrorAction SilentlyContinue)
        if ($resultado.log -and $resultado.log.Length -gt 20000) {
            $resultado.log = $resultado.log.Substring($resultado.log.Length - 20000)
        }
    }

    if ($codigoSaida -eq '0' -or $codigoSaida -eq '3010') {
        $resultado.ok = $true
        $resultado.mensagem = if ($codigoSaida -eq '3010') { 'Desinstalacao concluida (reinicio pendente na maquina de destino).' } else { 'Desinstalacao concluida.' }
    } else {
        $resultado.mensagem = "msiexec terminou com codigo de saida $codigoSaida (ver log)."
    }
}
catch {
    $resultado.mensagem = $_.Exception.Message
}
finally {
    if ($session) { Remove-CimSession -CimSession $session -ErrorAction SilentlyContinue }
    if ($mapeado) {
        try { cmd.exe /c "net use `"$adminShare`" /delete /y 2>&1" | Out-Null } catch { }
    }
}

Sair $resultado
