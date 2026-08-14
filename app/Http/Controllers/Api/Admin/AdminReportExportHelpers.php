<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

trait AdminReportExportHelpers
{
    private function csvResponse(string $filename, array $headers, $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $headers);
            foreach ($rows as $row) {
                fputcsv($out, is_array($row) ? $row : $row->toArray());
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    private function excelResponse(string $filename, array $headers, $rows): StreamedResponse
    {
        $excelName = str_replace('.csv', '.xlsx', $filename);
        if (! str_ends_with($excelName, '.xlsx')) {
            $excelName .= '.xlsx';
        }

        return response()->streamDownload(function () use ($headers, $rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $headers);
            foreach ($rows as $row) {
                fputcsv($out, is_array($row) ? $row : $row->toArray());
            }
            fclose($out);
        }, $excelName, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    private function pdfResponse(string $title, array $headers, $rows, string $filename): \Illuminate\Http\Response
    {
        $pdf = Pdf::loadView('pdf.admin-report', [
            'title' => $title,
            'headers' => $headers,
            'rows' => collect($rows)->map(fn ($row) => is_array($row) ? $row : $row->toArray())->all(),
            'generatedAt' => now()->format('d/m/Y H:i'),
        ])->setPaper('a4', 'landscape');

        return $pdf->download($filename);
    }

    private function exportByFormat(Request $request, string $basename, array $headers, $rows, string $pdfTitle)
    {
        $format = strtolower((string) $request->query('format', 'csv'));

        return match ($format) {
            'pdf' => $this->pdfResponse($pdfTitle, $headers, $rows, str_replace('.csv', '.pdf', $basename)),
            'excel', 'xlsx' => $this->excelResponse($basename, $headers, $rows),
            default => $this->csvResponse($basename, $headers, $rows),
        };
    }
}
