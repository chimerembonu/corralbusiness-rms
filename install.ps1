# ---------------------------------------------------------------------------
# CorralBusiness - RMS: one-command installer for a Windows 10/11 store server (Docker Desktop).
#
#   One line in PowerShell, from nothing:
#     irm https://raw.githubusercontent.com/chimerembonu/corralbusiness-rms/main/install.ps1 | iex
#   Or double-click install.cmd in a copy of the code (USB stick or downloaded ZIP).
#
# Options (after install.cmd or "powershell -ExecutionPolicy Bypass -File install.ps1"), or as settings
# before the one-line form ($env:RMS_BRANCH = 'complete-edition'):
#   -Branch NAME   which version to download (default main; the Complete Edition: complete-edition)
#   -Port N        the web port (default 80, or 8080 when something else already uses 80)
#   -Dir PATH      where to install (default C:\CorralBusiness-RMS)
#
# What it does, in order (the same as install.sh on Ubuntu and Mac):
#   1. Checks Docker Desktop is installed, and starts it if needed.
#   2. Puts the code in the install folder (git if installed, else the ZIP from GitHub, or the folder it runs from).
#      Run it again later to update: it makes a backup first, then gets the new version and keeps your data.
#   3. Creates .env with generated keys and passwords, bound to this PC's network address. Nothing to fill in.
#   4. Lets the tills on the restaurant network reach this PC (one Windows permission question).
#   5. Builds and starts everything, then opens the setup wizard (/setup) and shows the setup code.
#
# Written for Windows PowerShell 5.1 (built into Windows) and PowerShell 7. Plain ASCII on purpose: Windows
# PowerShell reads a script without a byte-order mark in the old Windows code page.
# ---------------------------------------------------------------------------
$ErrorActionPreference = 'Stop'
Set-StrictMode -Version 2.0

# --- Settings and options -------------------------------------------------------------------------------
$Script:RepoUrl = 'https://github.com/chimerembonu/corralbusiness-rms'
$Script:Branch = if ($env:RMS_BRANCH) { $env:RMS_BRANCH } else { 'main' }
$Script:WantPort = if ($env:RMS_HTTP_PORT) { $env:RMS_HTTP_PORT } else { '' }
$Script:Target = if ($env:RMS_DIR) { $env:RMS_DIR } else { 'C:\CorralBusiness-RMS' }

function Read-Options([string[]]$list) {
    if (-not $list) { return }
    for ($i = 0; $i -lt $list.Count; $i++) {
        switch -Regex ($list[$i]) {
            '^-{1,2}branch$' { $i++; $Script:Branch = $list[$i] }
            '^-{1,2}port$' { $i++; $Script:WantPort = $list[$i] }
            '^-{1,2}dir$' { $i++; $Script:Target = $list[$i] }
        }
    }
}

function Say([string]$text) { Write-Host ''; Write-Host "==> $text" -ForegroundColor Green }
function Warn([string]$text) { Write-Host ''; Write-Host "!!  $text" -ForegroundColor Yellow }
function Fail([string]$text) { Write-Host ''; Write-Host "XX  $text" -ForegroundColor Red; throw 'CorralBusiness - RMS was not installed.' }

# --- Random keys and passwords (cryptographic generator, no bias) ---------------------------------------
$Script:Rng = New-Object System.Security.Cryptography.RNGCryptoServiceProvider
function New-RandomText([int]$length, [string]$alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789') {
    $limit = 256 - (256 % $alphabet.Length)
    $out = New-Object System.Text.StringBuilder
    $byte = New-Object byte[] 1
    while ($out.Length -lt $length) {
        $Script:Rng.GetBytes($byte)
        if ($byte[0] -lt $limit) { [void]$out.Append($alphabet[$byte[0] % $alphabet.Length]) }
    }
    return $out.ToString()
}
function New-SetupCode { return New-RandomText 6 '0123456789' }
function New-AppKey {
    $bytes = New-Object byte[] 32
    $Script:Rng.GetBytes($bytes)
    return 'base64:' + [Convert]::ToBase64String($bytes)
}

