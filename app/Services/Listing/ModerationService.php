<?php

namespace App\Services\Listing;

use App\Models\ListingModerationLog;
use App\Models\PerformerTransport;
use App\Services\Sms\SmsGateway;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Переходы статусов объявления + журнал решений.
 *
 * Все смены статуса проходят только здесь: разрешённые переходы описаны
 * одной таблицей, чтобы «можно ли из paused в pending» не решалось
 * заново в каждом контроллере.
 */
class ModerationService
{
    private const TRANSITIONS = [
        PerformerTransport::STATUS_PENDING => [
            PerformerTransport::STATUS_PUBLISHED,
            PerformerTransport::STATUS_REJECTED,
            PerformerTransport::STATUS_ARCHIVED,
        ],
        PerformerTransport::STATUS_PUBLISHED => [
            PerformerTransport::STATUS_PAUSED,
            PerformerTransport::STATUS_PENDING,
            PerformerTransport::STATUS_ARCHIVED,
        ],
        PerformerTransport::STATUS_REJECTED => [
            PerformerTransport::STATUS_PENDING,
            PerformerTransport::STATUS_ARCHIVED,
        ],
        PerformerTransport::STATUS_PAUSED => [
            PerformerTransport::STATUS_PUBLISHED,
            PerformerTransport::STATUS_PENDING,
            PerformerTransport::STATUS_ARCHIVED,
        ],
        // Владелец может вернуть архивную карточку на витрину сам — тем же
        // resume(), что и после паузы: содержимое не менялось, повторная
        // проверка не нужна. Раньше архив был тупиком без выхода.
        PerformerTransport::STATUS_ARCHIVED => [
            PerformerTransport::STATUS_PUBLISHED,
        ],
    ];

    public function __construct(
        private readonly SmsGateway $sms,
        private readonly ListingService $listings,
    ) {
    }

    public function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    public function approve(PerformerTransport $listing, ?int $moderatorId, ?string $comment = null): PerformerTransport
    {
        $blockers = $this->listings->publishBlockers($listing);

        if ($blockers) {
            throw ValidationException::withMessages(['listing' => $blockers]);
        }

        return $this->transition(
            $listing,
            PerformerTransport::STATUS_PUBLISHED,
            $moderatorId,
            $comment,
            function (PerformerTransport $l) {
                $l->rejection_reason = null;
                $l->published_at = $l->published_at ?? now();
            },
            fn (PerformerTransport $l) => $this->notify($l, 'approved', [':title' => $this->titleOf($l)])
        );
    }

    public function reject(PerformerTransport $listing, ?int $moderatorId, string $reason, ?string $comment = null): PerformerTransport
    {
        return $this->transition(
            $listing,
            PerformerTransport::STATUS_REJECTED,
            $moderatorId,
            $comment ?: $reason,
            function (PerformerTransport $l) use ($reason) {
                $l->rejection_reason = $reason;
            },
            fn (PerformerTransport $l) => $this->notify($l, 'rejected', [
                ':title'  => $this->titleOf($l),
                ':reason' => $reason,
            ])
        );
    }

    /**
     * $comment — причина снятия с публикации (ТЗ, решение от 26.09.2026):
     * владелец выбирает готовый вариант или пишет свой, менеджер видит его
     * в журнале решений объявления.
     */
    public function pause(PerformerTransport $listing, ?string $comment = null): PerformerTransport
    {
        return $this->transition($listing, PerformerTransport::STATUS_PAUSED, null, $comment ?: 'Снято владельцем');
    }

    public function resume(PerformerTransport $listing): PerformerTransport
    {
        $blockers = $this->listings->publishBlockers($listing);

        if ($blockers) {
            throw ValidationException::withMessages(['listing' => $blockers]);
        }

        return $this->transition($listing, PerformerTransport::STATUS_PUBLISHED, null, 'Возвращено владельцем');
    }

    public function archive(PerformerTransport $listing, string $reason = 'Удалено владельцем'): PerformerTransport
    {
        return $this->transition($listing, PerformerTransport::STATUS_ARCHIVED, null, $reason);
    }

    public function resubmit(PerformerTransport $listing): PerformerTransport
    {
        $blockers = $this->listings->publishBlockers($listing);

        if ($blockers) {
            throw ValidationException::withMessages(['listing' => $blockers]);
        }

        return $this->transition(
            $listing,
            PerformerTransport::STATUS_PENDING,
            null,
            'Отправлено на повторную проверку',
            function (PerformerTransport $l) {
                $l->submitted_at = now();
                $l->rejection_reason = null;
            }
        );
    }

    private function transition(
        PerformerTransport $listing,
        string $to,
        ?int $moderatorId = null,
        ?string $comment = null,
        ?callable $mutate = null,
        ?callable $after = null,
    ): PerformerTransport {
        $from = (string) $listing->moderation_status;

        if ($from === $to) {
            return $listing;
        }

        if (!$this->canTransition($from, $to)) {
            throw ValidationException::withMessages([
                'moderation_status' => ["Переход из «{$from}» в «{$to}» не разрешён."],
            ]);
        }

        DB::transaction(function () use ($listing, $from, $to, $moderatorId, $comment, $mutate) {
            $listing->moderation_status = $to;

            if ($mutate) {
                $mutate($listing);
            }

            $listing->save();

            ListingModerationLog::create([
                'performer_transport_id' => $listing->id,
                'moderator_id'           => $moderatorId,
                'from_status'            => $from,
                'to_status'              => $to,
                'comment'                => $comment,
            ]);
        });

        if ($after) {
            $after($listing);
        }

        return $listing;
    }

    private function notify(PerformerTransport $listing, string $template, array $replacements): void
    {
        $owner = $listing->owner;

        if (!$owner) {
            return;
        }

        $this->sms->send($owner->phone, strtr(config("sms.templates.{$template}"), $replacements));
    }

    private function titleOf(PerformerTransport $listing): string
    {
        if ($listing->title) {
            return $listing->title;
        }

        $listing->loadMissing('model_car.brand');

        return trim(($listing->model_car?->brand?->name ?? '') . ' ' . ($listing->model_car?->car_model ?? ''))
            ?: "объявление #{$listing->id}";
    }
}
