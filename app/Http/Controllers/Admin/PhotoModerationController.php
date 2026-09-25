<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Photo;
use App\Services\Admin\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** Photo review queue: approve, or reject (which deletes the photo and its files). */
class PhotoModerationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = Photo::query()->with('user:id,name,gender,status,is_verified');

        $status = $request->query('status', 'pending');
        if ($status === 'face_rejected') {
            $q->where('face_status', 'rejected');
        } elseif ($status !== 'all') {
            $q->where('moderation_status', $status);
        }
        if ($request->filled('user_id')) {
            $q->where('user_id', (int) $request->query('user_id'));
        }

        $page = $q->orderBy('id', $status === 'pending' ? 'asc' : 'desc')->paginate(min(60, max(6, (int) $request->query('per_page', 24))));
        $page->getCollection()->transform(fn (Photo $p) => [
            'id' => $p->id, 'url' => $p->url, 'thumb_url' => $p->thumb_url ?: $p->url,
            'is_primary' => $p->is_primary, 'moderation_status' => $p->moderation_status,
            'face_status' => $p->face_status, 'face_similarity' => $p->face_similarity, 'face_reason' => $p->face_reason,
            'created_at' => $p->created_at?->toIso8601String(),
            'user' => $p->user ? ['id' => $p->user->id, 'name' => $p->user->name, 'status' => $p->user->status, 'is_verified' => $p->user->is_verified] : null,
        ]);

        return response()->json($page);
    }

    /** Bulk approve / reject. */
    public function review(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['integer'],
            'decision' => ['required', 'in:approve,reject'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $photos = Photo::query()->whereIn('id', $data['ids'])->get();
        // Photos live on the same media disk the mobile backend uploads to.
        $disk = Storage::disk((string) config('filesystems.media.disk', 'public'));
        $adminId = $request->user()->id;

        foreach ($photos as $photo) {
            if ($data['decision'] === 'approve') {
                DB::table('photos')->where('id', $photo->id)->update([
                    'moderation_status' => 'approved', 'moderated_by' => $adminId, 'moderated_at' => now(),
                ]);
                continue;
            }

            $userId = $photo->user_id;
            $wasPrimary = $photo->is_primary;
            $keys = array_values(array_filter([$photo->storage_key, $photo->webp_key, $photo->thumb_key, $photo->veil_key]));
            if ($keys !== []) {
                $disk->delete($keys);
            }
            $photo->delete();

            if ($wasPrimary) {
                $next = Photo::query()->where('user_id', $userId)->orderBy('position')->first();
                $next?->forceFill(['is_primary' => true])->save();
            }
        }

        AuditLogger::log($request, 'photo.'.$data['decision'], 'photo', null, ['ids' => $data['ids'], 'reason' => $data['reason'] ?? null]);

        return response()->json(['ok' => true, 'count' => $photos->count()]);
    }
}
