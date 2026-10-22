<?php

declare(strict_types=1);

namespace App\Services\AiChatbot;

use App\Models\AiChatbot\ChatbotConversation;
use App\Models\AiChatbot\ChatbotInstance;
use App\Models\AiChatbot\ChatbotMessage;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipArchive;

/**
 * Voting read of منصور chats. Reads stored messages only — never sends WhatsApp.
 */
class MansourInsightService
{
    public const GROUP_CERTAIN = 'certain';

    public const GROUP_UNSURE = 'unsure';

    public const GROUP_NOT_VOTING = 'not_voting';

    public const GROUP_OTHER_SIDE = 'other_side';

    public const GROUP_NO_REPLY = 'no_reply';

    public const GROUP_REFUSED = 'refused';

    /**
     * @return array{
     *     certain:int,
     *     unsure:int,
     *     not_voting:int,
     *     other_side:int,
     *     no_reply:int,
     *     refused:int,
     *     replied:int,
     *     certain_pct:int,
     *     unsure_pct:int,
     *     not_voting_pct:int,
     *     other_side_pct:int,
     *     no_reply_pct:int,
     *     refused_pct:int,
     *     conversation_ids:array<string, list<int>>
     * }
     */
    public function summarize(ChatbotInstance $instance, ?string $lineId = null, bool $includeUntagged = false): array
    {
        $buckets = $this->classify($instance, $lineId, $includeUntagged);
        $certain = count($buckets[self::GROUP_CERTAIN]);
        $unsure = count($buckets[self::GROUP_UNSURE]);
        $notVoting = count($buckets[self::GROUP_NOT_VOTING]);
        $other = count($buckets[self::GROUP_OTHER_SIDE]);
        $noReply = count($buckets[self::GROUP_NO_REPLY]);
        $refused = count($buckets[self::GROUP_REFUSED]);
        $replied = $certain + $unsure + $notVoting + $other + $refused;
        $total = $replied + $noReply;

        return [
            'certain' => $certain,
            'unsure' => $unsure,
            'not_voting' => $notVoting,
            'other_side' => $other,
            'no_reply' => $noReply,
            'refused' => $refused,
            'replied' => $replied,
            'certain_pct' => $this->percent($certain, $total),
            'unsure_pct' => $this->percent($unsure, $total),
            'not_voting_pct' => $this->percent($notVoting, $total),
            'other_side_pct' => $this->percent($other, $total),
            'no_reply_pct' => $this->percent($noReply, $total),
            'refused_pct' => $this->percent($refused, $total),
            'conversation_ids' => $buckets,
        ];
    }

    /**
     * @param  list<list<string>>  $rows
     * @param  list<int>  $widths
     */
    public function sheet(array $rows, array $widths, string $title): string
    {
        return $this->xlsx($rows, $widths, $title);
    }

    public function isValidGroup(string $group): bool
    {
        return in_array($group, [
            self::GROUP_CERTAIN,
            self::GROUP_UNSURE,
            self::GROUP_NOT_VOTING,
            self::GROUP_OTHER_SIDE,
            self::GROUP_NO_REPLY,
            self::GROUP_REFUSED,
        ], true);
    }

    /**
     * @return list<int>
     */
    public function conversationIdsForGroup(ChatbotInstance $instance, string $group, ?string $lineId = null, bool $includeUntagged = false): array
    {
        if (! $this->isValidGroup($group)) {
            return [];
        }

        return $this->classify($instance, $lineId, $includeUntagged)[$group] ?? [];
    }

    public function exportCsv(ChatbotInstance $instance, string $group, ?string $lineId = null, bool $includeUntagged = false): StreamedResponse
    {
        return app(AnalyticsDownloadService::class)->stream($this->exportBundle($instance, $group, $lineId, $includeUntagged));
    }

    /**
     * @return array{format:string,filename:string,ascii:string,sheet:string,widths:list<int>,phone_index:int,table:list<list<string>>}
     */
    public function exportBundle(ChatbotInstance $instance, string $group, ?string $lineId = null, bool $includeUntagged = false): array
    {
        if (! $this->isValidGroup($group)) {
            abort(404);
        }

        $ids = $this->conversationIdsForGroup($instance, $group, $lineId, $includeUntagged);
        $rows = $ids === []
            ? collect()
            : ChatbotConversation::query()
                ->where('instance_id', $instance->id)
                ->whereIn('id', $ids)
                ->orderBy('id')
                ->get(['id', 'contact_name', 'contact_phone', 'external_chat_id', 'title']);
        $notes = $group === self::GROUP_NO_REPLY
            ? []
            : $this->dialogueNotes($ids);

        $label = $this->arabicGroupLabel($group);
        $table = [[
            'الاسم',
            'الهاتف',
            'المجموعة',
            'الملاحظة',
        ]];
        foreach ($rows as $conversation) {
            $table[] = [
                $conversation->displayName(),
                $this->phoneOf($conversation),
                $label,
                $group === self::GROUP_NO_REPLY
                    ? MansourPrompt::noteForGroup(self::GROUP_NO_REPLY)
                    : ($notes[(int) $conversation->id] ?? ''),
            ];
        }

        return [
            'format' => 'xlsx',
            'filename' => $this->exportFilename($instance, $group),
            'ascii' => 'farz-'.$group.'-'.now()->format('Y-m-d').'.xlsx',
            'sheet' => $this->sheetTitle($group),
            'widths' => [36, 24, 42, 88],
            'phone_index' => 1,
            'table' => $table,
        ];
    }