# --- The .env settings file (UTF-8 without a byte-order mark, Unix line ends: Docker reads it) -------------
function Read-EnvLines([string]$path) {
    $text = [System.IO.File]::ReadAllText($path, (New-Object System.Text.UTF8Encoding($false)))
    $list = New-Object 'System.Collections.Generic.List[string]'
    foreach ($line in ($text -replace "`r", '' -split "`n")) { $list.Add($line) }
    return , $list   # the comma keeps it a list (PowerShell would otherwise turn it into a fixed array)
}
function Save-EnvLines([string]$path, $lines) {
    $text = ($lines -join "`n").TrimEnd("`n") + "`n"
    [System.IO.File]::WriteAllText($path, $text, (New-Object System.Text.UTF8Encoding($false)))
}
function Get-EnvValue($lines, [string]$key) {
    $value = ''
    foreach ($line in $lines) { if ($line.StartsWith("$key=")) { $value = $line.Substring($key.Length + 1) } }
    return $value
}
function Set-EnvValue($lines, [string]$key, [string]$value) {
    for ($i = 0; $i -lt $lines.Count; $i++) {
        if ($lines[$i].StartsWith("$key=")) { $lines[$i] = "$key=$value"; return }
    }
    $lines.Add("$key=$value")
}

# A fresh .env from the template: every "(set by install)" value filled in.
function New-EnvLines([string]$template, [string]$ip, [int]$port, [int]$httpsPort, [string]$usbDir) {
    $lines = Read-EnvLines $template
    $suffix = if ($port -eq 80) { '' } else { ":$port" }
    Set-EnvValue $lines 'APP_KEY' (New-AppKey)
    Set-EnvValue $lines 'APP_URL' "http://$ip$suffix"
    Set-EnvValue $lines 'RMS_BIND_IP' $ip
    Set-EnvValue $lines 'RMS_HTTP_PORT' "$port"
    Set-EnvValue $lines 'RMS_HTTPS_PORT' "$httpsPort"
    Set-EnvValue $lines 'DB_PASSWORD' (New-RandomText 24)
    Set-EnvValue $lines 'DB_ROOT_PASSWORD' (New-RandomText 24)
    Set-EnvValue $lines 'REVERB_APP_ID' (New-RandomText 8)
    Set-EnvValue $lines 'REVERB_APP_KEY' (New-RandomText 20)
    Set-EnvValue $lines 'REVERB_APP_SECRET' (New-RandomText 32)
    Set-EnvValue $lines 'RMS_BACKUP_PASSWORD' (New-RandomText 24)
    Set-EnvValue $lines 'RMS_SETUP_CODE' (New-SetupCode)
    Set-EnvValue $lines 'RMS_PRINTER_SUBNET' (Get-PrinterSubnet $ip)
    # Windows has no /media folder for USB sticks: backup copies go to a folder here (forward slashes for Docker).
    Set-EnvValue $lines 'RMS_USB_DIR' ($usbDir -replace '\\', '/')
    return , $lines
}

function Get-PrinterSubnet([string]$ip) { return ($ip -replace '\.\d+$', '.0') + '/24' }

# --- Network ---------------------------------------------------------------------------------------------
# This PC's address on the restaurant network: the adapter with the default gateway (not Docker's or WSL's).
function Get-LanAddress {
    try {
        $cfg = Get-NetIPConfiguration -ErrorAction Stop |
            Where-Object { $_.IPv4DefaultGateway -ne $null -and $_.NetAdapter.Status -eq 'Up' } |
            Select-Object -First 1
        if ($cfg) { return @($cfg.IPv4Address)[0].IPAddress }
    } catch { }
    $found = Get-NetIPAddress -AddressFamily IPv4 -ErrorAction SilentlyContinue |
        Where-Object { $_.IPAddress -notlike '127.*' -and $_.IPAddress -notlike '169.254.*' -and $_.InterfaceAlias -notlike 'vEthernet*' -and $_.InterfaceAlias -notlike '*WSL*' } |
        Select-Object -First 1
    if ($found) { return $found.IPAddress }
    return ''
}

