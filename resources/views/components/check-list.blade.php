@props(['name', 'legend', 'options', 'selected' => []])
@php($chosen = array_map('strval', (array) old($name, session()->hasOldInput() ? [] : $selected)))
<fieldset class="check-list wide" data-check-list>
    <legend>{{ $legend }}</legend>
    <input type="search" class="check-list-search" placeholder="ابحث في {{ $legend }}" aria-label="البحث في {{ $legend }}" data-check-list-search>
    <div class="check-list-options">
        @forelse($options as $value => $text)
            <label class="check-list-option" data-check-list-option>
                <input type="checkbox" name="{{ $name }}[]" value="{{ $value }}" @checked(in_array((string) $value, $chosen, true))>
                <span>{{ $text }}</span>
            </label>
        @empty
            <p class="muted">لا توجد خيارات متاحة.</p>
        @endforelse
    </div>
</fieldset>
@once
@push('scripts')
<script>
document.querySelectorAll('[data-check-list]').forEach(picker => {
    picker.querySelector('[data-check-list-search]').addEventListener('input', event => {
        const query = event.target.value.trim().toLocaleLowerCase();
        picker.querySelectorAll('[data-check-list-option]').forEach(option => {
            option.hidden = !option.textContent.toLocaleLowerCase().includes(query);
        });
    });
});
</script>
@endpush
@endonce
