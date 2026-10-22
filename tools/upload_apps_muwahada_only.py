#!/usr/bin/env python3
"""Upload the Mowahada apps-bot prompt change and the recent bot updates."""

from __future__ import annotations

import ssl
import urllib.request
from pathlib import Path

import paramiko
from sftp_config import load_sftp

LOCAL = Path(__file__).resolve().parents[1]
REMOTE = "/home/tut8x1radmrp/webtimize"
PUBLIC = "/home/tut8x1radmrp/public_html/kaman-workspace"
HOST, _PORT, USER, PASSWORD = load_sftp()

FILES = [
    "app/Http/Controllers/AiChatbot/ChatbotController.php",
    "app/Http/Controllers/AiChatbot/ChatbotGreenApiWebhookController.php",
    "app/Http/Controllers/AiChatbot/ChatbotInstanceController.php",
    "app/Http/Controllers/AiChatbot/ChatbotWorkspaceController.php",
    "app/Http/Controllers/AiChatbot/FarzDashboardController.php",
    "app/Http/Controllers/AiChatbot/MansourBotController.php",
    "app/Http/Controllers/AiChatbot/MowahadaLinesController.php",
    "app/Http/Middleware/KeepMowahadaInFarz.php",
    "app/Jobs/ProcessMansourConversationJob.php",
    "app/Models/AiChatbot/AnalyticsDownload.php",
    "app/Models/AiChatbot/ChatbotConversation.php",
    "app/Models/AiChatbot/ChatbotInstance.php",
    "app/Models/AiChatbot/MansourContact.php",
    "app/Models/AiChatbot/MansourSendRun.php",
    "app/Models/AiChatbot/MowahadaLine.php",
    "app/Services/AiChatbot/AiChatbotService.php",
    "app/Services/AiChatbot/AnalyticsDownloadService.php",
    "app/Services/AiChatbot/AppsCatchupService.php",
    "app/Services/AiChatbot/AppsInsightService.php",
    "app/Services/AiChatbot/AppsPrompt.php",
    "app/Services/AiChatbot/ChatbotAuthorizationService.php",
    "app/Services/AiChatbot/ChatbotGreenApiService.php",
    "app/Services/AiChatbot/ChatbotTestService.php",
    "app/Services/AiChatbot/DimaContextBuilder.php",
    "app/Services/AiChatbot/DimaConversationService.php",
    "app/Services/AiChatbot/DimaConversationState.php",
    "app/Services/AiChatbot/DimaEngine.php",
    "app/Services/AiChatbot/DimaFacts.php",
    "app/Services/AiChatbot/DimaInsightService.php",
    "app/Services/AiChatbot/DimaKnowledge.php",
    "app/Services/AiChatbot/DimaKnowledgeService.php",
    "app/Services/AiChatbot/DimaPrompt.php",
    "app/Services/AiChatbot/DimaResponseValidator.php",
    "app/Services/AiChatbot/FarzDashboardService.php",
    "app/Services/AiChatbot/GreenApiMediaDownloader.php",
    "app/Services/AiChatbot/HebrewName.php",
    "app/Services/AiChatbot/InviteInsightService.php",
    "app/Services/AiChatbot/InvitePrompt.php",
    "app/Services/AiChatbot/KamanBotInsightService.php",
    "app/Services/AiChatbot/MansourBotService.php",
    "app/Services/AiChatbot/MansourConversationBurstService.php",
    "app/Services/AiChatbot/MansourConversationRevival.php",
    "app/Services/AiChatbot/MansourHumanDelay.php",
    "app/Services/AiChatbot/MansourInsightService.php",
    "app/Services/AiChatbot/MansourLines.php",
    "app/Services/AiChatbot/MansourListeningWindow.php",
    "app/Services/AiChatbot/MansourPrompt.php",
    "app/Services/AiChatbot/MowahadaLinePool.php",
    "app/Services/AiChatbot/MowahadaOpenAi.php",
    "app/Services/AiChatbot/PromptCompiler.php",
    "app/Services/AiChatbot/SpeedcomDeskNotifier.php",
    "app/Services/AiChatbot/WhapiCloudClient.php",
    "app/Services/AiChatbot/WhatsAppAudioTranscriber.php",
    "app/Services/AiChatbot/WhatsAppVoiceInboundService.php",
    "app/Services/AiChatbot/Tools/ChatbotToolDefinitions.php",
    "app/Services/AiChatbot/Tools/ChatbotToolExecutor.php",
    "app/Support/FarzUi.php",
    "config/dima.php",
    "config/openai.php",
    "config/services.php",
    "routes/ai-chatbot.php",
    "lang/ar/chatbot.php",
    "lang/en/chatbot.php",
    "lang/he/chatbot.php",
    "lang/ar/workspace.php",
    "lang/en/workspace.php",
    "lang/he/workspace.php",
    "resources/views/ai-chatbot/choose.blade.php",
    "resources/views/ai-chatbot/instances/edit.blade.php",
    "resources/views/ai-chatbot/partials/whatsapp-provider.blade.php",
    "resources/views/ai-chatbot/workspace/campaigns/index.blade.php",
    "resources/views/ai-chatbot/workspace/campaigns/partials/subnav.blade.php",
    "resources/views/ai-chatbot/workspace/campaigns/settings.blade.php",
    "resources/views/ai-chatbot/workspace/campaigns/show.blade.php",
    "resources/views/ai-chatbot/workspace/conversation.blade.php",
    "resources/views/ai-chatbot/workspace/conversations.blade.php",
    "resources/views/ai-chatbot/workspace/settings.blade.php",
    "resources/views/ai-chatbot/workspace/test.blade.php",
    "resources/views/ai-chatbot/workspace/partials/conversation-list-pane.blade.php",
    "resources/views/ai-chatbot/workspace/partials/conversation-pane.blade.php",
    "resources/views/ai-chatbot/workspace/partials/conversation-row.blade.php",
    "resources/views/ai-chatbot/workspace/partials/download-history-modal.blade.php",
    "resources/views/ai-chatbot/workspace/partials/kaman-analytics.blade.php",
    "resources/views/ai-chatbot/workspace/partials/line-pressure.blade.php",
    "resources/views/ai-chatbot/workspace/partials/message-bubble.blade.php",
    "resources/views/ai-chatbot/workspace/partials/nav.blade.php",
    "resources/views/ai-chatbot/workspace/partials/settings-simple.blade.php",
    "resources/views/ai-chatbot/workspace/partials/whatsapp-line-card.blade.php",
    "resources/views/ai-chatbot/workspace/mansour/analytics.blade.php",
    "resources/views/ai-chatbot/workspace/mansour/followup-modal.blade.php",
    "resources/views/ai-chatbot/workspace/mansour/settings.blade.php",
    "resources/views/ai-chatbot/workspace/mansour/trigger-modal.blade.php",
    "resources/views/ai-chatbot/farz/dashboard.blade.php",
    "resources/views/ai-chatbot/farz/numbers.blade.php",
    "resources/views/ai-chatbot/farz/partials/persuasion-bot.blade.php",
    "public/css/farz-dashboard.css",
    "public/css/farz-theme.css",
    "public/images/farz/aswatkum.jpg",
    "public/images/farz/muwahada-app.jpg",
    "public/images/farz/womens-conference.jpg",
]


