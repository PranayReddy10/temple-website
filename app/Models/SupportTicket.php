<?php

namespace App\Models;

use App\Enums\TicketCategory;
use App\Enums\TicketKind;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;

/**
 * Something somebody told us, and what we did about it.
 */
class SupportTicket extends Model
{
    use HasFactory;

    protected $fillable = [
        'reference', 'kind', 'category', 'status', 'priority',
        'subject', 'body',
        'devotee_id', 'user_id', 'reporter_name', 'reporter_email',
        'about_type', 'about_id',
        'assigned_to', 'resolved_at', 'resolved_by', 'resolution_note',
        'source', 'app_version', 'platform',
    ];

    protected $attributes = [
        'kind' => 'support',
        'category' => 'other',
        'status' => 'new',
        'priority' => 'normal',
    ];

    protected function casts(): array
    {
        return [
            'kind' => TicketKind::class,
            'category' => TicketCategory::class,
            'status' => TicketStatus::class,
            'priority' => TicketPriority::class,
            'resolved_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        /*
         * The reference is generated here, not by the caller.
         *
         * It is the only handle somebody without an account has on their own
         * ticket, so every row must have one however it was created — through
         * the API, a seeder or the admin. Generated on creating rather than
         * saved afterwards, because a row that exists without one for even a
         * moment is a row that can be read without one.
         */
        static::creating(function (self $ticket): void {
            $ticket->reference ??= static::generateReference();

            // Content that should not be on a place of worship's listing gets
            // worse every hour it stays up, so it does not wait its turn.
            if ($ticket->category?->isUrgentByDefault() && $ticket->priority === TicketPriority::Normal) {
                $ticket->priority = TicketPriority::Urgent;
            }
        });
    }

    public static function generateReference(): string
    {
        do {
            // Unambiguous alphabet: no O/0 or I/1, because this gets read
            // over the phone and written down.
            $reference = 'TP-'.Str::upper(Str::password(6, symbols: false, numbers: true, letters: true));
            $reference = str_replace(['O', '0', 'I', 'l', '1'], ['R', '4', 'J', 'k', '7'], $reference);
        } while (static::where('reference', $reference)->exists());

        return $reference;
    }

    // --- Relations ---

    public function devotee(): BelongsTo
    {
        return $this->belongsTo(Devotee::class);
    }

    /** A staff or temple account, when the ticket came from inside. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function about(): MorphTo
    {
        return $this->morphTo();
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(SupportTicketMessage::class)->oldest();
    }

    /** What the reporter can see: the replies, never the internal notes. */
    public function replies(): HasMany
    {
        return $this->messages()->where('is_internal', false);
    }

    // --- Scopes ---

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', TicketStatus::openValues());
    }

    public function scopeReports(Builder $query): Builder
    {
        return $query->where('kind', TicketKind::Report);
    }

    public function scopeUnassigned(Builder $query): Builder
    {
        return $query->whereNull('assigned_to');
    }

    /**
     * Worst first, then oldest.
     *
     * Priority alone would let an urgent ticket from this morning jump one
     * from last week; age alone would leave an urgent one behind a week of
     * suggestions.
     */
    public function scopeInQueueOrder(Builder $query): Builder
    {
        $cases = collect(TicketPriority::cases())
            ->map(fn (TicketPriority $p): string => "WHEN '{$p->value}' THEN {$p->weight()}")
            ->implode(' ');

        return $query
            ->orderByRaw("CASE priority {$cases} ELSE 0 END DESC")
            ->orderBy('created_at');
    }

    // --- Helpers ---

    public function isOpen(): bool
    {
        return $this->status?->needsAttention() ?? false;
    }

    /** Who to write back to, whichever way they reached us. */
    public function reporterName(): string
    {
        return $this->devotee?->name
            ?? $this->user?->name
            ?? $this->reporter_name
            ?? 'Someone not signed in';
    }

    public function reporterEmail(): ?string
    {
        return $this->devotee?->email ?? $this->user?->email ?? $this->reporter_email;
    }

    /**
     * A one-line description of what this is about.
     *
     * Falls back rather than erroring when the record has since been deleted,
     * which is a normal outcome: a duplicate listing gets merged away, and
     * the ticket that reported it outlives it.
     */
    public function aboutLabel(): ?string
    {
        if ($this->about_type === null) {
            return null;
        }

        $subject = $this->about;

        if ($subject === null) {
            return class_basename($this->about_type).' (since deleted)';
        }

        return class_basename($subject).': '.($subject->name ?? $subject->title ?? '#'.$subject->getKey());
    }

    public function waitingSince(): ?string
    {
        return $this->isOpen() ? $this->created_at?->diffForHumans() : null;
    }
}
