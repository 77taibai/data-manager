param(
    [string]$BaseUrl = 'http://127.0.0.1:8000',
    [Parameter(Mandatory = $true)][string]$Password,
    [string]$RepoSlug = ''
)

$ErrorActionPreference = 'Stop'
$script:passed = 0
$script:failed = 0

function Write-Result {
    param([string]$Name, [bool]$Ok, [string]$Detail = '')
    if ($Ok) {
        $script:passed++
        Write-Host ("PASS - {0} {1}" -f $Name, $Detail) -ForegroundColor Green
    } else {
        $script:failed++
        Write-Host ("FAIL - {0} {1}" -f $Name, $Detail) -ForegroundColor Red
    }
}

function Get-Csrf {
    param([string]$Html)
    $m = [regex]::Match($Html, 'name="_token"\s+value="([^"]+)"')
    if ($m.Success) { return $m.Groups[1].Value }
    return ''
}

function Invoke-Dlm {
    param(
        [string]$Method,
        [string]$Path,
        [hashtable]$Body,
        [object]$Session,
        [switch]$NoRedirect
    )
    $uri = [System.Uri]::new([System.Uri]$BaseUrl, $Path)

    if ($NoRedirect) {
        $request = [System.Net.HttpWebRequest]::Create($uri)
        $request.Method = $Method
        $request.AllowAutoRedirect = $false
        $request.CookieContainer = $Session.Cookies
        $request.UserAgent = 'dlm-smoke'
        if ($Body) {
            $pairs = @()
            foreach ($k in $Body.Keys) {
                $pairs += ([System.Uri]::EscapeDataString([string]$k) + '=' + [System.Uri]::EscapeDataString([string]$Body[$k]))
            }
            $bytes = [System.Text.Encoding]::UTF8.GetBytes(($pairs -join '&'))
            $request.ContentType = 'application/x-www-form-urlencoded'
            $request.ContentLength = $bytes.Length
            $stream = $request.GetRequestStream()
            $stream.Write($bytes, 0, $bytes.Length)
            $stream.Close()
        }
        try {
            $response = $request.GetResponse()
        } catch [System.Net.WebException] {
            $response = $_.Exception.Response
            if ($null -eq $response) { throw }
        }
        $reader = New-Object System.IO.StreamReader($response.GetResponseStream())
        $content = $reader.ReadToEnd()
        $reader.Close()
        $location = [string]$response.Headers['Location']
        $status = [int]$response.StatusCode
        $response.Close()
        return [pscustomobject]@{ Status = $status; Content = $content; Location = $location }
    }

    $params = @{
        Uri             = $uri
        Method          = $Method
        WebSession      = $Session
        UseBasicParsing = $true
    }
    if ($Body) { $params['Body'] = $Body }
    try {
        $resp = Invoke-WebRequest @params
        return [pscustomobject]@{
            Status   = [int]$resp.StatusCode
            Content  = [string]$resp.Content
            Location = [string]$resp.Headers['Location']
        }
    } catch {
        $response = $_.Exception.Response
        if ($null -ne $response) {
            $content = ''
            try {
                $stream = $response.GetResponseStream()
                $reader = New-Object System.IO.StreamReader($stream)
                $content = $reader.ReadToEnd()
                $reader.Close()
            } catch { }
            return [pscustomobject]@{
                Status   = [int]$response.StatusCode
                Content  = $content
                Location = [string]$response.Headers['Location']
            }
        }
        throw
    }
}

$session = New-Object Microsoft.PowerShell.Commands.WebRequestSession

$r = Invoke-Dlm -Method 'GET' -Path '/healthz' -Session $session
$healthOk = $false
$healthDetail = "status=$($r.Status)"
try {
    $json = $r.Content | ConvertFrom-Json
    $healthOk = ($r.Status -eq 200 -and $json.status -eq 'ok' -and $json.checks.web.status -eq 'ok' -and $json.checks.database.status -eq 'ok')
    $healthDetail += " body=$($json.status)/web:$($json.checks.web.status)/db:$($json.checks.database.status)"
} catch {
    $healthDetail += ' json-parse-error'
}
Write-Result '健康检查 /healthz 无需登录且 WEB+DB 正常' $healthOk $healthDetail

