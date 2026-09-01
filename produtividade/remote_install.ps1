# remote_install.ps1
# Instala o MSI (ActivityWatch-Produtividade.msi) numa maquina da rede via WMI/DCOM - nao precisa
# de WinRM habilitado na maquina de destino (que nao esta habilitado em nenhuma maquina do
# dominio hoje). Chamado pelo remote_install_runner.php via proc_open, que escreve os parametros
# como JSON no stdin (nunca como argumento de linha de comando - evita que a senha apareca listada
# em tasklist/Gerenciador de Tarefas deste servidor enquanto o script roda) e le o resultado (JSON)
# no stdout.
#
# Entrada esperada (stdin, uma linha JSON):
#   { "computerName": "...", "username": "...", "password": "...", "msiPath": "C:\\...\\ActivityWatch-Produtividade.msi",
#     "serverIp": "10.10.140.17", "timeoutSegundos": 300 }
#
# Saida (stdout, uma linha JSON): { "ok": bool, "mensagem": "...", "log": "..." }

$ErrorActionPreference = 'Stop'

# NAO mexer em [Console]::OutputEncoding aqui: o PowerShell tambem usa essa configuracao para
# decodificar a saida de processos nativos (net.exe, cmd.exe), que escrevem na codepage OEM do
# console (850/860 em Windows pt-BR) - forcar UTF-8 nela faz o PowerShell ler errado o texto que
# o proprio net.exe ja produziu corretamente, trocando acentos por caracteres invalidos. A saida
# final deste script (funcao Sair, abaixo) grava os bytes UTF-8 direto no stream, sem depender
# dessa configuracao - e o unico ponto que precisa estar certo.
$resultado = @{ ok = $false; mensagem = ''; log = '' }

