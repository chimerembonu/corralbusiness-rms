# Checks for the Windows installer (install.ps1), runnable anywhere PowerShell 7 is installed:
#   pwsh -File tests/Installer/InstallPs1Checks.ps1
# GitHub runs it on every change (.github/workflows/tests.yml). Only the parts that need Windows itself
# (network address, starting Docker Desktop, the firewall) cannot be checked here.
$ErrorActionPreference = 'Stop'
$repo = (Resolve-Path (Join-Path $PSScriptRoot '../..')).Path
$failures = 0
function Check([string]$name, [bool]$ok) { if ($ok) { Write-Host "ok   $name" } else { Write-Host "FAIL $name"; $script:failures++ } }

# 1. The whole script parses
$tokens = $null; $errors = $null
[void][System.Management.Automation.Language.Parser]::ParseFile("$repo/install.ps1", [ref]$tokens, [ref]$errors)
Check 'install.ps1 parses with no errors' ($errors.Count -eq 0)
$errors | ForEach-Object { Write-Host "     $_" }

# 2. Load only the functions
$env:RMS_INSTALLER_TEST = '1'
. "$repo/install.ps1"
Set-StrictMode -Off

# 3. Options
Read-Options @('-Branch', 'complete-edition', '-Port', '8090', '-Dir', 'some/dir')
Check 'options are read' ($Script:Branch -eq 'complete-edition' -and $Script:WantPort -eq '8090' -and $Script:Target -eq 'some/dir')
Read-Options $null
Check 'no options is fine' ($Script:Branch -eq 'complete-edition')

# 4. The settings file
$lines = New-EnvLines "$repo/.env.docker.example" '192.168.8.40' 80 443 'C:\CorralBusiness-RMS\usb-copies'
Check 'settings come back as a list that can grow' ($lines -is [System.Collections.Generic.List[string]])
$tmp = [System.IO.Path]::GetTempPath()
$out = Join-Path $tmp 'rms-test.env'
Save-EnvLines $out $lines
$bytes = [System.IO.File]::ReadAllBytes($out)
Check 'no byte-order mark' (-not ($bytes[0] -eq 0xEF -and $bytes[1] -eq 0xBB))
Check 'no Windows line ends' (-not ($bytes -contains 13))
$back = Read-EnvLines $out
Check 'APP_URL has no port for 80' ((Get-EnvValue $back 'APP_URL') -eq 'http://192.168.8.40')
Check 'bound to the LAN address' ((Get-EnvValue $back 'RMS_BIND_IP') -eq '192.168.8.40')
$key = (Get-EnvValue $back 'APP_KEY')
Check 'APP_KEY is base64 of 32 bytes' ($key.StartsWith('base64:') -and [Convert]::FromBase64String($key.Substring(7)).Length -eq 32)
Check 'setup code is 6 digits' ((Get-EnvValue $back 'RMS_SETUP_CODE') -match '^\d{6}$')
foreach ($k in 'DB_PASSWORD', 'DB_ROOT_PASSWORD', 'RMS_BACKUP_PASSWORD', 'REVERB_APP_SECRET') { Check "$k is 20+ letters and digits" ((Get-EnvValue $back $k) -match '^[A-Za-z0-9]{20,}$') }
Check 'passwords differ' ((Get-EnvValue $back 'DB_PASSWORD') -ne (Get-EnvValue $back 'DB_ROOT_PASSWORD'))
Check 'printer network' ((Get-EnvValue $back 'RMS_PRINTER_SUBNET') -eq '192.168.8.0/24')
Check 'USB folder with forward slashes' ((Get-EnvValue $back 'RMS_USB_DIR') -eq 'C:/CorralBusiness-RMS/usb-copies')
Check 'name with its dash kept' ((Get-EnvValue $back 'APP_NAME') -eq '"CorralBusiness ' + [char]0x2013 + ' RMS"')
$templateKeys = (Read-EnvLines "$repo/.env.docker.example") | Where-Object { $_ -match '^[A-Z_]+=' } | ForEach-Object { ($_ -split '=')[0] }
$outKeys = $back | Where-Object { $_ -match '^[A-Z_]+=' } | ForEach-Object { ($_ -split '=')[0] }
Check 'every template setting is still there, once' (@($templateKeys | Where-Object { $outKeys -notcontains $_ }).Count -eq 0 -and @($outKeys | Group-Object | Where-Object Count -gt 1).Count -eq 0)
$lines2 = New-EnvLines "$repo/.env.docker.example" '10.0.0.5' 8080 8443 'C:\x'
Check 'APP_URL with port 8080' ((Get-EnvValue $lines2 'APP_URL') -eq 'http://10.0.0.5:8080')
Check 'each install gets its own keys' ((Get-EnvValue $lines2 'APP_KEY') -ne $key)

# 5. Updating keeps the restaurant's files and never nests folders
$src = Join-Path $tmp 'rms-src'; $dst = Join-Path $tmp 'rms-dst'
Remove-Item -Recurse -Force $src, $dst -ErrorAction SilentlyContinue
foreach ($f in 'app/new.php', 'docker/nginx/a.conf', 'docker/certs/public/server.crt', 'docker/ca/ca.key', '.env', 'usb-copies/b.zip', 'artisan', 'docker-compose.yml') {
    New-Item -ItemType File -Force -Path "$src/$f" -Value "new $f" | Out-Null }
foreach ($f in 'app/old.php', 'docker/nginx/a.conf', 'docker/certs/public/server.crt', 'docker/ca/ca.key', '.env', 'usb-copies/keep.zip') {
    New-Item -ItemType File -Force -Path "$dst/$f" -Value "old $f" | Out-Null }
Copy-Code $src $dst
Check 'new code arrives' ((Get-Content "$dst/app/new.php") -eq 'new app/new.php' -and (Get-Content "$dst/docker/nginx/a.conf") -eq 'new docker/nginx/a.conf')
Check 'no folder inside itself' (-not (Test-Path "$dst/app/app") -and -not (Test-Path "$dst/docker/docker"))
Check '.env kept' ((Get-Content "$dst/.env") -eq 'old .env')
Check 'certificates and CA key kept' ((Get-Content "$dst/docker/certs/public/server.crt") -eq 'old docker/certs/public/server.crt' -and (Get-Content "$dst/docker/ca/ca.key") -eq 'old docker/ca/ca.key')
Check 'backup copies kept, none brought in' ((Test-Path "$dst/usb-copies/keep.zip") -and -not (Test-Path "$dst/usb-copies/b.zip"))
Check 'code folder recognised' (Test-CodeFolder $dst)
Check 'same folder recognised' (Test-SamePath "$dst/" $dst)

# 6. Ports and refusing to run outside Windows
Check 'a free port is free' (-not (Test-PortBusy '127.0.0.1' 47123))
$l = New-Object System.Net.Sockets.TcpListener([System.Net.IPAddress]::Any, 47124); $l.Start()
Check 'a used port is busy' (Test-PortBusy '127.0.0.1' 47124)
Check 'next free port is found' ((Find-FreePort '127.0.0.1' 47124) -eq 47125)
$l.Stop()
$refused = $false
try { Install-Rms @() } catch { $refused = $true }
Check 'refuses to run on Linux (it is for Windows)' $refused

Write-Host ''
if ($failures) { Write-Host "$failures check(s) FAILED"; exit 1 } else { Write-Host 'All installer checks passed.' }
