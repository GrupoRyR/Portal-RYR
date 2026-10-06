<#
.SYNOPSIS
    Crea en un inquilino de Microsoft 365 los grupos de seguridad APP-... que usa el Portal RYR.

.DESCRIPTION
    Se conecta a Microsoft Graph en el inquilino indicado y crea solo los grupos que falten
    (grupos de seguridad, sin correo). Si un grupo ya existe, lo deja como esta.
    Se puede ejecutar varias veces sin efectos secundarios.

    Requisitos: PowerShell 5.1 o 7, el modulo Microsoft.Graph
    (Install-Module Microsoft.Graph -Scope CurrentUser) y una cuenta con permiso para
    crear grupos (por ejemplo, Administrador de grupos o Administrador global).

.EXAMPLE
    .\crear-grupos-app.ps1 -TenantId 54f53029-e6e2-4b5f-a862-3454ae886468

.EXAMPLE
    .\crear-grupos-app.ps1 -TenantId 54f53029-e6e2-4b5f-a862-3454ae886468 -Grupos 'APP-PortalAdmin','APP-TomaPedidos'
#>
param(
    [Parameter(Mandatory)][string]$TenantId,
    [string[]]$Grupos = @('APP-PortalAdmin')
)

$ErrorActionPreference = 'Stop'

if ($TenantId -notmatch '^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$') {
    throw "El ID de inquilino '$TenantId' no tiene el formato de un GUID."
}

Connect-MgGraph -TenantId $TenantId -Scopes Group.ReadWrite.All -NoWelcome

$creados = @()
$existentes = @()

foreach ($nombre in $Grupos) {
    $nombre = $nombre.Trim()
    if ($nombre -eq '') { continue }

    # Comillas simples escapadas para el filtro OData.
    $filtro = "displayName eq '{0}'" -f ($nombre -replace "'", "''")
    $encontrado = Get-MgGroup -Filter $filtro -All

    if ($encontrado) {
        $existentes += $nombre
        Write-Host "Ya existe:  $nombre"
        continue
    }

    # MailNickname: solo letras y numeros (requisito de Microsoft Graph).
    $alias = ($nombre -replace '[^A-Za-z0-9]', '')
    if ($alias -eq '') { $alias = 'grupo' + [guid]::NewGuid().ToString('N').Substring(0, 8) }

    New-MgGroup -DisplayName $nombre `
        -Description 'Grupo de acceso del Portal RYR' `
        -SecurityEnabled `
        -MailEnabled:$false `
        -MailNickname $alias | Out-Null

    $creados += $nombre
    Write-Host "Creado:     $nombre"
}

Write-Host ''
Write-Host ("Grupos creados: {0}. Ya existentes: {1}." -f $creados.Count, $existentes.Count)
