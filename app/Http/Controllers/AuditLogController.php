<?php

namespace App\Http\Controllers;

use App\Http\Requests\AuditLogExportRequest;
use App\Http\Requests\AuditLogIndexRequest;
use App\Http\Resources\AuditLogCsvRow;
use App\Http\Resources\AuditLogResource;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class AuditLogController extends Controller
{
    private const CSV_SEPARATOR = ';';

    private const CSV_EOL = "\r\n";

    private const UTF8_BOM = "\xEF\xBB\xBF";

    public function __construct(private readonly AuditLogService $service) {}

    public function index(AuditLogIndexRequest $request): JsonResponse
    {
        try {
            $response = $this->service->index($request->validated());

            return $this->listResponse($response, AuditLogResource::class);
        } catch (Throwable $th) {
            log_error($th);

            return response()->json([
                'message' => 'Houve um erro no servidor, tente novamente!',
            ], 500);
        }
    }

    public function export(AuditLogExportRequest $request): JsonResponse|StreamedResponse
    {
        try {
            $logs = $this->service->export($request->validated());

            return response()->streamDownload(
                fn () => $this->writeCsv($logs),
                'auditoria-'.now()->format('Y-m-d').'.csv',
                ['Content-Type' => 'text/csv; charset=UTF-8'],
            );
        } catch (ValidationException $ex) {
            return response()->json(['message' => $ex->getMessage()], 422);
        } catch (Throwable $th) {
            log_error($th);

            return response()->json([
                'message' => 'Houve um erro no servidor, tente novamente!',
            ], 500);
        }
    }

    private function writeCsv(iterable $logs): void
    {
        $row = new AuditLogCsvRow;
        $output = fopen('php://output', 'w');

        fwrite($output, self::UTF8_BOM);
        $this->writeLine($output, AuditLogCsvRow::HEADERS);

        foreach ($logs as $log) {
            $this->writeLine($output, $row->toArray($log));
        }

        fclose($output);
    }

    private function writeLine($output, array $fields): void
    {
        fputcsv($output, $fields, self::CSV_SEPARATOR, '"', '', self::CSV_EOL);
    }
}