def ensure_dir(sftp: paramiko.SFTPClient, path: str, cache: set[str]) -> None:
    if path in cache or path in {"", "/"}:
        return
    parent = path.rsplit("/", 1)[0]
    ensure_dir(sftp, parent, cache)
    try:
        sftp.stat(path)
    except FileNotFoundError:
        sftp.mkdir(path)
    cache.add(path)


def main() -> int:
    transport = paramiko.Transport((HOST, 22))
    transport.connect(username=USER, password=PASSWORD)
    sftp = paramiko.SFTPClient.from_transport(transport)
    assert sftp is not None
    cache: set[str] = set()
    missing = []
    for rel in FILES:
        local = LOCAL / rel
        if not local.is_file():
            missing.append(rel)
            print(f"missing {rel}", flush=True)
            continue
        remote = f"{REMOTE}/{rel}"
        ensure_dir(sftp, remote.rsplit("/", 1)[0], cache)
        sftp.put(str(local), remote)
        print(f"uploaded {rel}", flush=True)
        if rel.startswith("public/"):
            public_rel = rel[len("public/") :]
            public_remote = f"{PUBLIC}/{public_rel}"
            ensure_dir(sftp, public_remote.rsplit("/", 1)[0], cache)
            sftp.put(str(local), public_remote)
            print(f"public {public_rel}", flush=True)
    sftp.close()
    transport.close()
    if missing:
        raise SystemExit("missing files: " + ", ".join(missing))

    url = "https://kaman-workspace.com/ops/clear/483275634"
    print("GET", url, flush=True)
    req = urllib.request.Request(url, headers={"User-Agent": "deploy-bot"})
    with urllib.request.urlopen(req, timeout=180, context=ssl.create_default_context()) as resp:
        body = resp.read().decode("utf-8", errors="replace")
        print("status", resp.status, flush=True)
        print(body[:1500], flush=True)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
