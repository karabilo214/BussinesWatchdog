<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\PageBlock;
use App\Models\PlatformStaff;
use App\Models\PublicPage;
use App\Support\Site\PageBlockAdmin;
use App\Support\Site\PageSanitizer;
use App\Support\Site\SiteRejected;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class PageBlockController extends Controller
{
    public function __construct(private readonly PageBlockAdmin $blocks) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->blocks->blocks()]);
    }

    public function show(string $block): JsonResponse
    {
        return $this->guard(fn (): JsonResponse => response()->json($this->blocks->block($block)));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate($this->rules(true));

        return $this->guard(fn (PlatformStaff $staff): JsonResponse => response()->json($this->blocks->block($this->blocks->create($staff->id, $validated)->id), 201), $request);
    }

    public function update(Request $request, string $block): JsonResponse
    {
        $validated = $request->validate($this->rules(false));

        return $this->guard(function (PlatformStaff $staff) use ($block, $validated): JsonResponse {
            $this->blocks->update($staff->id, $block, $validated);

            return response()->json($this->blocks->block($block));
        }, $request);
    }

    public function archive(Request $request, string $block): JsonResponse|Response
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);

        return $this->guard(function (PlatformStaff $staff) use ($block, $validated): Response {
            $this->blocks->archive($staff->id, $block, $validated['reason']);

            return response()->noContent();
        }, $request);
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:120'],
            'category' => [$required, 'string', Rule::in(PageBlock::CATEGORIES)],
            'sort' => ['sometimes', 'integer', 'min:-1000', 'max:1000'],
            'bodies' => [$required, 'array:'.implode(',', PublicPage::LOCALES)],
            'bodies.*' => ['nullable', 'string', 'max:'.PageSanitizer::MAX_LENGTH],
        ];
    }

    /**
     * @param  Closure(PlatformStaff): (JsonResponse|Response)  $action
     */
    private function guard(Closure $action, ?Request $request = null): JsonResponse|Response
    {
        $request ??= request();
        /** @var PlatformStaff $staff */
        $staff = $request->attributes->get('platform_staff');

        try {
            return $action($staff);
        } catch (SiteRejected $exception) {
            return response()->json(['code' => $exception->reasonCode, 'message' => 'The block template change was rejected.'], $exception->status);
        }
    }
}
