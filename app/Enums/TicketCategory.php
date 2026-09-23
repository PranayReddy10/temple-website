<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * What the ticket is about, in the reporter's terms rather than ours.
 *
 * The list is short on purpose. A form with twenty categories gets one
 * answer — "other" — because nobody reads past the fifth, and a category
 * nobody picks accurately is worse than no category at all.
 */
enum TicketCategory: string implements HasLabel
{
    case WrongInformation = 'wrong_information';
    case InappropriateContent = 'inappropriate_content';
    case Duplicate = 'duplicate';
    case Account = 'account';
    case Booking = 'booking';
    case AppProblem = 'app_problem';
    case Suggestion = 'suggestion';
    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::WrongInformation => 'Wrong information',
            self::InappropriateContent => 'Inappropriate content',
            self::Duplicate => 'Duplicate listing',
            self::Account => 'Account or sign-in',
            self::Booking => 'Puja or booking',
            self::AppProblem => 'Something is broken',
            self::Suggestion => 'Suggestion',
            self::Other => 'Something else',
        };
    }

    /**
     * What a person picking this is telling us it is, before staff triage.
     *
     * Shown under the choice in the app so the categories mean the same
     * thing to the person filing and the person reading.
     */
    public function description(): string
    {
        return match ($this) {
            self::WrongInformation => 'Timings, address, phone number or anything else that is out of date.',
            self::InappropriateContent => 'A photo or text that does not belong on this listing.',
            self::Duplicate => 'This temple already appears somewhere else.',
            self::Account => 'Signing in, your profile, or your passport.',
            self::Booking => 'A seva or puja booking link that does not work.',
            self::AppProblem => 'The app crashed, froze or showed an error.',
            self::Suggestion => 'Something you would like the app to do.',
            self::Other => 'Anything that does not fit the others.',
        };
    }

    /**
     * Whether this category is urgent enough that a new ticket starts high.
     *
     * Content that should not be on a place of worship's listing is the one
     * thing here that gets worse every hour it stays up.
     */
    public function isUrgentByDefault(): bool
    {
        return $this === self::InappropriateContent;
    }

    /** Categories that only make sense against a record. */
    public function needsASubject(): bool
    {
        return in_array($this, [
            self::WrongInformation,
            self::InappropriateContent,
            self::Duplicate,
        ], true);
    }
}
