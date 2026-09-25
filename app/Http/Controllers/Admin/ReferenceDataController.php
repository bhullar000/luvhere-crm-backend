<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\City;
use App\Models\Interest;
use App\Models\Language;
use App\Models\LifestyleOption;
use App\Services\Admin\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** CRUD for the lookup tables the app renders in onboarding, profile and filters. */
class ReferenceDataController extends Controller
{
    /** @return array<string, array{model: class-string, order: string, usage: ?string, rules: callable}> */
    private function types(): array
    {
        return [
            'cities' => [
                'model' => City::class, 'order' => 'name', 'usage' => null,
                'rules' => fn ($id) => [
                    'name' => ['required', 'string', 'max:120'], 'state' => ['nullable', 'string', 'max:120'],
                    'country_code' => ['required', 'string', 'size:2'],
                    'latitude' => ['nullable', 'numeric', 'between:-90,90'], 'longitude' => ['nullable', 'numeric', 'between:-180,180'],
                ],
            ],
            'interests' => [
                'model' => Interest::class, 'order' => 'name', 'usage' => 'user_interests',
                'rules' => fn ($id) => [
                    'name' => ['required', 'string', 'max:60'],
                    'slug' => ['required', 'string', 'max:60', Rule::unique('interests', 'slug')->ignore($id)],
                    'emoji' => ['nullable', 'string', 'max:8'], 'category' => ['nullable', 'string', 'max:60'],
                ],
            ],
            'languages' => [
                'model' => Language::class, 'order' => 'name', 'usage' => 'user_languages',
                'rules' => fn ($id) => [
                    'name' => ['required', 'string', 'max:60'],
                    'code' => ['required', 'string', 'max:8', Rule::unique('languages', 'code')->ignore($id)],
                ],
            ],
            'lifestyle' => [
                'model' => LifestyleOption::class, 'order' => 'group', 'usage' => 'user_lifestyle',
                'rules' => fn ($id) => [
                    'group' => ['required', 'string', 'max:40'], 'value' => ['required', 'string', 'max:40'], 'label' => ['required', 'string', 'max:80'],
                ],
            ],
        ];
    }

    private function cfg(string $type): array
    {
        return $this->types()[$type] ?? abort(404, 'Unknown reference type.');
    }

    public function index(Request $request, string $type): JsonResponse
    {
        $c = $this->cfg($type);
        $q = $c['model']::query();

        if ($s = trim((string) $request->query('search', ''))) {
            $q->where(fn ($w) => $w->where($type === 'lifestyle' ? 'label' : 'name', 'like', "%{$s}%"));
        }

        $page = $q->orderBy($c['order'])->orderBy('id')->paginate(min(200, max(10, (int) $request->query('per_page', 50))));

        if ($c['usage']) {
            $col = ['interests' => 'interest_id', 'languages' => 'language_id', 'lifestyle' => 'lifestyle_option_id'][$type];
            $counts = DB::table($c['usage'])->whereIn($col, $page->getCollection()->pluck('id'))
                ->selectRaw("$col as k, COUNT(*) c")->groupBy($col)->pluck('c', 'k');
        } else {
            $counts = DB::table('users')->whereIn('city_id', $page->getCollection()->pluck('id'))
                ->selectRaw('city_id as k, COUNT(*) c')->groupBy('city_id')->pluck('c', 'k');
        }
        $page->getCollection()->transform(fn ($m) => array_merge($m->toArray(), ['users' => (int) ($counts[$m->id] ?? 0)]));

        return response()->json($page);
    }

    public function store(Request $request, string $type): JsonResponse
    {
        $c = $this->cfg($type);
        $row = $c['model']::query()->create($request->validate($c['rules'](null)));
        AuditLogger::log($request, "reference.{$type}.create", $type, $row->id);

        return response()->json($row, 201);
    }

    public function update(Request $request, string $type, int $id): JsonResponse
    {
        $c = $this->cfg($type);
        $row = $c['model']::query()->findOrFail($id);
        $row->update($request->validate($c['rules']($id)));
        AuditLogger::log($request, "reference.{$type}.update", $type, $id);

        return response()->json($row);
    }

    public function destroy(Request $request, string $type, int $id): JsonResponse
    {
        $c = $this->cfg($type);
        $c['model']::query()->findOrFail($id)->delete();
        AuditLogger::log($request, "reference.{$type}.delete", $type, $id);

        return response()->json(['ok' => true]);
    }
}
