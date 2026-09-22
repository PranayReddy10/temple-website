{{--
    Read-only facts about the signed-in account.

    Plain markup with scoped classes rather than Filament entry components:
    this sits inside a form schema, and a read-only entry there has to be
    fought into place. The styling lives in public/css/temple-admin.css with
    the rest of the theme, so it follows light and dark with everything else.
--}}
<dl class="temple-profile">
    <div class="temple-profile__item">
        <dt class="temple-profile__label">Signed in as</dt>
        <dd class="temple-profile__value">{{ $user->email }}</dd>
    </div>

    <div class="temple-profile__item">
        <dt class="temple-profile__label">Role</dt>
        <dd class="temple-profile__value">
            <span class="temple-profile__badge">{{ $role?->getLabel() ?? 'Unknown' }}</span>
        </dd>
        @if ($role)
            <p class="temple-profile__hint">{{ $role->description() }}</p>
        @endif
    </div>

    <div class="temple-profile__item">
        <dt class="temple-profile__label">Signs in to</dt>
        <dd class="temple-profile__value">{{ $panelName }}</dd>
    </div>

    <div class="temple-profile__item">
        <dt class="temple-profile__label">Last sign-in</dt>
        <dd class="temple-profile__value">
            {{ $user->last_login_at?->diffForHumans() ?? 'This is the first one' }}
        </dd>
    </div>

    @if ($role?->isTempleAdmin() ?? false)
        <div class="temple-profile__item temple-profile__item--wide">
            <dt class="temple-profile__label">Temples you maintain</dt>
            <dd class="temple-profile__value">
                @forelse ($temples as $temple)
                    <span class="temple-profile__badge">{{ $temple }}</span>
                @empty
                    <span class="temple-profile__hint">
                        None yet. A super admin has to approve your claim before a temple appears here.
                    </span>
                @endforelse
            </dd>
        </div>
    @endif
</dl>