$anon = Invoke-Dlm -Method 'GET' -Path '/' -Session $session -NoRedirect
Write-Result '未登录访问首页被重定向到登录' ($anon.Status -eq 302 -and $anon.Location -match '/login') "status=$($anon.Status) loc=$($anon.Location)"

$r = Invoke-Dlm -Method 'GET' -Path '/login' -Session $session
$token = Get-Csrf $r.Content
Write-Result '获取登录页与 CSRF' ($r.Status -eq 200 -and $token -ne '') "status=$($r.Status)"

$r = Invoke-Dlm -Method 'POST' -Path '/login' -Body @{ _token = $token; password = $Password } -Session $session -NoRedirect
Write-Result '登录返回 302' ($r.Status -eq 302) "status=$($r.Status)"

$r = Invoke-Dlm -Method 'GET' -Path '/' -Session $session
$homeOk = ($r.Status -eq 200)
$token = Get-Csrf $r.Content
Write-Result '登录后可访问首页' ($homeOk -and $token -ne '') "status=$($r.Status)"

$repoName = 'smoke-' + (Get-Random -Minimum 10000 -Maximum 99999)
$r = Invoke-Dlm -Method 'POST' -Path '/repos' -Body @{
    _token      = $token
    name        = $repoName
    slug        = $RepoSlug
    description = 'smoke test'
} -Session $session -NoRedirect
Write-Result '新建存储库' ($r.Status -eq 302) "status=$($r.Status)"

$slug = $RepoSlug
if ($slug -eq '') {
    $r = Invoke-Dlm -Method 'GET' -Path '/' -Session $session
    $escaped = [regex]::Escape($repoName)
    $m = [regex]::Match($r.Content, '<h3>' + $escaped + '</h3>[\s\S]*?href="[^"]*?/repos/([^"/?#]+)"')
    if ($m.Success) { $slug = $m.Groups[1].Value }
}
Write-Result '解析存储库 slug' ($slug -ne '') "slug=$slug"

$basePath = '/repos/' + $slug

$r = Invoke-Dlm -Method 'POST' -Path ($basePath + '/attributes') -Body @{
    _token      = $token
    label       = '颜色'
    key         = 'color'
    type        = 'text'
    required    = '0'
    constrained = '1'
    sort_order  = '0'
    options     = "红`n蓝"
} -Session $session -NoRedirect
Write-Result '新建属性 color' ($r.Status -eq 302) "status=$($r.Status)"

$r = Invoke-Dlm -Method 'POST' -Path ($basePath + '/attributes') -Body @{
    _token      = $token
    label       = '备注'
    key         = 'note'
    type        = 'text'
    required    = '0'
    constrained = '0'
    sort_order  = '1'
    options     = ''
} -Session $session -NoRedirect
Write-Result '新建属性 note' ($r.Status -eq 302) "status=$($r.Status)"

$r = Invoke-Dlm -Method 'POST' -Path ($basePath + '/links') -Body @{
    _token             = $token
    url                = 'http://example.com/a'
    'attributes[color]' = '红'
    'attributes[note]' = 'TODO-TEST'
} -Session $session -NoRedirect
Write-Result '新增链接' ($r.Status -eq 302) "status=$($r.Status)"

$r = Invoke-Dlm -Method 'GET' -Path $basePath -Session $session
Write-Result '文本属性中的大写T未被破坏' ($r.Content -match 'TODO-TEST' -and -not ($r.Content -match ' ODO-TEST')) "status=$($r.Status)"

$query = 'filter%5Bcolor%5D=' + [System.Uri]::EscapeDataString('红') + '&q=example'
$r = Invoke-Dlm -Method 'GET' -Path ($basePath + '?' + $query) -Session $session
Write-Result '过滤与关键词查询' ($r.Status -eq 200 -and $r.Content -match 'example\.com') "status=$($r.Status)"

$r = Invoke-Dlm -Method 'GET' -Path ($basePath + '/export') -Session $session
Write-Result '导出 CSV' ($r.Status -eq 200 -and $r.Content -match 'color' -and $r.Content -match 'url') "status=$($r.Status)"

