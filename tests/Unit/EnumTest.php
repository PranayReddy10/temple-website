<?php

namespace Tests\Unit;

use App\Enums\TempleStatus;
use App\Enums\UserRole;
use App\Enums\VerificationStatus;
use PHPUnit\Framework\TestCase;

/**
 * These enums encode the project's editorial and trust rules, so they are
 * worth testing directly. No database or framework boot needed.
 */
class EnumTest extends TestCase
{
    // --- VerificationStatus: section 20 of the project plan ---

    public function test_only_levels_claiming_authority_require_a_source(): void
    {
        $this->assertTrue(VerificationStatus::Official->requiresSource());
        $this->assertTrue(VerificationStatus::Verified->requiresSource());

        // Community and unverified make no claim, so they need no citation.
        $this->assertFalse(VerificationStatus::Community->requiresSource());
        $this->assertFalse(VerificationStatus::Unverified->requiresSource());
    }

    public function test_every_verification_level_is_visually_distinguishable(): void
    {
        $colors = array_map(
            fn (VerificationStatus $case): string => $case->getColor(),
            VerificationStatus::cases(),
        );

        // Official must never be mistaken for community at a glance.
        $this->assertSame(
            count($colors),
            count(array_unique($colors)),
            'Two verification levels share a colour, which blurs official and community content.',
        );
    }

    public function test_every_verification_level_has_a_label_and_description(): void
    {
        foreach (VerificationStatus::cases() as $case) {
            $this->assertNotSame('', trim($case->getLabel()));
            $this->assertNotSame('', trim($case->description()));
        }
    }

    // --- TempleStatus: the editorial workflow ---

    public function test_only_published_temples_are_public(): void
    {
        $this->assertTrue(TempleStatus::Published->isPublic());

        foreach ([TempleStatus::Draft, TempleStatus::InReview, TempleStatus::Archived] as $case) {
            $this->assertFalse(
                $case->isPublic(),
                "{$case->value} must never be exposed through the public API.",
            );
        }
    }

    public function test_exactly_one_status_is_public(): void
    {
        $public = array_filter(
            TempleStatus::cases(),
            fn (TempleStatus $case): bool => $case->isPublic(),
        );

        $this->assertCount(1, $public);
    }

    public function test_every_status_has_a_label_colour_and_icon(): void
    {
        foreach (TempleStatus::cases() as $case) {
            $this->assertNotSame('', trim($case->getLabel()));
            $this->assertNotSame('', trim($case->getColor()));
            $this->assertNotSame('', trim($case->getIcon()));
        }
    }

    // --- UserRole: who may publish and administer ---

    public function test_only_a_super_admin_may_publish(): void
    {
        $this->assertTrue(UserRole::SuperAdmin->canPublish());
        $this->assertFalse(UserRole::Editor->canPublish());
        // A temple admin submits for review; staff decide what goes live.
        $this->assertFalse(UserRole::TempleAdmin->canPublish());
    }

    public function test_each_role_belongs_to_exactly_one_panel(): void
    {
        $this->assertSame('admin', UserRole::SuperAdmin->panelId());
        $this->assertSame('admin', UserRole::Editor->panelId());
        // The separation that keeps a temple admin out of the editorial panel.
        $this->assertSame('temple', UserRole::TempleAdmin->panelId());
    }

    public function test_a_temple_admin_is_not_staff(): void
    {
        $this->assertTrue(UserRole::SuperAdmin->isStaff());
        $this->assertTrue(UserRole::Editor->isStaff());
        $this->assertFalse(UserRole::TempleAdmin->isStaff());
    }

    public function test_only_a_super_admin_may_manage_users(): void
    {
        $this->assertTrue(UserRole::SuperAdmin->canManageUsers());
        $this->assertFalse(UserRole::Editor->canManageUsers());
        $this->assertFalse(UserRole::TempleAdmin->canManageUsers());
    }

    public function test_every_role_has_a_label_and_description(): void
    {
        foreach (UserRole::cases() as $case) {
            $this->assertNotSame('', trim($case->getLabel()));
            $this->assertNotSame('', trim($case->description()));
        }
    }

    /**
     * Enum values are persisted in the database and will appear in API
     * responses, so renaming one is a migration, not a refactor. Pinning them
     * here makes that consequence explicit.
     */
    public function test_stored_enum_values_are_stable(): void
    {
        $this->assertSame(
            ['draft', 'in_review', 'published', 'archived'],
            array_column(TempleStatus::cases(), 'value'),
        );

        $this->assertSame(
            ['unverified', 'community', 'verified', 'official'],
            array_column(VerificationStatus::cases(), 'value'),
        );

        $this->assertSame(
            ['super_admin', 'editor', 'temple_admin'],
            array_column(UserRole::cases(), 'value'),
        );
    }
}
