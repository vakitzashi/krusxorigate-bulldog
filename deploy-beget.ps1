[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
[Console]::InputEncoding = New-Object Text.UTF8Encoding($false)
[Console]::OutputEncoding = New-Object Text.UTF8Encoding($false)

$ProjectRoot = $PSScriptRoot
$PublicRoot = Join-Path $ProjectRoot 'public_html'
$SecretRoot = Join-Path $ProjectRoot 'secret'
$KeyPath = Join-Path $SecretRoot 'beget-tactic-deploy-ed25519'
$SshHost = 'antoha5l.beget.tech'
$SshUser = 'antoha5l_tactic'
$SshPort = 22
$ExpectedRemoteHome = '/home/a/antoha5l/origate-tactic.ru'
$RemoteTarget = '{0}@{1}' -f $SshUser,$SshHost

$SshExe = (Get-Command ssh.exe -ErrorAction Stop).Source
$ScpExe = (Get-Command scp.exe -ErrorAction Stop).Source
$SshArgs = @('-i',$KeyPath,'-o','BatchMode=yes','-o','StrictHostKeyChecking=yes','-o','ConnectTimeout=15','-p',"$SshPort")
$ScpArgs = @('-i',$KeyPath,'-o','BatchMode=yes','-o','StrictHostKeyChecking=yes','-o','ConnectTimeout=15','-P',"$SshPort")

function Write-Stage([string]$Text) {
  Write-Host ''
  Write-Host "=== $Text ===" -ForegroundColor DarkYellow
}

function Quote-Remote([string]$Value) {
  return "'" + $Value.Replace("'", "'""'""'") + "'"
}

function Invoke-Remote {
  param([string]$Command,[switch]$Quiet)
  $previousPreference = $ErrorActionPreference
  $ErrorActionPreference = 'Continue'
  try {
    $raw = @(& $SshExe @SshArgs $RemoteTarget $Command 2>&1)
    $code = $LASTEXITCODE
  }
  finally {
    $ErrorActionPreference = $previousPreference
  }
  $output = @($raw | Where-Object { $_.ToString() -notmatch '^Welcome to LTD BeGet SSH Server' })
  if ($code -ne 0) {
    $details = ($output | ForEach-Object { $_.ToString() }) -join [Environment]::NewLine
    throw ('Ошибка SSH, код {0}.{1}{2}' -f $code,[Environment]::NewLine,$details)
  }
  if (-not $Quiet) { return $output }
}

function Get-RelativeFilePath([string]$BasePath,[string]$FullPath) {
  $base = [IO.Path]::GetFullPath($BasePath).TrimEnd('\') + '\'
  $full = [IO.Path]::GetFullPath($FullPath)
  $baseUri = New-Object Uri($base)
  $fullUri = New-Object Uri($full)
  return [Uri]::UnescapeDataString($baseUri.MakeRelativeUri($fullUri).ToString())
}

function Get-LocalFiles([ValidateSet('public_html','secret')][string]$Scope) {
  $base = if ($Scope -eq 'public_html') { $PublicRoot } else { $SecretRoot }
  $result = @{}
  $secretExcludes = @('.gitignore','README.md','beget-tactic-deploy-ed25519','beget-tactic-deploy-ed25519.pub')

  foreach ($file in Get-ChildItem -LiteralPath $base -File -Recurse) {
    $relative = (Get-RelativeFilePath $base $file.FullName).Replace('\','/')
    if ($Scope -eq 'secret' -and $relative -in $secretExcludes) { continue }
    $result[$relative] = [PSCustomObject]@{
      Hash = (Get-FileHash -LiteralPath $file.FullName -Algorithm SHA256).Hash.ToLowerInvariant()
      FullName = $file.FullName
    }
  }
  return $result
}

function Get-RemoteFiles {
  $result = @{ public_html = @{}; secret = @{} }
  $lines = Invoke-Remote 'find public_html secret -type f -exec sha256sum {} \;'
  foreach ($item in $lines) {
    $line = $item.ToString().Trim()
    if ($line -match '^(?<hash>[0-9a-fA-F]{64})\s+\*?(?<scope>public_html|secret)/(?<path>.+)$') {
      $result[$Matches.scope][$Matches.path] = $Matches.hash.ToLowerInvariant()
    }
  }
  return $result
}

function Add-ScopeChanges {
  param([string]$Scope,[hashtable]$Local,[hashtable]$Remote,[System.Collections.ArrayList]$Changes)

  foreach ($path in $Local.Keys) {
    $type = $null
    if (-not $Remote.ContainsKey($path)) { $type = 'ADDED' }
    elseif ($Local[$path].Hash -ne $Remote[$path]) { $type = 'MODIFIED' }
    if ($type) {
      [void]$Changes.Add([PSCustomObject]@{
        Scope=$Scope; Path=$path; Type=$type
        LocalHash=$Local[$path].Hash; LocalPath=$Local[$path].FullName
      })
    }
  }

  if ($Scope -eq 'public_html') {
    foreach ($path in $Remote.Keys) {
      if (-not $Local.ContainsKey($path)) {
        [void]$Changes.Add([PSCustomObject]@{
          Scope=$Scope; Path=$path; Type='DELETED'
          LocalHash=$null; LocalPath=$null
        })
      }
    }
  }
}

function Show-Changes([object[]]$Changes) {
  $labels = @{ ADDED='ДОБАВЛЕН'; MODIFIED='ИЗМЕНЁН'; DELETED='УДАЛЁН' }
  $colors = @{ ADDED='Green'; MODIFIED='Yellow'; DELETED='Red' }
  foreach ($change in $Changes | Sort-Object Scope,Path) {
    Write-Host ('[{0,-8}] ' -f $labels[$change.Type]) -NoNewline -ForegroundColor $colors[$change.Type]
    Write-Host ('{0}/{1}' -f $change.Scope,$change.Path)
  }
}

function Upload-StageFiles([object[]]$Changes,[string]$Stage) {
  $number = 0
  foreach ($change in $Changes) {
    $number++
    $stageName = 'payload-{0:D4}' -f $number
    $destination = '{0}:./{1}/{2}' -f $RemoteTarget,$Stage,$stageName
    & $ScpExe @ScpArgs '--' $change.LocalPath $destination
    if ($LASTEXITCODE -ne 0) { throw "Не удалось загрузить $($change.Scope)/$($change.Path)" }

    $hashOutput = Invoke-Remote ('sha256sum {0}' -f (Quote-Remote "$Stage/$stageName"))
    $hashLine = ($hashOutput | Select-Object -Last 1).ToString()
    if ($hashLine -notmatch '^(?<hash>[0-9a-fA-F]{64})\s+') {
      throw "Сервер не вернул хэш для $($change.Scope)/$($change.Path)"
    }
    if ($Matches.hash.ToLowerInvariant() -ne $change.LocalHash) {
      throw "Хэш загрузки не совпал: $($change.Scope)/$($change.Path)"
    }
    Add-Member -InputObject $change -NotePropertyName StageName -NotePropertyValue $stageName
  }
}

try {
  Write-Host 'ORIGATE TACTIC / безопасный деплой BeGet' -ForegroundColor White
  Write-Host "Проект: $ProjectRoot" -ForegroundColor DarkGray

  if (-not (Test-Path -LiteralPath $KeyPath -PathType Leaf)) { throw "Не найден SSH-ключ: $KeyPath" }
  if (-not (Test-Path -LiteralPath $PublicRoot -PathType Container)) { throw 'Не найдена папка public_html.' }
  if (-not (Test-Path -LiteralPath $SecretRoot -PathType Container)) { throw 'Не найдена папка secret.' }

  $publicDirectories = @(Get-ChildItem -LiteralPath $PublicRoot -Directory -Recurse)
  if ($publicDirectories.Count -gt 0) {
    $names = ($publicDirectories | ForEach-Object FullName) -join [Environment]::NewLine
    throw ('public_html должен быть плоским. Найдены папки:{0}{1}' -f [Environment]::NewLine,$names)
  }

  Write-Stage 'Проверка изолированного доступа'
  $homeOutput = Invoke-Remote 'pwd'
  $remoteHome = ($homeOutput | Select-Object -Last 1).ToString().Trim()
  if ($remoteHome -ne $ExpectedRemoteHome) {
    throw "Ожидался $ExpectedRemoteHome, но SSH открыл $remoteHome"
  }
  $roots = @((Invoke-Remote "find . -maxdepth 1 -mindepth 1 -type d -printf '%f\n' | sort") | ForEach-Object { $_.ToString().Trim() })
  if ('public_html' -notin $roots -or 'secret' -notin $roots) {
    throw 'В ограниченном корне не найдены public_html и secret.'
  }
  Write-Host "Доступ подтверждён: $ExpectedRemoteHome" -ForegroundColor Green

  Write-Stage 'Изменения после последнего деплоя'
  $localPublic = Get-LocalFiles 'public_html'
  $localSecret = Get-LocalFiles 'secret'
  $remote = Get-RemoteFiles
  $changes = New-Object System.Collections.ArrayList
  Add-ScopeChanges 'public_html' $localPublic $remote.public_html $changes
  Add-ScopeChanges 'secret' $localSecret $remote.secret $changes

  if ($changes.Count -eq 0) {
    Write-Host 'Изменений нет.' -ForegroundColor Green
    exit 0
  }

  Show-Changes @($changes)
  Write-Host ''
  Write-Host "Всего изменений: $($changes.Count)"
  Write-Host 'Лишние серверные файлы в secret и резервные копии сохраняются.' -ForegroundColor DarkGray
  Write-Host ''
  $answer = Read-Host 'Введите DEPLOY для подтверждения (любое другое значение отменяет)'
  if ($answer -cne 'DEPLOY') {
    Write-Host 'Деплой отменён. Сервер не изменён.' -ForegroundColor Yellow
    exit 0
  }

  $deployId = Get-Date -Format 'yyyyMMdd-HHmmss'
  $stage = ".deploy-staging-$deployId"
  if ($stage -notmatch '^\.deploy-staging-\d{8}-\d{6}$') { throw 'Некорректное имя временной области.' }
  $uploads = @($changes | Where-Object Type -ne 'DELETED')
  $publicChanges = @($changes | Where-Object Scope -eq 'public_html')
  $secretChanges = @($changes | Where-Object Scope -eq 'secret')
  $stageCreated = $false

  Write-Stage 'Проверочная загрузка'
  Invoke-Remote ('mkdir -p {0}' -f (Quote-Remote $stage)) -Quiet
  $stageCreated = $true

  try {
    Upload-StageFiles $uploads $stage

    Write-Stage 'Резервное копирование'
    if ($publicChanges.Count -gt 0) {
      $publicBackup = "secret/public_html-before-$deployId.tar.gz"
      Invoke-Remote ('tar -czf {0} -C public_html .' -f (Quote-Remote $publicBackup)) -Quiet
      Write-Host "Создана копия: $publicBackup" -ForegroundColor Green
    }
    if ($secretChanges.Count -gt 0) {
      $secretBackup = "secret/secret-before-$deployId.tar.gz"
      $secretBackupTemp = ".secret-before-$deployId.tar.gz"
      Invoke-Remote ("tar --exclude='*.tar.gz' --exclude='.deploy-*' -czf {0} -C secret . && mv -f -- {0} {1}" -f (Quote-Remote $secretBackupTemp),(Quote-Remote $secretBackup)) -Quiet
      Write-Host "Создана копия: $secretBackup" -ForegroundColor Green
    }

    Write-Stage 'Применение обновления'
    foreach ($change in $uploads) {
      $target = '{0}/{1}' -f $change.Scope,$change.Path
      $parent = $target.Substring(0,$target.LastIndexOf('/'))
      $command = 'mkdir -p {0} && cp -f -- {1} {2}' -f (Quote-Remote $parent),(Quote-Remote "$stage/$($change.StageName)"),(Quote-Remote $target)
      Invoke-Remote $command -Quiet
      Write-Host "Обновлён: $target"
    }
    foreach ($change in @($changes | Where-Object Type -eq 'DELETED')) {
      $target = '{0}/{1}' -f $change.Scope,$change.Path
      Invoke-Remote ('rm -f -- {0}' -f (Quote-Remote $target)) -Quiet
      Write-Host "Удалён: $target" -ForegroundColor DarkRed
    }
  }
  finally {
    if ($stageCreated) { Invoke-Remote ('rm -rf -- {0}' -f (Quote-Remote $stage)) -Quiet }
  }

  Write-Stage 'Финальная проверка'
  $after = Get-RemoteFiles
  $errors = New-Object System.Collections.Generic.List[string]
  foreach ($path in $localPublic.Keys) {
    if (-not $after.public_html.ContainsKey($path) -or $after.public_html[$path] -ne $localPublic[$path].Hash) {
      $errors.Add("public_html/$path")
    }
  }
  foreach ($path in $after.public_html.Keys) {
    if (-not $localPublic.ContainsKey($path)) { $errors.Add("лишний public_html/$path") }
  }
  foreach ($path in $localSecret.Keys) {
    if (-not $after.secret.ContainsKey($path) -or $after.secret[$path] -ne $localSecret[$path].Hash) {
      $errors.Add("secret/$path")
    }
  }
  if ($errors.Count -gt 0) {
    throw ('Проверка не пройдена:{0}{1}' -f [Environment]::NewLine,($errors -join [Environment]::NewLine))
  }

  $response = Invoke-WebRequest -UseBasicParsing -Uri ('https://origate-tactic.ru/?deploy={0}' -f $deployId) -TimeoutSec 20
  if ($response.StatusCode -ne 200) { throw "Сайт ответил HTTP $($response.StatusCode)" }

  Write-Host 'Деплой завершён. Хэши совпадают, сайт отвечает HTTP 200.' -ForegroundColor Green
  Write-Host 'https://origate-tactic.ru/' -ForegroundColor Cyan
  exit 0
}
catch {
  Write-Host ''
  Write-Host "ОШИБКА: $($_.Exception.Message)" -ForegroundColor Red
  exit 1
}
