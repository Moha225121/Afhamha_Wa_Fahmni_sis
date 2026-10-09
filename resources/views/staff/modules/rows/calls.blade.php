<td>{{ $row->student->user->name }}</td><td>{{ $row->reason }}</td><td>{{ $row->status }}</td><td>{{ $row->meeting_date?->format('Y-m-d') ?: '—' }}</td>
