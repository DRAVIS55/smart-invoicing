$ErrorActionPreference = 'Stop'
$ProjectDir = $PSScriptRoot
$DefaultPort = 8081
Set-Location $ProjectDir

function Write-Info([string]$Message) { Write-Host "`n[Ledger] $Message" -ForegroundColor Cyan }
function Write-Warn([string]$Message) { Write-Host "[Ledger] $Message" -ForegroundColor Yellow }
function Stop-WithError([string]$Message) {
    Write-Host "`n[Ledger error] $Message" -ForegroundColor Red
    if (Get-Command docker -ErrorAction SilentlyContinue) {
        Write-Host "`nCompose status:" -ForegroundColor DarkYellow
        & docker compose ps
        Write-Host "`nRecent service logs:" -ForegroundColor DarkYellow
        & docker compose logs --tail=40 web db
    }
    exit 1
}
function Invoke-DockerCompose([string[]]$Arguments) {
    & docker compose @Arguments
    if ($LASTEXITCODE -ne 0) { throw "docker compose $($Arguments -join ' ') failed with exit code $LASTEXITCODE." }
}
function Get-EnvValue([string]$Name) {
    if (-not (Test-Path '.env')) { return '' }
    $line = Get-Content '.env' | Where-Object { $_ -match "^$([regex]::Escape($Name))=" } | Select-Object -First 1
    if (-not $line) { return '' }
    return (($line -split '=', 2)[1]).Trim().Trim('"').Trim("'")
}
function Set-EnvValue([string]$Name, [string]$Value) {
    $lines = @()
    if (Test-Path '.env') { $lines = @(Get-Content '.env') }
    $pattern = '^' + [regex]::Escape($Name) + '='
    $found = $false
    for ($i = 0; $i -lt $lines.Count; $i++) {
        if ($lines[$i] -match $pattern) { $lines[$i] = "$Name=$Value"; $found = $true }
    }
    if (-not $found) { $lines += "$Name=$Value" }
    [System.IO.File]::WriteAllLines((Join-Path $ProjectDir '.env'), [string[]]$lines, [System.Text.UTF8Encoding]::new($false))
}
function New-RandomSecret {
    $bytes = New-Object byte[] 24
    $generator = [System.Security.Cryptography.RandomNumberGenerator]::Create()
    try { $generator.GetBytes($bytes) } finally { $generator.Dispose() }
    return ([System.BitConverter]::ToString($bytes) -replace '-', '').ToLowerInvariant()
}
function Test-PortInUse([int]$Port, [int]$CurrentAppPort) {
    if ($CurrentAppPort -eq $Port) { return $false }
    return [bool](Get-NetTCPConnection -LocalPort $Port -State Listen -ErrorAction SilentlyContinue | Select-Object -First 1)
}