# Is something already listening on this port (IIS, Skype, another web server...)?
function Test-PortBusy([string]$ip, [int]$port) {
    foreach ($address in @('0.0.0.0', $ip)) {
        try {
            $listener = New-Object System.Net.Sockets.TcpListener([System.Net.IPAddress]::Parse($address), $port)
            $listener.Start(); $listener.Stop()
        } catch { return $true }
    }
    return $false
}
function Find-FreePort([string]$ip, [int]$first) {
    $port = $first
    while (Test-PortBusy $ip $port) { $port++ }
    return $port
}

# --- Docker ----------------------------------------------------------------------------------------------
function Invoke-Docker {
    # Docker writes progress to the error stream; only the exit code says whether it worked.
    $previous = $ErrorActionPreference; $ErrorActionPreference = 'Continue'
    try { & docker @args 2>&1 | ForEach-Object { "$_" } | Out-Host; return ($LASTEXITCODE -eq 0) }
    finally { $ErrorActionPreference = $previous }
}
function Test-DockerQuiet {
    $previous = $ErrorActionPreference; $ErrorActionPreference = 'Continue'
    try { & docker @args *> $null; return ($LASTEXITCODE -eq 0) }
    finally { $ErrorActionPreference = $previous }
}

function Start-DockerDesktop {
    if (-not (Get-Command docker -ErrorAction SilentlyContinue)) {
        $bin = Join-Path $env:ProgramFiles 'Docker\Docker\resources\bin'
        if (Test-Path (Join-Path $bin 'docker.exe')) { $env:Path = "$env:Path;$bin" }
    }
    if (-not (Get-Command docker -ErrorAction SilentlyContinue)) {
        Fail 'Docker Desktop is not installed. Get it from https://www.docker.com/products/docker-desktop/ , install it (say yes to WSL 2 and restart), open it once, then run this again.'
    }
    if (Test-DockerQuiet info) { return }
    Say 'Starting Docker Desktop...'
    $exe = Join-Path $env:ProgramFiles 'Docker\Docker\Docker Desktop.exe'
    if (Test-Path $exe) { Start-Process $exe | Out-Null }
    for ($i = 0; $i -lt 90; $i++) {
        if (Test-DockerQuiet info) { return }
        Start-Sleep -Seconds 2
    }
    Fail 'Docker Desktop did not start. Open it from the Start menu, wait until it says "Engine running", then run this again.'
}

# --- Code ------------------------------------------------------------------------------------------------
function Test-CodeFolder([string]$path) {
    return $path -and (Test-Path (Join-Path $path 'docker-compose.yml')) -and (Test-Path (Join-Path $path 'artisan'))
}

