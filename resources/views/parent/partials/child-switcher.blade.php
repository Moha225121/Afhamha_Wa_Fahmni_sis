@if($children->count() > 1 && $selectedStudent)
    <form method="get" class="child-switch">
        <label for="student">الابن</label>
        <select id="student" name="student" data-unread-badges onchange="this.form.submit()">
            @foreach($children as $child)
                @php($unread = (int)($childUnreadCounts[$child->id] ?? 0))
                <option value="{{ $child->id }}" data-option-label="{{ $child->user->name }}{{ ($followupUnreadByStudent[$child->id] ?? 0) ? ' — '.$followupUnreadByStudent[$child->id].' جديد' : '' }}" data-unread-count="{{ $unread }}" @selected($selectedStudent->is($child))>{{ $child->user->name }}@if($followupUnreadByStudent[$child->id] ?? 0) — {{ $followupUnreadByStudent[$child->id] }} جديد@endif{{ $unread ? ' — '.$unread.' رسائل غير مقروءة' : '' }}</option>
            @endforeach
        </select>
    </form>
@endif
