<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Site\SiteController;
use App\Models\PlatformStaff;
use App\Models\PublicPage;
use App\Support\Site\PageAdmin;
use App\Support\Site\PageSanitizer;
use App\Support\Site\PricingTable;
use App\Support\Site\SiteRejected;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class PageController extends Controller
{
    public function __construct(private readonly PageAdmin $pages) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->pages->pages()]);
    }

    public function show(string $page): JsonResponse
    {
        return $this->guard(fn (): JsonResponse => response()->json($this->pages->page($page)));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'slug' => ['required', 'string', 'max:60'],
            'parent_id' => ['sometimes', 'nullable', 'uuid'],
            'menu' => ['sometimes', 'string', Rule::in(PublicPage::MENUS)],
            'sort' => ['sometimes', 'integer', 'min:-1000', 'max:1000'],
        ]);

        return $this->guard(fn (PlatformStaff $staff): JsonResponse => response()->json($this->pages->page($this->pages->create($staff->id, $validated)->id), 201), $request);
    }

    public function update(Request $request, string $page): JsonResponse
    {
        $validated = $request->validate([
            'menu' => ['sometimes', 'string', Rule::in(PublicPage::MENUS)],
            'sort' => ['sometimes', 'integer', 'min:-1000', 'max:1000'],
        ]);

        return $this->guard(function (PlatformStaff $staff) use ($page, $validated): JsonResponse {
            $this->pages->update($staff->id, $page, $validated);

            return response()->json($this->pages->page($page));
        }, $request);
    }

    public function saveDraft(Request $request, string $page, string $locale): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'description' => ['sometimes', 'nullable', 'string', 'max:300'],
            'body' => ['sometimes', 'nullable', 'string', 'max:'.PageSanitizer::MAX_LENGTH],
        ]);

        return $this->guard(function (PlatformStaff $staff) use ($page, $locale, $validated): JsonResponse {
            $this->pages->saveDraft($staff->id, $page, $locale, $validated);

            return response()->json($this->pages->page($page));
        }, $request);
    }

    public function discardDraft(Request $request, string $page, string $locale): JsonResponse
    {
        return $this->guard(function (PlatformStaff $staff) use ($page, $locale): JsonResponse {
            $this->pages->discardDraft($staff->id, $page, $locale);

            return response()->json($this->pages->page($page));
        }, $request);
    }

    public function publish(Request $request, string $page, string $locale): JsonResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);

        return $this->guard(function (PlatformStaff $staff) use ($page, $locale, $validated): JsonResponse {
            $this->pages->publish($staff->id, $page, $locale, $validated['reason']);

            return response()->json($this->pages->page($page));
        }, $request);
    }

    public function unpublish(Request $request, string $page, string $locale): JsonResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);

        return $this->guard(function (PlatformStaff $staff) use ($page, $locale, $validated): JsonResponse {
            $this->pages->unpublish($staff->id, $page, $locale, $validated['reason']);

            return response()->json($this->pages->page($page));
        }, $request);
    }

    public function restore(Request $request, string $page, string $version): JsonResponse
    {
        return $this->guard(function (PlatformStaff $staff) use ($page, $version): JsonResponse {
            $this->pages->restore($staff->id, $page, $version);

            return response()->json($this->pages->page($page));
        }, $request);
    }

    public function archive(Request $request, string $page): JsonResponse|Response
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);

        return $this->guard(function (PlatformStaff $staff) use ($page, $validated): Response {
            $this->pages->archive($staff->id, $page, $validated['reason']);

            return response()->noContent();
        }, $request);
    }

    public function preview(Request $request, string $page, PageSanitizer $sanitizer, SiteController $site, PricingTable $pricing): JsonResponse
    {
        $validated = $request->validate([
            'locale' => ['required', 'string', Rule::in(PublicPage::LOCALES)],
            'title' => ['required', 'string', 'max:200'],
            'description' => ['sometimes', 'nullable', 'string', 'max:300'],
            'body' => ['sometimes', 'nullable', 'string', 'max:'.PageSanitizer::MAX_LENGTH],
        ]);

        return $this->guard(function () use ($page, $validated, $sanitizer, $site, $pricing): JsonResponse {
            /** @var PublicPage|null $model */
            $model = PublicPage::query()->whereKey($page)->whereNull('archived_at')->first();

            if ($model === null) {
                throw new SiteRejected('page_not_found', 404);
            }

            $body = $sanitizer->sanitize((string) ($validated['body'] ?? ''));

            return response()->json([
                'body' => $body,
                'html' => $site->previewHtml($model, $validated['locale'], $validated['title'], $validated['description'] ?? null, $body, $pricing),
            ]);
        });
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
            return response()->json(['code' => $exception->reasonCode, 'message' => 'The page change was rejected.'], $exception->status);
        }
    }
}