$csv = "url,color`nhttps://example.com/b,蓝"
$r = Invoke-Dlm -Method 'POST' -Path ($basePath + '/import/preview') -Body @{ _token = $token; csv = $csv } -Session $session
$previewOk = $false
$previewDetail = "status=$($r.Status)"
try {
    $json = $r.Content | ConvertFrom-Json
    $previewOk = ($json.code -eq 0)
    if ($json.data) { $previewDetail += " total=$($json.data.total)" }
} catch {
    $previewDetail += ' json-parse-error'
}
Write-Result '导入预览返回 JSON' $previewOk $previewDetail

$r = Invoke-Dlm -Method 'GET' -Path $basePath -Session $session
$idMatch = [regex]::Match($r.Content, 'name="ids\[\]" value="(\d+)"')
$linkId = ''
if ($idMatch.Success) { $linkId = $idMatch.Groups[1].Value }

$bulkOk = $false
$bulkDetail = 'link-id-not-found'
if ($linkId -ne '') {
    $r = Invoke-Dlm -Method 'POST' -Path ($basePath + '/links/bulk') -Body @{
        _token     = $token
        action     = 'set_attr'
        attr_key   = 'color'
        attr_value = '绿'
        'ids[]'    = $linkId
    } -Session $session -NoRedirect
    $bulkOk = ($r.Status -eq 302)
    $bulkDetail = "status=$($r.Status)"
}
Write-Result '批量修改属性' $bulkOk $bulkDetail

$bulkExportOk = $false
$bulkExportDetail = 'link-id-not-found'
if ($linkId -ne '') {
    $r = Invoke-Dlm -Method 'POST' -Path ($basePath + '/links/bulk') -Body @{
        _token  = $token
        action  = 'export'
        'ids[]' = $linkId
    } -Session $session
    $bulkExportOk = ($r.Status -eq 200 -and $r.Content -match 'url' -and $r.Content -match 'color')
    $bulkExportDetail = "status=$($r.Status)"
}
Write-Result '批量导出选中为 CSV' $bulkExportOk $bulkExportDetail

$selectedExportOk = $false
$selectedExportDetail = 'link-id-not-found'
if ($linkId -ne '') {
    $r = Invoke-Dlm -Method 'GET' -Path ($basePath + '/export?ids=' + $linkId) -Session $session
    $lines = @($r.Content -split "`r?`n" | Where-Object { $_.Trim() -ne '' })
    $selectedExportOk = ($r.Status -eq 200 -and $lines.Count -eq 2)
    $selectedExportDetail = "status=$($r.Status) lines=$($lines.Count)"
}
Write-Result '导出指定 ids 仅含表头与选中行' $selectedExportOk $selectedExportDetail

$r = Invoke-Dlm -Method 'GET' -Path '/keys' -Session $session
$repoIdMatch = [regex]::Match($r.Content, '<select name="repository_id"[\s\S]*?<option value="(\d+)"')
$repoId = ''
if ($repoIdMatch.Success) { $repoId = $repoIdMatch.Groups[1].Value }

$keyOk = $false
$keyDetail = 'repo-id-not-found'
if ($repoId -ne '') {
    $r = Invoke-Dlm -Method 'POST' -Path '/keys' -Body @{
        _token        = $token
        repository_id = $repoId
        name          = 'smoke-key'
        permissions   = 'read'
        expires_at    = ''
    } -Session $session
    $tokenMatch = [regex]::Match($r.Content, 'dlm_[0-9a-f]{40}')
    $keyOk = ($r.Status -eq 200 -and $tokenMatch.Success)
    $keyDetail = "status=$($r.Status)"
}
Write-Result '创建 APIKEY 并返回明文' $keyOk $keyDetail

$r = Invoke-Dlm -Method 'POST' -Path '/logout' -Body @{ _token = $token } -Session $session -NoRedirect
Write-Result '退出登录' ($r.Status -eq 302) "status=$($r.Status)"

Write-Host ''
Write-Host ("汇总：PASS={0} FAIL={1}" -f $script:passed, $script:failed)
if ($script:failed -gt 0) { exit 1 }
exit 0
