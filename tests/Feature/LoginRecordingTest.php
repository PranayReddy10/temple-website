<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Devotee;
use App\Models\LoginEvent;
use App\Models\User;
use App\Support\DevoteeStats;
use Filament\Auth\Pages\Login;
use Livewire\Livewire;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Sign-ins as events, not just a timestamp.
 *
 * `last_login_at` can only hold the latest value. It cannot answer how many
 * people signed in this week, whether a returning devotee is a daily user,
 * or that one account has failed twenty attempts from three addresses — and
 * none of that can be reconstructed afterwards, which is why the event is
 * written at the time.
 */
class LoginRecordingTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_devotee_sign_in_is_recorded(): void
    {
        $devotee = Devotee::factory()->create([
            'email' => 'devotee@example.com',
            'password' => Hash::make('a-good-password'),
        ]);

        $this->postJson('/api/v1/auth/login', [
            'identifier' => 'devotee@example.com',
            'password' => 'a-good-password',
        ])->assertOk();

        $event = LoginEvent::firstOrFail();

        $this->assertTrue($event->succeeded);
        $this->assertSame('devotee', $event->guard);
        $this->assertSame($devotee->id, $event->authenticatable_id);
        $this->assertTrue($event->authenticatable->is($devotee));
    }

    public function test_a_staff_sign_in_is_recorded_and_stamps_the_column(): void
    {
        $user = User::factory()->create([
            'role' => UserRole::SuperAdmin,
            'is_active' => true,
            'email' => 'admin@example.com',
            'password' => Hash::make('a-good-password'),
        ]);

        // Through Filament's own login component, not a POST: the panel
        // signs in over Livewire, so a plain POST proves nothing about the
        // path a person actually takes.
        Livewire::test(Login::class)
            ->fillForm(['email' => 'admin@example.com', 'password' => 'a-good-password'])
            ->call('authenticate')
            ->assertHasNoFormErrors();

        $event = LoginEvent::query()->forGuard('web')->firstOrFail();

        $this->assertTrue($event->succeeded);
        $this->assertSame($user->id, $event->authenticatable_id);

        // Both: the column answers "when last", the event answers "how many".
        $this->assertNotNull($user->fresh()->last_login_at);
    }

    /** Failures on the panel are recorded by the same listener. */
    public function test_a_failed_staff_sign_in_is_recorded(): void
    {
        User::factory()->create([
            'role' => UserRole::SuperAdmin,
            'is_active' => true,
            'email' => 'admin@example.com',
            'password' => Hash::make('a-good-password'),
        ]);

        Livewire::test(Login::class)
            ->fillForm(['email' => 'admin@example.com', 'password' => 'the-wrong-one'])
            ->call('authenticate');

        $event = LoginEvent::query()->forGuard('web')->firstOrFail();

        $this->assertFalse($event->succeeded);
        $this->assertSame('admin@example.com', $event->identifier);
    }

    /** A burst of these against one account is the first sign of an attack. */
    public function test_a_failed_devotee_sign_in_is_recorded_with_its_reason(): void
    {
        Devotee::factory()->create([
            'email' => 'devotee@example.com',
            'password' => Hash::make('a-good-password'),
        ]);

        $this->postJson('/api/v1/auth/login', [
            'identifier' => 'devotee@example.com',
            'password' => 'the-wrong-one',
        ])->assertStatus(422);

        $event = LoginEvent::firstOrFail();

        $this->assertFalse($event->succeeded);
        $this->assertSame('bad_password', $event->failure_reason);
        $this->assertSame('devotee@example.com', $event->identifier);
    }

    public function test_an_attempt_against_an_unknown_account_is_recorded_too(): void
    {
        $this->postJson('/api/v1/auth/login', [
            'identifier' => 'nobody@example.com',
            'password' => 'whatever',
        ])->assertStatus(422);

        $event = LoginEvent::firstOrFail();

        $this->assertFalse($event->succeeded);
        $this->assertSame('unknown_account', $event->failure_reason);
        // Nothing to point at, which is the case worth counting.
        $this->assertNull($event->authenticatable_id);
    }

    /** A field that holds a password sometimes is a field that holds passwords. */
    public function test_a_password_is_never_written_to_the_event(): void
    {
        Devotee::factory()->create([
            'email' => 'devotee@example.com',
            'password' => Hash::make('a-good-password'),
        ]);

        $this->postJson('/api/v1/auth/login', [
            'identifier' => 'devotee@example.com',
            'password' => 'SUPERSECRETVALUE',
        ])->assertStatus(422);

        $row = json_encode(LoginEvent::firstOrFail()->getAttributes());

        $this->assertStringNotContainsString('SUPERSECRETVALUE', $row);
    }

    public function test_a_suspended_devotee_s_attempt_is_recorded_as_such(): void
    {
        Devotee::factory()->inactive()->create([
            'email' => 'suspended@example.com',
            'password' => Hash::make('a-good-password'),
        ]);

        $this->postJson('/api/v1/auth/login', [
            'identifier' => 'suspended@example.com',
            'password' => 'a-good-password',
        ])->assertStatus(422);

        $this->assertSame('inactive_account', LoginEvent::firstOrFail()->failure_reason);
    }

    // --- What the analytics screen reads ---

    /**
     * The metric most often got wrong: eight sessions from one devotee is one
     * active user, and a dashboard that says eight will be quoted to someone.
     */
    public function test_active_users_counts_people_and_sign_ins_counts_sessions(): void
    {
        $devotee = Devotee::factory()->create();
        $other = Devotee::factory()->create();

        foreach (range(1, 8) as $ignored) {
            $this->recordSignIn($devotee, now());
        }

        $this->recordSignIn($other, now());

        $this->assertSame(2, DevoteeStats::activeUsers(1));
        $this->assertSame(9, DevoteeStats::signIns(1));
    }

    public function test_the_active_window_excludes_events_outside_it(): void
    {
        $devotee = Devotee::factory()->create();

        $this->recordSignIn($devotee, now()->subDays(40));

        $this->assertSame(0, DevoteeStats::activeUsers(30));

        $this->recordSignIn($devotee, now()->subDays(5));

        $this->assertSame(1, DevoteeStats::activeUsers(30));
        $this->assertSame(0, DevoteeStats::activeUsers(1));
    }

    /** Staff and devotee figures must never be summed by accident. */
    public function test_the_two_guards_are_counted_separately(): void
    {
        $devotee = Devotee::factory()->create();
        $staff = User::factory()->create(['role' => UserRole::Editor, 'is_active' => true]);

        $this->recordSignIn($devotee, now());
        $this->recordSignIn($staff, now(), 'web');

        $this->assertSame(1, DevoteeStats::activeUsers(30));
        $this->assertSame(1, DevoteeStats::activeStaff(30));
    }

    /**
     * A quiet day must plot as a quiet day. Grouping in SQL returns only the
     * days that had something, and a chart drawn from that closes the gap.
     */
    public function test_the_daily_series_includes_the_empty_days(): void
    {
        $devotee = Devotee::factory()->create();

        $this->recordSignIn($devotee, now());
        $this->recordSignIn($devotee, now()->subDays(6));

        $series = DevoteeStats::dailySeries(
            'login_events',
            'occurred_at',
            7,
            fn ($q) => $q->where('guard', 'devotee')->where('succeeded', true),
        );

        $this->assertCount(7, $series);
        $this->assertSame(1, $series[now()->toDateString()]);
        $this->assertSame(1, $series[now()->subDays(6)->toDateString()]);
        $this->assertSame(0, $series[now()->subDays(3)->toDateString()]);
    }

    /** Registered and never came back: usually a broken confirmation step. */
    public function test_never_signed_in_counts_accounts_with_no_successful_event(): void
    {
        $returned = Devotee::factory()->create();
        Devotee::factory()->create();

        $this->recordSignIn($returned, now());

        $this->assertSame(1, DevoteeStats::neverSignedIn());
    }

    /** A failed attempt is not a sign-in, and must not make an account look active. */
    public function test_a_failed_attempt_does_not_count_as_activity(): void
    {
        $devotee = Devotee::factory()->create();

        LoginEvent::create([
            'authenticatable_type' => $devotee->getMorphClass(),
            'authenticatable_id' => $devotee->getKey(),
            'guard' => 'devotee',
            'succeeded' => false,
            'failure_reason' => 'bad_password',
            'occurred_at' => now(),
        ]);

        $this->assertSame(0, DevoteeStats::activeUsers(1));
        $this->assertSame(1, DevoteeStats::failedSignIns(1));
        $this->assertSame(1, DevoteeStats::neverSignedIn());
    }

    protected function recordSignIn(object $account, \Carbon\CarbonInterface $at, string $guard = 'devotee'): void
    {
        LoginEvent::create([
            'authenticatable_type' => $account->getMorphClass(),
            'authenticatable_id' => $account->getKey(),
            'guard' => $guard,
            'succeeded' => true,
            'occurred_at' => $at,
        ]);
    }
}
