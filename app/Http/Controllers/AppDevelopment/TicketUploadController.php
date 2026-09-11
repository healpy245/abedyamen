<?php

declare(strict_types=1);

namespace App\Http\Controllers\AppDevelopment;

use App\Http\Controllers\Controller;
use App\Http\Requests\AppDevelopment\AppendStagedUploadChunkRequest;
use App\Http\Requests\AppDevelopment\InitStagedUploadRequest;
use App\Models\AppDevelopment\AppDevelopmentStagedUpload;
use App\Services\AppDevelopment\TicketStagingUploadService;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class TicketUploadController extends Controller
{
    public function __construct(
        private readonly TicketStagingUploadService $staging,
    ) {}

    public function init(InitStagedUploadRequest $request): JsonResponse
    {
        try {
            $result = $this->staging->init(
                $request->user(),
                (string) $request->validated('name'),
                (int) $request->validated('size'),
                $request->validated('mime'),
                (string) ($request->validated('kind') ?? 'attachment'),
            );
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => __('app-development.errors.attachment_store_failed')], 500);
        }

        $upload = $result['upload'];

        return response()->json([
            'uuid' => $upload->uuid,
            'chunk_size' => $result['chunk_size'],
            'total_chunks' => $upload->total_chunks,
        ]);
    }

    public function chunk(AppendStagedUploadChunkRequest $request, string $uuid): JsonResponse
    {
        $upload = AppDevelopmentStagedUpload::query()
            ->where('uuid', $uuid)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        try {
            $updated = $this->staging->appendChunk(
                $upload,
                $request->user(),
                (int) $request->validated('index'),
                $request->file('chunk'),
            );
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => __('app-development.errors.attachment_store_failed')], 500);
        }

        return response()->json([
            'uuid' => $updated->uuid,
            'received_chunks' => $updated->received_chunks,
            'total_chunks' => $updated->total_chunks,
            'received_size' => $updated->received_size,
            'total_size' => $updated->total_size,
            'percent' => $updated->total_size > 0
                ? (int) min(100, (int) floor(($updated->received_size / $updated->total_size) * 100))
                : 0,
            'status' => $updated->status,
        ]);
    }
}