# Copies a new version over an installed one, file by file, keeping the restaurant's own files.
$Script:KeepOnUpdate = @('.env', 'usb-copies', 'docker/certs', 'docker/ca', '.git')
function Get-FullPath([string]$path) { return [System.IO.Path]::GetFullPath($path).TrimEnd('\', '/') }
function Test-SamePath([string]$a, [string]$b) { return (Get-FullPath $a) -eq (Get-FullPath $b) }
function Test-Kept([string]$relative) {
    $relative = $relative -replace '\\', '/'
    foreach ($kept in $Script:KeepOnUpdate) {
        if ($relative -eq $kept -or $relative.StartsWith("$kept/")) { return $true }
    }
    return $false
}
function Copy-Code([string]$from, [string]$to) {
    $from = Get-FullPath $from
    New-Item -ItemType Directory -Force -Path $to | Out-Null
    foreach ($file in (Get-ChildItem -LiteralPath $from -Recurse -File -Force)) {
        $relative = $file.FullName.Substring($from.Length + 1)
        if (Test-Kept $relative) { continue }
        $destination = Join-Path $to $relative
        $folder = Split-Path -Parent $destination
        if (-not (Test-Path -LiteralPath $folder)) { New-Item -ItemType Directory -Force -Path $folder | Out-Null }
        Copy-Item -LiteralPath $file.FullName -Destination $destination -Force
    }
}

function Get-Code([string]$here) {
    if ((Test-CodeFolder $here) -and -not (Test-SamePath $here $Script:Target)) {
        Say "Copying the code to $Script:Target..."
        Copy-Code $here $Script:Target
        return
    }
    if (Test-CodeFolder $here) { return }   # running inside the install folder itself
    if (Test-Path (Join-Path $Script:Target '.git')) {
        Say "Updating the code in $Script:Target..."
        if (-not (Invoke-GitQuiet -C $Script:Target pull --ff-only)) { Fail 'Could not get the new version (git pull). Check the internet connection and try again.' }
        return
    }
    if ((-not (Test-CodeFolder $Script:Target)) -and (Get-Command git -ErrorAction SilentlyContinue)) {
        Say "Downloading CorralBusiness - RMS ($Script:Branch) to $Script:Target..."
        if (-not (Invoke-GitQuiet clone --depth 1 --branch $Script:Branch "$Script:RepoUrl.git" $Script:Target)) { Fail 'Downloading failed. Check the internet connection and try again.' }
        return
    }
    Say "Downloading CorralBusiness - RMS ($Script:Branch) to $Script:Target..."
    $zip = Join-Path $env:TEMP "corralbusiness-rms-$([guid]::NewGuid().ToString('N')).zip"
    $unzipped = Join-Path $env:TEMP "corralbusiness-rms-$([guid]::NewGuid().ToString('N'))"
    try {
        [Net.ServicePointManager]::SecurityProtocol = [Net.ServicePointManager]::SecurityProtocol -bor [Net.SecurityProtocolType]::Tls12
        Invoke-WebRequest -UseBasicParsing -Uri "$Script:RepoUrl/archive/refs/heads/$Script:Branch.zip" -OutFile $zip
        Expand-Archive -LiteralPath $zip -DestinationPath $unzipped -Force
        $inner = Get-ChildItem -LiteralPath $unzipped -Directory | Select-Object -First 1
        if (-not (Test-CodeFolder $inner.FullName)) { Fail 'The download was incomplete. Try again.' }
        Copy-Code $inner.FullName $Script:Target
    } finally {
        Remove-Item -LiteralPath $zip -Force -ErrorAction SilentlyContinue
        Remove-Item -LiteralPath $unzipped -Recurse -Force -ErrorAction SilentlyContinue
    }
}
function Invoke-GitQuiet {
    $previous = $ErrorActionPreference; $ErrorActionPreference = 'Continue'
    try { & git -c core.autocrlf=false @args 2>&1 | Out-Host; return ($LASTEXITCODE -eq 0) }
    finally { $ErrorActionPreference = $previous }
}

# --- Windows firewall: tills on the restaurant network must reach this PC -----------------------------------
function Open-Firewall([int[]]$ports) {
    $name = 'CorralBusiness RMS'
    $portList = ($ports | Sort-Object -Unique) -join ','
    $command = "Remove-NetFirewallRule -DisplayName '$name' -ErrorAction SilentlyContinue; New-NetFirewallRule -DisplayName '$name' -Direction Inbound -Protocol TCP -LocalPort $portList -Action Allow -Profile Private,Domain | Out-Null"
    $admin = ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
    try {
        if ($admin) { Invoke-Expression $command }
        else {
            Say 'Windows will ask once for permission to let the tills reach this PC (firewall). Click Yes.'
            $encoded = [Convert]::ToBase64String([System.Text.Encoding]::Unicode.GetBytes($command))
            Start-Process powershell -Verb RunAs -Wait -WindowStyle Hidden -ArgumentList '-NoProfile', '-EncodedCommand', $encoded
        }
    } catch {
        Warn "The firewall could not be opened for ports $portList. If tablets cannot open the app, allow those ports in Windows Security -> Firewall, for private networks."
    }
}

# --- Main --------------------------------------------------------------------------------------------------
function Install-Rms([string[]]$options) {
    Read-Options $options
    # A folder typed as "rms" or ".\rms" means one inside the current folder (PowerShell's, not the process's).
    $Script:Target = $ExecutionContext.SessionState.Path.GetUnresolvedProviderPathFromPSPath($Script:Target)
    if ($Script:WantPort -and $Script:WantPort -notmatch '^\d{2,5}$') { Fail '-Port must be a number, like 8080' }
    if ($PSVersionTable.PSEdition -eq 'Core' -and -not $IsWindows) { Fail 'This installer is for Windows. On Ubuntu or a Mac, use install.sh.' }
    $here = (Get-Location).Path
    if ($PSScriptRoot) { $here = $PSScriptRoot }

    # 1. Docker
    Start-DockerDesktop
    if (-not (Test-DockerQuiet compose version)) { Fail 'Docker Compose is missing. Update Docker Desktop, then run this again.' }

    # 2. Code (an existing install is backed up first, as scripts/update.sh does on Ubuntu)
    $envFile = Join-Path $Script:Target '.env'
    if ((Test-Path $envFile) -and -not ((Test-CodeFolder $here) -and (Test-SamePath $here $Script:Target))) {
        Push-Location $Script:Target
        try {
            Say 'Backing up the database before updating...'
            if (-not (Invoke-Docker compose exec -T app php artisan rms:backup --note=before-update)) {
                Fail 'The backup failed, so nothing was updated. Check that Docker Desktop is running and the disk has free space, then try again.'
            }
        } finally { Pop-Location }
    }
    Get-Code $here
    Set-Location $Script:Target

    # 3. Settings
    $ip = Get-LanAddress
    if (-not $ip) { Fail 'Could not work out this PC''s network address. Connect it to the restaurant network (a cable to the router is best) and run this again.' }
    if (-not (Test-Path $envFile)) {
        Say 'Creating the settings file (.env): keys and passwords are generated once and kept...'
        $port = 80
        if ($Script:WantPort) { $port = [int]$Script:WantPort }
        elseif (Test-PortBusy $ip 80) {
            $port = Find-FreePort $ip 8080
            Warn "Port 80 is already used by another program on this PC, so CorralBusiness - RMS uses port $port."
        }
        $httpsPort = 443
        if (Test-PortBusy $ip 443) { $httpsPort = Find-FreePort $ip 8443 }
        $usbDir = Join-Path $Script:Target 'usb-copies'
        New-Item -ItemType Directory -Force -Path $usbDir | Out-Null
        Save-EnvLines $envFile (New-EnvLines (Join-Path $Script:Target '.env.docker.example') $ip $port $httpsPort $usbDir)
    } else {
        Say 'Keeping the existing settings (.env).'
    }
    $lines = Read-EnvLines $envFile
    if ((Get-EnvValue $lines 'RMS_SETUP_CODE') -notmatch '^\d') { Set-EnvValue $lines 'RMS_SETUP_CODE' (New-SetupCode); Save-EnvLines $envFile $lines }
    $port = [int](Get-EnvValue $lines 'RMS_HTTP_PORT'); if (-not $port) { $port = 80 }
    $httpsPort = [int](Get-EnvValue $lines 'RMS_HTTPS_PORT'); if (-not $httpsPort) { $httpsPort = 443 }
    $boundIp = Get-EnvValue $lines 'RMS_BIND_IP'
    if ($boundIp -and $boundIp -ne $ip) {
        # The router gave this PC a new address: follow it (scripts/address.sh does this on Ubuntu).
        Warn "This PC's network address changed from $boundIp to $ip; the app moves to the new one."
        Set-EnvValue $lines 'RMS_BIND_IP' $ip
        Set-EnvValue $lines 'APP_URL' ("http://$ip" + $(if ($port -eq 80) { '' } else { ":$port" }))
        Save-EnvLines $envFile $lines
    }

    # 4. Firewall
    Open-Firewall @($port, $httpsPort)

    # 5. Start
    Say 'Building and starting CorralBusiness - RMS (the first time takes 5-15 minutes)...'
    if (-not (Invoke-Docker compose build --pull)) { Fail 'Building failed. Check the internet connection and free disk space (at least 10 GB), then run this again.' }
    if (-not (Invoke-Docker compose up -d --remove-orphans)) { Fail 'The app could not start. If the message above says a port is not available, another program uses it: run this again with -Port 8090.' }
    Say 'Waiting for the app to come up...'
    for ($i = 0; $i -lt 100; $i++) {
        if (Test-DockerQuiet compose exec -T app php artisan about --only=environment) { break }
        Start-Sleep -Seconds 3
    }

    $suffix = if ($port -eq 80) { '' } else { ":$port" }
    $address = "http://$ip$suffix"
    $setupNeeded = -not (Test-DockerQuiet compose exec -T app php artisan rms:setup --check)
    Say 'CorralBusiness - RMS is running.'
    if ($setupNeeded) {
        Write-Host ''
        Write-Host '    NEXT: open this address on any computer, tablet or phone on the restaurant network:'
        Write-Host ''
        Write-Host "        $address/setup" -ForegroundColor Cyan
        Write-Host ''
        Write-Host "    Setup code: $(Get-EnvValue (Read-EnvLines $envFile) 'RMS_SETUP_CODE')"
        Write-Host ''
        Write-Host '    The setup screens ask for the restaurant, owner login, taxes, receipt, tills, printers, menu and staff.'
        Write-Host '    Switch the printers on first: the setup finds them and prints a test page.'
        Start-Process "$address/setup" -ErrorAction SilentlyContinue | Out-Null
    } else {
        Write-Host '    The restaurant is already set up.'
    }
    Write-Host ''
    Write-Host "    Back office:  $address/admin"
    Write-Host "    Till:         $address/pos"
    Write-Host "    Kitchen:      $address/kitchen"
    Write-Host "    Clock in:     $address/clock"
    Write-Host '    Tips: in Docker Desktop -> Settings -> General, tick "Start Docker Desktop when you sign in";'
    Write-Host '          set this PC never to sleep (Settings -> System -> Power); reserve its address on the router.'
    Write-Host ''
    Write-Host 'IMPORTANT - your backup password (backups are locked with it):' -ForegroundColor Yellow
    Write-Host "    $(Get-EnvValue (Read-EnvLines $envFile) 'RMS_BACKUP_PASSWORD')"
    Write-Host 'Write it down and keep it somewhere safe, away from this computer. You need it to restore a backup'
    Write-Host 'on a replacement computer. The setup screens show it again.'
}

# RMS_INSTALLER_TEST=1 only loads the functions above (for the automated checks); otherwise install.
if ($env:RMS_INSTALLER_TEST -ne '1') {
    try { Install-Rms $args }
    catch {
        if ("$_" -ne 'CorralBusiness - RMS was not installed.') { Write-Host "XX  $_" -ForegroundColor Red }
        Write-Host 'CorralBusiness - RMS was not installed. Nothing in the restaurant data was changed.' -ForegroundColor Red
        if ($PSCommandPath) { exit 1 }
    } finally {
        if (-not $PSCommandPath) { Set-StrictMode -Off; $ErrorActionPreference = 'Continue' }   # the one-line form runs in the user's window
    }
}
