<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LandingApplicationRequest;
use App\Http\Resources\Landing\OfferDetailResource;
use App\Http\Resources\Landing\OfferListResource;
use App\Models\ApplicationStatus;
use App\Models\BodyType;
use App\Models\CarBrand;
use App\Models\CarOption;
use App\Models\City;
use App\Models\ColorCar;
use App\Models\Gearbox;
use App\Models\Marka;
use App\Models\Owner;
use App\Models\PerformerTransport;
use App\Models\RentalApplication;
use App\Models\RentalTariff;
use App\Services\CarFilterService;
use App\Services\Listing\AvailabilityService;
use App\Services\Listing\PriceCalculator;
use App\Services\Owner\PhoneNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LandingController extends Controller
{
    public function __construct(
        private readonly AvailabilityService $availability,
        private readonly PriceCalculator $calculator,
    ) {
    }

    // ------------------------------------------------------------------
    // Справочники
    // ------------------------------------------------------------------

    public function cities(): JsonResponse
    {
        return app(CityController::class)->index();
    }

    /**
     * Город по умолчанию — Худжанд (ТЗ §6.1). Фронт спрашивает его у бэка,
     * а не хардкодит, чтобы менеджер мог поменять из админки.
     */
    public function defaultCity(): JsonResponse
    {
        $city = City::where('is_default', true)->first()
            ?? City::orderBy('sort')->orderBy('id')->first();

        if (!$city) {
            return $this->error('Справочник городов пуст.', 404);
        }

        return $this->success(['id' => $city->id, 'name' => $city->name]);
    }

    public function rentalTariffs(): JsonResponse
    {
        return $this->success(RentalTariff::orderByDesc('id')->get());
    }

    public function gearboxes(): JsonResponse
    {
        return $this->success(Gearbox::orderBy('id')->get());
    }

    public function fuelTypes(): JsonResponse
    {
        return $this->success(
            CarOption::where('model', 'car_fuel_type')
                ->where('is_active', 1)
                ->get(['id', 'name'])
        );
    }

    public function carBrands(): JsonResponse
    {
        return $this->success(CarBrand::orderBy('name')->get(['id', 'name']));
    }

    public function carModels(Request $request): JsonResponse
    {
        $models = Marka::query()
            ->when($request->filled('brand_id'), fn ($q) => $q->where('car_brand_id', $request->integer('brand_id')))
            ->orderBy('car_model')
            ->get(['id', 'car_model', 'car_brand_id']);

        return $this->success($models);
    }

    public function bodyTypes(): JsonResponse
    {
        return $this->success(BodyType::orderBy('name')->get(['id', 'name']));
    }

    public function colors(): JsonResponse
    {
        return $this->success(ColorCar::orderBy('name')->get(['id', 'name']));
    }

    // ------------------------------------------------------------------
    // Витрина
    // ------------------------------------------------------------------

    public function offers(Request $request): JsonResponse
    {
        $query = PerformerTransport::query()
            ->visibleOnShowcase()
            ->with([
                'model_car.brand', 'model_car.category_car', 'model_car.class_car',
                'body_type', 'color', 'fuel_type', 'photos', 'city', 'gearbox',
                'taxiTariff', 'tariffs', 'priceTiers', 'terms',
            ]);

        $query = $this->applyFilters($query, $request);
        $query = $this->applySorting($query, $request);

        $offers = $query->paginate($request->integer('per_page', 12));

        return $this->success([
            'data' => OfferListResource::collection($offers),
            'meta' => [
                'total'        => $offers->total(),
                'per_page'     => $offers->perPage(),
                'current_page' => $offers->currentPage(),
                'last_page'    => $offers->lastPage(),
            ],
        ]);
    }

    public function offer(Request $request, int $id): JsonResponse
    {
        $offer = PerformerTransport::query()
            ->visibleOnShowcase()
            ->with([
                'model_car.brand', 'gearbox', 'city', 'body_type', 'color', 'fuel_type',
                'photos', 'taxiTariff', 'tariffs', 'priceTiers', 'terms', 'unavailablePeriods',
                'dopOptions.car_option', 'owner',
            ])
            ->find($id);

        if (!$offer) {
            return $this->error('Объявление не найдено.', 404);
        }

        // Счётчик просмотров: increment, а не save(), чтобы не затирать
        // параллельные изменения объявления.
        $offer->newQuery()->whereKey($offer->id)->increment('views_count');

        return $this->success(new OfferDetailResource($offer));
    }

    /**
     * Публичный профиль владельца (ТЗ, решение от 26.09.2026): арендатор
     * должен видеть имя и все опубликованные объявления того же владельца,
     * не только карточку одной машины. Заблокированный/несуществующий
     * владелец отдаёт 404 — не палим разницу между «нет такого» и «забанен».
     */
    public function ownerProfile(Request $request, int $id): JsonResponse
    {
        $owner = Owner::find($id);

        if (!$owner || $owner->isBlocked()) {
            return $this->error('Профиль не найден.', 404);
        }

        $listings = PerformerTransport::query()
            ->visibleOnShowcase()
            ->where('owner_id', $owner->id)
            ->with([
                'model_car.brand', 'body_type', 'color', 'fuel_type', 'photos',
                'city', 'gearbox', 'taxiTariff', 'tariffs', 'priceTiers', 'terms',
            ])
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 12));

        return $this->success([
            'owner' => [
                'id'           => $owner->id,
                'display_name' => $owner->display_name,
                'owner_type'   => $owner->owner_type,
                'member_since' => $owner->created_at?->toDateString(),
            ],
            'listings' => [
                'data' => OfferListResource::collection($listings),
                'meta' => [
                    'total'        => $listings->total(),
                    'per_page'     => $listings->perPage(),
                    'current_page' => $listings->currentPage(),
                    'last_page'    => $listings->lastPage(),
                ],
            ],
        ]);
    }

    /**
     * Калькулятор-ориентир для карточки. Не оферта: платежей в системе нет.
     */
    public function priceCalc(Request $request): JsonResponse
    {
        $offer = PerformerTransport::query()
            ->visibleOnShowcase()
            ->with(['priceTiers', 'terms'])
            ->find($request->integer('offer_id'));

        if (!$offer) {
            return $this->error('Объявление не найдено.', 404);
        }

        $result = $this->calculator->calculate(
            $offer,
            $request->input('date_from'),
            $request->input('date_to'),
            $request->boolean('with_delivery')
        );

        if ($result['error']) {
            return $this->error($result['error'], 422, $result);
        }

        return $this->success($result);
    }

    // ------------------------------------------------------------------
    // Заявка
    // ------------------------------------------------------------------

    /**
     * Код для подтверждения телефона в заявке.
     *
     * Отдельно от входа владельца: аккаунт не создаётся, арендатор остаётся
     * анонимным. Смысл — отсечь выдуманные номера, чтобы владелец получал
     * лиды, по которым можно дозвониться.
     */
    public function requestApplyOtp(Request $request): JsonResponse
    {
        $phone = (string) $request->input('phone', '');

        if (!PhoneNormalizer::isValid($phone)) {
            return $this->error('Validation error', 422, [
                'phone' => ['Введите корректный номер телефона.'],
            ]);
        }

        $limit = $this->otp->checkRateLimit($phone, $request->ip());

        if (!$limit['allowed']) {
            return $this->error(
                "Слишком много запросов. Повторите через {$limit['seconds']} сек.",
                429,
                ['retry_after' => $limit['seconds']]
            );
        }

        $issued = $this->otp->issue($phone, OwnerOtpCode::PURPOSE_APPLICATION, $request->ip());

        return $this->success([
            'expires_in' => (int) config('otp.ttl_seconds'),
            'delivery'   => $this->otp->isStubMode() ? 'stub' : 'sms',
            'stub_code'  => $issued['stub_code'],
        ]);
    }

    public function apply(LandingApplicationRequest $request): JsonResponse
    {
        $data = $request->validated();

        // Телефон должен быть подтверждён кодом
        $verified = $this->otp->verify(
            $data['phone'],
            (string) $request->input('code', ''),
            OwnerOtpCode::PURPOSE_APPLICATION
        );

        if (!$verified['ok']) {
            return $this->error($verified['error'], 422, ['code' => [$verified['error']]]);
        }

        $offer = PerformerTransport::query()
            ->visibleOnShowcase()
            ->with(['priceTiers', 'terms'])
            ->find($data['offer_id']);

        // Заявка на снятое с публикации объявление не принимается (ТЗ §9.5).
        if (!$offer) {
            return $this->error('Объявление больше не доступно.', 422);
        }

        if (!empty($data['tariff_id'])) {
            $validTariff = DB::table('car_rental_tariff')
                ->where('rental_tariff_id', $data['tariff_id'])
                ->where('performer_transport_id', $data['offer_id'])
                ->exists();

            if (!$validTariff) {
                return $this->error('Тариф не относится к данному объявлению.', 422);
            }
        }

        $calc = $this->calculator->calculate(
            $offer,
            $data['desired_start_date'] ?? null,
            $data['desired_end_date'] ?? null
        );

        $statusId = ApplicationStatus::where('code', 'new')->value('id')
            ?? ApplicationStatus::first()?->id
            ?? 1;

        $application = RentalApplication::create([
            'performer_transport_id' => $offer->id,
            'owner_id'               => $offer->owner_id,
            'rental_tariff_id'       => $data['tariff_id'] ?? null,
            'price_tier_id'          => $calc['tier_id'],
            'calculated_total'       => $calc['total'],
            'desired_start_date'     => $data['desired_start_date'] ?? null,
            'desired_end_date'       => $data['desired_end_date'] ?? null,
            // Имя необязательно: спрашиваем только телефон, чтобы не терять
            // заявки на лишнем поле. Менеджер и владелец узнают имя при звонке.
            'name'                   => $data['name'] ?? 'Без имени',
            'phone'                  => PhoneNormalizer::normalize($data['phone']),
            'city_id'                => $data['city_id'],
            'comment'                => $data['comment'] ?? null,
            'status_id'              => $statusId,
            'source'                 => 'landing',
        ]);

        $this->notifyOwner($offer, $application);

        return $this->success(
            ['application_id' => $application->id],
            'Заявка принята. Мы свяжемся с вами в ближайшее время.',
            201
        );
    }

    // ------------------------------------------------------------------

    private function applyFilters($query, Request $request)
    {
        // Старые админские фильтры (filter_color_id, filter_year_of_issue и т.п.)
        // оставлены ради обратной совместимости: ручка публичная, кто-то мог
        // ими пользоваться.
        $query = CarFilterService::applyFilters($query, $request);

        $query
            ->when($request->filled('listing_type'), fn ($q) => $q->where('listing_type', $request->input('listing_type')))
            ->when($request->filled('city_id'), fn ($q) => $q->where('city_id', $request->integer('city_id')))
            ->when($request->filled('gearbox_id'), fn ($q) => $q->where('gearbox_id', $request->integer('gearbox_id')))
            ->when($request->filled('fuel_type_id'), fn ($q) => $q->where('fuel_type_id', $request->integer('fuel_type_id')))
            ->when($request->filled('body_type_id'), fn ($q) => $q->where('body_type_id', $request->integer('body_type_id')))
            ->when($request->filled('model_id'), fn ($q) => $q->where('car_model_id', $request->integer('model_id')))
            ->when($request->filled('year_from'), fn ($q) => $q->where('year_of_issue', '>=', $request->integer('year_from')))
            ->when($request->filled('year_to'), fn ($q) => $q->where('year_of_issue', '<=', $request->integer('year_to')))
            ->when($request->filled('seats_min'), fn ($q) => $q->where('count_seat', '>=', $request->integer('seats_min')))
            ->when($request->filled('min_rent_days_max'), fn ($q) => $q->where('min_rent_days', '<=', $request->integer('min_rent_days_max')))
            ->when($request->filled('brand_id'), fn ($q) => $q->whereHas(
                'model_car',
                fn ($sub) => $sub->where('car_brand_id', $request->integer('brand_id'))
            ))
            ->when($request->filled('deposit_max'), fn ($q) => $q->whereHas(
                'terms',
                fn ($sub) => $sub->where('deposit_amount', '<=', $request->float('deposit_max'))
            ));

        // Цена: у general — из ступеней, у taxi — из старых тарифов.
        // Проверяем обе ветки, иначе фильтр молча выкинул бы один из типов.
        if ($request->filled('price_from') || $request->filled('price_to')) {
            $from = $request->filled('price_from') ? $request->float('price_from') : null;
            $to   = $request->filled('price_to') ? $request->float('price_to') : null;

            $query->where(function ($q) use ($from, $to) {
                $q->whereHas('priceTiers', function ($sub) use ($from, $to) {
                    $sub->when($from !== null, fn ($s) => $s->where('price_per_day', '>=', $from))
                        ->when($to !== null, fn ($s) => $s->where('price_per_day', '<=', $to));
                })->orWhereHas('tariffs', function ($sub) use ($from, $to) {
                    $sub->when($from !== null, fn ($s) => $s->where('price', '>=', $from))
                        ->when($to !== null, fn ($s) => $s->where('price', '<=', $to));
                });
            });
        }

        // Старая таксопарковая фильтрация — сохранена без изменений.
        $query->when($request->filled('tariff_id'), fn ($q) => $q->whereHas(
            'tariffs',
            fn ($tq) => $tq->whereKey($request->integer('tariff_id'))
        ))->when($request->filled('duration_days'), fn ($q) => $q->whereHas(
            'tariffs',
            fn ($tq) => $tq->where('duration_days', $request->integer('duration_days'))
        ));

        return $this->availability->excludeBusy(
            $query,
            $request->input('date_from'),
            $request->input('date_to')
        );
    }

    private function applySorting($query, Request $request)
    {
        // Минимальная цена по объявлению независимо от типа: берём меньшее
        // из ступеней и старых тарифов. COALESCE, потому что у taxi ступеней
        // нет, а у general нет тарифов.
        // С 25.09.2026 — taxi_tariffs.price_per_day, один тариф на объявление.
        // Старые пути (rental_price_tiers у general, rental_tariffs у taxi до
        // этой даты) остаются в LEAST() ради записей, созданных раньше.
        $minPrice = fn () => DB::raw('(SELECT LEAST(
                COALESCE((SELECT tt.price_per_day FROM taxi_tariffs tt
                          WHERE tt.performer_transport_id = performer_transports.id), 999999999),
                COALESCE((SELECT MIN(rpt.price_per_day) FROM rental_price_tiers rpt
                          WHERE rpt.performer_transport_id = performer_transports.id), 999999999),
                COALESCE((SELECT MIN(rt.price) FROM car_rental_tariff crt
                          JOIN rental_tariffs rt ON rt.id = crt.rental_tariff_id
                          WHERE crt.performer_transport_id = performer_transports.id), 999999999)
            ))');

        // Поднятое в топ — всегда первым, независимо от выбранной сортировки.
        // Сама сортировка ниже работает уже внутри двух групп: поднятые
        // между собой, обычные между собой.
        $query->orderByRaw(
            'CASE WHEN performer_transports.boosted_until IS NOT NULL '
            . 'AND performer_transports.boosted_until > ? THEN 0 ELSE 1 END',
            [now()]
        );

        return match ($request->input('sort')) {
            'price_asc'  => $query->orderBy($minPrice()),
            'price_desc' => $query->orderByDesc($minPrice()),
            'year_desc'  => $query->orderByDesc('year_of_issue'),
            'year_asc'   => $query->orderBy('year_of_issue'),
            default      => $query->orderByDesc('performer_transports.id'),
        };
    }

    private function notifyOwner(PerformerTransport $offer, RentalApplication $application): void
    {
        $owner = $offer->owner;

        if (!$owner || $owner->isBlocked()) {
            return;
        }

        $title = $offer->title
            ?: trim(($offer->model_car?->brand?->name ?? '') . ' ' . ($offer->model_car?->car_model ?? ''))
            ?: "объявление #{$offer->id}";

        app(\App\Services\Sms\SmsGateway::class)->send(
            $owner->phone,
            strtr(config('sms.templates.application'), [
                ':title' => $title,
                ':phone' => $application->phone,
            ])
        );
    }
}
