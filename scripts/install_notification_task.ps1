$ErrorActionPreference = 'Stop'

try {
    $projectPath = Split-Path -Parent $PSScriptRoot
    $workerPath = Join-Path $PSScriptRoot 'run_billing.php'
    if (!(Test-Path -LiteralPath $workerPath)) {
        throw 'The notification worker is missing. Copy the whole project before running setup.'
    }

    # Find the XAMPP containing this project, including custom drives/folders.
    $phpPath = $null
    $ancestor = Get-Item -LiteralPath $projectPath
    while ($null -ne $ancestor) {
        $candidate = Join-Path $ancestor.FullName 'php\php.exe'
        if (Test-Path -LiteralPath $candidate) {
            $phpPath = $candidate
            break
        }
        $ancestor = $ancestor.Parent
    }
    if (!$phpPath -and (Test-Path -LiteralPath 'C:\xampp\php\php.exe')) {
        $phpPath = 'C:\xampp\php\php.exe'
    }
    if (!$phpPath) {
        $phpCommand = Get-Command php.exe -ErrorAction SilentlyContinue
        if ($phpCommand) { $phpPath = $phpCommand.Source }
    }
    if (!$phpPath) {
        throw 'PHP was not found. Place the project inside your XAMPP htdocs folder and run setup again.'
    }

    Write-Host "Project: $projectPath"
    Write-Host "PHP: $phpPath"
    Write-Host 'Checking database connection (no messages will be sent during setup)...'
    $databaseConfig = Join-Path $projectPath 'config\database.php'
    & $phpPath -r 'require $argv[1]; getDbConnection();' $databaseConfig
    if ($LASTEXITCODE -ne 0) {
        throw 'Database check failed. Start MySQL, import the project database, and check config/database.php.'
    }

    $taskName = 'CloudInterweb Billing Automation'
    $action = New-ScheduledTaskAction -Execute $phpPath -Argument ('"' + $workerPath + '" --scheduled') -WorkingDirectory $projectPath
    $trigger = New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(1) -RepetitionInterval (New-TimeSpan -Minutes 1)
    $identity = [System.Security.Principal.WindowsIdentity]::GetCurrent().Name
    $principal = New-ScheduledTaskPrincipal -UserId $identity -LogonType Interactive -RunLevel Limited
    $settings = New-ScheduledTaskSettingsSet -StartWhenAvailable -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -MultipleInstances IgnoreNew -ExecutionTimeLimit (New-TimeSpan -Hours 1)
    Register-ScheduledTask -TaskName $taskName -Action $action -Trigger $trigger -Principal $principal -Settings $settings -Description 'Checks every minute; sends at the Philippine time configured in Billing Automation settings.' -Force | Out-Null

    Write-Host ''
    Write-Host 'Notification scheduled task installed successfully.' -ForegroundColor Green
    Write-Host 'It checks every minute and respects the sending time in Billing Automation settings.'
    Write-Host 'Keep MySQL running, internet connected, and this Windows user logged in (locking the screen is fine).'
    Write-Host 'The laptop must be awake. SMS also requires the configured Android phone to be online.'
    Write-Host 'If you move the project again, rerun this batch file to update the task paths.'
    exit 0
}
catch {
    Write-Host ''
    Write-Host ('Setup failed: ' + $_.Exception.Message) -ForegroundColor Red
    Write-Host 'If Windows denied task registration, right-click the batch file and choose Run as administrator using the same Windows account.'
    exit 1
}
