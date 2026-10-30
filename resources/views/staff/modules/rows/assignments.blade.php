<td>{{ $row->title }}</td><td>{{ $row->subject?->name }}</td>
<td>
    @forelse ($row->classrooms as $classroom)
        <span>{{ $classroom->name }} {{ $classroom->section }}</span>
    @empty
        {{ $row->classroom?->name }} {{ $row->classroom?->section }}
    @endforelse
</td>
<td>{{ $row->teacher?->user?->name }}</td><td>{{ ($row->due_at ?: $row->due_date)?->format('Y-m-d') ?: '—' }}</td>
