#!/usr/bin/env python3
"""Print recent kaman-support device rows and log lines. No secrets."""

from __future__ import annotations

import json
from pathlib import Path

import paramiko

LOCAL = Path(__file__).resolve().parents[1]
REMOTE = "/home/tut8x1radmrp/webtimize"
PHP = r"""
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$devices = App\Models\KamanSupport\SupportDevice::query()->orderByDesc('last_seen_at')->limit(15)->get();
foreach ($devices as $d) {
    $kind = is_array($d->extra) ? ($d->extra['deviceKind'] ?? '') : '';
    echo $d->id.' | '.$d->restaurant.' | '.$d->platform.' | '.$kind.' | '.$d->app_version.' | '.$d->last_seen_at.' | '.$d->device_id.PHP_EOL;
}
echo "----LOGS----".PHP_EOL;
$logs = App\Models\KamanSupport\SupportDeviceLog::query()->orderByDesc('id')->limit(80)->get();
foreach ($logs as $l) {
    $msg = preg_replace('/\s+/', ' ', (string) $l->message);
    if (strlen($msg) > 240) {
        $msg = substr($msg, 0, 240);
    }
    echo $l->logged_at.' | '.$l->support_device_id.' | '.$l->level.' | '.$l->source.' | '.$msg.PHP_EOL;
}
"""


def main() -> int:
    cfg = json.loads((LOCAL / ".vscode" / "sftp.json").read_text(encoding="utf-8"))
    client = paramiko.SSHClient()
    client.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    client.connect(cfg["host"], username=cfg["username"], password=cfg["password"], timeout=25)
    try:
        command = f"cd {REMOTE} && php -r {json.dumps(PHP)}"
        _, stdout, stderr = client.exec_command(command, timeout=90)
        out = stdout.read().decode("utf-8", "replace")
        err = stderr.read().decode("utf-8", "replace")
        print(out)
        if err.strip():
            print(err[:2000])
    finally:
        client.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
