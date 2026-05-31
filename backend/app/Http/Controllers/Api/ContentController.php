<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\NewsItem;
use App\Models\Page;
use App\Services\DemoData;
use App\Services\LegacySchema;
use App\Services\TranslationService;
use Illuminate\Http\Request;

class ContentController extends Controller
{
    public function __construct(private TranslationService $translator)
    {
    }

    public function newsIndex(Request $request)
    {
        if (! LegacySchema::newsReady()) {
            return DemoData::paginatedNews($request, min((int) $request->integer('per_page', 10), 50));
        }

        return NewsItem::query()
            ->published()
            ->latest('date')
            ->paginate(min((int) $request->integer('per_page', 10), 50));
    }

    public function newsShow(Request $request, NewsItem $news)
    {
        abort_unless(LegacySchema::newsReady(), 404);
        abort_unless($news->id > 0 && $news->published, 404);

        return $this->translateNews($news, $this->translator->targetLanguage($request->query('lang')));
    }

    public function pagesIndex()
    {
        if (! LegacySchema::pagesReady()) {
            return [];
        }

        return Page::query()->alive()->orderBy('title')->get(['id', 'title', 'alias']);
    }

    public function pageShow(Request $request, string $alias)
    {
        if ($alias === 'feedback') {
            return $this->feedbackPage($request);
        }

        abort_unless(LegacySchema::pagesReady(), 404);

        $page = Page::query()->alive()->where('alias', $alias)->firstOrFail();
        $lang = $this->translator->targetLanguage($request->query('lang'));

        if (! $lang) {
            return $page;
        }

        return [
            'id' => $page->id,
            'title' => $this->translator->translate($page->title, $lang),
            'alias' => $page->alias,
            // Full page HTML is too large for free translation APIs — keep original body.
            'content' => $page->content,
        ];
    }

    private function feedbackPage(Request $request): array
    {
        $lang = $this->translator->targetLanguage($request->query('lang'));
        $title = 'Հետադարձ կապ';

        if (LegacySchema::pagesReady()) {
            $row = Page::query()->alive()->where('alias', 'feedback')->first();
            if ($row?->title) {
                $title = $row->title;
            }
        }

        if ($lang) {
            $title = $this->translator->translate($title, $lang) ?? $title;
        }

        return [
            'id' => 0,
            'alias' => 'feedback',
            'title' => $title,
            'content' => '',
            'type' => 'feedback',
        ];
    }

    private function translateNews(NewsItem $item, ?string $lang): array
    {
        $data = $item->toArray();

        if (! $lang) {
            return $data;
        }

        $data['title'] = $this->translator->translate($item->title, $lang);

        return $data;
    }
}
