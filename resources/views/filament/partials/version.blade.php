{{-- Which build is running. Compare the commit with the latest on GitHub. --}}
<div style="padding:.5rem 1rem;font-size:.7rem;opacity:.55;text-align:center" title="Release {{ \App\Support\AppVersion::number() }}{{ \App\Support\AppVersion::commit() ? ', commit '.\App\Support\AppVersion::commit() : '' }}">
    {{ \App\Support\AppVersion::label() }}
</div>
