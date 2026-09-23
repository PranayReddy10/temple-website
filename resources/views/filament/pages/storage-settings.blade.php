{{--
    Storage & Uploads.

    Two questions, answered in order, because they are the two questions people
    actually arrive with: "why can't I see the picture I just uploaded?" and
    "what am I allowed to upload in the first place?".

    Everything is plain markup inside Filament sections, styled from
    public/css/temple-admin.css. No Vite build, same as the rest of the panel.
--}}
<x-filament-panels::page>
    {{-- The switch first: it is what someone came here to change. --}}
    <form wire:submit="save">
        {{ $this->form }}

        @if ($connection !== null)
            <div @class([
                'temple-storage__result',
                'temple-storage__result--bad' => ! $connection['ok'],
            ])>
                {{ $connection['message'] }}
            </div>
        @endif

        <div class="temple-storage__actions">
            @foreach ($this->getFormActions() as $action)
                {{ $action }}
            @endforeach
        </div>
    </form>

    <x-filament::section>
        <x-slot name="heading">Is storage working?</x-slot>
        <x-slot name="description">
            Each cause of missing images is checked on its own, so the one that is
            actually wrong is the one that shows red.
        </x-slot>

        <dl class="temple-storage__checks">
            @foreach ($this->checks() as $check)
                <div class="temple-storage__check">
                    <dt class="temple-storage__label">
                        <span @class([
                            'temple-storage__dot',
                            'temple-storage__dot--bad' => ! $check['ok'],
                        ])></span>
                        {{ $check['label'] }}
                    </dt>
                    <dd class="temple-storage__value">{{ $check['value'] }}</dd>
                    @if (filled($check['note']))
                        <p class="temple-storage__hint">{{ $check['note'] }}</p>
                    @endif
                </div>
            @endforeach
        </dl>
    </x-filament::section>

    {{--
        The live test. Hidden until it has been run, because a result from a
        previous page load would be worse than none: it would be read as current.
    --}}
    <x-filament::section>
        <x-slot name="heading">Live test</x-slot>
        <x-slot name="description">
            The checks above read settings. This one writes a real file and fetches
            a real image over the web, which is the only proof that it all works.
        </x-slot>

        @if ($probe === null && $reachability === null)
            <p class="temple-storage__hint">
                Press <strong>Check it now</strong> at the top of this page to run it.
            </p>
        @else
            <dl class="temple-storage__checks">
                @if ($probe !== null)
                    <div class="temple-storage__check">
                        <dt class="temple-storage__label">
                            <span @class([
                                'temple-storage__dot',
                                'temple-storage__dot--bad' => ! $probe['ok'],
                            ])></span>
                            Writing a file
                        </dt>
                        <dd class="temple-storage__value">{{ $probe['message'] }}</dd>
                    </div>
                @endif

                @if ($reachability !== null)
                    <div class="temple-storage__check">
                        <dt class="temple-storage__label">
                            <span @class([
                                'temple-storage__dot',
                                'temple-storage__dot--bad' => $reachability['ok'] === false,
                                'temple-storage__dot--unknown' => $reachability['ok'] === null,
                            ])></span>
                            Seeing a file in a browser
                        </dt>
                        <dd class="temple-storage__value">{{ $reachability['message'] }}</dd>
                        @if ($reachability['url'])
                            <p class="temple-storage__hint">
                                Tested with
                                <a href="{{ $reachability['url'] }}" target="_blank" rel="noopener" class="temple-storage__link">
                                    {{ $reachability['url'] }}
                                </a>
                            </p>
                        @endif
                    </div>
                @endif
            </dl>
        @endif
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">What can be uploaded</x-slot>
        <x-slot name="description">
            These limits are enforced by the upload fields themselves — this table
            reads them from the same place, so it cannot drift out of date.
        </x-slot>

        <div class="temple-storage__scroll">
            <table class="temple-storage__table">
                <thead>
                    <tr>
                        <th scope="col">Upload</th>
                        <th scope="col">Where</th>
                        <th scope="col">Accepted</th>
                        <th scope="col">Largest</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($this->uploadRules() as $rule)
                        <tr>
                            <th scope="row">
                                {{ $rule['label'] }}
                                @if (filled($rule['note']))
                                    <p class="temple-storage__hint">{{ $rule['note'] }}</p>
                                @endif
                            </th>
                            <td>{{ $rule['where'] }}</td>
                            <td>{{ $rule['types'] }}</td>
                            <td class="temple-storage__nowrap">
                                {{ $rule['max'] }}
                                @if ($rule['capped'])
                                    {{-- Why it is not the number in UploadRules; the check above says by what. --}}
                                    <p class="temple-storage__hint temple-storage__capped">
                                        form allows {{ $rule['capped_from'] }}
                                    </p>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <p class="temple-storage__hint temple-storage__footnote">
            To change a limit, edit <code>app/Support/UploadRules.php</code>. It is
            one file and every upload field reads from it, so a change there reaches
            the form, its error message and this table together. Raising one above
            what the server allows does nothing on its own: PHP's
            <code>upload_max_filesize</code> and <code>post_max_size</code> still
            apply, and on this server they are
            <strong>{{ ini_get('upload_max_filesize') ?: 'unknown' }}</strong> and
            <strong>{{ ini_get('post_max_size') ?: 'unknown' }}</strong>.
        </p>
    </x-filament::section>
</x-filament-panels::page>
