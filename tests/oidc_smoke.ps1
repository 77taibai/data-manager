$ErrorActionPreference = 'Stop'
$Base = 'http://127.0.0.1:8126'
$Idp = 'http://127.0.0.1:8125'

$script:pass = 0
$script:fail = 0

function Check {
    param([string]$Name, [bool]$Ok, [string]$Detail = '')
    if ($Ok) {
        $script:pass++
        Write-Host ("PASS  " + $Name) -ForegroundColor Green
    } else {
        $script:fail++
        $msg = "FAIL  " + $Name
        if ($Detail -ne '') { $msg += "  -> " + $Detail }
        Write-Host $msg -ForegroundColor Red
    }
}

function Get-Csrf {
    param([string]$Html)
    $m = [regex]::Match($Html, 'name="_token"\s+value="([^"]+)"')
    if ($m.Success) { return $m.Groups[1].Value }
    return ''
}

function New-Session { New-Object Microsoft.PowerShell.Commands.WebRequestSession }

# 跟随重定向关闭，便于断言 302
function Request {
    param([string]$Method, [string]$Url, [object]$Session, [hashtable]$Body, [switch]$NoRedirect)
    $uri = [System.Uri]::new($Url)
    $req = [System.Net.HttpWebRequest]::Create($uri)
    $req.Method = $Method
    $req.AllowAutoRedirect = -not $NoRedirect
    $req.CookieContainer = $Session.Cookies
    $req.UserAgent = 'dlm-oidc-smoke'
    if ($Body) {
        $pairs = @()
        foreach ($k in $Body.Keys) {
            $pairs += ([System.Uri]::EscapeDataString([string]$k) + '=' + [System.Uri]::EscapeDataString([string]$Body[$k]))
        }
        $bytes = [System.Text.Encoding]::UTF8.GetBytes(($pairs -join '&'))
        $req.ContentType = 'application/x-www-form-urlencoded'
        $req.ContentLength = $bytes.Length
        $s = $req.GetRequestStream()
        $s.Write($bytes, 0, $bytes.Length)
        $s.Close()
    }
    try {
        $resp = $req.GetResponse()
    } catch [System.Net.WebException] {
        $resp = $_.Exception.Response
        if ($null -eq $resp) { throw }
    }
    $reader = New-Object System.IO.StreamReader($resp.GetResponseStream())
    $content = $reader.ReadToEnd()
    $reader.Close()
    $loc = [string]$resp.Headers['Location']
    $status = [int]$resp.StatusCode
    $resp.Close()
    return [pscustomobject]@{ Status = $status; Content = $content; Location = $loc }
}

$dbName = 'db_ka2t5d'
$dbUser = 'u_fsh2x8'
$dbPass = 'vd43Pte4if868hn#'

# --- 安装（启用 OIDC，不设密码）---
$s = New-Session
$r = Request 'GET' "$Base/install" $s
$token = Get-Csrf $r.Content
$r = Request 'POST' "$Base/install" $s @{
    _token             = $token
    db_host            = '127.0.0.1'
    db_port            = '3306'
    db_name            = $dbName
    db_user            = $dbUser
    db_pass            = $dbPass
    oidc_enabled       = '1'
    oidc_issuer        = $Idp
    oidc_client_id     = 'dlm-client'
    oidc_client_secret = 'test-secret'
    oidc_redirect_uri  = "$Base/oidc/callback"
    oidc_scope         = 'openid email profile'
    oidc_allowed_subs  = "user-123"
} -NoRedirect
Check '安装（仅 OIDC）返回 302' ($r.Status -eq 302) "status=$($r.Status) body=$($r.Content.Substring(0,[Math]::Min(300,$r.Content.Length)))"

$cfg = 'C:\Users\taiba\AppData\Local\Temp\opencode\app-oidc\config.php'
$cfgText = if (Test-Path $cfg) { [System.IO.File]::ReadAllText($cfg) } else { '' }
Check 'config.php 写入 oidc 段' ($cfgText -match "'oidc'" -and $cfgText -match 'allowed_subs')
Check '未设密码时 admin 段无 password_hash' (-not ($cfgText -match 'password_hash'))

# --- 登录页出现 OIDC 按钮，且无密码框 ---
$s = New-Session
$r = Request 'GET' "$Base/login" $s
Check '登录页显示 OIDC 登录按钮' ($r.Content -match '/oidc/start')
Check '未设密码时登录页无密码框' (-not ($r.Content -match 'name="password"'))

# --- 未登录访问首页被拦截 ---
$r = Request 'GET' "$Base/" $s -NoRedirect
Check '未登录访问首页重定向到 /login' ($r.Status -eq 302 -and $r.Location -match '/login') "status=$($r.Status) loc=$($r.Location)"