    private function arabicGroupLabel(string $group): string
    {
        return match ($group) {
            self::GROUP_CERTAIN => 'الموحدة',
            self::GROUP_UNSURE => 'المشتركة',
            self::GROUP_NOT_VOTING => 'مقاطع',
            self::GROUP_OTHER_SIDE => 'حزب صهيوني',
            self::GROUP_NO_REPLY => 'لم يرد',
            self::GROUP_REFUSED => 'رفضوا الحديث',
            default => $group,
        };
    }

    private function arabicTopic(string $group): string
    {
        return match ($group) {
            self::GROUP_CERTAIN => 'مصوت للقائمة العربية الموحدة',
            self::GROUP_UNSURE => 'مصوت للقائمة المشتركة',
            self::GROUP_NOT_VOTING => 'لا يريد التصويت',
            self::GROUP_OTHER_SIDE => 'مصوت لحزب صهيوني',
            self::GROUP_NO_REPLY => 'أرسلت الرسالة ولم يرد',
            self::GROUP_REFUSED => 'رفض يكمل الحديث',
            default => $group,
        };
    }

    private function exportFilename(ChatbotInstance $instance, string $group): string
    {
        $bot = trim((string) $instance->name);
        if ($bot === '') {
            $bot = 'بوت الفرز';
        }
        $bot = trim((string) preg_replace('/[\\\\\\/:*?"<>|]+/u', ' ', $bot));
        $topic = $this->arabicTopic($group);

        return $bot.' - '.$topic.' - '.now()->format('Y-m-d').'.xlsx';
    }

    private function sheetTitle(string $group): string
    {
        $title = $this->arabicTopic($group);
        $title = str_replace(['\\', '/', '*', '?', ':', '[', ']'], ' ', $title);
        if ($title === '') {
            $title = $group;
        }

        return mb_substr($title, 0, 31);
    }

    /**
     * @param  list<list<string>>  $rows
     * @param  list<int>  $widths
     */
    private function xlsx(array $rows, array $widths, string $sheetTitle): string
    {
        $cols = '';
        foreach ($widths as $index => $width) {
            $n = $index + 1;
            $cols .= '<col min="'.$n.'" max="'.$n.'" width="'.$width.'" customWidth="1"/>';
        }

        $sheetRows = '';
        foreach ($rows as $r => $row) {
            $line = '';
            foreach ($row as $c => $value) {
                $ref = $this->columnLetter($c).($r + 1);
                $style = $r === 0 ? ' s="1"' : ' s="2"';
                $line .= '<c r="'.$ref.'" t="inlineStr"'.$style.'><is><t xml:space="preserve">'.$this->xml($value).'</t></is></c>';
            }
            $sheetRows .= '<row r="'.($r + 1).'">'.$line.'</row>';
        }

        $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<sheetViews><sheetView rightToLeft="1" workbookViewId="0"/></sheetViews>'
            .'<cols>'.$cols.'</cols>'
            .'<sheetData>'.$sheetRows.'</sheetData>'
            .'</worksheet>';

        $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="12"/><name val="Calibri"/></font></fonts>'
            .'<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
            .'<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="3">'
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            .'<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1"><alignment readingOrder="2"/></xf>'
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment wrapText="1" readingOrder="2"/></xf>'
            .'</cellXfs></styleSheet>';

        $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets><sheet name="'.$this->xml($sheetTitle).'" sheetId="1" r:id="rId1"/></sheets></workbook>';

        $parts = [
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                .'<Default Extension="xml" ContentType="application/xml"/>'
                .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
                .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
                .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
                .'</Types>',
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
                .'</Relationships>',
            'xl/workbook.xml' => $workbook,
            'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
                .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
                .'</Relationships>',
            'xl/styles.xml' => $styles,
            'xl/worksheets/sheet1.xml' => $sheet,
        ];

        $tmp = tempnam(sys_get_temp_dir(), 'farz');
        if ($tmp === false) {
            return '';
        }
        $zip = new ZipArchive;
        $zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ($parts as $name => $xml) {
            $zip->addFromString($name, $xml);
            $zip->setCompressionName($name, ZipArchive::CM_STORE);
        }
        $zip->close();
        $binary = (string) file_get_contents($tmp);
        @unlink($tmp);

        return $binary;
    }