function Sair($obj) {
    # Escreve os bytes UTF-8 direto no stream de stdout, sem passar pelo escritor de texto do
    # PowerShell - [Console]::OutputEncoding sozinho nao bastou (o parsing JSON no PHP passou a
    # funcionar, mas caracteres acentuados dentro das strings continuavam corrompidos).
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
$msiPath = $entrada.msiPath
$serverIp = $entrada.serverIp
$timeoutSegundos = [int]$entrada.timeoutSegundos
if ($timeoutSegundos -le 0) { $timeoutSegundos = 300 }

if (-not (Test-Path -LiteralPath $msiPath)) {
    $resultado.mensagem = "Arquivo MSI nao encontrado neste servidor: $msiPath"
    Sair $resultado
}

$adminShare = "\\$computerName\C$"
$remoteMsiUnc = "$adminShare\Windows\Temp\ActivityWatch-Produtividade.msi"
$remoteLogUnc = "$adminShare\Windows\Temp\aw-install.log"
$remoteExitCodeUnc = "$adminShare\Windows\Temp\aw-install.exitcode"
$remoteMsiLocal = "C:\Windows\Temp\ActivityWatch-Produtividade.msi"
$remoteLogLocal = "C:\Windows\Temp\aw-install.log"
$remoteExitCodeLocal = "C:\Windows\Temp\aw-install.exitcode"

$mapeado = $false
$session = $null

try {
    # "net use" roda via cmd.exe /c para o merge do stderr (2>&1) virar texto simples: feito
    # direto no pipeline do PowerShell, com $ErrorActionPreference = 'Stop' em vigor, qualquer
    # linha de stderr vira um erro terminante ALI MESMO - pulando o "if ($LASTEXITCODE...)" abaixo
    # e indo direto pro catch com so a mensagem crua do Windows, sem o contexto de qual host/usuario
    # falhou.
    #
    # De propósito NAO repassamos o texto de erro do proprio net.exe na mensagem final: ele sai
    # pela saida padrao do console na codepage OEM local (850/860 em Windows pt-BR), e o PowerShell
    # 5.1 decodifica esse texto de forma inconsistente dependendo de como o stdout deste script foi
    # conectado (pipe do proc_open do PHP x arquivo/console) - em alguns casos os acentos viram
    # caractere de substituicao (U+FFFD) antes mesmo de chegarmos a reescrever em UTF-8. O codigo de
    # saida numerico nao depende de nenhuma codepage - nao e igual ao numero de erro do Windows que
    # aparece na mensagem do proprio net.exe (ex: codigo de saida 2 para "Erro de sistema 86"), mas
    # já diferencia sucesso de falha de forma confiável.
    cmd.exe /c "net use `"$adminShare`" /user:$username $password 2>&1" | Out-Null
    if ($LASTEXITCODE -ne 0) {
        throw "Falha ao autenticar em $adminShare com o usuario '$username' (net use retornou codigo $LASTEXITCODE)."
    }
    $mapeado = $true

    Copy-Item -LiteralPath $msiPath -Destination $remoteMsiUnc -Force
    Remove-Item -LiteralPath $remoteLogUnc -Force -ErrorAction SilentlyContinue
    Remove-Item -LiteralPath $remoteExitCodeUnc -Force -ErrorAction SilentlyContinue

    # O msiexec e gravado num .bat em vez de montado como uma linha de comando so, com aspas
    # aninhadas escapadas na mao (`"..."`) - cmd.exe NAO entende \" como aspas escapadas (essa e
    # a convencao do parser de argv do runtime C/CreateProcess, nao do cmd.exe), entao aquela
    # abordagem gerava uma linha de comando mal interpretada: o processo era criado (ReturnValue=0)
    # mas nem o log nem o arquivo de codigo de saida eram gerados, porque o msiexec nunca rodava
    # com os argumentos certos (as vezes nem rodava). Um .bat evita ter que escapar aspas dentro de
    # aspas dentro de aspas - o conteudo do arquivo e interpretado linha a linha pelo cmd.exe, do
    # jeito normal de um script batch.
    $remoteBatUnc = "$adminShare\Windows\Temp\aw-install-cmd.bat"
    $remoteBatLocal = "C:\Windows\Temp\aw-install-cmd.bat"
    $batContent = @"
@echo off
msiexec.exe /i "$remoteMsiLocal" SERVERIP="$serverIp" /quiet /norestart /l*v "$remoteLogLocal"
echo %errorlevel% > "$remoteExitCodeLocal"
"@
    $localBatPath = Join-Path $env:TEMP "aw-install-cmd-$([guid]::NewGuid().ToString('N')).bat"
    [System.IO.File]::WriteAllText($localBatPath, $batContent, [System.Text.Encoding]::ASCII)
    try {
        Copy-Item -LiteralPath $localBatPath -Destination $remoteBatUnc -Force
    } finally {
        Remove-Item -LiteralPath $localBatPath -Force -ErrorAction SilentlyContinue
    }

    $securePwd = ConvertTo-SecureString $password -AsPlainText -Force
    $cred = New-Object System.Management.Automation.PSCredential($username, $securePwd)
    $cimOption = New-CimSessionOption -Protocol Dcom
    $session = New-CimSession -ComputerName $computerName -Credential $cred -SessionOption $cimOption

    # Win32_Process.Create nao devolve o codigo de saida do processo depois que ele termina, so o
    # ReturnValue da propria chamada de criacao (0 = "processo criado com sucesso", nao "instalou
    # com sucesso") - por isso o .bat grava %errorlevel% num arquivo a parte, lido de volta abaixo.
    # Caminho do .bat sem espacos, sem precisar de nenhuma aspa aqui.
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
            throw "Tempo limite de $timeoutSegundos segundos excedido esperando a instalacao terminar em $computerName."
        }
    }

    Start-Sleep -Seconds 2 # da um instante para o SO liberar o handle do arquivo de log/exitcode

    $codigoSaida = $null
    if (Test-Path -LiteralPath $remoteExitCodeUnc) {
        $codigoSaida = (Get-Content -LiteralPath $remoteExitCodeUnc -Raw -ErrorAction SilentlyContinue).Trim()
    }
    if (Test-Path -LiteralPath $remoteLogUnc) {
        # Guarda so a cauda do log (verbose do MSI pode passar de varios MB) - normalmente basta
        # para diagnosticar falha; o arquivo completo continua na maquina de destino.
        $resultado.log = (Get-Content -LiteralPath $remoteLogUnc -Raw -ErrorAction SilentlyContinue)
        if ($resultado.log -and $resultado.log.Length -gt 20000) {
            $resultado.log = $resultado.log.Substring($resultado.log.Length - 20000)
        }
    }

    if ($codigoSaida -eq '0' -or $codigoSaida -eq '3010') {
        $resultado.ok = $true
        $resultado.mensagem = if ($codigoSaida -eq '3010') { 'Instalacao concluida (reinicio pendente na maquina de destino).' } else { 'Instalacao concluida.' }
    } else {
        $resultado.mensagem = "msiexec terminou com codigo de saida $codigoSaida (ver log)."
    }
}
catch {
    $resultado.mensagem = $_.Exception.Message
}
finally {
    if ($session) { Remove-CimSession -CimSession $session -ErrorAction SilentlyContinue }
    # Mesma cautela do "net use" acima: nunca pode lançar um erro terminante aqui dentro, ou o
    # script sai sem nunca imprimir o JSON de resultado no stdout.
    if ($mapeado) {
        try { cmd.exe /c "net use `"$adminShare`" /delete /y 2>&1" | Out-Null } catch { }
    }
}

Sair $resultado