# --- OIDC 完整流程（白名单内的 sub）---
$s = New-Session
$r = Request 'GET' "$Base/oidc/start" $s -NoRedirect
Check 'oidc/start 返回 302 到 IdP' ($r.Status -eq 302 -and $r.Location -like "$Idp/authorize*") "status=$($r.Status) loc=$($r.Location)"
$authorizeUrl = $r.Location
Check '授权地址含 PKCE S256' ($authorizeUrl -match 'code_challenge_method=S256' -and $authorizeUrl -match 'code_challenge=')
Check '授权地址含 state 与 nonce' ($authorizeUrl -match 'state=' -and $authorizeUrl -match 'nonce=')

$r = Request 'GET' $authorizeUrl $s -NoRedirect
Check 'IdP 授权后重定向回回调地址' ($r.Status -eq 302 -and $r.Location -like "$Base/oidc/callback*") "status=$($r.Status) loc=$($r.Location)"

$r = Request 'GET' $r.Location $s -NoRedirect
Check '回调后登录成功并重定向到首页' ($r.Status -eq 302 -and $r.Location -eq '/') "status=$($r.Status) loc=$($r.Location)"

$r = Request 'GET' "$Base/" $s
Check 'OIDC 登录后可访问首页' ($r.Status -eq 200 -and (Get-Csrf $r.Content) -ne '')

# --- 白名单外的 sub 被拒绝 ---
$s2 = New-Session
$r = Request 'GET' "$Base/oidc/start" $s2 -NoRedirect
$authorizeUrl2 = $r.Location -replace 'nonce=', 'test_sub=hacker&nonce='
$r = Request 'GET' $authorizeUrl2 $s2 -NoRedirect
$r = Request 'GET' $r.Location $s2 -NoRedirect
Check '白名单外 sub 登录被拒绝' ($r.Status -eq 200 -and $r.Content -match '未被授权')
$r = Request 'GET' "$Base/" $s2 -NoRedirect
Check '白名单外 sub 未获得会话' ($r.Status -eq 302 -and $r.Location -match '/login')

# --- 签名错误被拒绝 ---
$s3 = New-Session
$r = Request 'GET' "$Base/oidc/start" $s3 -NoRedirect
$authorizeUrl3 = $r.Location -replace 'nonce=', 'test_bad_sig=1&nonce='
$r = Request 'GET' $authorizeUrl3 $s3 -NoRedirect
$r = Request 'GET' $r.Location $s3 -NoRedirect
Check '错误签名的 id_token 被拒绝' ($r.Status -eq 200 -and $r.Content -match 'OIDC 登录失败')

# --- nonce 不匹配被拒绝 ---
$s4 = New-Session
$r = Request 'GET' "$Base/oidc/start" $s4 -NoRedirect
$authorizeUrl4 = $r.Location -replace 'nonce=', 'test_wrong_nonce=1&nonce='
$r = Request 'GET' $authorizeUrl4 $s4 -NoRedirect
$r = Request 'GET' $r.Location $s4 -NoRedirect
Check 'nonce 不匹配被拒绝' ($r.Status -eq 200 -and $r.Content -match 'OIDC 登录失败')

# --- state 被篡改 ---
$s5 = New-Session
$r = Request 'GET' "$Base/oidc/start" $s5 -NoRedirect
$r = Request 'GET' $r.Location $s5 -NoRedirect
$badCallback = $r.Location -replace 'state=[^&]*', 'state=forged'
$r = Request 'GET' $badCallback $s5 -NoRedirect
Check '伪造 state 被拒绝' ($r.Status -eq 200 -and $r.Content -match 'state 校验失败')

# --- 退出登录 ---
$s6 = New-Session
$r = Request 'GET' "$Base/oidc/start" $s6 -NoRedirect
$r = Request 'GET' $r.Location $s6 -NoRedirect
$r = Request 'GET' $r.Location $s6 -NoRedirect
$r = Request 'GET' "$Base/" $s6
$t = Get-Csrf $r.Content
$r = Request 'POST' "$Base/logout" $s6 @{ _token = $t } -NoRedirect
Check '退出登录返回 302' ($r.Status -eq 302) "status=$($r.Status)"
$r = Request 'GET' "$Base/" $s6 -NoRedirect
Check '退出后会话失效' ($r.Status -eq 302 -and $r.Location -match '/login')

Write-Host ''
Write-Host ("汇总：PASS={0} FAIL={1}" -f $script:pass, $script:fail)
if ($script:fail -gt 0) { exit 1 }
exit 0
