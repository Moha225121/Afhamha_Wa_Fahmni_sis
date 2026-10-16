@if($children->count() > 1 && $selectedStudent)
    <form method="get" class="child-switch">
        <label for="student">الابن</label>
        <select id="student" name="student" onchange="this.form.submit()">
            @foreach($children as $child)
                <option value="{{ $child->id }}" @selected($selectedStudent->is($child))>{{ $child->user->name }}@if($followupUnreadByStudent[$child->id] ?? 0) — {{ $followupUnreadByStudent[$child->id] }} جديد@endif</option>
            @endforeach
        </select>
    </form>
@endif
