<?php

namespace App\Http\Controllers;

use App\Enums\CustomerRequestStatus;
use App\Models\Category;
use App\Models\Contact;
use App\Models\CustomerRequest;
use App\Models\Listing;
use App\Models\Location;
use App\Services\Ai\CtaLinkBuilder;
use App\Services\Ai\ListingMatcher;
use App\Services\CustomerRequestPlacer;
use App\Services\DereuMessenger;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Throwable;

/**
 * The customer web catalog reached via signed CTA links from WhatsApp
 * (Modules 2–3): every published listing — the chat list shows only the
 * top 10 — with the same text search the chat matcher runs, category
 * and place filters, and the «Выбрать» action that places a customer
 * request exactly like picking a row of the chat list.
 * See docs/modules/whatsapp-integration.md, «Веб-каталог заказчика».
 */
class CustomerCatalogController extends Controller
{
    private const int PER_PAGE = 20;

    private const string SORT_RELEVANCE = 'relevance';

    private const string SORT_NEWEST = 'newest';

    private const string SORT_OLDEST = 'oldest';

    /**
     * The supplier notification quotes the request's query text, so a
     * pick made with an empty search box stores this instead of an empty
     * quote.
     */
    private const string CATALOG_PICK_QUERY = 'выбор в веб-каталоге';

    public function __construct(
        private readonly CtaLinkBuilder $links,
        private readonly ListingMatcher $matcher,
        private readonly CustomerRequestPlacer $placer,
        private readonly DereuMessenger $messenger,
    ) {}

    public function index(Request $request, Contact $contact): View
    {
        $filters = $this->filters($request);
        $listings = $this->paginate($filters);
        $pageIds = collect($listings->items())->pluck('id');

        return view('customer.catalog', [
            'filters' => $filters,
            'listings' => $listings,
            'categories' => Category::query()->orderBy('name')->get(),
            'locationLabel' => $filters['location']?->label(),
            'resetUrl' => $this->links->catalogUrl($contact),
            'signature' => (string) $request->query('signature'),
            'expires' => (string) $request->query('expires'),
            'selectUrls' => collect($listings->items())->mapWithKeys(
                fn (Listing $listing): array => [$listing->id => $this->links->selectUrl($contact, $listing)],
            ),
            'requestedListingIds' => CustomerRequest::query()
                ->where('contact_id', $contact->id)
                ->where('status', CustomerRequestStatus::Pending)
                ->whereIn('listing_id', $pageIds)
                ->pluck('listing_id')
                ->all(),
        ]);
    }

    /**
     * «Выбрать» on a catalog card: the same request placement as picking
     * a row of the chat list, deduplicated against a still-pending
     * request for the same listing. The open chat dialog (if any) is
     * deliberately left untouched — equipment is never locked and
     * parallel requests are the norm.
     */
    public function select(Request $request, Contact $contact, Listing $listing): RedirectResponse
    {
        $catalogUrl = $this->catalogReturnUrl($request, $contact);

        // The listing may have expired or been archived between the page
        // render and the click — an honest refusal instead of a request
        // the supplier can no longer serve.
        if (! Listing::query()->searchable()->whereKey($listing->getKey())->exists()) {
            return redirect()->to($catalogUrl)
                ->with('error', 'Это объявление уже не публикуется. Выберите, пожалуйста, другой вариант.');
        }

        $queryText = trim((string) $request->input('q', ''));
        $customerRequest = $this->placer->place(
            $contact,
            $listing,
            $queryText === '' ? self::CATALOG_PICK_QUERY : $queryText,
        );

        if (! $customerRequest->wasRecentlyCreated) {
            return redirect()->to($catalogUrl)
                ->with('status', 'Вы уже отправляли заявку по этому объявлению — ждём ответа поставщика.');
        }

        $this->confirmInWhatsapp($contact, $listing);

        return redirect()->to($catalogUrl)
            ->with('status', 'Заявка отправлена поставщику. Его ответ придёт вам в WhatsApp.');
    }

