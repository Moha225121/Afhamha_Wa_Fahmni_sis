<td>{{ $row->user->name }}</td>
<td>
    <div class="staff-classroom-list">
        @forelse ($row->classrooms as $classroom)
            <span>{{ $classroom->name }} {{ $classroom->section }}</span>
        @empty
            <span class="muted">لا توجد صفوف مسندة</span>
        @endforelse
    </div>
</td>
<td>{{ $lessons[$row->id] ?? 0 }}</td>
<td>{{ $assignments[$row->id] ?? 0 }}</td>
