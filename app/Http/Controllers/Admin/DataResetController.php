<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

/** Wipes every member and what they created, keeping seed data, settings and staff. */
class DataResetController extends Controller
{
    /** Typed by the admin to confirm. */
    public const PHRASE = 'DELETE ALL USER DATA';

    /** Member data. Reference tables, settings, the premium catalogue, admins and the audit log are not listed, so they stay. */
    private const TABLES = [
        'users', 'preferences', 'user_interests', 'user_languages', 'user_lifestyle',
        'photos', 'media_uploads', 'verifications', 'devices', 'otp_codes', 'password_reset_tokens', 'sessions',
        'likes', 'matches', 'compatibility_scores', 'chats', 'messages',
        'anon_conversations', 'anon_messages', 'reveal_requests',
        'blocks', 'reports', 'notifications', 'broadcasts',
        'subscriptions', 'transactions', 'usage_counters',
        'account_closures', 'analytics_events', 'app_feedback',
        'jobs', 'job_batches', 'failed_jobs',
    ];

    public function show(): JsonResponse
    {
        return response()->json([
            'enabled' => $this->enabled(),
            'phrase' => self::PHRASE,
            'counts' => $this->counts(),
            'files' => count($this->mediaKeys()),
        ]);
    }

    public function destroy(Request $request): JsonResponse
    {
        abort_unless($this->enabled(), 403, 'Clearing test data is turned off on this server (ALLOW_DATA_RESET).');

        $data = $request->validate([
            'password' => ['required', 'string'],
            'phrase' => ['required', 'string'],
        ]);
        if (! Hash::check($data['password'], (string) $request->user()->password)) {
            throw ValidationException::withMessages(['password' => 'Password is incorrect.']);
        }
        if ($data['phrase'] !== self::PHRASE) {
            throw ValidationException::withMessages(['phrase' => 'Type the phrase exactly as shown.']);
        }

        $counts = $this->counts();
        // Read before the rows go, since the rows are the only record of the keys.
        $media = $this->mediaKeys();
        $references = $this->referenceKeys();

        Schema::withoutForeignKeyConstraints(function (): void {
            foreach ($this->tables() as $table) {
                DB::table($table)->truncate();
            }
            // Member app sessions only; CRM staff stay signed in.
            DB::table('personal_access_tokens')->where('tokenable_type', 'App\\Models\\User')->delete();
        });

        $filesDeleted = $this->deleteFiles((string) config('filesystems.media.disk', 's3'), $media);
        $referenceDisk = config('filesystems.verification_disk');
        $referencesDeleted = $referenceDisk ? $this->deleteFiles((string) $referenceDisk, $references) : 0;

        AuditLogger::log($request, 'data.reset', 'database', null, [
            'rows' => $counts,
            'files_deleted' => $filesDeleted,
            'references_deleted' => $referencesDeleted,
        ]);

        return response()->json([
            'rows' => array_sum($counts),
            'files_deleted' => $filesDeleted,
            'references_deleted' => $referencesDeleted,
            'references_skipped' => $referenceDisk ? 0 : count($references),
        ]);
    }

    private function enabled(): bool
    {
        return (bool) config('app.allow_data_reset');
    }

    /** @return list<string> */
    private function tables(): array
    {
        return array_values(array_filter(self::TABLES, fn (string $t) => Schema::hasTable($t)));
    }

    /** @return array<string, int> */
    private function counts(): array
    {
        $counts = [];
        foreach ($this->tables() as $table) {
            $counts[$table] = DB::table($table)->count();
        }
        $counts['personal_access_tokens'] = DB::table('personal_access_tokens')->where('tokenable_type', 'App\\Models\\User')->count();

        return $counts;
    }

    /** @return list<string> */
    private function mediaKeys(): array
    {
        $keys = [];
        foreach (DB::table('photos')->get(['storage_key', 'webp_key', 'thumb_key', 'veil_key']) as $p) {
            array_push($keys, $p->storage_key, $p->webp_key, $p->thumb_key, $p->veil_key);
        }
        array_push($keys, ...DB::table('media_uploads')->pluck('key')->all());
        array_push($keys, ...DB::table('verifications')->pluck('video_key')->all());

        return array_values(array_unique(array_filter($keys, fn ($k) => is_string($k) && $k !== '')));
    }

    /** @return list<string> */
    private function referenceKeys(): array
    {
        return DB::table('verifications')->whereNotNull('reference_key')->pluck('reference_key')->unique()->values()->all();
    }

    /** @param list<string> $keys */
    private function deleteFiles(string $disk, array $keys): int
    {
        $deleted = 0;
        foreach (array_chunk($keys, 500) as $chunk) {
            try {
                Storage::disk($disk)->delete($chunk);
                $deleted += count($chunk);
            } catch (Throwable $e) {
                // The rows are already gone, so a storage hiccup only leaves orphaned files.
                Log::warning('Data reset could not delete files', ['disk' => $disk, 'error' => $e->getMessage()]);
            }
        }

        return $deleted;
    }
}
