<td>{{ $row->student->user->name }}</td><td>{{ $row->date?->format('Y-m-d') }}</td><td>{{ $row->status?->value ?? $row->status }}</td><td>{{ $row->arrival_time ?: '—' }}</td>