    /**
     * The filters arrive on a deep link from WhatsApp, so anything
     * invalid degrades silently — junk values are dropped, unknown sorts
     * fall back to the default — instead of erroring a page that has no
     * «back» to redirect to.
     *
     * @return array{q: string, category: Category|null, location: Location|null, sort: string}
     */
    protected function filters(Request $request): array
    {
        $q = trim((string) $request->query('q', ''));
        $sort = (string) $request->query('sort', '');

        if (! in_array($sort, [self::SORT_RELEVANCE, self::SORT_NEWEST, self::SORT_OLDEST], true)
            || ($sort === self::SORT_RELEVANCE && $q === '')) {
            $sort = $q === '' ? self::SORT_NEWEST : self::SORT_RELEVANCE;
        }

        return [
            'q' => $q,
            'category' => Category::find((int) $request->query('category_id')),
            'location' => Location::find((int) $request->query('location_id')),
            'sort' => $sort,
        ];
    }

    /**
     * With a search query the catalog shows exactly what the chat search
     * would rank (the same matcher, without its top-10 cap), paginated
     * DB-side over the ranked ids; without one it is a plain browse of
     * searchable listings.
     *
     * @param  array{q: string, category: Category|null, location: Location|null, sort: string}  $filters
     * @return LengthAwarePaginator<int, Listing>
     */
    protected function paginate(array $filters): LengthAwarePaginator
    {
        $query = Listing::query()
            ->searchable()
            ->with(['supplier', 'category', 'brand', 'location', 'photos'])
            ->when($filters['category'], fn (Builder $builder, Category $category): Builder => $builder->where('category_id', $category->id));

        if ($filters['q'] !== '') {
            $ids = $this->matcher->matchAll($filters['q'], $filters['location'])->pluck('id');

            $query->whereIn('listings.id', $ids);

            if ($filters['sort'] === self::SORT_RELEVANCE) {
                // The ids are integers straight from the database; non-pgsql
                // drivers have no array_position and fall back to newest-first.
                DB::getDriverName() === 'pgsql' && $ids->isNotEmpty()
                    ? $query->orderByRaw('array_position(ARRAY['.$ids->implode(',').']::bigint[], listings.id)')
                    : $query->latest('id');
            }
        } else {
            $query->inLocation($filters['location']);
        }

        if ($filters['sort'] === self::SORT_NEWEST) {
            $query->latest('id');
        } elseif ($filters['sort'] === self::SORT_OLDEST) {
            $query->oldest('id');
        }

        return $query->paginate(self::PER_PAGE)->withQueryString();
    }

    /**
     * The way back to the catalog page the customer clicked on: a fresh
     * signed URL plus the filter state posted by the form's hidden
     * fields — never a user-supplied return URL.
     */
    protected function catalogReturnUrl(Request $request, Contact $contact): string
    {
        $filters = http_build_query(array_filter($request->only(['q', 'location_id', 'category_id', 'sort', 'page'])));

        $url = $this->links->catalogUrl($contact);

        return $filters === '' ? $url : $url.'&'.$filters;
    }

    /**
     * Best-effort: the web flash is the confirmation of record. A closed
     * 24-hour window (a catalog visit days after the link arrived) skips
     * the message, and a delivery problem never breaks the placed
     * request — no paid template is spent on this.
     */
    protected function confirmInWhatsapp(Contact $contact, Listing $listing): void
    {
        if (! $contact->hasOpenSessionWindow()) {
            return;
        }

        try {
            $this->messenger->sendText($contact, sprintf(
                'Заявка по варианту «%s» отправлена поставщику. Как только он ответит, мы сразу сообщим вам.',
                $listing->displayName() ?: 'объявление',
            ));
        } catch (Throwable $e) {
            Log::warning('Failed to confirm a web-catalog request in WhatsApp.', [
                'contact_id' => $contact->id,
                'listing_id' => $listing->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
