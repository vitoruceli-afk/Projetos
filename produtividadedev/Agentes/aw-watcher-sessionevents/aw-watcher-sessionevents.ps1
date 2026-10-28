# aw-watcher-sessionevents.ps1
#
# "Custom watcher" do ActivityWatch (mesmo principio do aw-watcher-currentuser nesta mesma pasta
# Agentes/ - nao faz parte da instalacao oficial, nao modifica nenhum arquivo do AW): reporta
# eventos de LOGIN, BLOQUEIO (lock) e DESBLOQUEIO (unlock) da sessao do Windows para o aw-server
# LOCAL, no bucket proprio "aw-watcher-sessionevents_<hostname>", via a mesma API REST publica que
# aw-watcher-window/afk e o aw-watcher-currentuser ja usam (http://127.0.0.1:5600/api/0).
#
# Por que isso alem do aw-watcher-afk existente: o AFK oficial so enxerga "tempo sem teclado/mouse
# alem de um limite" - nao sabe se a tela estava DE FATO bloqueada (Win+L, Ctrl+Alt+Del > Bloquear)
# ou so sem interacao por um tempo. "Bloqueado" e um sinal bem mais forte de que o usuario realmente
# se afastou, pedido explicito pra deixar as estatisticas de uso do dispositivo mais fieis.
#
# Disparado por Tarefas Agendadas com gatilho nativo do Windows (ver as 3 task-aw-watcher-
# sessionevents-*.xml nesta pasta), roda na sessao do usuario logado, sem privilegio de
# administrador:
#   - login:  LogonTrigger (mesmo gatilho do aw-watcher-currentuser)
#   - lock:   SessionStateChangeTrigger StateChange="SessionLock"
#   - unlock: SessionStateChangeTrigger StateChange="SessionUnlock"
#
# Diferente do aw-watcher-currentuser (heartbeat continuo, repetido a cada poucos minutos, pensado
# pra responder "quem esta usando a maquina agora"), este agente manda um evento PONTUAL (duration=0)
# por disparo - cada entrada no bucket e um marco no tempo ("aconteceu aqui"), nao um intervalo.
#
#   Uso: aw-watcher-sessionevents.ps1 -Evento login|lock|unlock

param(
    [Parameter(Mandatory=$true)]
    [ValidateSet('login', 'lock', 'unlock')]
    [string]$Evento
)

$ErrorActionPreference = 'Stop'

$hostname = $env:COMPUTERNAME
$bucketId = "aw-watcher-sessionevents_$hostname"
$baseUrl = "http://127.0.0.1:5600/api/0"
$usuario = if ($env:USERDOMAIN -and $env:USERDOMAIN -ne $env:COMPUTERNAME) { "$env:USERDOMAIN\$env:USERNAME" } else { $env:USERNAME }

try {
    $bucketBody = @{ client = 'aw-watcher-sessionevents'; type = 'sessionevent'; hostname = $hostname } | ConvertTo-Json -Compress
    try {
        Invoke-RestMethod -Uri "$baseUrl/buckets/$bucketId" -Method Post -Body $bucketBody -ContentType 'application/json' -TimeoutSec 5 | Out-Null
    } catch {
        $codigo = $_.Exception.Response.StatusCode.value__
        if ($codigo -ne 304) { throw }
    }

    $agoraUtc = (Get-Date).ToUniversalTime().ToString('yyyy-MM-ddTHH:mm:ss.fffZ')
    # duration=0 e de proposito - evento pontual, nao uma sessao. pulsetime=0 evita que o aw-server
    # funda este evento com um anterior do mesmo tipo (queremos CADA login/lock/unlock como sua
    # propria linha, pra contar quantas vezes aconteceu, nao so a ultima).
    $eventoBody = @{
        timestamp = $agoraUtc
        duration = 0
        data = @{ user = $usuario; evento = $Evento }
    } | ConvertTo-Json -Compress

    Invoke-RestMethod -Uri "$baseUrl/buckets/$bucketId/events" -Method Post -Body "[$eventoBody]" -ContentType 'application/json' -TimeoutSec 5 | Out-Null
}
catch {
    # Silencioso de proposito, mesmo criterio do aw-watcher-currentuser: nunca deve gerar uma janela
    # de erro visivel nem travar o logon/bloqueio/desbloqueio do usuario. Se o aw-server nao estiver
    # de pe ainda (ex: acabou de logar, aw-qt ainda subindo), o evento so se perde - aceitavel pra
    # um marco pontual (diferente do heartbeat continuo do aw-watcher-currentuser, que se corrige
    # sozinho na proxima execucao).
}
