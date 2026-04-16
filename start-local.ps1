$ErrorActionPreference = 'Stop'

Set-Location $PSScriptRoot

php `
  -d upload_max_filesize=10M `
  -d post_max_size=12M `
  -d memory_limit=128M `
  -S 127.0.0.1:8000 router.php
