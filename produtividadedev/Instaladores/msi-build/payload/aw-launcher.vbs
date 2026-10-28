' aw-launcher.vbs
' Runs via HKLM\...\Run at every user logon on this machine. Makes sure this user's aw-server
' already has network access enabled (host 0.0.0.0) before ActivityWatch starts - without this,
' every new user who logs into a shared machine would need the aw-server.toml edited by hand.
Option Explicit

Dim fso, shell, localAppData, configDir, configFile, templateFile, awExe

Set fso = CreateObject("Scripting.FileSystemObject")
Set shell = CreateObject("WScript.Shell")

localAppData = shell.ExpandEnvironmentStrings("%LOCALAPPDATA%")
configDir = localAppData & "\activitywatch\activitywatch\aw-server"
configFile = configDir & "\aw-server.toml"
templateFile = fso.GetParentFolderName(WScript.ScriptFullName) & "\aw-server.default.toml"

' Only writes the template if this user does not already have their own aw-server.toml - never
' overwrites a config that a user or administrator already customized by hand.
If Not fso.FileExists(configFile) And fso.FileExists(templateFile) Then
    CriarPastas fso, configDir
    fso.CopyFile templateFile, configFile, False
End If

' Avoids opening a second instance if ActivityWatch is already running for this user.
If Not ProcessoJaRodando("aw-qt.exe") Then
    awExe = CaminhoAwQt(fso)
    If awExe <> "" Then
        shell.Run """" & awExe & """", 1, False
    End If
End If

Sub CriarPastas(fso, caminho)
    Dim partes, atual, i
    partes = Split(caminho, "\")
    atual = partes(0) & "\" & partes(1) ' drive + first folder, e.g. C:\Users
    For i = 2 To UBound(partes)
        atual = atual & "\" & partes(i)
        If Not fso.FolderExists(atual) Then
            fso.CreateFolder atual
        End If
    Next
End Sub

Function CaminhoAwQt(fso)
    Dim candidatos(1), c
    candidatos(0) = shellExpand("%ProgramFiles(x86)%") & "\ActivityWatch\aw-qt.exe"
    candidatos(1) = shellExpand("%ProgramFiles%") & "\ActivityWatch\aw-qt.exe"
    CaminhoAwQt = ""
    For Each c In candidatos
        If fso.FileExists(c) Then
            CaminhoAwQt = c
            Exit Function
        End If
    Next
End Function

Function shellExpand(s)
    Dim sh
    Set sh = CreateObject("WScript.Shell")
    shellExpand = sh.ExpandEnvironmentStrings(s)
End Function

Function ProcessoJaRodando(nomeProcesso)
    Dim wmi, procs
    ProcessoJaRodando = False
    On Error Resume Next
    Set wmi = GetObject("winmgmts:\\.\root\cimv2")
    Set procs = wmi.ExecQuery("Select * from Win32_Process Where Name='" & nomeProcesso & "'")
    If Err.Number = 0 And Not procs Is Nothing Then
        ProcessoJaRodando = (procs.Count > 0)
    End If
    On Error Goto 0
End Function
