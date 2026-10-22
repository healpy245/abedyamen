<?php

declare(strict_types=1);

namespace App\Services\AiChatbot;

use App\Models\AiChatbot\AnalyticsDownload;
use App\Models\AiChatbot\ChatbotInstance;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Numbered Excel files for a sidebar result. Each file remembers the phones it contained.
 */
class AnalyticsDownloadService
{
    public function __construct(
        protected MansourInsightService $sheets,
    ) {}

    public function lineKey(Request $request): string
    {
        return trim((string) $request->query('line', ''));
    }

    /**
     * @param  callable(): array{format:string,filename:string,ascii:string,sheet:string,widths:list<int>,phone_index:int,table:list<list<string>>}  $current
     * @return array{downloads:list<array{id:int,sequence:int,count:int,at:string}>,next:int,fresh_count:int}
     */
    public function history(ChatbotInstance $instance, string $group, string $lineKey, ?int $campaignId, callable $current): array
    {
        $saved = $this->scoped($instance->id, $campaignId, $group, $lineKey)->orderBy('sequence')->get();
        $seen = $this->seenPhones($saved);
        $bundle = $current();
        $fresh = 0;
        foreach (array_slice($bundle['table'], 1) as $row) {
            if (! isset($seen[$this->phoneKey($row, (int) $bundle['phone_index'])])) {
                $fresh++;
            }
        }

        return [
            'downloads' => $saved->map(fn (AnalyticsDownload $row): array => [
                'id' => (int) $row->id,
                'sequence' => (int) $row->sequence,
                'count' => (int) $row->row_count,
                'at' => $row->created_at?->timezone(config('app.timezone'))->format('Y-m-d H:i') ?? '',
            ])->all(),
            'next' => ((int) $saved->max('sequence')) + 1,
            'fresh_count' => $fresh,
        ];
    }

    /**
     * @param  callable(): array{format:string,filename:string,ascii:string,sheet:string,widths:list<int>,phone_index:int,table:list<list<string>>}  $current
     */
    public function deliver(Request $request, ChatbotInstance $instance, string $group, ?int $campaignId, callable $current): StreamedResponse
    {
        $lineKey = $this->lineKey($request);
        $downloadId = (int) $request->query('download', 0);
        if ($downloadId > 0) {
            $saved = $this->scoped($instance->id, $campaignId, $group, $lineKey)->whereKey($downloadId)->first();
            abort_if($saved === null, 404);

            return $this->stream(is_array($saved->payload) ? $saved->payload : []);
        }

        $bundle = $current();
        if ($request->boolean('fresh')) {
            $seen = $this->seenPhones($this->scoped($instance->id, $campaignId, $group, $lineKey)->get());
            $header = $bundle['table'][0] ?? [];
            $rows = [];
            foreach (array_slice($bundle['table'], 1) as $row) {
                if (! isset($seen[$this->phoneKey($row, (int) $bundle['phone_index'])])) {
                    $rows[] = $row;
                }
            }
            $bundle['table'] = $header === [] ? $rows : array_merge([$header], $rows);
            if ($rows === []) {
                return $this->stream($bundle);
            }
        }

        $sequence = ((int) $this->scoped($instance->id, $campaignId, $group, $lineKey)->max('sequence')) + 1;
        $bundle['filename'] = $this->numbered((string) $bundle['filename'], $sequence);
        $bundle['ascii'] = $this->numbered((string) $bundle['ascii'], $sequence);
        $phones = [];
        foreach (array_slice($bundle['table'], 1) as $row) {
            $phones[] = $this->phoneKey($row, (int) $bundle['phone_index']);
        }
        $user = $request->user();
        AnalyticsDownload::query()->create([
            'instance_id' => $instance->id,
            'campaign_id' => $campaignId,
            'user_id' => $user instanceof User ? $user->id : null,
            'group' => $group,
            'line_key' => $lineKey,
            'sequence' => $sequence,
            'row_count' => count($phones),
            'phones' => $phones,
            'payload' => $bundle,
        ]);

        return $this->stream($bundle);
    }

    /**
     * @param  array{format?:string,filename?:string,ascii?:string,sheet?:string,widths?:list<int>,phone_index?:int,table?:list<list<string>>}  $bundle
     */
    public function stream(array $bundle): StreamedResponse
    {
        $table = array_values($bundle['table'] ?? []);
        $filename = (string) ($bundle['filename'] ?? 'download.csv');
        if (($bundle['format'] ?? 'csv') === 'xlsx') {
            $binary = $this->sheets->sheet(
                $table,
                array_values($bundle['widths'] ?? []),
                (string) ($bundle['sheet'] ?? 'Sheet'),
            );
            $ascii = (string) ($bundle['ascii'] ?? 'download.xlsx');

            return response()->streamDownload(function () use ($binary): void {
                echo $binary;
            }, null, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Content-Disposition' => 'attachment; filename="'.$ascii.'"; filename*=UTF-8\'\''.rawurlencode($filename),
            ]);
        }

        return response()->streamDownload(function () use ($table): void {
            $out = fopen('php://output', 'wb');
            if ($out === false) {
                return;
            }
            fwrite($out, "\xEF\xBB\xBF");
            foreach ($table as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * @param  iterable<int, AnalyticsDownload>  $rows
     * @return array<string, true>
     */
    private function seenPhones(iterable $rows): array
    {
        $seen = [];
        foreach ($rows as $row) {
            foreach ($row->phones ?? [] as $phone) {
                $seen[(string) $phone] = true;
            }
        }

        return $seen;
    }

    /**
     * @param  list<string>  $row
     */
    private function phoneKey(array $row, int $index): string
    {
        $digits = preg_replace('/\D+/', '', (string) ($row[$index] ?? '')) ?? '';
        if ($digits !== '') {
            return $digits;
        }

        return 'row:'.md5((string) json_encode($row, JSON_UNESCAPED_UNICODE));
    }

    private function numbered(string $name, int $sequence): string
    {
        $dot = strrpos($name, '.');
        if ($dot === false) {
            return $name.'-'.$sequence;
        }

        return substr($name, 0, $dot).'-'.$sequence.substr($name, $dot);
    }

    private function scoped(int $instanceId, ?int $campaignId, string $group, string $lineKey): Builder
    {
        return AnalyticsDownload::query()
            ->where('instance_id', $instanceId)
            ->where('group', $group)
            ->where('line_key', $lineKey)
            ->when(
                $campaignId === null,
                fn (Builder $query) => $query->whereNull('campaign_id'),
                fn (Builder $query) => $query->where('campaign_id', $campaignId),
            );
    }
}
