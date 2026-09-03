# aw-watcher-currentuser.ps1
#
# "Custom watcher" do ActivityWatch (nao faz parte da instalacao oficial - nao modifica nenhum
# arquivo do AW): reporta o usuario do Windows logado nesta sessao para o aw-server LOCAL, no bucket
# proprio "aw-watcher-currentuser_<hostname>", via a mesma API REST publica que aw-watcher-window e
# aw-watcher-afk ja usam (http://127.0.0.1:5600/api/0). E exatamente o mecanismo de extensao que a
# propria documentacao do ActivityWatch descreve ("Writing custom watchers") - nao e um hack.
#
# Roda dentro da sessao do usuario logado (Tarefa Agendada com gatilho de logon, sem privilegio de
# administrador) - por isso $env:USERNAME sempre reflete quem esta realmente usando a maquina,
# sem precisar de nenhuma credencial armazenada (diferente da alternativa via WMI, que foi
# descartada exatamente por exigir uma credencial de admin rodando o tempo todo).
#
# Cada execucao manda UM heartbeat. A repeticao (a cada poucos minutos) fica a cargo do agendador
# do Windows -"pulsetime" abaixo funde heartbeats consecutivos do MESMO usuario num unico evento
# continuo, e fecha esse evento (abrindo um novo) se o usuario mudar ou passar tempo demais sem
# reportar (ex: sessao encerrada).

$ErrorActionPreference = 'Stop'

$hostname = $env:COMPUTERNAME
$bucketId = "aw-watcher-currentuser_$hostname"
$baseUrl = "http://127.0.0.1:5600/api/0"
$usuario = if ($env:USERDOMAIN -and $env:USERDOMAIN -ne $env:COMPUTERNAME) { "$env:USERDOMAIN\$env:USERNAME" } else { $env:USERNAME }

# IP reportado pela propria maquina (nunca por DNS): o servidor de Produtividade resolve o "host"
# cadastrado via DNS pra falar com o aw-server, mas isso pode ser um hostname com registro
# desatualizado. O IP de verdade e o da interface de rede que tem a ROTA PADRAO (0.0.0.0/0) -
# ou seja, a que a maquina realmente usa pra se comunicar com o resto da rede. Ordenar so por
# "menor IP" ou pegar qualquer IPv4 nao-loopback nao basta: uma maquina com VMware/VPN/hotspot
# Bluetooth instalado tem varios adaptadores virtuais, e um deles pode aparecer antes do de
# verdade dependendo da ordem que o Windows devolve - foi exatamente o que aconteceu testando
# isso na maquina deste servidor (pegava o IP de uma rede virtual do VMware em vez do
# 10.10.140.17 de verdade).
$ipLocal = $null
try {
    $rotaPadrao = Get-NetRoute -DestinationPrefix '0.0.0.0/0' -ErrorAction Stop |
        Sort-Object -Property RouteMetric |
        Select-Object -First 1
    if ($rotaPadrao) {
        $ipLocal = Get-NetIPAddress -InterfaceIndex $rotaPadrao.InterfaceIndex -AddressFamily IPv4 -ErrorAction Stop |
            Select-Object -First 1 -ExpandProperty IPAddress
    }
} catch {
    # Sem rota padrao ou cmdlet indisponivel (ex: versao antiga do Windows) - manda o heartbeat
    # sem "ip" mesmo, igual antes desta mudanca.
}

try {
    # Garante que o bucket existe - 200 (criou agora) ou 304 (ja existia) sao os dois resultados
    # esperados; qualquer outro codigo cai no catch abaixo.
    $bucketBody = @{ client = 'aw-watcher-currentuser'; type = 'currentuser'; hostname = $hostname } | ConvertTo-Json -Compress
    try {
        Invoke-RestMethod -Uri "$baseUrl/buckets/$bucketId" -Method Post -Body $bucketBody -ContentType 'application/json' -TimeoutSec 5 | Out-Null
    } catch {
        $codigo = $_.Exception.Response.StatusCode.value__
        if ($codigo -ne 304) { throw }
    }

    $agoraUtc = (Get-Date).ToUniversalTime().ToString('yyyy-MM-ddTHH:mm:ss.fffZ')
    $dadosHeartbeat = @{ user = $usuario }
    if ($ipLocal) { $dadosHeartbeat.ip = $ipLocal }
    $heartbeatBody = @{
        timestamp = $agoraUtc
        duration = 0
        data = $dadosHeartbeat
    } | ConvertTo-Json -Compress

    Invoke-RestMethod -Uri "$baseUrl/buckets/$bucketId/heartbeat?pulsetime=600" -Method Post -Body $heartbeatBody -ContentType 'application/json' -TimeoutSec 5 | Out-Null
}
catch {
    # Silencioso de proposito: roda em segundo plano a cada poucos minutos - nao deve nunca gerar
    # uma janela de erro visivel nem travar o logon do usuario. Se o aw-server nao estiver de pe
    # ainda (ex: acabou de logar), a proxima execucao agendada tenta de novo sozinha.
}
