' run-hidden.vbs
' Wrapper so a Tarefa Agendada roda o aw-watcher-currentuser.ps1 de verdade sem janela nenhuma.
' Chamar powershell.exe direto do Agendador de Tarefas, mesmo com -WindowStyle Hidden, ainda pisca
' um console por uma fracao de segundo a cada execucao: o Windows cria a janela do console ANTES do
' PowerShell processar o proprio argumento -WindowStyle e escondela. WScript.Shell.Run com o
' parametro de janela = 0 (oculta) evita isso porque a janela nunca chega a ser criada visivel -
' o mesmo motivo pelo qual aw-launcher.vbs (nesta mesma pasta) ja usa wscript.exe em vez de
' cscript.exe pra nao abrir console nenhum.
Option Explicit

Dim shell, caminhoScript
Set shell = CreateObject("WScript.Shell")
caminhoScript = shell.ExpandEnvironmentStrings("%ProgramFiles(x86)%") & "\ActivityWatch-Produtividade\aw-watcher-currentuser.ps1"

shell.Run "powershell.exe -NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File """ & caminhoScript & """", 0, True
