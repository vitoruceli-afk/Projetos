' run-hidden-sessionevents.vbs
' Mesmo wrapper usado pelo aw-watcher-currentuser (ver Agentes\aw-watcher-currentuser\run-hidden.vbs
' - mesmo comentario explica o motivo do WScript.Shell.Run em vez de chamar powershell.exe direto),
' só que parametrizado: recebe o nome do evento (login/lock/unlock) como argumento e repassa pro
' script via -Evento, porque as 3 Tarefas Agendadas (uma por gatilho) chamam este MESMO wrapper,
' cada uma com seu próprio argumento fixo. Nome diferente do wrapper do aw-watcher-currentuser (em
' vez de reaproveitar "run-hidden.vbs") porque os dois arquivos acabam lado a lado na mesma pasta
' de instalação (INSTALLFOLDER é "achatada", ver Product.wxs) — nomes iguais colidiriam.
'
'   Uso: wscript.exe run-hidden-sessionevents.vbs login|lock|unlock
Option Explicit

Dim shell, caminhoScript, evento
Set shell = CreateObject("WScript.Shell")
caminhoScript = shell.ExpandEnvironmentStrings("%ProgramFiles(x86)%") & "\ActivityWatch-Produtividade\aw-watcher-sessionevents.ps1"

If WScript.Arguments.Count < 1 Then
    WScript.Quit 1
End If
evento = WScript.Arguments(0)

shell.Run "powershell.exe -NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File """ & caminhoScript & """ -Evento " & evento, 0, True
