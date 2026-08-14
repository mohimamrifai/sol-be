<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Controller;
use App\Models\AdminActivityLog;
use App\Models\NumberingFormat;
use App\Models\SystemSetting;
use App\Services\AdminActivityLogger;
use App\Support\SystemSettingsSchema;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

class AdminSettingsController extends Controller
{
    public function __construct(
        private AuthController $authController,
        private AdminActivityLogger $activityLogger,
    ) {}

    public function profile(Request $request): JsonResponse
    {
        return $this->authController->profile($request);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'current_password' => 'required|string',
            'password' => 'required|string|min:8|confirmed',
        ]);

        if (! Hash::check($data['current_password'], $user->password)) {
            return response()->json(['message' => 'Password saat ini tidak sesuai.'], 422);
        }

        $user->update(['password' => Hash::make($data['password'])]);

        return response()->json(['message' => 'Password berhasil diubah.']);
    }

    public function numberingFormatsIndex(): JsonResponse
    {
        $formats = NumberingFormat::query()->orderBy('document_type')->get()->map(fn (NumberingFormat $f) => [
            'id' => $f->id,
            'document_type' => $f->document_type,
            'prefix' => $f->prefix,
            'running_digits' => $f->running_digits,
            'separator' => $f->separator,
            'reset_period' => $f->reset_period,
            'last_number' => $f->last_number,
            'preview' => $f->preview(),
        ]);

        return response()->json(['data' => $formats]);
    }

    public function numberingFormatShow(NumberingFormat $numberingFormat): JsonResponse
    {
        return response()->json(['data' => [
            'id' => $numberingFormat->id,
            'document_type' => $numberingFormat->document_type,
            'prefix' => $numberingFormat->prefix,
            'running_digits' => $numberingFormat->running_digits,
            'separator' => $numberingFormat->separator,
            'reset_period' => $numberingFormat->reset_period,
            'last_number' => $numberingFormat->last_number,
            'last_reset_at' => $numberingFormat->last_reset_at?->toIso8601String(),
            'preview' => $numberingFormat->preview(),
            'activity_log' => $this->activityPayload('numbering_format', $numberingFormat),
        ]]);
    }

    public function numberingFormatPreview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'prefix' => 'required|string|max:20',
            'running_digits' => 'required|integer|min:3|max:10',
            'separator' => 'required|string|in:-,/,none',
            'reset_period' => 'required|in:never,monthly,yearly',
        ]);

        return response()->json([
            'preview' => NumberingFormat::previewFrom($data),
        ]);
    }

    public function numberingFormatUpdate(Request $request, NumberingFormat $numberingFormat): JsonResponse
    {
        $data = $request->validate([
            'prefix' => 'sometimes|string|max:20',
            'running_digits' => 'sometimes|integer|min:3|max:10',
            'separator' => 'sometimes|string|in:-,/,none',
            'reset_period' => 'sometimes|in:never,monthly,yearly',
        ]);

        $changes = [];
        foreach ($data as $field => $value) {
            if ($numberingFormat->{$field} != $value) {
                $changes[] = match ($field) {
                    'prefix' => "Prefix {$numberingFormat->document_type} diubah menjadi {$value}.",
                    'running_digits' => "Running Digit {$numberingFormat->document_type} menjadi {$value}.",
                    'separator' => "Separator {$numberingFormat->document_type} diubah.",
                    'reset_period' => "Reset Number {$numberingFormat->document_type} diubah menjadi {$value}.",
                    default => "{$field} diperbarui.",
                };
            }
        }

        $numberingFormat->update($data);

        foreach ($changes as $description) {
            $this->activityLogger->log(
                'numbering_format',
                $description,
                $numberingFormat,
                'updated',
                null,
                $request->user()?->id
            );
        }

        $fresh = $numberingFormat->fresh();

        return response()->json([
            'message' => 'Numbering format diperbarui.',
            'data' => array_merge($fresh->toArray(), [
                'preview' => $fresh->preview(),
                'activity_log' => $this->activityPayload('numbering_format', $fresh),
            ]),
        ]);
    }

    public function systemSettingsIndex(): JsonResponse
    {
        $stored = SystemSetting::query()->get()->keyBy('key');
        $values = [];
        $schema = SystemSettingsSchema::fields();

        foreach ($schema as $field) {
            $key = $field['key'];
            $raw = $stored->get($key)?->value ?? $field['default'] ?? null;
            if (SystemSettingsSchema::isSecret($key) && $raw !== null && $raw !== '') {
                $values[$key] = SystemSetting::maskedValue($key, $raw);
            } else {
                $values[$key] = $raw;
            }
        }

        return response()->json([
            'data' => [
                'schema' => $schema,
                'values' => $values,
                'activity_log' => $this->activityPayload('system_settings'),
            ],
        ]);
    }

    public function systemSettingsUpdate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'settings' => 'required|array',
            'settings.*.key' => 'required|string|max:100',
            'settings.*.value' => 'nullable',
        ]);

        foreach ($data['settings'] as $item) {
            $key = $item['key'];
            $field = SystemSettingsSchema::find($key);
            if (! $field) {
                continue;
            }

            $incoming = $item['value'] ?? null;
            if (SystemSettingsSchema::isSecret($key) && ($incoming === '********' || $incoming === null || $incoming === '')) {
                continue;
            }

            $previous = SystemSetting::getValue($key, $field['default'] ?? null);
            if ($previous == $incoming) {
                continue;
            }

            SystemSetting::setValue($key, $incoming, $field['group']);

            $label = $field['label'] ?? $key;
            $description = match (true) {
                SystemSettingsSchema::isSecret($key) => "{$label} diperbarui.",
                is_bool($incoming) => "{$label} diubah menjadi ".($incoming ? 'Active' : 'Inactive').'.',
                default => "{$label} diubah menjadi {$incoming}.",
            };

            $this->activityLogger->log(
                'system_settings',
                $description,
                null,
                'updated',
                ['key' => $key],
                $request->user()?->id
            );
        }

        return $this->systemSettingsIndex();
    }

    public function testEmailConfiguration(Request $request): JsonResponse
    {
        $data = $request->validate([
            'recipient' => 'required|email',
        ]);

        $host = SystemSetting::getValue('smtp_host');
        $port = (int) SystemSetting::getValue('smtp_port', 587);
        $username = SystemSetting::getValue('smtp_username');
        $password = SystemSetting::getValue('smtp_password');
        $senderName = SystemSetting::getValue('sender_name', 'SOL Logistics');
        $senderEmail = SystemSetting::getValue('sender_email');

        if (! $host || ! $senderEmail) {
            return response()->json(['message' => 'Konfigurasi SMTP belum lengkap.'], 422);
        }

        Config::set('mail.default', 'smtp');
        Config::set('mail.mailers.smtp.transport', 'smtp');
        Config::set('mail.mailers.smtp.host', $host);
        Config::set('mail.mailers.smtp.port', $port);
        Config::set('mail.mailers.smtp.username', $username);
        Config::set('mail.mailers.smtp.password', $password);
        Config::set('mail.mailers.smtp.encryption', $port === 465 ? 'ssl' : 'tls');
        Config::set('mail.from.address', $senderEmail);
        Config::set('mail.from.name', $senderName);

        try {
            Mail::raw('Test email dari System Configuration SOL.', function ($message) use ($data, $senderEmail, $senderName) {
                $message->to($data['recipient'])
                    ->from($senderEmail, (string) $senderName)
                    ->subject('Test Email Configuration');
            });
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Gagal mengirim email: '.$e->getMessage()], 422);
        }

        $this->activityLogger->log(
            'system_settings',
            'Test email configuration dikirim ke '.$data['recipient'].'.',
            null,
            'test_email',
            ['recipient' => $data['recipient']],
            $request->user()?->id
        );

        return response()->json(['message' => 'Email test berhasil dikirim.']);
    }

    public function activityLogs(Request $request): JsonResponse
    {
        $data = $request->validate([
            'module' => 'required|string|max:50',
            'subject_id' => 'nullable|integer',
        ]);

        $query = AdminActivityLog::query()
            ->with('actor:id,name')
            ->where('module', $data['module'])
            ->orderByDesc('occurred_at');

        if ($request->filled('subject_id')) {
            $query->where('subject_id', $data['subject_id']);
        }

        return response()->json(['data' => $query->limit(30)->get()->map(fn (AdminActivityLog $log) => [
            'id' => $log->id,
            'description' => $log->description,
            'event_key' => $log->event_key,
            'user' => $log->actor?->name,
            'occurred_at' => $log->occurred_at?->toIso8601String(),
        ])]);
    }

    /** @return array<int, array<string, mixed>> */
    private function activityPayload(string $module, ?NumberingFormat $subject = null): array
    {
        $query = AdminActivityLog::query()
            ->with('actor:id,name')
            ->where('module', $module)
            ->orderByDesc('occurred_at');

        if ($subject) {
            $query->where('subject_type', $subject->getMorphClass())
                ->where('subject_id', $subject->getKey());
        }

        return $query->limit(20)->get()->map(fn (AdminActivityLog $log) => [
            'description' => $log->description,
            'user' => $log->actor?->name,
            'occurred_at' => $log->occurred_at?->toIso8601String(),
        ])->all();
    }
}