    private function columnLetter(int $index): string
    {
        $letter = '';
        $n = $index;
        do {
            $letter = chr(65 + ($n % 26)).$letter;
            $n = intdiv($n, 26) - 1;
        } while ($n >= 0);

        return $letter;
    }

    private function xml(string $value): string
    {
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $value) ?? $value;

        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /**
     * @return array<string, list<int>>
     */
    private function classify(ChatbotInstance $instance, ?string $lineId = null, bool $includeUntagged = false): array
    {
        $buckets = [
            self::GROUP_CERTAIN => [],
            self::GROUP_UNSURE => [],
            self::GROUP_NOT_VOTING => [],
            self::GROUP_OTHER_SIDE => [],
            self::GROUP_NO_REPLY => [],
            self::GROUP_REFUSED => [],
        ];

        $conversations = $this->conversations($instance, $lineId, $includeUntagged);
        if ($conversations->isEmpty()) {
            return $buckets;
        }

        $ids = $conversations->map(fn (ChatbotConversation $c) => (int) $c->id)->all();
        $turnsByConversation = $this->turns($ids);

        foreach ($ids as $id) {
            $turns = $turnsByConversation[$id] ?? [];
            $hasUser = false;
            $hasAssistant = false;
            foreach ($turns as $turn) {
                if ($turn['role'] === 'user') {
                    $hasUser = true;
                }
                if ($turn['role'] === 'assistant') {
                    $hasAssistant = true;
                }
            }
            if ($hasAssistant && ! $hasUser) {
                $buckets[self::GROUP_NO_REPLY][] = $id;

                continue;
            }

            $group = MansourPrompt::dialogueResult($turns)['group'] ?? null;
            if ($group === null || ! isset($buckets[$group])) {
                continue;
            }
            $buckets[$group][] = $id;
        }

        return $buckets;
    }

    /**
     * @param  list<int>  $conversationIds
     * @return array<int, string>
     */
    private function dialogueNotes(array $conversationIds): array
    {
        $notes = [];
        foreach ($this->turns($conversationIds) as $id => $turns) {
            $result = MansourPrompt::dialogueResult($turns);
            $notes[$id] = MansourPrompt::noteForGroup($result['group'], $result['line']);
        }

        return $notes;
    }

    /**
     * @param  list<int>  $conversationIds
     * @return array<int, list<array{role:string,message:string}>>
     */
    private function turns(array $conversationIds): array
    {
        $turns = [];
        foreach (array_chunk($conversationIds, 500) as $chunk) {
            $messages = ChatbotMessage::query()
                ->whereIn('conversation_id', $chunk)
                ->whereIn('role', ['user', 'assistant'])
                ->orderBy('id')
                ->get(['conversation_id', 'role', 'message']);
            foreach ($messages as $message) {
                $id = (int) $message->conversation_id;
                $turns[$id][] = [
                    'role' => (string) $message->role,
                    'message' => (string) $message->message,
                ];
            }
        }

        return $turns;
    }

    /**
     * @return Collection<int, ChatbotConversation>
     */
    private function conversations(ChatbotInstance $instance, ?string $lineId = null, bool $includeUntagged = false): Collection
    {
        return ChatbotConversation::query()
            ->where('instance_id', $instance->id)
            ->customerFacing()
            ->when(trim((string) $lineId) !== '', fn ($query) => $query->onLine((string) $lineId, $includeUntagged))
            ->where(function ($query): void {
                $query->whereNull('external_chat_id')
                    ->orWhere('external_chat_id', 'not like', '%@g.us');
            })
            ->orderBy('id')
            ->get(['id']);
    }

    private function percent(int $count, int $total): int
    {
        if ($total <= 0) {
            return 0;
        }

        return (int) round(($count / $total) * 100);
    }

    private function phoneOf(ChatbotConversation $conversation): string
    {
        $phone = preg_replace('/\D+/', '', (string) ($conversation->contact_phone ?? '')) ?: '';
        if ($phone !== '') {
            return $phone;
        }

        if (preg_match('/(\d{8,})/', (string) ($conversation->external_chat_id ?? ''), $match) === 1) {
            return $match[1];
        }

        return '';
    }
}