try {
    if (-not (Test-Path 'docker-compose.yml') -or -not (Test-Path 'Dockerfile')) {
        Stop-WithError 'Place this script in the Ledger project root next to docker-compose.yml and Dockerfile.'
    }
    if (-not (Get-Command docker -ErrorAction SilentlyContinue)) {
        $downloadUrl = 'https://www.docker.com/products/docker-desktop/'
        Write-Warn 'Docker Desktop is required to run this containerized app on Windows.'
        Write-Host "Install Docker Desktop from $downloadUrl, start it, then run this script again."
        Start-Process $downloadUrl
        exit 1
    }

    $composeVersion = & docker compose version 2>$null
    if ($LASTEXITCODE -ne 0) { Stop-WithError 'Docker Compose is unavailable. Update or reinstall Docker Desktop, then rerun this script.' }

    $dockerInfo = $null
    try { $dockerInfo = & docker info 2>$null } catch { $dockerInfo = $null }
    if ($LASTEXITCODE -ne 0 -or -not $dockerInfo) {
        $desktopPaths = @(
            (Join-Path $env:ProgramFiles 'Docker\Docker\Docker Desktop.exe'),
            (Join-Path $env:LOCALAPPDATA 'Programs\Docker\Docker\Docker Desktop.exe')
        )
        $desktopExe = $desktopPaths | Where-Object { Test-Path $_ } | Select-Object -First 1
        if ($desktopExe) {
            Write-Info 'Starting Docker Desktop. Waiting for its engine to become ready...'
            Start-Process $desktopExe
            $ready = $false
            for ($i = 0; $i -lt 60; $i++) {
                Start-Sleep -Seconds 2
                & docker info *> $null
                if ($LASTEXITCODE -eq 0) { $ready = $true; break }
            }
            if (-not $ready) { Stop-WithError 'Docker Desktop did not become ready. Open Docker Desktop, resolve any startup prompt, and run this script again.' }
        } else {
            Stop-WithError 'Docker Desktop is installed but its engine is stopped. Open Docker Desktop, wait until it says it is running, then rerun this script.'
        }
    }

    if (-not (Test-Path '.env')) {
        Write-Info 'Creating local settings and a secure recovery password.'
        @(
            'APP_USERNAME=admin',
            "APP_PASSWORD=$(New-RandomSecret)",
            'APP_CURRENCY=KES',
            "APP_PORT=$DefaultPort"
        ) | Set-Content -Path '.env' -Encoding Ascii
    }
    $recoveryPassword = Get-EnvValue 'APP_PASSWORD'
    if ([string]::IsNullOrWhiteSpace($recoveryPassword) -or $recoveryPassword -in @('replace-before-deploy', 'change-me-now')) {
        $recoveryPassword = New-RandomSecret
        Set-EnvValue 'APP_PASSWORD' $recoveryPassword
        Write-Info 'Replaced the sample recovery password with a generated one.'
    }

    $portText = Get-EnvValue 'APP_PORT'
    if ([string]::IsNullOrWhiteSpace($portText)) { $portText = "$DefaultPort" }
    $port = 0
    if (-not [int]::TryParse($portText, [ref]$port) -or $port -lt 1024 -or $port -gt 65535) {
        Stop-WithError 'APP_PORT in .env must be a number between 1024 and 65535.'
    }

    $currentAppPort = 0
    $webId = (& docker compose ps -q web 2>$null | Select-Object -First 1)
    if ($LASTEXITCODE -eq 0 -and $webId) {
        $published = & docker port $webId 80/tcp 2>$null
        foreach ($binding in $published) {
            if ($binding -match ':(\d+)$') { $currentAppPort = [int]$Matches[1]; break }
        }
    }
    if (Test-PortInUse -Port $port -CurrentAppPort $currentAppPort) {
        $requestedPort = $port
        while ($port -le 65535 -and (Test-PortInUse -Port $port -CurrentAppPort $currentAppPort)) { $port++ }
        if ($port -gt 65535) { Stop-WithError 'No available TCP port was found. Set APP_PORT in .env and rerun.' }
        Set-EnvValue 'APP_PORT' "$port"
        Write-Warn "Port $requestedPort is already in use. The app will use port $port instead."
    }

    Write-Info "Validating the Compose configuration ($composeVersion)."
    Invoke-DockerCompose -Arguments @('config', '--quiet')
    Write-Info 'Building and starting Apache/PHP and MySQL. The database volume is preserved between runs.'
    Invoke-DockerCompose -Arguments @('up', '-d', '--build')

    Write-Info 'Waiting for MySQL health check...'
    $dbId = (& docker compose ps -q db | Select-Object -First 1)
    if (-not $dbId) { Stop-WithError 'The MySQL container was not created.' }
    $dbReady = $false
    for ($i = 0; $i -lt 60; $i++) {
        $health = & docker inspect --format '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}' $dbId 2>$null
        if ($health -eq 'healthy') { $dbReady = $true; break }
        Start-Sleep -Seconds 2
    }
    if (-not $dbReady) { Stop-WithError 'MySQL did not become healthy within 120 seconds.' }

    $url = "http://localhost:$port/"
    Write-Info 'Checking the homepage over HTTP...'
    $siteReady = $false
    for ($i = 0; $i -lt 45; $i++) {
        try {
            $response = Invoke-WebRequest -Uri $url -UseBasicParsing -TimeoutSec 5
            if ($response.StatusCode -eq 200) { $siteReady = $true; break }
        } catch { }
        Start-Sleep -Seconds 2
    }
    if (-not $siteReady) { Stop-WithError "The app did not return HTTP 200 at $url." }

    Write-Info "Opening $url in your browser."
    Start-Process $url
    $recoveryUsername = Get-EnvValue 'APP_USERNAME'
    if ([string]::IsNullOrWhiteSpace($recoveryUsername)) { $recoveryUsername = 'admin' }
    Write-Host "`nLedger is ready to preview." -ForegroundColor Green
    Write-Host "  Homepage:       $url"
    Write-Host "  Sign in:        http://localhost:$port/login"
    Write-Host "  Owner setup:    http://localhost:$port/register"
    Write-Host "  Recovery login: $recoveryUsername / $recoveryPassword"
    Write-Host "`nCreate the workspace owner account from the homepage. The recovery login is stored locally in .env."
    Write-Host 'To stop the app later, run: docker compose down'
} catch {
    Stop-WithError $_.Exception.Message
}
