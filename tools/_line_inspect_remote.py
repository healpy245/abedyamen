"""One-off: upload a tokened inspect script, print bot lines, delete the script."""

from __future__ import annotations

import ssl
import urllib.request
from pathlib import Path

import paramiko
from sftp_config import load_sftp

REMOTE = "/home/tut8x1radmrp/public_html/kaman-workspace/ops-line-inspect-483275634.php"
OUT = Path(__file__).resolve().parents[1] / "storage" / "app" / "line-inspect.json"

PHP = r"""<?php
require __DIR__.'/../../webtimize/vendor/autoload.php';
$app = require __DIR__.'/../../webtimize/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$linesSvc = app(App\Services\AiChatbot\MansourLines::class);
$bots = App\Models\AiChatbot\ChatbotInstance::query()->where('integration_type', 'mansour')->orderBy('id')->get();
$out = ['now' => now()->toIso8601String(), 'bots' => []];
$idsByBot = [];
foreach ($bots as $bot) {
    $settings = is_array($bot->integration_settings) ? $bot->integration_settings : [];
    $ids = [];
    foreach ($linesSvc->lines($bot) as $line) {
        $ids[] = (string) $line['id'];
    }
    $idsByBot[(int) $bot->id] = $ids;
    $out['bots'][] = [
        'id' => $bot->id,
        'name' => $bot->name,
        'role' => (string) ($settings['role'] ?? ''),
        'updated_at' => (string) $bot->updated_at,
        'lines' => count($ids),
        'ids' => $ids,
    ];
}
$usage = [];
$recent = [];
$cutoff = now()->subHours(18)->toDateTimeString();
App\Models\AiChatbot\ChatbotConversation::query()
    ->where('channel', 'whatsapp')
    ->whereIn('instance_id', array_keys($idsByBot))
    ->select(['id', 'instance_id', 'external_chat_id', 'metadata', 'created_at', 'updated_at'])
    ->orderBy('id')
    ->chunk(400, function ($rows) use (&$usage, &$recent, $cutoff): void {
        foreach ($rows as $row) {
            $meta = is_array($row->metadata) ? $row->metadata : [];
            $line = (string) ($meta['mansour_line'] ?? '');
            $key = $row->instance_id.'|'.$line;
            if (! isset($usage[$key])) {
                $usage[$key] = ['instance_id' => (int) $row->instance_id, 'line' => $line, 'convs' => 0, 'newest' => null];
            }
            $usage[$key]['convs']++;
            $stamp = (string) $row->updated_at;
            if ($usage[$key]['newest'] === null || $stamp > $usage[$key]['newest']) {
                $usage[$key]['newest'] = $stamp;
            }
            if ((string) $row->created_at >= $cutoff) {
                $recent[] = [
                    'id' => $row->id,
                    'instance_id' => (int) $row->instance_id,
                    'line' => $line,
                    'chat' => (string) $row->external_chat_id,
                    'created_at' => (string) $row->created_at,
                    'updated_at' => (string) $row->updated_at,
                ];
            }
        }
    });
$out['usage'] = array_values($usage);
$out['recent_created'] = $recent;
header('Content-Type: application/json; charset=utf-8');
echo json_encode($out, JSON_UNESCAPED_UNICODE);
"""


def main() -> None:
    host, port, user, password = load_sftp()
    transport = paramiko.Transport((host, port))
    transport.connect(username=user, password=password)
    sftp = paramiko.SFTPClient.from_transport(transport)
    assert sftp is not None
    try:
        with sftp.open(REMOTE, "w") as handle:
            handle.write(PHP)
        print("uploaded", len(PHP), flush=True)
    finally:
        sftp.close()
        transport.close()

    req = urllib.request.Request(
        "https://kaman-workspace.com/ops-line-inspect-483275634.php",
        headers={"User-Agent": "ops-inspect"},
    )
    try:
        with urllib.request.urlopen(req, timeout=180, context=ssl.create_default_context()) as resp:
            body = resp.read().decode("utf-8", "replace")
            print("status", resp.status, "bytes", len(body), flush=True)
    except urllib.error.HTTPError as exc:
        body = exc.read().decode("utf-8", "replace")
        print("status", exc.code, "bytes", len(body), flush=True)
    OUT.write_text(body, encoding="utf-8")
    print(body[:3000])


if __name__ == "__main__":
    main()
